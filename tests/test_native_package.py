import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("native_builder", ROOT / "scripts/package.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class NativePackageTests(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="h4-native-package-")
        self.addCleanup(self.fixture.cleanup)
        self.root = Path(self.fixture.name)
        self.source = self.root / "extension"
        shutil.copytree(ROOT / "extension", self.source, symlinks=True,
                        ignore=shutil.ignore_patterns("__pycache__", "*.pyc"))
        (self.root / "scripts").mkdir()
        self.script = self.root / "scripts/package.py"
        shutil.copyfile(ROOT / "scripts/package.py", self.script)
        meta = ET.parse(self.source / "meta.xml").getroot()
        self.filename = f"help4-disk-usage-{meta.findtext('version')}-{meta.findtext('release')}.zip"
        self.archive = self.root / "dist" / self.filename
        self.manifest = self.root / "dist/SHA256SUMS"

    def build(self):
        return subprocess.run([sys.executable, "-I", "-S", str(self.script)],
                              capture_output=True, timeout=10, check=False)

    def assert_refused(self, change):
        self.assertEqual(self.build().returncode, 0, "Valid native fixture failed")
        before = (self.archive.read_bytes(), self.manifest.read_bytes())
        change()
        result = self.build()
        self.assertNotEqual(result.returncode, 0, "Unsafe/incomplete native source accepted")
        self.assertEqual((self.archive.read_bytes(), self.manifest.read_bytes()), before,
                         "Rejected source overwrote the previous package/checksum")
        self.assertEqual(sorted(p.name for p in (self.root / "dist").iterdir()),
                         sorted([self.filename, "SHA256SUMS"]))

    def symlink(self, target, link, directory=False):
        try:
            link.symlink_to(target, target_is_directory=directory)
        except (OSError, NotImplementedError):
            self.skipTest("Symlink creation unavailable on this test identity")

    def test_complete_deterministic_exact_public_payload(self):
        expected = {p.relative_to(self.source).as_posix(): p.read_bytes()
                    for p in self.source.rglob("*") if p.is_file()}
        self.assertEqual(len(expected), 37, "Review native public inventory changes explicitly")
        self.assertEqual(self.build().returncode, 0)
        first = self.archive.read_bytes()
        self.assertEqual(self.build().returncode, 0)
        self.assertEqual(self.archive.read_bytes(), first)
        digest = hashlib.sha256(first).hexdigest()
        self.assertEqual(self.manifest.read_text("ascii"), f"{digest}  {self.filename}\n")
        with zipfile.ZipFile(self.archive) as package:
            self.assertIsNone(package.testzip())
            self.assertEqual(set(package.namelist()), set(expected))
            self.assertEqual(len(package.namelist()), len(expected))
            self.assertEqual(package.read("LICENSE"), (ROOT / "LICENSE").read_bytes())
            for entry in package.infolist():
                self.assertEqual(package.read(entry.filename), expected[entry.filename])
                self.assertEqual(entry.external_attr >> 16, 0o100644)
                self.assertEqual(entry.date_time, (2026, 10, 8, 0, 0, 0))
                self.assertFalse(entry.filename.startswith("/"))
                self.assertNotIn("..", Path(entry.filename).parts)

    def test_unexpected_private_file_rejected_without_output_changes(self):
        self.assert_refused(lambda: (self.source / ".env").write_text("SYNTHETIC-PRIVATE-CANARY"))

    def test_missing_worker_rejected_without_output_changes(self):
        self.assert_refused(lambda: (self.source / "plib/scripts/worker.php").unlink())

    def test_missing_license_rejected_without_output_changes(self):
        self.assert_refused(lambda: (self.source / "LICENSE").unlink())

    def test_missing_windows_acl_helper_rejected_without_output_changes(self):
        self.assert_refused(lambda: (self.source / "plib/collector/WindowsAcl.psm1").unlink())

    def test_file_symlink_rejected_without_output_changes(self):
        outside = self.root / "synthetic-private"
        outside.write_text("SYNTHETIC-PRIVATE-CANARY")
        def change():
            path = self.source / "plib/scripts/worker.php"
            path.unlink()
            self.symlink(outside, path)
        self.assert_refused(change)

    def test_metadata_symlink_rejected_without_output_changes(self):
        outside = self.root / "synthetic-meta.xml"
        shutil.copyfile(self.source / "meta.xml", outside)
        def change():
            path = self.source / "meta.xml"
            path.unlink()
            self.symlink(outside, path)
        self.assert_refused(change)

    def test_source_root_symlink_rejected_without_output_changes(self):
        def change():
            outside = self.root / "synthetic-source"
            self.source.rename(outside)
            self.symlink(outside, self.source, directory=True)
        self.assert_refused(change)

    def simulated_reparse(self, relative, missing=False):
        self.assertEqual(self.build().returncode, 0)
        before = (self.archive.read_bytes(), self.manifest.read_bytes())
        target = self.source / relative
        original_stat, original_iterdir = Path.lstat, Path.iterdir
        traversed = []
        def marked(path):
            metadata = original_stat(path)
            if path == target:
                fields = {"st_mode": metadata.st_mode}
                if not missing:
                    fields["st_file_attributes"] = getattr(metadata, "st_file_attributes", 0) | 0x400
                return SimpleNamespace(**fields)
            return metadata
        def observed(path):
            if path == target:
                traversed.append(path)
            return original_iterdir(path)
        with mock.patch.object(builder, "ROOT", self.root), mock.patch.object(Path, "lstat", marked), \
                mock.patch.object(Path, "iterdir", observed):
            if missing:
                with mock.patch.object(builder.sys, "platform", "win32"):
                    with self.assertRaises(RuntimeError):
                        builder.build()
            else:
                with self.assertRaises(RuntimeError):
                    builder.build()
        self.assertEqual(traversed, [], "Reparse/unverifiable source was traversed")
        self.assertEqual((self.archive.read_bytes(), self.manifest.read_bytes()), before)

    def test_simulated_root_reparse_rejected_before_traversal(self):
        self.simulated_reparse("")

    def test_simulated_directory_reparse_rejected_before_traversal(self):
        self.simulated_reparse("plib/collector")

    def test_unavailable_windows_metadata_fails_closed(self):
        self.simulated_reparse("", missing=True)

    @unittest.skipUnless(sys.platform == "win32", "Native Windows junction fixture")
    def test_native_windows_source_junction_rejected_without_output_changes(self):
        def change():
            outside = self.root / "synthetic-source"
            self.source.rename(outside)
            result = subprocess.run(["cmd", "/c", "mklink", "/J", str(self.source), str(outside)],
                                    capture_output=True, timeout=10, check=False)
            self.assertEqual(result.returncode, 0, "Native junction fixture unavailable")
        self.assert_refused(change)

    @unittest.skipUnless(sys.platform == "win32", "Native Windows junction fixture")
    def test_native_windows_child_junction_rejected_without_output_changes(self):
        def change():
            path = self.source / "plib/collector"
            outside = self.root / "synthetic-collector"
            path.rename(outside)
            result = subprocess.run(["cmd", "/c", "mklink", "/J", str(path), str(outside)],
                                    capture_output=True, timeout=10, check=False)
            self.assertEqual(result.returncode, 0, "Native junction fixture unavailable")
        self.assert_refused(change)

    def test_ignored_bytecode_symlink_rejected_without_output_changes(self):
        outside = self.root / "synthetic-bytecode"
        outside.write_text("SYNTHETIC-PRIVATE-CANARY")
        self.assert_refused(lambda: self.symlink(outside, self.source / "private.pyc"))

    @unittest.skipUnless(hasattr(os, "mkfifo"), "POSIX FIFO fixture")
    def test_nonregular_source_rejected_without_output_changes(self):
        self.assert_refused(lambda: os.mkfifo(self.source / "synthetic-pipe"))

    def test_generated_bytecode_is_excluded(self):
        cache = self.source / "plib/collector/__pycache__"
        cache.mkdir()
        (cache / "scan.fixture.pyc").write_bytes(b"synthetic bytecode")
        self.assertEqual(self.build().returncode, 0)
        with zipfile.ZipFile(self.archive) as package:
            self.assertEqual(len(package.namelist()), 37)
            self.assertFalse(any("__pycache__" in name or name.endswith(".pyc") for name in package.namelist()))

    def test_unsafe_and_ambiguous_metadata_rejected_without_output_changes(self):
        path = self.source / "meta.xml"
        original = path.read_bytes()
        for field, value in [("version", "../1.0.0"), ("version", "1/0/0"),
                             ("version", ""), ("release", "../2"), ("release", "0"),
                             ("release", "-1"), ("id", "other-extension")]:
            with self.subTest(field=field, value=value):
                path.write_bytes(original)
                def change():
                    meta = ET.fromstring(original)
                    meta.find(field).text = value
                    ET.ElementTree(meta).write(path, encoding="utf-8")
                self.assert_refused(change)
        path.write_bytes(original)
        def duplicate():
            meta = ET.fromstring(original)
            ET.SubElement(meta, "version").text = "9.9.9"
            ET.ElementTree(meta).write(path, encoding="utf-8")
        self.assert_refused(duplicate)


if __name__ == "__main__":
    unittest.main()
