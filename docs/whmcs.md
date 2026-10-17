# Standalone WHMCS Integration

Canonical WHMCS source, tests, packages and deployment instructions for both cPanel/WHM and Plesk now live in [Help4 Disk Usage for WHMCS](https://github.com/Help4Network/help4-disk-usage-whmcs).

- [WHMCS installation](https://github.com/Help4Network/help4-disk-usage-whmcs/blob/main/docs/install.md)
- [Plesk operations and explicit connection/mapping](https://github.com/Help4Network/help4-disk-usage-whmcs/blob/main/docs/plesk.md)
- [Migration, independent upgrades and removal](https://github.com/Help4Network/help4-disk-usage-whmcs/blob/main/docs/upgrade.md)
- [Compatibility and native validation](https://github.com/Help4Network/help4-disk-usage-whmcs/blob/main/docs/validation.md)

This repository installs only the native Linux/Windows Plesk extension. Its opt-in XML API bridge stays here and is still required by the standalone addon. WHMCS's native Plesk provisioning module does not automatically install/enable this extension or approve subscription mappings. Native extension installation/update remains an administrator operation on each Plesk Linux or Windows server; no cPanel SSH installer is sent to Windows.

Existing WHMCS addon ID help4_disk_usage_plesk, settings and mod_help4_du_plesk_servers/bindings/limits tables remain unchanged. Do not rename, drop or deactivate/reactivate them merely to change source repositories. Do not overwrite the separate cPanel addon help4_disk_usage. Historical release packages are historical, not the new independent distribution format.

Native Plesk versions and updates belong to this repository; WHMCS addon versions/packages belong to the standalone repository. A Git pull is not a deployed upgrade of either component. Native Plesk tutorial kits no longer bundle WHMCS addons. Keep private state, credentials, raw security/native evidence and production screenshots out of public packages. No routine snapshots are created.

Standalone and native Linux/Windows/framework/MySQL/TLS/role acceptance remain separate release gates; extraction and CI are not stable certification. The software remains free without Help4 license keys or fees, with the included notice and small linked byline.

Built by [Help4 Network](https://help4network.com/).
