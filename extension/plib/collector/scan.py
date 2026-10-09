"""Bounded metadata-only traversal. Invoked only by the Plesk CLI worker."""
import argparse
import contextlib
import ctypes
import datetime
import heapq
import json
import ntpath
import os
import stat
import time


def safe_relative(path):
    return (isinstance(path, str) and len(path) <= 4096 and
            not any(ord(c) < 32 for c in path) and "\\" not in path and
            ":" not in path and not path.startswith("/") and
            all(p not in ("", "..") for p in path.split("/")))


def category(path):
    parts = path.lower().split("/")
    for kind, needles in [("cache", ("cache", ".cache", "wp-cache")),
                          ("mail", ("mail", "maildir", "cur", "new")),
                          ("logs", ("logs", "log")), ("temporary", ("tmp", "temp")),
                          ("dependencies", ("node_modules", "vendor", ".git"))]:
        if any(p in needles for p in parts):
            return kind
    name = parts[-1]
    if name.endswith((".zip", ".tar", ".gz", ".bak", ".sql", ".7z")):
        return "backups"
    if name.endswith((".log", ".log.1")):
        return "logs"
    return "other"


HINTS = {
    "cache": "Review application cache retention and purge through the application.",
    "mail": "Review mailbox retention with its owner; do not delete Maildir files blindly.",
    "logs": "Check rotation and retention; preserve logs needed for incident investigation.",
    "temporary": "Confirm no active process needs these files before clearing temporary data.",
    "dependencies": "Review generated dependencies with the application owner; preserve source.",
    "backups": "Verify a usable off-server backup before removing redundant archives.",
    "other": "Review ownership and purpose in File Manager before changing anything.",
}


class PosixTree:
    def __init__(self, root):
        if not os.path.isabs(root) or root == "/":
            raise ValueError("A non-root absolute subscription home is required")
        self.root = root
        self.ancestors = []

    def __enter__(self):
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
        try:
            fd = os.open("/", flags)
            self.ancestors.append(fd)
            for part in self.root.split("/")[1:]:
                if part in ("", ".", ".."):
                    raise ValueError("Invalid home component")
                fd = os.open(part, flags, dir_fd=fd)
                self.ancestors.append(fd)
            self.handle = fd
            self.device = os.fstat(fd).st_dev
            return self
        except BaseException:
            self.__exit__()
            raise

    def __exit__(self, *args):
        for fd in reversed(self.ancestors):
            os.close(fd)
        self.ancestors = []

    def entries(self, handle):
        with os.scandir(handle) as entries:
            for entry in entries:
                yield entry.name

    def info(self, handle, name):
        s = os.stat(name, dir_fd=handle, follow_symlinks=False)
        kind = "directory" if stat.S_ISDIR(s.st_mode) else "file" if stat.S_ISREG(s.st_mode) else "skip"
        if s.st_dev != self.device:
            kind = "skip"
        return {"kind": kind, "bytes": s.st_size, "modified": int(s.st_mtime),
                "identity": (s.st_dev, s.st_ino)}

    @contextlib.contextmanager
    def child(self, handle, name, info):
        fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=handle)
        try:
            s = os.fstat(fd)
            if (s.st_dev, s.st_ino) != info["identity"]:
                raise OSError("Directory changed during scan")
            yield fd
        finally:
            os.close(fd)


class WindowsTree:
    """Pin every ancestor against rename; never dereference a reparse point."""
    def __init__(self, root):
        from ctypes import wintypes
        self.w = wintypes
        self.k = ctypes.WinDLL("kernel32", use_last_error=True)
        self.k.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
                                      wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
        self.k.CreateFileW.restype = wintypes.HANDLE
        self.k.CloseHandle.argtypes = [wintypes.HANDLE]
        class Info(ctypes.Structure):
            _fields_ = [("attributes", wintypes.DWORD), ("created", wintypes.FILETIME),
                        ("accessed", wintypes.FILETIME), ("modified", wintypes.FILETIME),
                        ("volume", wintypes.DWORD), ("size_high", wintypes.DWORD),
                        ("size_low", wintypes.DWORD), ("links", wintypes.DWORD),
                        ("id_high", wintypes.DWORD), ("id_low", wintypes.DWORD)]
        self.Info = Info
        self.k.GetFileInformationByHandle.argtypes = [wintypes.HANDLE, ctypes.POINTER(Info)]
        drive, tail = ntpath.splitdrive(root)
        if (len(drive) != 2 or drive[1] != ":" or not drive[0].isalpha() or
                not tail.startswith("\\") or tail == "\\"):
            raise ValueError("Only an absolute local-drive subscription home is allowed")
        if any(p in ("", ".", "..") or p.endswith((".", " ")) or ":" in p
               for p in tail[1:].split("\\")):
            raise ValueError("Invalid Windows home component")
        self.root = root
        self.drive = drive
        self.parts = tail[1:].split("\\")
        self.ancestors = []

    def open(self, path):
        # READ_ATTRIBUTES only. Share read/write, but NOT delete/rename.
        h = self.k.CreateFileW(path, 0x80, 0x3, None, 3, 0x02200000, None)
        if h == ctypes.c_void_p(-1).value:
            raise ctypes.WinError(ctypes.get_last_error())
        data = self.Info()
        if not self.k.GetFileInformationByHandle(h, ctypes.byref(data)):
            self.k.CloseHandle(h)
            raise ctypes.WinError(ctypes.get_last_error())
        if data.attributes & 0x400:
            self.k.CloseHandle(h)
            raise OSError("Reparse point rejected")
        modified = ((data.modified.dwHighDateTime << 32) | data.modified.dwLowDateTime)
        info = {"kind": "directory" if data.attributes & 0x10 else "file",
                "bytes": (data.size_high << 32) | data.size_low,
                "modified": int(modified / 10000000 - 11644473600),
                "identity": (data.volume, data.id_high, data.id_low)}
        return h, info

    def __enter__(self):
        try:
            path = self.drive + "\\"
            for part in [None] + self.parts:
                if part is not None:
                    path = ntpath.join(path, part)
                h, info = self.open(path)
                if info["kind"] != "directory":
                    self.k.CloseHandle(h)
                    raise OSError("Home ancestor is not a directory")
                self.ancestors.append(h)
            self.handle = self.root
            self.volume = info["identity"][0]
            return self
        except BaseException:
            self.__exit__()
            raise

    def __exit__(self, *args):
        for h in reversed(self.ancestors):
            self.k.CloseHandle(h)
        self.ancestors = []

    def entries(self, handle):
        with os.scandir(handle) as entries:
            for entry in entries:
                yield entry.name

    def info(self, handle, name):
        if not safe_relative(name) or name in (".", "..") or name.endswith((".", " ")):
            raise OSError("Unsafe Windows name")
        h, info = self.open(ntpath.join(handle, name))
        self.k.CloseHandle(h)
        if info["identity"][0] != self.volume:
            raise OSError("Cross-volume entry rejected")
        return info

    @contextlib.contextmanager
    def child(self, handle, name, info):
        path = ntpath.join(handle, name)
        h, current = self.open(path)
        try:
            if current["kind"] != "directory" or current["identity"] != info["identity"]:
                raise OSError("Directory changed during scan")
            yield path
        finally:
            self.k.CloseHandle(h)


def scan(root, seconds=60, max_entries=2000000, max_directories=50000, depth=64, top=100):
    seconds = max(1, min(120, int(seconds)))
    top = max(1, min(200, int(top)))
    max_entries = max(1, min(2000000, int(max_entries)))
    max_directories = max(1, min(50000, int(max_directories)))
    depth = max(1, min(64, int(depth)))
    started = time.monotonic()
    now = int(time.time())
    report = {"schema": 1, "platform": "windows" if os.name == "nt" else "linux",
              "scanned_at": datetime.datetime.fromtimestamp(now, datetime.timezone.utc).isoformat(),
              "bytes": 0, "entries": 0, "files": 0, "directories": 1, "skipped": 0,
              "errors": 0, "complete": True, "limit": None, "categories": {},
              "largest_files": [], "stale_files": [], "largest_trees": [], "entry_trees": []}
    largest, stale, trees, counts = [], [], [], []
    serial = 0
    def retain(heap, score, row):
        nonlocal serial
        serial += 1
        item = (score, serial, row)
        if len(heap) < top:
            heapq.heappush(heap, item)
        elif score > heap[0][0]:
            heapq.heapreplace(heap, item)
    def bounded():
        reason = ("time" if time.monotonic() - started >= seconds else
                  "entries" if report["entries"] >= max_entries else None)
        if reason:
            report.update(complete=False, limit=reason)
            return True
        return False
    tree_type = WindowsTree if os.name == "nt" else PosixTree
    with tree_type(root) as tree:
        def walk(handle, prefix, level):
            total_bytes = total_entries = direct_bytes = direct_files = 0
            try:
                for name in tree.entries(handle):
                    if bounded():
                        break
                    path = name if prefix == "." else prefix + "/" + name
                    report["entries"] += 1
                    total_entries += 1
                    if not safe_relative(path):
                        report["skipped"] += 1
                        continue
                    try:
                        info = tree.info(handle, name)
                        if info["kind"] == "directory":
                            if level >= depth or report["directories"] >= max_directories:
                                report.update(complete=False, limit="depth_or_directories")
                                continue
                            report["directories"] += 1
                            with tree.child(handle, name, info) as child:
                                size, entries = walk(child, path, level + 1)
                            total_bytes += size
                            total_entries += entries
                        elif info["kind"] == "file":
                            size = max(0, info["bytes"])
                            report["bytes"] += size
                            report["files"] += 1
                            total_bytes += size
                            direct_bytes += size
                            direct_files += 1
                            kind = category(path)
                            report["categories"][kind] = report["categories"].get(kind, 0) + size
                            row = {"path": path, "kind": "file", "bytes": size,
                                   "modified": info["modified"], "category": kind, "hint": HINTS[kind]}
                            retain(largest, size, row)
                            if size >= 10485760 and now - info["modified"] >= 90 * 86400:
                                retain(stale, size, row)
                        else:
                            report["skipped"] += 1
                    except OSError:
                        report["errors"] += 1
                        report["complete"] = False
            except OSError:
                report["errors"] += 1
                report["complete"] = False
            row = {"path": prefix, "kind": "directory", "bytes": total_bytes,
                   "entries": total_entries, "direct_bytes": direct_bytes, "direct_files": direct_files,
                   "category": category(prefix), "hint": HINTS[category(prefix)]}
            retain(trees, total_bytes, row)
            retain(counts, total_entries, row)
            return total_bytes, total_entries
        walk(tree.handle, ".", 0)
    for key, heap in [("largest_files", largest), ("stale_files", stale),
                      ("largest_trees", trees), ("entry_trees", counts)]:
        report[key] = [item[2] for item in sorted(heap, reverse=True)]
    report["duration_seconds"] = round(time.monotonic() - started, 3)
    return report


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", required=True)
    parser.add_argument("--seconds", type=int, default=60)
    args = parser.parse_args()
    try:
        print(json.dumps(scan(args.root, args.seconds), ensure_ascii=True))
    except (ValueError, OSError):
        # Never leak an absolute account path or exception text to a customer.
        print(json.dumps({"error": "Subscription home unavailable or unsafe"}))
        raise SystemExit(1)
