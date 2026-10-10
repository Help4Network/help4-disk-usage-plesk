# WHMCS Integration

The planned Plesk adapter name is `help4_disk_usage_plesk`, separate from the cPanel module. Source-only entitlement and health primitives now live in `integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib`. **There is still no installable addon, customer/admin dashboard, activation migration, hook or authenticated remote transport in this preview.** Do not upload the foundation into production, overwrite the cPanel addon or reuse the WHM API protocol for Plesk.

Pre-1.0 acceptance: authenticated server-scoped ingestion/deployment; immutable Plesk subscription GUID + WHMCS server ID mapping; current active service/client ownership on every customer view; no stale healthy-zero status; no customer-supplied roots or cached-owner entitlement.

0.3.0 does not expose a production remote WHMCS ingestion/deployment API. Native Plesk isolation gates must pass first. The separate cPanel repository's WHMCS module does not establish Plesk compatibility. No billing/chargeback/ticket changes belong to this extension.

## Implemented Foundation Contract

`Help4\DiskUsagePlesk\Scope::read($serviceId, $actorReader, $entityReader, $read)` is a backend library, not an HTTP handler or authentication system. All callbacks must be trusted adapter code; none may be supplied by a browser or deserialize executable content.

The actor reader must resolve a currently authenticated WHMCS User **and currently selected Client Account**, not assume a User ID equals a Client ID or use a cached scan's owner. It returns `authenticated`, `user_id`, `client_id`, `products_allowed`, `manage_products_allowed` and `masquerading`. Both product permissions are required; missing/false permissions fail. Masquerading is denied in this foundation until a separately verified native support workflow exists. Do not bypass this with an admin session flag. Use native WHMCS CurrentUser and current account association/permissions; there is no implemented native actor adapter yet.

The entity reader must freshly load a single service, its current enabled Plesk server and an administrator-approved binding. It returns:

| Object | Required fields |
| --- | --- |
| `service` | `id`, `client_id`, `server_id`, exact `Active` status, `plesk` module, boolean `server_enabled`, `username`, `domain`, `server_binding` |
| `binding` | `service_id`, `client_id`, `server_id`, `username`, `domain`, `server_binding`, `subscription_guid`, `owner_guid`, `identity_binding`, `revision` |

The IDs are positive 32-bit integers or canonical decimal strings. GUIDs must be canonical lowercase non-nil UUID strings. Digests/revisions must be 64 lowercase hex characters. Native database/transport adapters must normalize verified native data to this contract, not coerce missing/invalid data into success. Use a transaction-consistent, bounded lookup; duplicate/ambiguous service or subscription matches must fail rather than select the first row.

`server_binding` must come from a reviewed current server endpoint/install identity, including verified TLS/server identity, not just a hostname or an untrusted response string. `identity_binding` represents the native Plesk subscription's current GUID/owner/name/home binding. `revision` is an administrator-controlled random mapping revision; replace it on remapping and invalidate older cache rows. Never automatically rebind by a matching username/domain or copy a cached client owner after transfer. Credentials stay in WHMCS's protected configuration and are not digest inputs exposed to clients.

Only an authorized initial scope reaches the private-read callback. Its result must contain matching `server_id`, `server_binding`, `subscription_guid`, `owner_guid`, `identity_binding`, `revision`, a fresh integer `identity_checked_at`, and array `payload`. Identity evidence older than 30 seconds or more than five seconds ahead is rejected. Report scan age is separate and must remain visible in the future UI. The payload is limited to 256 KiB encoded JSON and depth 16. Extra envelope fields are not returned.

The read callback must use authenticated current native identity evidence, not relabel a cached identity with the current time. Timestamps, fingerprints and matching strings are **not authentication, signatures or replay protection**. The future transport must bind the response to the exact request/server, verify TLS without redirects or insecure fallback, apply finite connection/response/deadline caps and recheck Plesk identity/authorization around report retrieval. No network transport is implemented by these libraries and they cannot interrupt a blocking callback. Future report parsing must allowlist metadata, escape HTML, neutralize CSV formulas and preserve native authorization on File Manager links; this generic scope guard is not a report parser or file-content API.

After the callback, the guard rereads the actor, service, server and binding and compares the complete scope fingerprint. Transfer, suspension, reassignment, endpoint/mapping changes and permission/account switches abort without returning the payload. This is a before/after check, not a distributed lock against changes after the result returns; the eventual route must render only in this authorized context and reauthorize every export/jump/refresh.

## Bounded Health Contract

`Health::page` consumes a batch of at most 20 unique enabled/disabled Plesk server records and their observations. It does not make API calls. Each current server record has `id`, `module`, boolean `enabled` and current `server_binding`. Each observation binds schema/server identity and measurement time; successful observations contain extension version, runtime/storage checks, pending count (0-32), active scanners (0-1), failed-scan count (0-10,000) and nullable last-success timestamp.

States distinguish `disabled`, `unmeasured`, `stale`, `degraded`, `no_report`, `stale_report` and `observed`. A fresh successful observation is **observed**, not a claim that the entire server or every tenant is healthy. There is no CPU/disk/service-manager monitoring, fleet certification or stable-release approval implied. Raw errors, credentials, account identifiers and paths are not copied into the result. Native validation is always false in this preview foundation.

The eventual administrator route must enforce native addon-role permissions, CSRF/POST for collection/deployment, an editable bounded batch, one admitted action per server, finite server/global hourly caps and a cooldown including failed attempts. Customer views must never trigger a fleet collector or expose server-wide health. Transport failure, missing ACL/runtime evidence or unavailable task state must remain unknown/degraded, not fabricated successful zero. Those admission, UI and transport layers are not implemented yet.

## Deployment, Updates And Removal

There is currently **nothing to activate or deploy for WHMCS**. The native Plesk extension still uses its separate reviewed ZIP and the Linux/Windows lifecycle instructions in the [operator tutorial](tutorial.md). The source-only WHMCS foundation is excluded from that ZIP and from installable tutorial artifacts. Pulling source is not a runtime installation or a stable-channel promotion.

Before an installable adapter: add native WHMCS activation/upgrade/uninstall migrations with distinct table names, current actor/role adapters, a bounded authenticated Plesk bridge, root/admin-only deployment with reviewed checksum/version gating and Linux/Windows lifecycle proof, cache revision invalidation, native client/admin pages and safe export/navigation. Preserve configuration/current data on upgrades; disable actions before removal and explicitly document any retained private data. No automatic remote installer or production deployment is authorized by these foundations.

References: [WHMCS CurrentUser authentication](https://developers.whmcs.com/advanced/authentication), [WHMCS user permissions](https://developers.whmcs.com/api-reference/getpermissionslist), [Plesk extension API operator](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-plesk-extensions.76730/).

Built by [Help4 Network](https://help4network.com).
