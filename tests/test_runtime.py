import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("h4_runtime", Path(__file__).resolve().parents[1] /
                                              "extension/plib/collector/runtime.py")
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


class RuntimeTests(unittest.TestCase):
    def test_native_runtime(self):
        result = runtime.check()
        self.assertEqual(result["ok"], sys.platform == "win32" or sys.platform.startswith("linux"))
        self.assertFalse(result["native_panel_validated"])
        self.assertFalse(result["private_storage_validated"])

    def test_old_python_denied(self):
        with patch.object(runtime.sys, "version_info", (3, 9, 2)):
            self.assertFalse(runtime.check()["ok"])

    def test_unsupported_platform_denied(self):
        with patch.object(runtime.sys, "platform", "unsupported"):
            self.assertFalse(runtime.check()["ok"])
