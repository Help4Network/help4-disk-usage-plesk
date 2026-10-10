# WHMCS Plesk Addon: Installation And Operation

## Status And Scope

The separately named `help4_disk_usage_plesk` addon is now installable candidate source in the 0.3.0-2 preview. It includes activation/upgrade migrations, current WHMCS actor/role adapters, customer reports/exports, a Service Details sidebar link, retained-path native File Manager navigation and a paginated administrator extension-health dashboard. The opt-in Plesk XML API bridge and verified-TLS transport are implemented and fixture-tested. **Licensed native WHMCS/Plesk Linux and Windows validation remains open; this is not a stable production release.**

Do not overwrite `help4_disk_usage` (the cPanel addon) or reuse WHM APIs for Plesk. No billing, ticket, chargeback, file-deletion or file-content API is included. The dashboard measures this extension, not all server services, hardware or overall server health.

## Requirements And Build

Use a supported WHMCS installation with its native Plesk server module, PHP 8.3/8.4, cURL, DOM/libxml and the native Capsule database. These are candidate test runtimes, not a claim that all WHMCS versions support them. Follow the exact installed WHMCS version's PHP/system requirements. Native MySQL transaction/row-lock behavior is an acceptance gate; SQLite fixtures are not that proof.

The Plesk extension is a separate deployment on Linux or Windows Server. Configure its administrator-owned Python executable and verify native private storage, role isolation, task dispatch and lifecycle first. Keep customer reports and scheduled rotation disabled until licensed lab acceptance. Bridge authentication requires a current native Plesk administrator identity; if API-RPC does not establish it, the bridge deliberately denies the call. Never replace that check with an assumed administrator.

```sh
python3 scripts/package.py
python3 scripts/package_whmcs.py
php tests/whmcs_scope.php
php tests/whmcs_report.php
php tests/whmcs_transport.php
php tests/whmcs_native.php
php tests/lifecycle.php
```

Windows builds use `py -3`. The addon ZIP is `dist/help4-disk-usage-plesk-whmcs-0.3.0-2.zip`, with an adjacent SHA-256 file and an internal per-file manifest. It contains `modules/addons/help4_disk_usage_plesk` plus documentation/license; it is not a native Plesk upload ZIP.

## Install And Connect In An Authorized Lab

1. Verify the addon ZIP SHA-256 against the reviewed artifact. Extract it outside the web root. Verify its internal `SHA256SUMS`; upload only `modules/addons/help4_disk_usage_plesk` into the WHMCS root's matching directory. Do not serve deployment documents or private evidence from that root.
2. In native WHMCS Addon Modules, activate **Plesk Disk Usage Audit** and explicitly select authorized administrator roles in Access Control. **Customer Reports** defaults off. Activation creates only the three separately named private addon tables; it never modifies core services or provisions servers.
3. Use an existing enabled native Plesk server record. Its hostname/IP and port are administrator-controlled; choose a hostname matching a valid trusted HTTPS certificate. Credentials come only from WHMCS's protected core server record: Plesk API key in the native access-hash field, or native admin username/decrypted core password. No new secret field is exposed. Do not print credentials or place them in URLs, Git or screenshots.
4. Install the reviewed native extension separately using the Linux/Windows commands in the operator tutorial. Configure Python, check runtime/storage, then enable the opt-in bridge from the authorized server terminal:

```sh
# Linux
plesk bin extension --exec help4-disk-usage bridge.php enable
plesk bin extension --exec help4-disk-usage bridge.php status
```

```powershell
# Windows Server
plesk bin extension.exe --exec help4-disk-usage bridge.php enable
plesk bin extension.exe --exec help4-disk-usage bridge.php status
```

5. Open the addon in the WHMCS admin area. **Connect** the exact server record. This pins a random non-secret installation identity returned over authenticated verified TLS. A changed endpoint or installation pin fails closed; investigate instead of silently rebinding.
6. **Approve service** using its existing positive service ID. The active native Plesk product/server, exact domain and system username must match current native subscription and owner GUIDs. A subscription home/name/owner binding and random mapping revision are stored. There is no automatic matching by username, cached client owner or customer-supplied GUID.
7. Perform the negative/native matrix below. Only then enable **Customer Reports** for the candidate lab. Customers enter from Service Details > Disk Usage Audit or `index.php?m=help4_disk_usage_plesk`. Both current Products and Manage Products permissions are required. A WHMCS User ID is never treated as a Client Account ID.

A fresh connection with no scan history is unknown/degraded, not a successful zero. Run the native subscription scan and confirm completion before expecting report/health evidence. A queued API response means admission, not completion.

## Reports And File Manager

Views and exports reread the authenticated User, selected Client Account, current permissions, active service/product, enabled server and approved mapping before and after remote IO. Masquerading sessions are denied. Current native subscription identity is checked around report retrieval; cached ownership is not entitlement.

Reports show logical bytes, filesystem entries, last scan/age, partial or complete coverage, ranked offenders and fixed remediation hints. Search/sort/paging operate on bounded retained rows, not every filesystem entry. CSV labels path cells and neutralizes spreadsheet formula prefixes; JSON/CSV retain a small Help4 Network credit. Raw transport fields, absolute roots and private bindings are excluded.

**File Manager** first retrieves a fresh scoped report and requires exact retained path/kind membership. The destination uses only the current configured server endpoint and verified native domain ID. Plesk repeats native authorization and retained-path checks before opening its own File Manager. No SSO token, login account or session URL is minted; the user's existing Plesk login is required. Native File Manager remains responsible for current path access and junction policy.

Refresh is a CSRF-protected POST followed by 303 GET. The native bridge reserves as the subscription owner, not as an unlimited administrator, so service-plan/override/disabled-plan and queue/runtime caps still apply. No automatic page refresh or fleet collection runs during an idle customer session.

## Limits And Health

- WHMCS reads: default 30 per User/hour, administrator-editable 1-120. Includes fresh report, export, jump and refresh reads.
- WHMCS remote requests: default 120 per server/hour, editable 1-240. One leased request per server, including failures. Administrative actions also have 30 attempts per administrator/hour and a five-second interval.
- Native bridge: one request at a time, hard 600 admitted calls/hour; failed admitted calls count. Native scanner limits remain separate and stricter where configured.
- Transport: HTTPS only, verified peer/hostname, no redirects/proxy fallback, three-second connect and 20-second total deadline, 512 KiB XML cap. Request JSON is 8 KiB; native JSON response is 350,000 bytes. All JSON/XML/schema/nonce/pin/current-identity checks fail closed.
- Native Windows ACL helper: read-only snapshot before selected runtime saves, worker execution and bridge enable; 12-second subprocess/16 KiB output limits. It does not prove dependency trust or continuous enforcement.
- Admin health: 20 Plesk records per page. GET performs no outbound collection; **Check** is per-server POST. Observations expire after five minutes. Missing, invalid, changed-pin, disabled and failed observations remain unknown/stale/degraded with unavailable counters as null.

Health reports extension version, runtime/storage snapshot, pending/active scans, bounded cumulative failed scans and last successful scan. A historical failure is not silently reset into healthy status. Linux storage checks cover bounded flat private metadata; Windows checks inspect bounded selected ACL trees. Neither snapshot certifies native installation or continuous safety.

## Upgrade, Rollback, Disconnect And Uninstall

A Git pull changes source only. Review the identified commit/release, run tests, build both ZIPs, verify manifests and install the native Plesk ZIP plus the separately named WHMCS addon. Quiesce admitted work before replacement. Keep private configuration/data outside source; do not create routine backup copies of the repository.

The native extension's **Software updates** checks the fixed public repository's stable GitHub release; preview commits are not stable updater promotions. WHMCS addon upgrades run idempotent distinct-table migrations. Do not rely on a native version bump to deploy addon files. Rebuilding/reinstalling an identified previous commit is source rollback, not proof of compatible private data; review schemas and invalidate incompatible/foreign mappings before re-enabling.

**Disconnect** is administrator-only, confirmed POST. It removes that server's approved service mappings and records a revocation tombstone under a database row lock. In-flight connections/approvals must not overwrite it. Review the server/installation change, reconnect and explicitly approve intended services again.

Disable the native bridge before removal:

```sh
plesk bin extension --exec help4-disk-usage bridge.php disable
```

```powershell
plesk bin extension.exe --exec help4-disk-usage bridge.php disable
```

Disable Customer Reports, disconnect intended servers and deactivate the addon before removing its files. Deactivation retains private `mod_help4_du_plesk_servers`, `mod_help4_du_plesk_bindings` and `mod_help4_du_plesk_limits` tables for reviewed reinstall/data retention. A database administrator may explicitly drop **only those three tables** after confirming the addon is deactivated, requests are quiesced and no retention is needed. No automatic purge is provided. Native uninstall refuses active scanners, live reservations and in-flight bridge calls, disables the bridge and removes only its owned scheduler. Verify native private-data removal on both OSes; do not assume the installer removed every retained file.

## Acceptance Before Stable 1.0

Use licensed native Linux and supported Windows Server Plesk plus the intended WHMCS version. Prove API-RPC administrator identity, API-key/password TLS authentication and true private storage; native task completion, concurrency/caps and upgrade/uninstall; unrelated clients/users/resellers/limited users, suspended/transferred/recreated subscriptions, changed home/owner/server/pin and role revocation during IO. Prove native MySQL serialization of Connect/Approve/Disconnect, CSRF errors, exports, authenticated File Manager destinations, native page layout, desktop/mobile and five-minute idle behavior. Never expose live PII in tutorials.

References: [WHMCS CurrentUser](https://developers.whmcs.com/advanced/authentication), [WHMCS addon admin output](https://developers.whmcs.com/addon-modules/admin-area-output), [Plesk extension API calls](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-plesk-extensions/calling-extensions-operations.76740/).

Built by [Help4 Network](https://help4network.com).
