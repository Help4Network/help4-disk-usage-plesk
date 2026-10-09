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
            not any(ord(c) < 32 or 0xd800 <= ord(c) <= 0xdfff for c in path) and "\\" not in path and
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
    """Enumerate and open children relative to checked native directory handles."""
    def __init__(self, root):
        from ctypes import wintypes
        self.w = wintypes
        self.k = ctypes.WinDLL("kernel32", use_last_error=True)
        self.k.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
                                      wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
        self.k.CreateFileW.restype = wintypes.HANDLE
        self.k.CloseHandle.argtypes = [wintypes.HANDLE]
        self.k.GetFileInformationByHandleEx.argtypes = [wintypes.HANDLE, ctypes.c_int,
                                                        wintypes.LPVOID, wintypes.DWORD]
        self.k.GetFileInformationByHandleEx.restype = wintypes.BOOL
        self.n = ctypes.WinDLL("ntdll")
        class UnicodeString(ctypes.Structure):
            _fields_ = [("Length", wintypes.USHORT), ("MaximumLength", wintypes.USHORT),
                        ("Buffer", wintypes.LPWSTR)]
        class ObjectAttributes(ctypes.Structure):
            _fields_ = [("Length", wintypes.ULONG), ("RootDirectory", wintypes.HANDLE),
                        ("ObjectName", ctypes.POINTER(UnicodeString)), ("Attributes", wintypes.ULONG),
                        ("SecurityDescriptor", wintypes.LPVOID), ("SecurityQualityOfService", wintypes.LPVOID)]
        class IoStatus(ctypes.Structure):
            _fields_ = [("Status", ctypes.c_void_p), ("Information", ctypes.c_size_t)]
        class DirectoryInfo(ctypes.Structure):
            _fields_ = [("NextEntryOffset", wintypes.DWORD), ("FileIndex", wintypes.DWORD),
                        ("CreationTime", ctypes.c_longlong), ("LastAccessTime", ctypes.c_longlong),
                        ("LastWriteTime", ctypes.c_longlong), ("ChangeTime", ctypes.c_longlong),
                        ("EndOfFile", ctypes.c_longlong), ("AllocationSize", ctypes.c_longlong),
                        ("FileAttributes", wintypes.DWORD), ("FileNameLength", wintypes.DWORD),
                        ("EaSize", wintypes.DWORD), ("ShortNameLength", ctypes.c_byte),
                        ("ShortName", wintypes.WCHAR * 12), ("FileId", ctypes.c_longlong),
                        ("FileName", wintypes.WCHAR * 1)]
        self.UnicodeString, self.ObjectAttributes = UnicodeString, ObjectAttributes
        self.IoStatus, self.DirectoryInfo = IoStatus, DirectoryInfo
        self.n.NtCreateFile.argtypes = [ctypes.POINTER(wintypes.HANDLE), wintypes.DWORD,
            ctypes.POINTER(ObjectAttributes), ctypes.POINTER(IoStatus), wintypes.LPVOID,
            wintypes.ULONG, wintypes.ULONG, wintypes.ULONG, wintypes.ULONG,
            wintypes.LPVOID, wintypes.ULONG]
        self.n.NtCreateFile.restype = ctypes.c_long
        self.n.RtlNtStatusToDosError.argtypes = [ctypes.c_long]
        self.n.RtlNtStatusToDosError.restype = wintypes.ULONG
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

    def details(self, h):
        data = self.Info()
        if not self.k.GetFileInformationByHandle(h, ctypes.byref(data)):
            raise ctypes.WinError(ctypes.get_last_error())
        if data.attributes & 0x400:
            raise OSError("Reparse point rejected")
        modified = ((data.modified.dwHighDateTime << 32) | data.modified.dwLowDateTime)
        info = {"kind": "directory" if data.attributes & 0x10 else "file",
                "bytes": (data.size_high << 32) | data.size_low,
                "modified": int(modified / 10000000 - 11644473600),
                "identity": (data.volume, data.id_high, data.id_low)}
        return info

    def open_child(self, parent, name, directory=False):
        if (not safe_relative(name) or "/" in name or name in (".", "..") or
                name.endswith((".", " "))):
            raise OSError("Unsafe Windows name")
        buffer = ctypes.create_unicode_buffer(name)
        length = len(name.encode("utf-16-le"))
        string = self.UnicodeString(length, length + 2, ctypes.cast(buffer, self.w.LPWSTR))
        attributes = self.ObjectAttributes(ctypes.sizeof(self.ObjectAttributes), parent,
            ctypes.pointer(string), 0x1040, None, None)  # OBJ_DONT_REPARSE | OBJ_CASE_INSENSITIVE
        h, status = self.w.HANDLE(), self.IoStatus()
        result = self.n.NtCreateFile(ctypes.byref(h), 0x100081 if directory else 0x100080,
            ctypes.byref(attributes), ctypes.byref(status), None, 0, 1 if directory else 3,
            1, 0x200020, None, 0)  # FILE_OPEN_REPARSE_POINT | synchronous, FILE_OPEN
        if result < 0:
            raise ctypes.WinError(self.n.RtlNtStatusToDosError(result))
        try:
            info = self.details(h)
            if directory and info["kind"] != "directory":
                raise OSError("Not a directory")
            if info["identity"][0] != self.volume:
                raise OSError("Cross-volume entry rejected")
            return h, info
        except BaseException:
            self.k.CloseHandle(h)
            raise

    def __enter__(self):
        try:
            h = self.k.CreateFileW(self.drive + "\\", 0x81, 1, None, 3, 0x02200000, None)
            if h == ctypes.c_void_p(-1).value:
                raise ctypes.WinError(ctypes.get_last_error())
            self.ancestors.append(h)
            info = self.details(h)
            self.volume = info["identity"][0]
            for part in self.parts:
                h, info = self.open_child(h, part, directory=True)
                self.ancestors.append(h)
            self.handle = h
            return self
        except BaseException:
            self.__exit__()
            raise

    def __exit__(self, *args):
        for h in reversed(self.ancestors):
            self.k.CloseHandle(h)
        self.ancestors = []

    def entries(self, handle):
        buffer = ctypes.create_string_buffer(65536)
        offset = self.DirectoryInfo.FileName.offset
        info_class = 11
        while True:
            self.details(handle)
            if not self.k.GetFileInformationByHandleEx(handle, info_class, buffer, len(buffer)):
                error = ctypes.get_last_error()
                if error == 18:  # ERROR_NO_MORE_FILES
                    return
                raise ctypes.WinError(error)
            info_class = 10
            cursor = 0
            while True:
                entry = self.DirectoryInfo.from_buffer(buffer, cursor)
                length = entry.FileNameLength
                if length % 2 or cursor + offset + length > len(buffer):
                    raise OSError("Invalid directory record")
                name = ctypes.string_at(ctypes.addressof(buffer) + cursor + offset, length).decode("utf-16-le", "surrogatepass")
                if name not in (".", ".."):
                    yield name
                step = entry.NextEntryOffset
                if not step:
                    break
                if step < offset + length or cursor + step + ctypes.sizeof(self.DirectoryInfo) > len(buffer):
                    raise OSError("Invalid directory offset")
                cursor += step

    def info(self, handle, name):
        h, info = self.open_child(handle, name)
        self.k.CloseHandle(h)
        return info

    @contextlib.contextmanager
    def child(self, handle, name, info):
        h, current = self.open_child(handle, name, directory=True)
        try:
            if current["kind"] != "directory" or current["identity"] != info["identity"]:
                raise OSError("Directory changed during scan")
            yield h
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
                        report["complete"] = False
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
                            report["complete"] = False
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
