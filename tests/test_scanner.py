import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

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
        for value in ["../neighbor", "/etc/passwd", "C:/secret", "a\\b", "x\x00y", "a//b"]:
            self.assertFalse(scanner.safe_relative(value))
        self.assertTrue(scanner.safe_relative("httpdocs/file name.txt"))

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


if __name__ == "__main__":
    unittest.main()
