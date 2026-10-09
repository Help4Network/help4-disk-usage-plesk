"""Build an allowlisted, synthetic-only public tutorial handoff."""
import hashlib
from pathlib import Path
import runpy
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
STAMP = (2026, 10, 8, 0, 0, 0)


def build():
    runpy.run_path(str(ROOT / "scripts/package.py"), run_name="__main__")
    meta = ET.parse(ROOT / "extension/meta.xml").getroot()
    version, release = meta.findtext("version"), meta.findtext("release")
    package = ROOT / "dist" / f"help4-disk-usage-{version}-{release}.zip"
    paths = [ROOT / "README.md", ROOT / "LICENSE", ROOT / "SECURITY.md"]
    paths += sorted((ROOT / "docs").glob("*.md"))
    expected = [f"synthetic-{name}-{version}.jpg" for name in
                ("desktop", "entry-trees", "settings", "partial", "failure", "updates")]
    paths += [ROOT / "docs/screenshots" / name for name in expected]
    paths += [package, ROOT / "dist/SHA256SUMS"]
    for path in paths:
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(f"Missing or unsafe public artifact: {path.name}")
    entries = {p.relative_to(ROOT).as_posix(): p.read_bytes() for p in paths}
    entries["KIT-SHA256SUMS.txt"] = "".join(
        f"{hashlib.sha256(data).hexdigest()}  {name}\n"
        for name, data in sorted(entries.items())
    ).encode("ascii")
    archive = ROOT / "dist" / f"help4-disk-usage-plesk-{version}-tutorial-kit.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as out:
        for name, data in sorted(entries.items()):
            info = zipfile.ZipInfo(name, STAMP)
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            out.writestr(info, data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(
        f"{digest}  {archive.name}\n", encoding="ascii")
    print(f"{archive}\nSHA256 {digest}")


if __name__ == "__main__":
    build()
