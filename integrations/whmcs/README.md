# Plesk WHMCS Addon

Installable candidate source: **help4_disk_usage_plesk**, separately packaged and never interchangeable with the cPanel addon. Version 0.3.0-2 is a development preview, not licensed/native production certification.

Implemented: native addon activation/upgrade/deactivation, current User/Client Account/product permission and administrator role checks, exact service/subscription/owner/installation mappings, bounded verified-TLS Plesk XML transport, customer reports/exports and native File Manager jump, service-sidebar link and manual paginated administrator extension-health dashboard.

Build from repository root with `python3 scripts/package_whmcs.py` (Windows: `py -3`). Verify the archive SHA-256 and internal manifest. Extract outside the web root; upload only `modules/addons/help4_disk_usage_plesk`. Activate and explicitly select administrator roles. Customer Reports defaults off. Deploy the native Plesk ZIP separately, enable its bridge from the authorized terminal, Connect the exact existing server and Approve the intended active service. Valid trusted HTTPS is mandatory; never disable certificate checks.

Full [deployment/configuration/lifecycle and acceptance instructions](../../docs/whmcs.md) are also included as DEPLOYMENT.md in the addon ZIP. Native Plesk Linux/Windows and licensed WHMCS/MySQL acceptance remain open. The current emulated Linux container does not prove working background tasks; fixture success is not native permission proof.

Test from repository root:
```sh
php tests/whmcs_scope.php
php tests/whmcs_report.php
php tests/whmcs_transport.php
php tests/whmcs_native.php
php tests/lifecycle.php
python3 -m unittest discover -s tests -v
```

Default WHMCS read cap: 30/User/hour (editable up to 120); requests 120/server/hour (up to 240), one in flight; native bridge 600/hour, one in flight. Remote refresh also applies native subscription/plan/queue/runtime caps. Failures consume admission budgets. Admin GET is passive, health is extension-only, and unknown/stale is not healthy-zero.

No unchecked remote installer, SSO minting, arbitrary roots, file contents/deletion, billing mutations or preview social launch is included. Deactivation retains only this addon's private mapping/observation/limit tables; see the runbook for explicit reviewed removal.

Built by [Help4 Network](https://help4network.com).
