# Linux And Windows Installation Targets

Vendor list reviewed 2026-10-09. A supported Plesk/OS combination is a target, not evidence that this preview passed native shared-hosting validation. The same extension ZIP is used on Linux and Windows. Python 3.10+ must be separately installed under administrator control; do not replace an older OS's system Python or weaken isolation to meet that requirement.

| Vendor OS family | Target versions / restrictions | Current extension evidence |
| --- | --- | --- |
| Ubuntu | 26.04, 24.04, 22.04; older versions require vendor lifecycle review | Linux runtime/collector CI; native panel QA pending |
| AlmaLinux | 8, 9, 10 | Linux runtime/collector CI; native panel QA pending |
| Debian | 12, 13; 11 ends at Plesk 18.0.81; 10 needs ELS | Linux runtime/collector CI on 12/13; native panel QA pending |
| CloudLinux | 8, 9; 7 requires ELS | Licensed LVE/CageFS/runtime/ACL validation pending |
| RHEL | 8, 9, latest minor and vendor prerequisites | Native licensed validation pending; AlmaLinux CI is not RHEL certification |
| Rocky Linux | 8, latest minor | Native validation pending; not substituted with a Rocky 9 target |
| CentOS | 7, latest minor, ELS | Legacy/custom Python and native validation pending |
| Windows Server | 2016, 2019, 2022, 2025; supported editions/Server Core, NTFS | Native Windows collector/runtime/PHP CI; Plesk install/ACL/GUI gates pending |
| ARM | Vendor-listed Ubuntu 22.04 ARM only | Extension/native panel QA pending; macOS/ARM emulation is not Linux ARM proof |

Respect the installed Plesk build's OS minimums, lifecycle and paid ELS entitlements. The vendor requirements page includes restrictions that may supersede a historical OS list. Do not advertise obsolete OSes as new-install recommendations. Unsupported Windows clients, network/UNC homes, reparse-point ancestors and non-NTFS Plesk installations are not targets.

## Native Diagnostics

After configuring the actual administrator-owned Python executable, run:

```sh
plesk bin extension --exec help4-disk-usage doctor.php
```

```powershell
plesk bin extension.exe --exec help4-disk-usage doctor.php
```

This checks runtime version and descriptor/handle API availability without reading subscription files. It returns nonzero on an unavailable/incompatible runtime. It intentionally reports native panel and private-storage validation as false; inspect those separately.

## Background Rotation

Installation registers one extension-scoped hourly native task. Its body is disabled until an unimpersonated administrator enables Scheduled stale-report refresh. The default interval is six hours, batch two, and each queued scan uses the same owner/plan/minimum interval/hourly/server/queue constraints as manual scans. One worker remains active at a time. The candidate-selection loop has a ten-second budget, at most 10,000 examined domains and at most twice the batch size in admission attempts. Oldest-first ordering is among examined candidates; missing reports rank first. Native inventory lookup duration and fleets exceeding this window remain explicit scale-validation gates, not a guaranteed bounded native database query or complete-fleet fairness claim.

Run `rotate.php` through the same native extension CLI for a controlled opt-in lab check. Repeated ticks obey the configured cooldown; they cannot submit an unlimited scan batch. No browser auto-refresh or automatic package updater is introduced.

## Stable Gate

Complete [native Linux and Windows matrix](validation.md) on full, licensed targets, including private storage/executable ACLs, current role/plan/ownership changes, native tasks, File Manager, mobile shell, upgrades and pending-task uninstall. Public GitHub runner tests and a successful container installation do not replace that matrix. Keep production/native evidence private; public tutorials require version-matched synthetic images.

[Official Plesk OS and component requirements](https://docs.plesk.com/release-notes/obsidian/system-requirements/)

Built by [Help4 Network](https://help4network.com).
