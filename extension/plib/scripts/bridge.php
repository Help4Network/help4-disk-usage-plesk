<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
try {
    if (!in_array($argv[1] ?? '', ['enable', 'disable', 'status'], true)) {
        throw new RuntimeException('Use bridge.php enable, disable or status');
    }
    $result = $argv[1] === 'status' ? Modules_Help4DiskUsage_Store::read('bridge', ['enabled' => false])
        : Modules_Help4DiskUsage_Remote::configure($argv[1] === 'enable');
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Integration configuration failed; check runtime and private-storage permissions.\n");
    exit(2);
}
