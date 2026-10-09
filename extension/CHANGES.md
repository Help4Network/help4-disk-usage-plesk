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
