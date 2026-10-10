# Windows ACL Preflight And Release Gate

Available in the 0.3.0-2 preview as a **read-only diagnostic and fail-closed preflight**, not an installer permission repair or a stable-release approval. The bounded native wrapper runs before saving a selected Python executable, before Windows worker/runtime execution, and before enabling the optional WHMCS bridge. The command remains available for operator inspection. It does not change ACLs, create accounts, change services or read report/file contents. No unauthenticated diagnostic browser endpoint exists.

Verify the reviewed package SHA-256 and installed source first. A diagnostic cannot establish the trustworthiness of the code executing it. Use an isolated, licensed Windows Server Plesk lab, keep scheduled refresh disabled and preserve the native release gates.

## Native Extension Command

After configuring the actual administrator-controlled Python executable, run from an authorized administrator terminal:

```powershell
plesk bin extension.exe --exec help4-disk-usage permissions.php
```

The native command selects the extension's private audit directory, the `plib` and `htdocs` trees, and the configured Python executable. It checks existing private files as well as the directory: Windows traverse privileges can make a folder-only ACL inspection inadequate. Private storage is expected to be flat; an unexpected child directory fails the check.

Successful JSON includes `schema: 1`, `ok: true`, an object count, `native_panel_validated: false` and `continuous_enforcement: false`. Failures return nonzero with a generic native CLI message. Paths, account names, SIDs and raw ACLs are not emitted. Keep even sanitized lab results outside the source repository.

## Expanded Administrator Check

The default trusted local panel account is `psaadm`, plus SYSTEM and Built-in Administrators. TrustedInstaller is allowed only for protected paths and their ancestors. Verify the actual identities running the Plesk application pool and extension tasks on this exact server. **The default does not establish that those identities are correct or have the required access.** An unavailable, disabled or non-local selected account fails closed. This helper does not support arbitrary account groups, domain identities or virtual application-pool identities as substitutes for a verified local panel account.

For a verified existing local panel account and additional protected runtime trees, use the installed script with Windows PowerShell 5.1 or PowerShell 7. Substitute the actual administrator-controlled paths; examples are not a statement about a particular installation:

```powershell
$private = 'C:\PleskPrivate\help4-disk-usage\audit'
$script = 'C:\PleskModule\help4-disk-usage\plib\collector\windows-acl.ps1'
$protected = @(
    'C:\PleskModule\help4-disk-usage\plib',
    'C:\PleskModule\help4-disk-usage\htdocs',
    'C:\Program Files\Python313'
) | ConvertTo-Json -Compress
$encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($protected))
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" `
    -NoLogo -NoProfile -NonInteractive -File $script `
    -PrivateDirectory $private -ProtectedPathsBase64 $encoded `
    -PanelUser 'psaadm' -MaxObjects 4096 -MaxSeconds 10
if ($LASTEXITCODE -ne 0) { throw 'ACL preflight did not pass; do not approve shared hosting.' }
```

No execution-policy bypass is included. If execution is blocked, follow the organization's reviewed signing/execution policy; do not weaken it for this preview. The native PHP wrapper selects 64-bit Windows PowerShell, including Sysnative when required, so the local-account module is available on a 64-bit server.

The path-list argument is Base64-encoded UTF-8 JSON to avoid Windows PowerShell 5.1 native argument quoting differences. This is transport encoding, not encryption or authorization. The helper still requires an array of string paths and validates each path before inspection.

The default native command checks **Python's executable only**, not every DLL, standard-library file, helper, launcher or search-path directory it can load. The expanded check can inspect selected runtime trees, but is limited to 8 selected paths and 4,096 objects. The default is 512 objects. A large runtime that exceeds the limit does not pass; do not increase a cap without reviewing the implementation. Use independent Windows security tooling and an administrator-reviewed runtime installation to finish that gate.

## Fail-Closed Decisions

- Require local drive paths on NTFS. Reject UNC, device, alternate-data-stream, dot-segment and ambiguous trailing-dot/space paths.
- Inspect ancestor metadata from the volume root. Reject reparse points rather than following them.
- Require trusted ownership, a present non-null DACL and supported non-callback standard allow/deny ACEs.
- Reject any nonzero foreign allow grant on private storage/files, including read and inherit-only grants. Deny entries do not cancel an unsafe allow for this conservative audit.
- Reject foreign write, delete, delete-child, write-DACL, write-owner, generic-write and generic-all grants on selected code/runtime objects. Read/execute grants alone are permitted there.
- Check ancestor replacement/write permissions separately; inherit-only ancestor grants are checked where inherited on selected objects. Create-subdirectory permission alone on an ancestor is not treated as replacement of an existing protected child.

Unsupported or complex ACLs, missing metadata, foreign owners and object/time limits are failures, not a healthy empty result. Diagnostic loop checks have a 10-second ceiling; the native PHP subprocess has a 12-second deadline and 16 KiB output cap. OS calls and cleanup can stall; neither bound is a certification against uninterruptible kernel calls. The standalone PowerShell command has cooperative checks, not the PHP parent's process deadline.

## What Still Needs Native Proof

This metadata snapshot does not pin handles, prevent ACL/path changes afterward, continuously enforce permissions, verify all executable dependencies or enumerate every customer's effective token. It is not rerun on every customer report render; queued Windows workers fail before runtime execution when the preflight fails. Quiesce permission changes during administrator inspection and retain the collector's separate handle-based protections.

Before a shared-hosting release, test actual Plesk installation/upgrade/uninstall and both application-pool/task identities. From unrelated hosting-user identities, prove denied reads of every private report/state file and denied writes/replacement of source, Python and dependencies. Repeat after native upgrade, inheritance changes and account transfer. Prove authorized panel/task access still works, including scheduling and native File Manager navigation. Windows GitHub runner NTFS tests are not these licensed Plesk tests.

References: [Microsoft Windows file security](https://learn.microsoft.com/en-us/windows/win32/fileio/file-security-and-access-rights), [Microsoft local accounts](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.localaccounts/get-localuser?view=powershell-5.1), [Plesk default Windows disk permissions](https://docs.plesk.com/en-US/obsidian/advanced-administration-guide-win/changing-security-settings-for-file-system-objects-and-accounts/windows-accounts-used-by-plesk-to-manage-windows-objects/default-user-permissions-for-disks.49485/).

Built by [Help4 Network](https://help4network.com).
