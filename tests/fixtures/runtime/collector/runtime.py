"""Untrusted subprocess responses for the PHP runtime boundary tests."""
import json
import os
import sys
import time

mode = os.environ.get("H4_RUNTIME_FIXTURE")
if mode == "timeout":
    time.sleep(30)
elif mode == "oversized":
    print("x" * 20000)
elif mode == "malformed":
    print("not json")
else:
    print(json.dumps({"ok": mode != "not-ready",
                      "platform": "wrong" if mode == "wrong-platform" else sys.platform,
                      "python": list(sys.version_info[:3]), "errors": [],
                      "native_panel_validated": False, "private_storage_validated": False,
                      "built_by": "https://help4network.com"}))
    if mode == "nonzero":
        sys.exit(2)
