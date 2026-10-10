# Plesk WHMCS Adapter Foundation

Reserved addon name: **`help4_disk_usage_plesk`**. Source-only security foundation, not an installable WHMCS addon, remote Plesk API or customer/admin dashboard. No activation entrypoint, database migration, hook, credentials or public endpoint is provided yet. Do not upload this directory into a production WHMCS installation or overwrite the separate cPanel addon.

The namespace `Help4\DiskUsagePlesk` contains:

- `Scope::read`: current selected-client/product-permission, active-service and approved immutable mapping checks before IO, plus fresh actor/entity rechecks before returning the payload. Foreign or missing services never reach the private-read callback. Service/client/server changes, subscription/owner GUID changes, home-identity changes, mapping revision changes and permission/account switches fail with one generic denial.
- `Health::page`: at most 20 Plesk server rows from already-collected observations. Default freshness is 300 seconds; missing, invalid, foreign-bound or expired observations are not healthy zero. Counters remain null on unavailable/stale measurements. Output is extension-only, includes no customer identifiers/paths, and explicitly does not certify native Plesk validation.

Run fixtures from the repository root:

```sh
php tests/whmcs_scope.php
```

These tests run on Windows/Linux PHP CI. They do not establish native WHMCS authorization, database transactions, authenticated Plesk transport or runtime deployment. See the [integration contract](../../docs/whmcs.md) for the remaining acceptance gates.

Built by [Help4 Network](https://help4network.com).
