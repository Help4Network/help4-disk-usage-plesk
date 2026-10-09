# Help4 Disk Usage For Plesk

Read-only subscription-scoped disk and filesystem-entry audits for **Plesk Obsidian on Linux and Windows**. A separate native extension, not a renamed [cPanel installer](https://github.com/Help4Network/help4-disk-usage).

**0.1.1 development preview: not yet approved for shared production hosting.** Native Linux AND Windows installation, role isolation, GUI, scheduling and update gates must pass before stable 1.0.0. See [validation](docs/validation.md). Automated CI is not Plesk certification.

## Features

- Largest files, recursively largest trees, entry-heavy trees, and large files unchanged for 90 days.
- Cache/mail/log/temp/backup/dependency attribution with cautious remediation hints.
- Last-scanned time, age, duration, errors and partial coverage; growth requires two complete scans of the same current subscription identity.
- Search, sort, paging, relative-path copy, formula-safe CSV and JSON. Rankings retain the top 100/view; search applies to this retained ranking.
- File -> parent / tree -> itself **Open in File Manager** links in a new authenticated Plesk tab, preserving native panel navigation.
- Administrator-editable runtime/TTL/refresh/server queue policies with per-subscription overrides. One scanner at a time.
- Current Plesk authorization on reports, refresh, export and file jumps. Worker authorization before and after scans. Ownership/home/name/GUID changes invalidate cached reports.

No deletion, rename, file-content reading, public arbitrary-root endpoint or automatic page-refresh loop. Cleanup stays in Plesk File Manager and the application. Bytes are logical file sizes, **not quota/allocated disk usage**; hard-link entries count separately. Mail/databases/backups outside the home are excluded. Windows reports filesystem entries, not POSIX inode quota. Symlinks, junctions, reparse points, cross-device trees and unsafe names are not followed.

## Requirements

Plesk Obsidian 18.0.55+, administrator-installed **Python 3.10+**, local subscription homes and private extension storage. No Python packages required. Python is not assumed to ship with Plesk.

| Platform | Default runtime setting | Collector protection |
| --- | --- | --- |
| Linux | `/usr/bin/python3` | Pinned descriptors, no-follow, device/inode identity checks |
| Windows | `C:\Program Files\Python313\python.exe` (edit to actual path) | Handle-bound enumeration and child opens, no-reparse flags, pinned ancestors |

Use your OS package manager or [official Windows Python installer](https://www.python.org/downloads/windows/). Select an absolute administrator-owned executable, never one writable by hosting users. Windows **ACLs**, not chmod, protect private reports/executables. UNC/network/device/ADS paths and reparse-point home ancestors are unsupported.

## Build And Test

```sh
git clone https://github.com/Help4Network/help4-disk-usage-plesk.git
cd help4-disk-usage-plesk
python3 -m unittest discover -s tests -v
php tests/security.php
python3 scripts/package.py
```

Windows: use `py -3` instead of `python3`. Packaging is cross-platform, without Bash. CI runs actual native Windows junction/handle tests separately from Linux descriptor tests.

## Install In A Plesk Lab

Verify `dist/SHA256SUMS`, then upload the built ZIP through **Extensions > My Extensions > Upload Extension** where permitted, or use an administrator terminal:

```sh
# Linux root
plesk bin extension --install /absolute/path/help4-disk-usage-0.1.1-1.zip
```

```powershell
# Windows elevated PowerShell
Get-FileHash 'C:\Lab\help4-disk-usage-0.1.1-1.zip' -Algorithm SHA256
plesk bin extension.exe --install 'C:\Lab\help4-disk-usage-0.1.1-1.zip'
```

Open **Disk Usage Audit** from native Plesk navigation. **Scan settings** selects the real Python executable and policy. Create synthetic unrelated customers/resellers first and follow [native validation](docs/validation.md). Click **Refresh scan**, then **Check status**. The scan runs in a background task; the customer page never auto-submits or refreshes itself.

Default customer policy: **3 requests/hour**, **300s minimum interval**, **60s scan runtime**. Server: **1 active scanner**, **60 requests/hour**, **16 pending subscriptions**. Hard ceilings: 120s, 2 million entries, 50,000 directories, 64 levels and 200 retained rows/view. Limits apply to actor and subscription; resellers cannot bypass them. Admin requests still obey server limits.

Administrator subscription overrides:

```json
{"123":{"hourly":6,"minimum_interval":300,"seconds":90},"456":{"hourly":0}}
```

`hourly:0` disables customer refresh. Service-plan automatic mappings, scheduled oldest-cache rotation and release-discovery/admin update UI remain pre-1.0 gates; they are not released features.

## Upgrade, Rollback And Uninstall

Pull Git changes, review `extension/CHANGES.md`, rerun tests/build, verify checksums, and reinstall the built ZIP with the same native command. **A git pull alone is not a deployed upgrade.** Policy/cache live outside source. No automatic download-and-execute pipeline or snapshot job exists. Roll back by rebuilding/reinstalling an identified prior Git tag/commit; invalidate incompatible cache schemas and never reuse a foreign-owner report.

Linux uninstall: `plesk bin extension --uninstall help4-disk-usage`. Windows: `plesk bin extension.exe --uninstall help4-disk-usage`. Finish/cancel queued scans first; native uninstall task/data handling must pass both OS gates before stable release.

## WHMCS And Public Review

[WHMCS integration status](docs/whmcs.md), [security policy](SECURITY.md), [release gates](docs/validation.md) and [marketing rules](docs/marketing.md). A separately named Plesk adapter must not overwrite the cPanel module. Public screenshots use synthetic example.test data only; private production evidence never enters this repo.

## Official SDK References

- [Native extension structure](https://docs.plesk.com/en-US/obsidian/extensions-guide/plesk-extensions-basics/extension-structure.71076/)
- [Official long-task example](https://github.com/plesk/ext-long-tasks)
- [Domain/home API](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Domain.html)
- [Session/impersonation API](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Session.html)
- [Current domain access checks](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Client.html)
- [Native Windows/Linux extension CLI](https://docs.plesk.com/en-US/obsidian/extensions-guide/extensions-management-utility.73617/)
- [Official local Plesk test server](https://github.com/plesk/docker)

MIT licensed. Anyone may use/modify/redistribute it, including panel vendors. Configurable report title; small footer credit links to [Help4 Network](https://help4network.com), who built it. Not a claim of Plesk endorsement, approval or catalog listing.
