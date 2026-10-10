# Security Policy

Development preview: no shared-production release until docs/validation.md passes. Report suspected vulnerabilities privately through GitHub private reporting if enabled or https://help4network.com. Never attach customer reports to public issues.

## Required Properties

- Current authenticated Plesk identity authorizes every view/refresh/export/file jump. Admin impersonation is not an admin policy bypass. Workers repeat checks before and after traversal.
- Current subscription GUID, owner, name and home bind caches. Transfer/recreation/home changes invalidate old reports.
- Roots come from Plesk, never HTTP path parameters. Customers cannot configure executables. Collector processes use an argument array, not a shell string.
- Linux pins descriptors with O_NOFOLLOW and verifies device/inode. Windows rejects reparse points, pins ancestors without FILE_SHARE_DELETE and refuses UNC/device/ADS roots.
- One active scanner, bounded queue, actor/subscription hourly and interval limits, hard runtime/entry/directory/depth/output limits. Growth requires complete snapshots.
- Native plan profiles fail closed on lookup/conflict; disabled customer refresh cannot be re-enabled by an override. Reservations bind effective policy, have finite queue-drain leases, and cannot clear another identity's newer token.
- Stable-release discovery is unimpersonated-admin-only, CSRF-protected and rate-limited. Fixed verified HTTPS endpoint, no redirects, bounded response/deadline, strict version metadata and reconstructed repository links. No remote binaries/content are executed; failed checks keep a labeled stale result.
- Escaped HTML, formula-safe CSV, no-store exports, retained-member file jumps. Relative paths and metadata only, never file contents.
- Private storage and runtime/source paths must be administrator-owned. Windows ACLs are an explicit gate; chmod alone is not Windows protection.
- Optional Plesk XML bridge is off by default and requires a current unimpersonated native administrator identity. Fixed operations only, installation pin/nonce/current-identity binding, one call at a time, finite hourly budget, bounded messages; no arbitrary roots, commands, login creation or contents.
- WHMCS requires current User and selected Client Account/product permissions, current active service/enabled Plesk server and explicitly approved immutable subscription/owner/home/install mapping before and after IO. Addon-role admin checks and POST/native CSRF protect mapping/health actions. Mapping revocations serialize with commits under a database lock; native MySQL proof remains a release gate.
- Transport uses current protected core server credentials, verified HTTPS/hostname, no redirects/proxy/insecure fallback, finite connect/deadline/response limits and nonce/pin/freshness checks. Paths/session secrets never become transport endpoints. Unknown health is not healthy-zero. Customer views cannot collect fleet health or run deployment.

Review controllers, privilege/background boundaries, cache/storage, impersonated/sub-user access, native File Manager behavior, update trust and WHMCS current service ownership. Treat file names, browser fields, cache and remote reports as untrusted.

Residual risks: native SDK behavior, Windows ACL inheritance, scheduling and release trust need native tests. Filesystem kernel calls may stall beyond collector checks; the outer worker terminates the child. Network homes are unsupported. This policy is not a security certification or a completed independent audit.
