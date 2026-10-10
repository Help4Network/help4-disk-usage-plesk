<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
try {
    $python = Modules_Help4DiskUsage_Store::policy()['python'];
    if (PHP_OS_FAMILY === 'Windows') { Modules_Help4DiskUsage_Permissions::check($python); }
    echo json_encode(Modules_Help4DiskUsage_Runtime::check($python), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Runtime diagnostic failed. Configure an administrator-owned Python 3.10+ executable with native filesystem APIs.\n");
    exit(2);
}
