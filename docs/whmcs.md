# WHMCS Integration

The planned Plesk adapter name is `help4_disk_usage_plesk`, separate from the cPanel module. It is not an installable adapter in this preview. Do not overwrite the cPanel addon or reuse the WHM API protocol for Plesk.

Pre-1.0 acceptance: authenticated server-scoped ingestion/deployment; immutable Plesk subscription GUID + WHMCS server ID mapping; current active service/client ownership on every customer view; no stale healthy-zero status; no customer-supplied roots or cached-owner entitlement.

0.3.0 does not expose a production remote WHMCS ingestion/deployment API. Native Plesk isolation gates must pass first. The separate cPanel repository's WHMCS module does not establish Plesk compatibility. No billing/chargeback/ticket changes belong to this extension.
