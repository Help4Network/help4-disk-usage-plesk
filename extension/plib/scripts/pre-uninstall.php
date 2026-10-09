<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
Modules_Help4DiskUsage_Store::locked(function () {
    $lock = fopen(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . 'scanner.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) { fclose($lock); }
        throw new RuntimeException('Wait for the active scanner before uninstall');
    }
    try {
    $state = Modules_Help4DiskUsage_Store::read('state');
    foreach ($state['pending'] ?? [] as $pending) {
        if (Modules_Help4DiskUsage_Store::alive($pending)) {
            throw new RuntimeException('Finish or cancel pending scans before uninstall');
        }
    }
    Modules_Help4DiskUsage_Scheduler::remove();
    } finally { flock($lock, LOCK_UN); fclose($lock); }
});
