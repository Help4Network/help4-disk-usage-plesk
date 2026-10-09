"""Build the same Plesk extension ZIP on Windows and Linux."""
import hashlib
from pathlib import Path
import zipfile
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
meta = ET.parse(root / "extension/meta.xml").getroot()
dist = root / "dist"
dist.mkdir(exist_ok=True)
name = f"help4-disk-usage-{meta.findtext('version')}-{meta.findtext('release')}.zip"
archive = dist / name
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
    for p in sorted((root / "extension").rglob("*")):
        if p.is_file() and "__pycache__" not in p.parts and p.suffix != ".pyc":
            info = zipfile.ZipInfo(p.relative_to(root / "extension").as_posix(), (2026, 10, 8, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, p.read_bytes())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(dist / "SHA256SUMS").write_text(f"{digest}  {name}\n", encoding="ascii")
print(f"{archive}\nSHA256 {digest}")
