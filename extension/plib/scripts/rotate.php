<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
try {
    echo json_encode(Modules_Help4DiskUsage_Scheduler::run(), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Scheduled disk audit unavailable; check native scheduler and private storage.\n");
    exit(1);
}
