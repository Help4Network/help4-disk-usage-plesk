# Feature Evidence: 0.3.0 Preview

This matrix describes the Plesk extension, not the separate cPanel/WHM/WHMCS product. Implemented source and successful fixture/OS tests are not approval for shared production hosting. Consult the exact commit's [CI](https://github.com/Help4Network/help4-disk-usage-plesk/actions) and [native release gates](validation.md).

| Capability | Source / Automated Coverage | Native Plesk Status |
| --- | --- | --- |
| Largest files, recursive trees, entry-heavy trees, stale large files | Linux and Windows collector regressions | End-to-end subscription scans pending |
| Relative paths, partial coverage, timestamps, growth | Collector, report and identity fixtures; six fresh synthetic templates | Native task completion and ownership transitions pending |
| Search, sort, paging, path copy, safe CSV/JSON | Report/export fixtures; template browser QA | Authenticated role/UI/download QA pending |
| File Manager jumps | Exact retained-path membership, kind, authorization and parent-routing fixtures | Native Linux/Windows destination and panel junction checks pending |
| Customer, reseller, additional-user and administrator isolation | Current-role/ownership/impersonation and foreign-action fixtures | Native multi-tenant negative tests pending |
| Manual scan limits, finite queue, one worker | Actor/subscription/interval/server/lease/policy/token fixtures | Native concurrent requests and worker dispatch pending |
| Native service-plan profiles and subscription overrides | Precedence, disabled-plan, conflict and policy-change fixtures | Native synchronization and lifecycle pending |
| Opt-in scheduled rotation | Disabled default, bounded batches, cooldown and common-cap fixtures | Linux SDK shows one registered rotator; disabled tick queues zero; working tasks/Windows lifecycle pending |
| Runtime diagnostics and bounded subprocess capture | Python/API and malformed/platform/exit/size/timeout fixtures; process tests on both CI OSes | Linux CLI diagnostics passed after an intermittent lab timeout; licensed/native reliability and Windows Plesk checks pending |
| Private report/executable storage | Linux private modes and descriptor/handle protections in source | Windows Server explicit/inherited ACL and hosting-user access tests pending |
| Native panel navigation and no idle reload | SDK page integration; passive GET/POST-303 fixtures; desktop/390px template QA | Native shell, five-minute idle and mobile role views pending |
| Stable-release detection | Fixed-repository TLS/metadata/cooldown/admin-isolation/failure-retention fixtures | Native network page and reviewed upgrade/rollback pending |
| Linux/Windows installable ZIP | Cross-platform deterministic builder, checksums; isolated Linux native install | Full-systemd licensed Linux and supported Windows Server install/uninstall pending |
| WHMCS Plesk adapter and server health/deployment | Planned, no working adapter in this preview | Not available; separate integration acceptance required |
| Automatic package installation or file cleanup | Not implemented intentionally | No unattended updater, deletion, rename or content editing |

Logical file bytes are not allocated blocks, billing totals or quota reconciliation. Windows entry counts are not POSIX inode quotas. Files outside the subscription home are excluded. Vendor OS support and extension proof are separate; see [Linux/Windows targets](platform-support.md).

The public tutorial ZIP contains the version-matched installable preview, release notes, instructions, this matrix, synthetic screenshots with captions/alt text and checksums. It excludes private lab artifacts, customer identities and raw security reports. A final stable 1.0.0 launch requires version-matched native evidence and explicit release approval.

Built by [Help4 Network](https://help4network.com).
