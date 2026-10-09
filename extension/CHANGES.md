# 0.3.0 Development Preview

- Add disabled-by-default native scheduled stale-report rotation with 1-4 queued subscriptions per tick, host/actor/subscription caps, finite admission attempts and a configurable 1-24 hour cooldown. Disabled plans remain disabled. Installation registration is idempotent; removal preserves unrelated tasks and refuses active/pending scans.
- Add Python 3.10+ native filesystem capability diagnostics and checks before saving a runtime or traversing a subscription. CLI diagnostics do not certify native panel roles or Windows ACLs.
- Recheck queued authorization, identity, policy and lease inside the publication state lock; disabling scheduled refresh revokes scheduled reservations.
- Extend native Windows/Linux Python and PHP CI and AlmaLinux/Ubuntu/Debian collector matrices. These are not licensed-panel installation/GUI/ACL proof.
- Remove the PHP 8.5 curl_close deprecation in release transport.
- Fix the scheduled-refresh checkbox sizing on desktop/mobile, add six fresh labeled 0.3.0 actual-template synthetic screenshots, and update operator instructions and public captions/alt text.
- Test runtime response size, timeout, malformed data, readiness/platform and subprocess exit failures without widening the five-second diagnostic limit.
- Stable 1.0.0, native Windows/Linux QA, Windows storage ACLs, large-fleet inventory query bounds and the WHMCS adapter remain gated. Synthetic tutorials are not native validation evidence.

# 0.2.0-1

Native service-plan profiles: host default, extended limits and customer refresh disabled. Host-editable profile limits and explicit subscription overrides retain hard ceilings; disabled plans cannot be re-enabled by an override. Conflicting or unavailable native plan data fails closed. Queued work binds its effective policy as well as current subscription identity.

Queue leases now allow the configured bounded queue to drain instead of expiring every reservation after ten minutes. Expired/failed scans display an identity-bound failure state while preserving the last good report. Transferred subscriptions cannot expose prior status or let an old worker clear a new reservation.

Administrator-only stable release discovery checks the fixed public repository with verified HTTPS, no redirects, a six-second timeout, a 64 KiB response ceiling and a five-minute cooldown. Failed checks retain the previous result with a stale warning. No remote content or binaries are executed; deployment still uses the reviewed native installer.

PHP policy/queue/release regressions and cross-platform lint added; PHP CI now runs on Linux and Windows. Still a development preview: native panel role/ACL/task validation, scheduled rotation, WHMCS integration and independent release review remain 1.0 gates.

# 0.1.1-1

Security hardening: handle-bound Windows directory enumeration and relative child opens with OBJ_DONT_REPARSE; reject undecodable filename bytes without blocking the remaining report; omissions mark coverage partial; preserve the originating administrator decision and recheck the current role before publication. Native FSCTL reparse race and pre-opened-writer regressions added.

Linux root-side private storage is owned by psaadm. Display omitted entries, default entry-heavy trees to entry count, and check the final collector-output size.

Still a development preview. Native Plesk Linux/Windows GUI, roles and ACLs remain release gates.

# 0.1.0-1

Initial development preview: native Plesk pages and domain-scoped background tasks; POSIX descriptor traversal and Windows pinned no-follow handles; identity-bound reports, queue/refresh limits, offender views, native File Manager links and safe exports.

Not stable 1.0.0. Native role/platform QA, WHMCS/update/scheduling gates remain open.
