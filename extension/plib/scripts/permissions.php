<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
try {
    echo json_encode(Modules_Help4DiskUsage_Permissions::check(), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Windows ACL preflight failed or unavailable. Use the administrator runbook; no permissions were changed.\n");
    exit(2);
}
