"""Content-free runtime capability check; not native panel/ACL certification."""
import json
import os
import sys


def check():
    errors = []
    if sys.version_info < (3, 10):
        errors.append("Python 3.10 or newer is required")
    if sys.platform == "win32":
        import ctypes
        try:
            kernel = ctypes.WinDLL("kernel32", use_last_error=True)
            native = ctypes.WinDLL("ntdll")
            for name in ("CreateFileW", "GetFileInformationByHandle", "DeviceIoControl"):
                getattr(kernel, name)
            for name in ("NtCreateFile", "NtQueryDirectoryFile"):
                getattr(native, name)
        except (AttributeError, OSError):
            errors.append("Required Windows handle APIs are unavailable")
    elif sys.platform.startswith("linux"):
        if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_DIRECTORY"):
            errors.append("No-follow directory opens are unavailable")
        if not {os.open, os.stat}.issubset(os.supports_dir_fd) or os.scandir not in os.supports_fd:
            errors.append("Descriptor-relative filesystem APIs are unavailable")
    else:
        errors.append("Production collector supports Linux and Windows only")
    return {"ok": not errors, "platform": sys.platform,
            "python": list(sys.version_info[:3]), "errors": errors,
            "native_panel_validated": False, "private_storage_validated": False,
            "built_by": "https://help4network.com"}


if __name__ == "__main__":
    result = check()
    print(json.dumps(result))
    sys.exit(0 if result["ok"] else 2)
