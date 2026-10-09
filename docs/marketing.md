# Marketing And Tutorial Rules

Help4 Disk Usage for Plesk, MIT licensed, built by https://help4network.com. Repository: https://github.com/Help4Network/help4-disk-usage-plesk. Reuse the official H4 mark, not a metrics/chart icon.

Announce a **development preview for testing/review** until BOTH native Windows and Linux gates pass. Designed for Linux and Windows is not production certification. Do not claim best-on-market, quota parity, native Windows inode quotas, implemented cleanup, automatic updates or WHMCS availability until verified.

Public images use actual extension rendering with synthetic example.test data or clearly labeled illustrative mockups. No cPanel captures masquerading as Plesk, no production/customer/IP/session paths, and no reliance on blur. Tag Plesk after verifying the actual account; never imply endorsement. Record final publication URLs and screenshot evidence privately.

Tutorial topics: lab installation, administrator Python path, bounded refresh, complete/partial/stale status, offender rankings, native File Manager, relative path copy and safe export. Explain logical bytes versus quota and no-follow limitations.

## Publishable Kit

Run `python3 scripts/tutorial_bundle.py` or `py -3 scripts/tutorial_bundle.py` to create `dist/help4-disk-usage-plesk-0.2.0-tutorial-kit.zip`. The allowlisted kit contains the [operator tutorial](tutorial.md), README, license/security/validation/WHMCS-status notes, six synthetic screenshots, the installable preview ZIP and an internal SHA-256 manifest. It does not include production screenshots, private lab evidence, credentials or the private security report.

Use these captions and preserve the warning banner:

| Image | Caption |
| --- | --- |
| `screenshots/synthetic-desktop-0.2.0.jpg` | Largest-file report rendered from the 0.2.0 extension template with dummy data. |
| `screenshots/synthetic-entry-trees-0.2.0.jpg` | Entry-heavy trees ranked by filesystem-entry count; synthetic data. |
| `screenshots/synthetic-settings-0.2.0.jpg` | Administrator controls for scan limits, native plan profiles and subscription overrides; synthetic template. |
| `screenshots/synthetic-partial-0.2.0.jpg` | Partial scan example: lower-bound totals, errors/omissions and no comparable growth; synthetic data. |
| `screenshots/synthetic-failure-0.2.0.jpg` | Failed scan notice preserves the previous report; synthetic template. |
| `screenshots/synthetic-updates-0.2.0.jpg` | Administrator stable-release check page with no published stable release; synthetic template, not live GitHub evidence. |

These are template screenshots, **not native Plesk panel captures**. No unverified mobile screenshot is included. Do not crop away development-preview/synthetic labels or imply vendor certification.

## Product Facts For Copy

Suggested short description: "An MIT-licensed development preview for subscription-scoped disk and filesystem-entry audits in Plesk, designed for Linux and Windows. It highlights large files, entry-heavy directories and stale archives, with bounded scan policies, exports and File Manager links."

Available in source: four offender views, relative paths, last-scan/coverage/failure status, search/sort/paging, CSV/JSON, one-worker lock, host-configurable refresh/runtime/queue limits, native service-plan profiles, explicit subscription overrides, bound finite queue leases, admin-only stable-release discovery, current authorization checks and a small Help4 Network footer. The earlier 0.1.1 native Linux package installation and native Windows/Linux collector CI passed. See [lab limits](testing-lab.md) and current CI before describing end-to-end panel operation; source features are not native production certification.

Not released: WHMCS Plesk adapter, scheduler, unattended updater, file deletion/editing, quota parity, production certification or measured performance superiority. Native service-plan synchronization, release-page network/native upgrade behavior and all role/GUI/background-task gates need validation. A Plesk trial/license/EULA and container task-manager gate still block native role/GUI/background-scan validation. Windows Plesk ACL testing is also pending.

## Initial Social Publication

- [Phillip Ley LinkedIn announcement](https://www.linkedin.com/feed/update/urn:li:activity:7514159106316984320/): original image-bearing development-preview post with a Plesk company mention.
- [Help4 Network LinkedIn](https://www.linkedin.com/feed/update/urn:li:activity:7514160114711441408/): repost with its own preview-status caption and Plesk mention.
- [Fix I.T. Phill LinkedIn](https://www.linkedin.com/feed/update/urn:li:activity:7514161121939955712/): repost with its own preview-status caption and Plesk mention.
- [Fix IT Phill on X](https://x.com/Fix_IT_Phill/status/2108399617272615008): original 0.1.1 dummy-data image, accessible alt text and verified `@Plesk` mention; native Plesk/ACL QA explicitly pending.

Brand reposts inherit the original dummy-data image. Posting establishes publication, not delivery, endorsement or engagement. Preserve that distinction in campaign reporting.
