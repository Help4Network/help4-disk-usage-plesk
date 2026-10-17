# Help4 Disk Usage For Plesk

Read-only subscription-scoped disk and filesystem-entry audits for **Plesk Obsidian on Linux and Windows**. A separate native extension, not a renamed [cPanel installer](https://github.com/Help4Network/help4-disk-usage).

**Free to use and deploy, including the Help4 WHMCS addon.** No Help4 license key, activation service, subscription, per-server fee or paid feature tier is required. Hosts and vendors may customize the surrounding branding and redistribute the software while retaining the small linked **Built by [Help4 Network](https://help4network.com/)** byline at the bottom of pages and reports and the included copyright/permission notice.

The repository's `LICENSE` records redistribution permission and attribution; it is not a paid activation requirement. Plesk, WHMCS and Windows Server platform licenses and terms are separate from this free extension. References to licensed native validation mean testing inside those vendor platforms, not activating Help4 Disk Usage. Free availability does not change the preview validation and release gates described below.

Both WHMCS adapters now live in the standalone [Help4 Disk Usage for WHMCS](https://github.com/Help4Network/help4-disk-usage-whmcs) repository, with independent versions, tests and packages. This repository installs only the native Plesk extension and retains its opt-in XML API bridge. Existing Plesk addon ID/settings/private tables do not change. WHMCS provisioning support alone does not install the extension or approve mappings. [Acceptance](docs/whmcs.md) remains a separate native WHMCS/Plesk gate; the cPanel adapter is not Plesk proof.

**0.3.0 development preview: not yet approved for shared production hosting.** Native Linux AND Windows installation, role isolation, GUI, scheduling and upgrade gates must pass before stable 1.0.0. See [validation](docs/validation.md) and the [Linux/Windows target matrix](docs/platform-support.md). Automated CI is not Plesk certification.

## Features

- Largest files, recursively largest trees, entry-heavy trees, and large files unchanged for 90 days.
- Cache/mail/log/temp/backup/dependency attribution with cautious remediation hints.
- Last-scanned time, age, duration, errors and partial coverage; growth requires two complete scans of the same current subscription identity.
- Search, sort, paging, relative-path copy, formula-safe CSV and JSON. Rankings retain the top 100/view; search applies to this retained ranking.
- File -> parent / tree -> itself **Open in File Manager** links in a new authenticated Plesk tab, preserving native panel navigation.
- Administrator-editable runtime/TTL/refresh/server queue policies, native service-plan profiles and per-subscription overrides. One scanner at a time; expired/failed scans preserve the previous report.
- Administrator-only stable-release discovery with bounded HTTPS checks, cooldown and last-check timestamps; reviewed native upgrades, not unattended download-and-execute.
- Opt-in native scheduled stale-report rotation, administrator-selected small batches and cooldown, with the same shared admission caps as manual refreshes.
- CLI Python/platform diagnostics and fail-closed runtime checks before settings saves and scans.
- Current Plesk authorization on reports, refresh, export and file jumps. Worker authorization before and after scans. Ownership/home/name/GUID changes invalidate cached reports.
- Export/File Manager actions retain their original viewer identity and recheck current authorization and subscription binding after private report reads and before attachment/redirect publication. SDK fixtures cover late access loss; native session reload and continuously atomic authorization remain validation gates.

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
php tests/policy.php
php tests/releases.php
php tests/release_authorization.php
php tests/controllers.php
php tests/index_viewer.php
php tests/report_actions.php
php tests/scheduler.php
php tests/runtime.php
php tests/runtime_response.php
php tests/process.php
php tests/process_deadline.php
php tests/lint.php
php tests/permissions.php
php tests/lifecycle.php
php tests/executable_preflight.php
php tests/queue_lock.php
python3 scripts/package.py
```

Windows: use `py -3` instead of `python3`. Packaging is cross-platform, without Bash. CI runs actual native Windows junction/handle tests separately from Linux descriptor tests.

The native builder requires the complete, explicitly reviewed public file inventory, including the unchanged repository license notice. Unexpected files, symlinks (including metadata/source-root/ignored-bytecode links), Windows reparse points/junctions, nonregular objects, missing components and ambiguous or unsafe module/version metadata fail before changing an existing ZIP or checksum. Directory metadata is checked before traversal; unavailable Windows reparse metadata fails closed on Python 3.10+. Normal generated bytecode remains excluded. Public inventory changes require updating the builder and its exact-payload regression together; keep the native license copy matched to `LICENSE`. Build from a trusted, quiescent exact-commit checkout: these path checks are not concurrent-replacement protection, and validation rejection safety is not an atomic ZIP/checksum transaction against filesystem failure.

## Install In A Plesk Lab

Verify `dist/SHA256SUMS`, then upload the built ZIP through **Extensions > My Extensions > Upload Extension** where permitted, or use an administrator terminal:

```sh
# Linux root
plesk bin extension --install /absolute/path/help4-disk-usage-0.3.0-2.zip
```

```powershell
# Windows elevated PowerShell
Get-FileHash 'C:\Lab\help4-disk-usage-0.3.0-2.zip' -Algorithm SHA256
plesk bin extension.exe --install 'C:\Lab\help4-disk-usage-0.3.0-2.zip'
```

Open **Disk Usage Audit** from native Plesk navigation. **Scan settings** selects the real Python executable and policy. Create synthetic unrelated customers/resellers first and follow [native validation](docs/validation.md). Click **Refresh scan**, then **Check status**. The scan runs in a background task; the customer page never auto-submits or refreshes itself.

Default customer policy: **3 requests/hour**, **300s minimum interval**, **60s scan runtime**. Server: **1 active scanner**, **60 requests/hour**, **16 pending subscriptions**. Hard ceilings: 120s, 2 million entries, 50,000 directories, 64 levels and 200 retained rows/view. Limits apply to actor and subscription; resellers cannot bypass them. Admin requests still obey server limits.

Administrator subscription overrides:

```json
{"123":{"hourly":6,"minimum_interval":300,"seconds":90},"456":{"hourly":0}}
```

`hourly:0` disables customer refresh. Native service plans expose one exclusive Disk Usage Audit profile: **Host default**, **Extended**, or **Customer refresh disabled**. No selected profile uses host default. Extended defaults to 6 requests/hour and 90s runtime; administrators can edit default/extended profile limits in Scan settings. Effective limits use subscription override, then profile, then host defaults. Disabled always blocks customer/reseller refresh, even with an enabling override; administrator requests still obey global admission limits. Plan/profile changes invalidate queued work before scanning or publication. Native plan synchronization is a Linux/Windows validation gate, not yet live-certified behavior.

Queue reservations expire after enough time for the configured queue to drain at the hard maximum runtime, plus a ten-minute allowance (46 minutes at the default queue size). Expired work is not silently kept queued. Installation registers one native hourly rotation task; work is **disabled by default**. Administrators can enable it in Scan settings. Default: two stale/missing reports every six hours. Scheduled work respects disabled plans, owner/host limits and one active scanner. See [bounded rotation and native scale gates](docs/platform-support.md).

Shared queue-lock contention waits at most two seconds of monotonic polling before rejecting with a retry-later error, without entering the state-changing callback. Short contention can clear within that window; this is not a bound on filesystem system calls or callback execution. The scanner and integration mutexes continue to reject concurrent work immediately. Do not repeatedly submit refresh while the queue is busy.

Run `plesk bin extension --exec help4-disk-usage doctor.php` on Linux, or `plesk bin extension.exe --exec help4-disk-usage doctor.php` on Windows, after configuring Python. This checks runtime capabilities without inspecting subscription contents; it does not certify storage ACLs or native panel behavior.

Windows includes a read-only ACL preflight: `plesk bin extension.exe --exec help4-disk-usage permissions.php`. The same bounded check runs before saving the selected executable, worker execution and enabling the optional WHMCS bridge. It fails on exposed private files, foreign-writable selected code/runtime paths, unsafe/reparse paths, unsupported ACLs or bounds. It changes no permissions and does not certify dependencies or continuous enforcement. See the [Windows ACL runbook](docs/windows-acl.md) for exact scope, trusted identities and native hosting-user gates.

## Upgrade, Rollback And Uninstall

The administrator **Software updates** page checks only the fixed repository's latest stable GitHub release. An explicit CSRF-protected check has a five-minute cooldown, verified TLS, no redirects, a six-second deadline and a 64 KiB metadata ceiling. It sends no subscription paths or account data. Failed checks retain the previous result and flag it as potentially stale; checks older than 24 hours are labeled stale. No published stable release is distinct from an unavailable check. Preview commits are not advertised as stable releases.

Pull Git changes, review `extension/CHANGES.md`, rerun tests/build, verify checksums, and reinstall the built ZIP with the same native command. **A git pull alone is not a deployed upgrade.** Policy/cache live outside source. Finish/cancel pending tasks before upgrades; older reservations without a policy binding fail closed. No automatic download-and-execute pipeline or snapshot job exists. Roll back by rebuilding/reinstalling an identified prior Git tag/commit; invalidate incompatible cache schemas and never reuse a foreign-owner report.

Linux uninstall: `plesk bin extension --uninstall help4-disk-usage`. Windows: `plesk bin extension.exe --uninstall help4-disk-usage`. Finish/cancel queued scans and in-flight bridge calls first. The guard disables the bridge and removes only its owned scheduler; native private-data handling must pass both OS gates before stable release. WHMCS deactivation retains private tables as documented in its separate runbook.

## WHMCS And Public Review

[Operator tutorial](docs/tutorial.md), [isolated lab results](docs/testing-lab.md), [WHMCS integration status](docs/whmcs.md), [security policy](SECURITY.md), [release gates](docs/validation.md) and [marketing kit](docs/marketing.md). A separately named Plesk adapter must not overwrite the cPanel module. Public screenshots use synthetic example.test data only; private production evidence never enters this repo.

The [feature evidence matrix](docs/features.md) distinguishes source/CI coverage from native Plesk validation and planned integrations.

Build the single tutorial handoff ZIP with `python3 scripts/tutorial_bundle.py` (Windows: `py -3 scripts/tutorial_bundle.py`). Six fresh 0.3.0 actual-template synthetic captures are included with captions and alt text in the marketing guide; the builder fails closed on missing version-matched captures. Existing 0.2.0 screenshots/kits remain historical preview material. The native kit includes public documentation, synthetic screenshots, native preview and checksums, not WHMCS addon runtime. Obtain the addon separately from its canonical repository. Private lab evidence, credentials and security-scan artifacts are excluded. It is not the stable 1.0.0 launch package.

## Official SDK References

- [Native extension structure](https://docs.plesk.com/en-US/obsidian/extensions-guide/plesk-extensions-basics/extension-structure.71076/)
- [Official long-task example](https://github.com/plesk/ext-long-tasks)
- [Domain/home API](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Domain.html)
- [Session/impersonation API](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Session.html)
- [Current domain access checks](https://plesk.github.io/pm-api-stubs/docs/classes/pm-Client.html)
- [Native service-plan integration](https://docs.plesk.com/en-US/obsidian/extensions-guide/plesk-features-available-for-extensions/implement-ui/integrate-to-plesk-ui/integrate-with-plesk-service-plans.77217/)
- [Native Windows/Linux extension CLI](https://docs.plesk.com/en-US/obsidian/extensions-guide/extensions-management-utility.73617/)
- [Official local Plesk test server](https://github.com/plesk/docker)

MIT licensed. Anyone may use/modify/redistribute it, including panel vendors. Configurable report title; small footer credit links to [Help4 Network](https://help4network.com), who built it. Not a claim of Plesk endorsement, approval or catalog listing.
