# Advisory Applicability Review - 2026-10-27

This is a bounded source/advisory applicability review, not native acceptance, an exhaustive vulnerability assessment or a stable release. This extension does not bundle Plesk, PHP, Python, libcurl or the OS. No production package, trial, permission, account or service was changed for this review.

## Operator Patch Requirements

[Plesk's PHP2Shell advisory](https://support.plesk.com/hc/en-us/articles/44397729587351-Vulnerability-CVE-2026-109618-and-CVE-2026-109619-PHP2Shell-Remote-code-execution-in-PHP-shipped-with-Plesk) covers CVE-2026-109618 and CVE-2026-109619 in both hosted PHP and the panel engine. For the listed Plesk branches, panel fixes are 18.0.80 Update 11 and 18.0.81 Update 4. Verify the actual engine build and every enabled PHP handler independently; updating only the extension or panel version string is not sufficient. Use the vendor's currently supported patched branch, with documented backports where applicable.

[PHP's security notices](https://www.php.net/archive/2026.php) additionally distinguish October 26 Windows package rebuilds containing Brotli 1.2.1 for CVE-2026-111497 from the unchanged PHP runtime version. The extension's passive release checker does not enable automatic HTTP response decompression. This narrows that path, not the entire platform's exposure. Do not infer a vendor rebuild from phpversion() alone.

Plesk Backup Manager, DNS/database management and optional REST/Site Import extensions have separate vendor advisories. This plugin does not implement those features or fix their vulnerabilities. Patch the actual installed components under their administrator's change policy; do not substitute this review for the platform's advisory inventory.

## Python Runtime And Packaging

The API compatibility floor remains Python 3.10, but that is not a supported-security baseline. [Python's lifecycle policy](https://devguide.python.org/versions/) now lists 3.10 as end-of-life. New deployments should use an administrator-owned, security-supported Python branch compatible with the target, including current vendor security fixes. A supported vendor backport needs build-specific evidence. Do not replace an OS's system Python to satisfy the extension.

[Python's October security release announcement](https://discuss.python.org/t/python-3-15-2-3-14-10-3-13-18-3-12-16-and-3-11-18-are-out/109678) includes archive/XML/dependency fixes. The collector traverses metadata under no-follow/identity guards; it does not extract tenant tar/ZIP archives or parse tenant XML. Package builders write fixed DEFLATE inventories from trusted, quiescent source. Tests read their own generated ZIPs. These source boundaries do not demonstrate reachable tenant archive exploitation, nor do they eliminate the need to patch the administrator's Python runtime. Doctor checks API availability, not CVE patch status.

## Separate WHMCS Mitigation

The [standalone WHMCS addon](https://github.com/Help4Network/help4-disk-usage-whmcs/blob/main/docs/security-advisories.md) now selects HTTP/1.1 for Plesk XML uploads, avoiding the HTTP/2 path in curl CVE-2026-92392, and rejects failed cURL configuration before sending a request. That runtime belongs only to the WHMCS repository. Native extension ZIPs do not include it. This extension's optional release checker performs a GET, not that XML upload.

## Status

No applicable native-source CVE requiring a code patch was confirmed in this bounded review. Native Linux/Windows ACLs, effective roles, tasks, install/upgrade/uninstall and real WHMCS/MySQL/TLS acceptance remain open. Documentation and host patch guidance do not turn the 0.3.0-2 preview into a certified stable release.

Built by [Help4 Network](https://help4network.com/).
