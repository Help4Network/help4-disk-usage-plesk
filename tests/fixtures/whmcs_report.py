"""Synthetic collector/WHMCS schema interoperability fixture; no live panel access."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import time

source = Path(__file__).resolve().parents[2] / "extension/plib/collector/scan.py"
spec = importlib.util.spec_from_file_location("collector", source)
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary).resolve()
    (root / "httpdocs/cache").mkdir(parents=True)
    (root / "logs").mkdir()
    (root / "httpdocs/cache/item.bin").write_bytes(b"x" * 256)
    (root / "logs/access.log").write_bytes(b"x" * 64)
    (root / ".hidden").write_bytes(b"x" * 8)
    (root / "valid-\u00e9-\U0001f4c1.txt").write_bytes(b"x" * 16)
    (root / "=1+2.txt").write_bytes(b"x" * 32)
    backup = root / "backup.zip"
    with backup.open("wb") as output:
        output.truncate(12 * 1024 * 1024)
    os.utime(backup, (time.time() - 100 * 86400,) * 2)
    report = collector.scan(str(root))
    report["growth_bytes"] = None
    report["binding"] = "private-not-for-export"
    report["built_by"] = {"name": "untrusted", "url": "https://untrusted.example.test"}
    print(json.dumps(report, ensure_ascii=True))
