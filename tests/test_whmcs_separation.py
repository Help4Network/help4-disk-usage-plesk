from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class WhmcsSeparationTests(unittest.TestCase):
    def test_native_repository_does_not_install_or_package_addons(self):
        self.assertFalse(any(path.is_file() for path in (ROOT / 'integrations/whmcs/modules').rglob('*')))
        self.assertFalse((ROOT / 'scripts/package_whmcs.py').exists())
        bundle = (ROOT / 'scripts/tutorial_bundle.py').read_text()
        self.assertNotIn('package_whmcs.py', bundle)
        self.assertNotIn('whmcs.with_suffix', bundle)
        self.assertIn('help4-disk-usage-whmcs', (ROOT / 'docs/whmcs.md').read_text())

    def test_native_bridge_and_protocol_contract_remain(self):
        self.assertTrue((ROOT / 'extension/plib/library/Remote.php').is_file())
        self.assertTrue((ROOT / 'extension/plib/hooks/ApiRpc.php').is_file())
        workflow = (ROOT / '.github/workflows/test.yml').read_text()
        self.assertIn('Help4Network/help4-disk-usage-whmcs', workflow)
        self.assertIn('H4DU_PLESK_SOURCE', workflow)
        self.assertNotIn('WHMCS_CONTRACT_COMMIT', workflow)


if __name__ == '__main__':
    unittest.main()
