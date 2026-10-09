# Isolated Test Lab: Measured Status

This records the historical 0.1.1 and 0.2.0 checkpoints and the 2026-10-09 0.3.0 update. This is bounded testing, not certification. Current source/CI must be checked independently of older evidence.

## 0.3.0 Native Linux Package Update: 2026-10-09

The existing isolated Plesk 18.0.81.2 / Ubuntu 24.04 AMD64-emulated container accepted the 0.3.0 extension. The installer still warned that the native task manager was unavailable. No EULA was accepted, licensing bypassed, public listener added, production server changed or routine snapshot created. Reinstallation checks idempotent task registration; it does not establish working background execution.

Final package SHA-256, including the scheduled-refresh checkbox correction and subprocess timeout fix:

```text
c74143cd1d77a672e0e80897f9f00a86f860c0091bd78e91c74f8b239293764c
```

The SDK readback showed extension version 0.3.0, one module-owned rotator, scheduling disabled, zero pending reservations and no active scanner. A disabled native rotation tick returned zero queued/skipped requests. The native `doctor.php` diagnostic initially failed its five-second subprocess deadline; a subsequent direct check completed in approximately one second and native extension-CLI readback succeeded with Python 3.12.3/Linux API availability. The intermittent emulation/load failure is recorded, not concealed by widening the diagnostic budget or counted as full reliability proof. Private-storage and native-panel validation flags remain false.

New adversarial runtime tests on commit `cd335cc` then exposed a separate Windows PHP pipe issue: the 30-second sleeper prevented the nominal five-second deadline from being enforced. The runtime and scanner now use temporary-file capture and monotonic process polling, with stderr discarded and direct-child termination before cleanup. Scanner captures stay in private extension storage; content-free diagnostics alone use system temporary files. The timeout assertion is retained, not relaxed. Native Windows Plesk ACL and task tests still remain gates. PHP documents platform-specific non-blocking stream limitations in its [stream manual](https://www.php.net/manual/en/function.stream-set-blocking.php) and supports real-file descriptors in [proc_open](https://www.php.net/manual/en/function.proc-open.php).

[CI for the 0.3.0 implementation](https://github.com/Help4Network/help4-disk-usage-plesk/actions/runs/37976936552) passed all 18 jobs: actual Windows/Linux Python 3.10/3.13/3.14 collector tests, Windows/Linux PHP 8.3/8.4 fixture tests, and eight AlmaLinux/Ubuntu/Debian runtime jobs. Check the exact final commit's workflow for subsequent tutorial/UI/runtime-fixture changes. Windows runner success is not a Windows Server Plesk install/ACL/GUI result; AlmaLinux success is not licensed CloudLinux/RHEL certification.

Six public screenshots render fresh 0.3.0 templates with synthetic example.test data. Browser QA checked the scheduler checkbox interaction and fixed dimensions, settings inputs, loaded image assets and all six views. Actual 1440px desktop and 390px mobile template viewports had no page overflow or console errors. The native Plesk shell, authenticated roles, real settings submission and File Manager destination were not exercised by this fixture.

The native Linux full-systemd/license/GUI/task gates and separate Windows Server installation/ACL/GUI gates remain open. Independent post-fix security review, native ownership/plan transitions, large-fleet inventory bounds, native lifecycle and WHMCS integration are still required before stable 1.0.0. The public tutorial kit is a preview only.

## 0.2.0 Native Linux Package Update: 2026-10-09

The same isolated container accepted `help4-disk-usage-0.2.0-1.zip` through the native Plesk installer, with the existing task-manager warning still present. The first attempt from `/tmp` was rejected because the copied package was no longer available there; retrying from a private root-owned lab directory succeeded. No new EULA was accepted, licensing bypassed, public listener added or production server changed.

Installed package SHA-256:

```text
7ba97b756c7bc1761929abfe4beff283b102039257e98d76e8552bccce2af844
```

Installed `Store.php`, `Releases.php` and `PlanItems.php` match source SHA-256 values:

```text
8735ddab5961ec5f7153d9aa7054f6f0b97b339249da30746bbca665f1ad1f96  Store.php
8dd28c7d7c5669a5e6d524a6662bd0a7402039e99757ebc94f8fe2151c6d7ca9  Releases.php
3c3d6482ec70f4228690c723a9c7ad148e081f5b0487e16936eee8c4a7c1aa2f  PlanItems.php
```

Policy/queue, release-discovery and controller regression fixtures passed against those installed library/controller files using Plesk's own PHP 8.4.25 CLI. This uses mocked SDK/session/domain data, not authenticated live roles or outbound GitHub connectivity. The installed collector again passed 12 Linux tests; six Windows-only tests were skipped. The source Mac run passed 11 tests with seven platform/filesystem skips. Current CI separately exercises actual Windows/Linux collectors and PHP; read the workflow result for the exact pushed commit.

Six public 0.2.0 screenshots render the shipped templates with synthetic data: reports, entry trees, settings/profiles, partial coverage, failed scan and update status. No native Plesk shell/tenant/ACL success is inferred from them. The 0.1.1 evidence below remains historical and its hashes were not relabeled as 0.2.0 evidence.

## Historical 0.1.1 Checkpoint

Extension code: commit `6722a397cde2deb22d55a4901fa1fc5cfc8f6d01`, preview 0.1.1 release 1.

## What Was Actually Tested

An official `plesk/plesk` Linux container was created locally on an Apple Silicon Mac using AMD64 emulation. Plesk reports **18.0.81.2** on **Ubuntu 24.04**. HTTP/HTTPS panel ports bind only to `127.0.0.1:9880` and `127.0.0.1:9443`. No paid VPS, public listener, production server change or host SYS_ADMIN capability was introduced.

The native Plesk extension installer accepted `help4-disk-usage-0.1.1-1.zip`. SHA-256:

```text
d593ac447fb690194ec3991fd72dd681a29df67f119ccd44ade4eb345e3e74d3
```

The installed Linux collector was hash-matched to source and tested via a symlink into the test harness: 18 tests, 12 passed, six Windows-only tests skipped. The raw-byte filename/PHP JSON regression passed on Linux. Installed collector SHA-256:

```text
c5a7cd820c7cda2b7388dc699b33007f56094c8e114bfd62ce8ebcab0bac7150
```

[CI for this code](https://github.com/Help4Network/help4-disk-usage-plesk/actions/runs/37877428967) passed all five jobs: Ubuntu Python 3.10/3.13, Windows Python 3.10/3.13 and PHP 8.3. Windows jobs execute native handle/junction/race tests; they are not Linux skips. This does not establish Windows Plesk GUI/ACL behavior.

## Blocking Native Panel Tests

The panel requires WebPros EULA acceptance. Its bundled trial key is rejected in this Mac/emulated-container environment. The agreement was not accepted and licensing was not bypassed. A properly licensed, authorized lab is required before creating native test tenants or asserting GUI/role behavior.

The installer also warns that it cannot connect to the Plesk task manager. The official task-manager process logs inability to subscribe to systemd DBus events in this container. A successful extension install does **not** establish that scans can queue/complete through Plesk. We did not invent a fake DBus service, force licensing checks or hide that warning.

The synthetic PHP fixture renders the shipped templates/CSS/JS with dummy data. Desktop, settings, entry-heavy and partial-coverage images are public examples. The browser's responsive capture mechanism changed the requested viewport during capture, so the invalid mobile image was excluded. Actual native desktop/mobile shell behavior remains a release gate.

## Next Native Test Environments

Use a disposable **full-systemd Linux VM** and a separate **supported Windows Server VM**, with a valid free vendor developer/trial license if available and explicitly authorized EULA acceptance. Obtain current supported OS requirements from [Plesk's official documentation](https://docs.plesk.com/en-US/obsidian/).

Keep management access private/loopback or on an authorized restricted network. Do not create paid resources, enroll recurring subscriptions or expose a public administrator endpoint without explicit authorization. Keep credentials and panel screenshots outside this public repository.

Install administrator-owned Python, create two unrelated synthetic customers plus reseller/additional-user fixtures, and execute every item in [the native validation matrix](validation.md). In particular, test negative cross-tenant routes, queue pressure, idle behavior, native File Manager jumps, Windows private-storage ACLs, ownership transfers, upgrades and uninstall cleanup.

## Security Review Status

A Codex Security scan of the preceding `28e1a9d` snapshot found a Windows path-based enumeration race and invalid POSIX filename encoding that could block report serialization. The 0.1.1 code replaces Windows enumeration/child opens with handle-bound native calls and marks unsupported filename encodings as partial omissions. Native OS regression tests passed in CI.

The scan is a **pre-fix report**, not independent verification of the final patch. Its coverage is marked partial; one independent architecture pass did not return, and the parent performed a sequential fallback. Final native role/ACL validation and independent post-fix review remain required. Private scan artifacts are deliberately not included in the tutorial kit.
