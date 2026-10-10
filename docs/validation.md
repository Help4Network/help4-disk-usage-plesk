# Validation And 1.0 Release Gates

0.3.0 is a development preview. Record environment/date for each check. CI is not live Plesk proof.

## Automated

Linux: no-follow, replaced-directory identity, hidden/Unicode names, raw-byte filename partial report (including PHP decoding when installed), sparse logical bytes, stale age, entry/directory bounds, no absolute-path output. Windows: actual Win32 handles, handle-bound enumeration, junction rejection, post-check FSCTL reparse mutation, pre-opened writer rejection, pinned-ancestor rename denial, UNC/device/ADS/root rejection. Windows tests must run on Windows, not just skip on Linux. PHP: access denial, originating admin decision and fresh role recheck, traversal rejection, cached membership, correct parent jump, export stripping and formula neutralization; service-plan precedence/hard bounds, actor/subscription/server admission, queue lease/expiry, policy changes, transfer/token isolation and failure retention; release metadata validation, admin/impersonation isolation, cooldown and failed-check cache preservation. PHP tests run on both OSes; fixture success is not native SDK proof.

## Native Matrix: Both Linux And Windows

Windows ACL automated tests cover raw descriptors (including deny/allow combinations, null DACLs, unsupported ACEs and inherited grants), real private-file and executable ACLs, ancestor replacement grants, junctions, safe-path rejection and limits on Windows runners under PowerShell 5.1 and 7. PHP tests reject malformed/nonzero/overbroad diagnostic responses. The [operator diagnostic](windows-acl.md) is a read-only snapshot, not native installer verification, continuous enforcement or complete Python dependency validation.

WHMCS guard/report fixtures reject foreign/inactive services before IO, bind current client/server/subscription/owner/home/mapping identities and reread scope after IO. The installable candidate adds SDK/transport/SQLite tests for activation, exact mappings, TLS options/XML/nonce/pin/response caps, selected-client/transfer changes, role/permission denial, disconnect tombstones, leases/rate caps and escaped pages. Health preserves unknown/stale counters and 20-server bounds; actual Linux/Windows collectors interoperate with strict metadata exports. Lifecycle fixtures exercise active scanner/RPC/pending uninstall guards and private locks. These are not licensed native API authentication, true TLS network, MySQL concurrency, WHMCS framework or Plesk File Manager proof; see [acceptance runbook](whmcs.md).

Create two unrelated customers, owned reseller subscriptions, an unrelated reseller and a limited additional user using synthetic example.test names only.

1. Install ZIP; confirm native left navigation/header remain on admin, reseller, customer and impersonated pages.
2. Deny foreign index/refresh/export/open actions without revealing foreign identity, path or report presence.
3. Valid/missing/invalid CSRF; POST-only refresh and stable 303 GET. Five minutes idle must not trigger reloads or scans.
4. Simultaneous requests: one scanner, finite queue, actor/subscription/interval/server limits.
5. Source/runtime/private-storage ownership. Windows inherited/explicit ACLs deny hosting users report/state access and executable writes.
6. Foreign-target symlinks/junctions and replacement races reveal no target names/paths/sizes. Partial/skipped coverage is explicit.
7. File -> parent, tree -> itself native File Manager, spaces/Unicode names. Panel itself refuses unauthorized junction traversal.
8. Ownership/home change or delete/recreate invalidates cache/export/jumps immediately; queued workers abort.
9. Desktop and actual 390px mobile rendering, no overlap/console errors, deliberate table scrolling, stable shell.
10. Versioned upgrade/downgrade, cache compatibility and native uninstall with pending tasks leave no exposed worker/data.
11. Native service-plan selection/synchronization: default/extended/disabled, explicit overrides, conflict/lookup failure and policy change while queued. Disabled blocks customers/resellers; admins still obey server caps.
12. Admin-only update page, no automatic outbound GET requests, valid/invalid CSRF, five-minute cooldown including failures, TLS/timeout/response bound, no-release versus unavailable, prior-result stale warning and native reviewed install/rollback.

## Remaining 1.0 Gates

Implemented in source with fixture tests: native service-plan hook/policy mapping, queue expiry/failure/identity protections and administrator release-discovery UI. Native role/synchronization/network/upgrade validation remains required.

Scheduled stale-cache rotation is now implemented in source and fixture-tested, opt-in and subject to existing caps. The isolated Linux container's SDK readback confirms one registered module rotator with scheduling disabled. This is not working background execution. Native execution, full idempotent upgrade/removal, active-worker uninstall guards and large-fleet inventory-query bounds still need native verification. No unattended installer is included.

Still open: native Windows/Linux panel QA, Windows ACL installer verification, native WHMCS connection/deployment/freshness, independent post-fix security review and final version-matched native tutorials. Pending features must not be advertised as available. See [platform matrix](platform-support.md).
