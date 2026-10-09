# Security Policy

Development preview: no shared-production release until docs/validation.md passes. Report suspected vulnerabilities privately through GitHub private reporting if enabled or https://help4network.com. Never attach customer reports to public issues.

## Required Properties

- Current authenticated Plesk identity authorizes every view/refresh/export/file jump. Admin impersonation is not an admin policy bypass. Workers repeat checks before and after traversal.
- Current subscription GUID, owner, name and home bind caches. Transfer/recreation/home changes invalidate old reports.
- Roots come from Plesk, never HTTP path parameters. Customers cannot configure executables. Collector processes use an argument array, not a shell string.
- Linux pins descriptors with O_NOFOLLOW and verifies device/inode. Windows rejects reparse points, pins ancestors without FILE_SHARE_DELETE and refuses UNC/device/ADS roots.
- One active scanner, bounded queue, actor/subscription hourly and interval limits, hard runtime/entry/directory/depth/output limits. Growth requires complete snapshots.
- Escaped HTML, formula-safe CSV, no-store exports, retained-member file jumps. Relative paths and metadata only, never file contents.
- Private storage and runtime/source paths must be administrator-owned. Windows ACLs are an explicit gate; chmod alone is not Windows protection.

Review controllers, privilege/background boundaries, cache/storage, impersonated/sub-user access, native File Manager behavior, update trust and WHMCS current service ownership. Treat file names, browser fields, cache and remote reports as untrusted.

Residual risks: native SDK behavior, Windows ACL inheritance, scheduling and release trust need native tests. Filesystem kernel calls may stall beyond collector checks; the outer worker terminates the child. Network homes are unsupported. This policy is not a security certification or a completed independent audit.
