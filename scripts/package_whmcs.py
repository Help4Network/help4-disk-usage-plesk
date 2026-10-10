"""Build the separately named WHMCS addon; never mix it with the native ZIP."""
import hashlib
from pathlib import Path
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MODULE = "help4_disk_usage_plesk"


def build(destination=None):
    meta = ET.parse(ROOT / "extension/meta.xml").getroot()
    version, release = meta.findtext("version"), meta.findtext("release")
    source = ROOT / "integrations/whmcs/modules/addons" / MODULE
    files = sorted(source.rglob("*"))
    entries = {}
    for path in files:
        if path.is_symlink():
            raise RuntimeError("Symlink in addon source")
        if path.is_file():
            if path.suffix not in (".php", ".tpl"):
                raise RuntimeError("Unexpected addon file")
            entries[f"modules/addons/{MODULE}/{path.relative_to(source).as_posix()}"] = path.read_bytes()
    required = [f"modules/addons/{MODULE}/{name}" for name in
                (f"{MODULE}.php", "hooks.php", "templates/clientarea.tpl", "lib/Native.php", "lib/Transport.php")]
    if any(name not in entries for name in required):
        raise RuntimeError("Incomplete addon")
    config = entries[f"modules/addons/{MODULE}/{MODULE}.php"].decode("utf-8")
    if f"'version' => '{version}'" not in config:
        raise RuntimeError("Addon version differs from native metadata")
    for name, path in {"README.md": ROOT / "integrations/whmcs/README.md",
                       "DEPLOYMENT.md": ROOT / "docs/whmcs.md", "LICENSE": ROOT / "LICENSE",
                       "SECURITY.md": ROOT / "SECURITY.md"}.items():
        if path.is_symlink():
            raise RuntimeError("Unsafe documentation source")
        entries[name] = path.read_bytes()
    entries["SHA256SUMS"] = "".join(
        f"{hashlib.sha256(data).hexdigest()}  {name}\n" for name, data in sorted(entries.items())
    ).encode("ascii")
    destination = Path(destination) if destination else ROOT / "dist"
    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / f"help4-disk-usage-plesk-whmcs-{version}-{release}.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as out:
        for name, data in sorted(entries.items()):
            info = zipfile.ZipInfo(name, (2026, 10, 8, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            out.writestr(info, data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n", encoding="ascii")
    return archive, digest


if __name__ == "__main__":
    archive, digest = build()
    print(f"{archive}\nSHA256 {digest}")
