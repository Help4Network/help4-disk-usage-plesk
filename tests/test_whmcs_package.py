import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("whmcs_builder", ROOT / "scripts/package_whmcs.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class WhmcsPackageTests(unittest.TestCase):
    def test_complete_deterministic_and_private_data_free(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive, digest = builder.build(tmp)
            first = archive.read_bytes()
            _, second = builder.build(tmp)
            self.assertEqual(digest, second)
            self.assertEqual(first, archive.read_bytes())
            self.assertEqual(digest, hashlib.sha256(first).hexdigest())
            with zipfile.ZipFile(archive) as package:
                names = package.namelist()
                self.assertEqual(len(names), len(set(names)))
                self.assertTrue(all(not name.startswith("/") and ".." not in Path(name).parts for name in names))
                self.assertFalse(any("audit/" in name or "evidence" in name or ".git/" in name or "screenshots" in name for name in names))
                self.assertIn("modules/addons/help4_disk_usage_plesk/hooks.php", names)
                self.assertIn("modules/addons/help4_disk_usage_plesk/lib/Transport.php", names)
                self.assertNotIn("meta.xml", names)
                for line in package.read("SHA256SUMS").decode("ascii").splitlines():
                    checksum, name = line.split("  ", 1)
                    self.assertEqual(checksum, hashlib.sha256(package.read(name)).hexdigest())


if __name__ == "__main__":
    unittest.main()
