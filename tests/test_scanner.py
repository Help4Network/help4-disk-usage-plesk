import importlib.util
import errno
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location("scanner", Path(__file__).parents[1] / "extension/plib/collector/scan.py")
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)


class ScannerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(os.path.realpath(self.tmp.name))
        (self.root / "httpdocs/cache").mkdir(parents=True)
        (self.root / "httpdocs/cache/item.bin").write_bytes(b"x" * 4096)
        (self.root / ".hidden").write_bytes(b"x" * 17)

    def tearDown(self):
        self.tmp.cleanup()

    def test_counts_hidden_and_categories(self):
        r = scanner.scan(str(self.root))
        self.assertEqual(r["bytes"], 4113)
        self.assertEqual(r["files"], 2)
        self.assertEqual(r["entries"], 4)
        self.assertEqual(r["categories"]["cache"], 4096)
        self.assertTrue(r["complete"])

    def test_sparse_logical_size_and_stale(self):
        p = self.root / "backup.zip"
        with p.open("wb") as f:
            f.truncate(12 * 1024 * 1024)
        os.utime(p, (time.time() - 100 * 86400,) * 2)
        r = scanner.scan(str(self.root))
        self.assertEqual(r["largest_files"][0]["bytes"], 12 * 1024 * 1024)
        self.assertEqual(r["stale_files"][0]["path"], "backup.zip")

    def test_entry_limit_is_partial(self):
        r = scanner.scan(str(self.root), max_entries=1)
        self.assertFalse(r["complete"])
        self.assertEqual(r["limit"], "entries")

    def test_directory_limit_is_partial(self):
        self.assertFalse(scanner.scan(str(self.root), max_directories=1)["complete"])

    def test_unsafe_report_paths(self):
        for value in ["../neighbor", "/etc/passwd", "C:/secret", "a\\b", "x\x00y", "a//b", "bad\udcff"]:
            self.assertFalse(scanner.safe_relative(value))
        self.assertTrue(scanner.safe_relative("httpdocs/file name.txt"))

    @unittest.skipIf(os.name == "nt", "Raw POSIX filename bytes")
    def test_non_utf8_filename_produces_decodable_partial_report(self):
        name = os.fsencode(self.root) + b"/bad-\xff.zip"
        try:
            with open(name, "wb") as f:
                f.truncate(12 * 1024 * 1024)
        except OSError as error:
            if error.errno == errno.EILSEQ:
                self.skipTest("This filesystem requires valid Unicode filenames")
            raise
        r = scanner.scan(str(self.root))
        self.assertFalse(r["complete"])
        self.assertEqual(r["skipped"], 1)
        self.assertEqual(r["bytes"], 4113)
        encoded = json.dumps(r, ensure_ascii=True)
        self.assertNotIn("\\udcff", encoded)
        decoded = json.loads(encoded)
        self.assertEqual(decoded["files"], 2)
        php = shutil.which("php")
        if php:
            result = subprocess.run([php, "-r", "json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);"],
                input=encoded, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)

    def test_valid_unicode_names_preserved(self):
        name = "valid-\u00e9-\U0001f4c1.txt"
        (self.root / name).write_bytes(b"x" * 8192)
        r = scanner.scan(str(self.root))
        self.assertTrue(r["complete"])
        self.assertEqual(r["largest_files"][0]["path"], name)

    def test_unsafe_name_omission_is_partial(self):
        if os.name == "nt":
            self.skipTest("Windows cannot create a colon filename")
        (self.root / "unsafe:name").write_bytes(b"not retained")
        r = scanner.scan(str(self.root))
        self.assertFalse(r["complete"])
        self.assertEqual(r["skipped"], 1)

    def test_no_absolute_home_in_report(self):
        import json
        self.assertNotIn(str(self.root), json.dumps(scanner.scan(str(self.root))))

    @unittest.skipIf(os.name == "nt", "POSIX descriptor test")
    def test_symlink_does_not_reveal_neighbor(self):
        with tempfile.TemporaryDirectory() as other:
            Path(other, "secret-neighbor.txt").write_text("private")
            (self.root / "linked").symlink_to(other, target_is_directory=True)
            r = scanner.scan(str(self.root))
            self.assertNotIn("secret-neighbor.txt", str(r))
            self.assertEqual(r["skipped"], 1)
            self.assertFalse(r["complete"])

    @unittest.skipIf(os.name == "nt", "POSIX descriptor test")
    def test_root_symlink_rejected(self):
        alias = self.root / "alias"
        alias.symlink_to(self.root / "httpdocs", target_is_directory=True)
        with self.assertRaises(OSError):
            scanner.scan(str(alias))

    @unittest.skipIf(os.name == "nt", "POSIX descriptor test")
    def test_changed_directory_identity_rejected(self):
        with scanner.PosixTree(str(self.root)) as tree:
            info = tree.info(tree.handle, "httpdocs")
            (self.root / "httpdocs").rename(self.root / "old")
            (self.root / "httpdocs").mkdir()
            with self.assertRaises(OSError):
                with tree.child(tree.handle, "httpdocs", info):
                    self.fail("Changed directory opened")

    @unittest.skipUnless(os.name == "nt", "Native Windows junction test")
    def test_windows_junction_is_not_followed(self):
        with tempfile.TemporaryDirectory() as other:
            Path(other, "secret-neighbor.txt").write_text("private")
            junction = str(self.root / "junction")
            result = subprocess.run(["cmd", "/c", "mklink", "/J", junction, other], capture_output=True)
            self.assertEqual(result.returncode, 0)
            try:
                r = scanner.scan(str(self.root))
                self.assertNotIn("secret-neighbor.txt", str(r))
                self.assertFalse(r["complete"])
            finally:
                os.rmdir(junction)

    @unittest.skipUnless(os.name == "nt", "Native Windows handle test")
    def test_windows_ancestors_cannot_be_renamed(self):
        with scanner.WindowsTree(str(self.root)):
            with self.assertRaises(OSError):
                (self.root / "httpdocs").parent.rename(str(self.root) + "-moved")

    @unittest.skipUnless(os.name == "nt", "Native Windows input test")
    def test_windows_devices_unc_ads_and_root_rejected(self):
        for p in ["C:\\", "\\\\server\\share", "\\\\?\\C:\\test", "C:\\test:ads", "C:\\test\\..\\other"]:
            with self.assertRaises(ValueError):
                scanner.WindowsTree(p)

    @staticmethod
    def windows_set_junction(path, target, access=0x40000000):
        import ctypes
        import struct
        from ctypes import wintypes
        k = ctypes.WinDLL("kernel32", use_last_error=True)
        k.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
            wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
        k.CreateFileW.restype = wintypes.HANDLE
        k.CloseHandle.argtypes = [wintypes.HANDLE]
        k.DeviceIoControl.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.LPVOID,
            wintypes.DWORD, wintypes.LPVOID, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD), wintypes.LPVOID]
        substitute = ("\\??\\" + target).encode("utf-16-le")
        display = target.encode("utf-16-le")
        names = substitute + b"\x00\x00" + display + b"\x00\x00"
        data = struct.pack("<IHHHHHH", 0xa0000003, 8 + len(names), 0,
            0, len(substitute), len(substitute) + 2, len(display)) + names
        h = k.CreateFileW(path, access, 7, None, 3, 0x02200000, None)
        if h == ctypes.c_void_p(-1).value:
            return False
        try:
            returned = wintypes.DWORD()
            buffer = ctypes.create_string_buffer(data)
            return bool(k.DeviceIoControl(h, 0x900a4, buffer, len(data), None, 0,
                ctypes.byref(returned), None))
        finally:
            k.CloseHandle(h)

    @unittest.skipUnless(os.name == "nt", "Native Windows reparse mutation test")
    def test_windows_in_place_reparse_race_never_returns_foreign_metadata(self):
        with tempfile.TemporaryDirectory() as other:
            Path(other, "secret-neighbor.txt").write_text("private")
            control = self.root / "positive-control"
            control.mkdir()
            try:
                self.assertTrue(self.windows_set_junction(str(control), other), "Reparse test harness must work")
            finally:
                os.rmdir(control)
            for access in [0, 0x100, 0x2, 0x40000000]:
                candidate = self.root / ("candidate-" + str(access))
                candidate.mkdir()
                try:
                    with scanner.WindowsTree(str(self.root)) as tree:
                        info = tree.info(tree.handle, candidate.name)
                        with tree.child(tree.handle, candidate.name, info) as child:
                            original = tree.details
                            attempted = []
                            def mutate_after_check(handle):
                                result = original(handle)
                                if handle == child and not attempted:
                                    attempted.append(self.windows_set_junction(str(candidate), other, access))
                                return result
                            tree.details = mutate_after_check
                            try:
                                names = list(tree.entries(child))
                            except OSError:
                                names = []
                            self.assertTrue(attempted, "Race must be attempted after the final attribute check")
                            self.assertNotIn("secret-neighbor.txt", names)
                            with self.assertRaises(OSError):
                                tree.info(child, "secret-neighbor.txt")
                finally:
                    os.rmdir(candidate)

    @unittest.skipUnless(os.name == "nt", "Native Windows handle-relative traversal")
    def test_windows_traversal_does_not_reopen_scandir_paths(self):
        with mock.patch.object(scanner.os, "scandir", side_effect=AssertionError("Pathname enumeration")):
            r = scanner.scan(str(self.root))
        self.assertTrue(r["complete"])
        self.assertEqual(r["bytes"], 4113)

    @unittest.skipUnless(os.name == "nt", "Native Windows writable pin test")
    def test_windows_preopened_writer_causes_fail_closed_pin(self):
        import ctypes
        from ctypes import wintypes
        k = ctypes.WinDLL("kernel32", use_last_error=True)
        k.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
            wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
        k.CreateFileW.restype = wintypes.HANDLE
        k.CloseHandle.argtypes = [wintypes.HANDLE]
        h = k.CreateFileW(str(self.root), 0x40000000, 7, None, 3, 0x02200000, None)
        self.assertNotEqual(h, ctypes.c_void_p(-1).value)
        try:
            with self.assertRaises(OSError):
                scanner.scan(str(self.root))
        finally:
            k.CloseHandle(h)


if __name__ == "__main__":
    unittest.main()
