<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
try {
    echo json_encode(Modules_Help4DiskUsage_Runtime::check(Modules_Help4DiskUsage_Store::policy()['python']), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Runtime diagnostic failed. Configure an administrator-owned Python 3.10+ executable with native filesystem APIs.\n");
    exit(2);
}
