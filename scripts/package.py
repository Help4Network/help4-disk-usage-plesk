"""Build the same Plesk extension ZIP on Windows and Linux."""
import hashlib
from pathlib import Path
import re
import stat
import sys
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
REPARSE_POINT = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)
PUBLIC_FILES = frozenset([
    "CHANGES.md", "DESCRIPTION.md", "LICENSE", "meta.xml",
    "htdocs/css/audit.css", "htdocs/images/h4.png", "htdocs/index.php", "htdocs/js/audit.js",
    "plib/collector/WindowsAcl.psm1", "plib/collector/runtime.py", "plib/collector/scan.py",
    "plib/collector/windows-acl.ps1", "plib/controllers/IndexController.php",
    "plib/hooks/ApiRpc.php", "plib/hooks/CustomButtons.php", "plib/hooks/LongTasks.php", "plib/hooks/PlanItems.php",
    "plib/library/Access.php", "plib/library/Permissions.php", "plib/library/Process.php",
    "plib/library/Releases.php", "plib/library/Remote.php", "plib/library/Report.php",
    "plib/library/Runtime.php", "plib/library/Scheduler.php", "plib/library/Store.php",
    "plib/library/Task/Scan.php", "plib/scripts/bridge.php", "plib/scripts/doctor.php",
    "plib/scripts/permissions.php", "plib/scripts/post-install.php", "plib/scripts/pre-uninstall.php",
    "plib/scripts/rotate.php", "plib/scripts/worker.php", "plib/views/scripts/index/index.phtml",
    "plib/views/scripts/index/settings.phtml", "plib/views/scripts/index/updates.phtml",
])


def _source_metadata(path):
    metadata = path.lstat()
    attributes = getattr(metadata, "st_file_attributes", None)
    if attributes is None:
        if sys.platform == "win32":
            raise RuntimeError("Windows source reparse metadata unavailable")
        attributes = 0
    if type(attributes) is not int or attributes < 0 or attributes & REPARSE_POINT:
        raise RuntimeError("Reparse or unverifiable native extension source")
    return metadata


def build(destination=None):
    source = ROOT / "extension"
    if not stat.S_ISDIR(_source_metadata(source).st_mode):
        raise RuntimeError("Native extension source must be a real directory")
    entries = {}
    directories = [source]
    while directories:
        for path in sorted(directories.pop().iterdir()):
            mode = _source_metadata(path).st_mode
            if stat.S_ISLNK(mode) or not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
                raise RuntimeError("Linked or nonregular native extension source")
            if stat.S_ISDIR(mode):
                # Inspect reparse/link metadata before descent, including on Python 3.10 Windows.
                directories.append(path)
                continue
            relative = path.relative_to(source)
            if "__pycache__" in relative.parts or path.suffix == ".pyc":
                continue
            name = relative.as_posix()
            if name not in PUBLIC_FILES:
                raise RuntimeError("Unexpected native extension file; review the public inventory")
            entries[name] = path.read_bytes()
    if entries.keys() != PUBLIC_FILES:
        raise RuntimeError("Incomplete native extension source")
    if len(entries["meta.xml"]) > 65536:
        raise RuntimeError("Native metadata exceeds the packaging limit")
    meta = ET.fromstring(entries["meta.xml"])
    if meta.tag != "module":
        raise RuntimeError("Invalid native module metadata")
    values = {}
    for key, pattern in [("id", r"help4-disk-usage"), ("version", r"[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}"),
                         ("release", r"[1-9][0-9]{0,5}")]:
        nodes = meta.findall(key)
        if len(nodes) != 1 or len(nodes[0]) or not re.fullmatch(pattern, nodes[0].text or ""):
            raise RuntimeError("Invalid or ambiguous native module identity/version")
        values[key] = nodes[0].text

    # Validate and capture the complete public payload before touching existing output artifacts.
    dist = Path(destination) if destination is not None else ROOT / "dist"
    dist.mkdir(parents=True, exist_ok=True)
    name = f"help4-disk-usage-{values['version']}-{values['release']}.zip"
    archive = dist / name
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as package:
        for name_in_zip, data in sorted(entries.items()):
            info = zipfile.ZipInfo(name_in_zip, (2026, 10, 8, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (dist / "SHA256SUMS").write_text(f"{digest}  {name}\n", encoding="ascii")
    return archive, digest


if __name__ == "__main__":
    archive, digest = build()
    print(f"{archive}\nSHA256 {digest}")
