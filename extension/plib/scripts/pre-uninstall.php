<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
Modules_Help4DiskUsage_Store::locked(function () {
    $lock = Modules_Help4DiskUsage_Store::mutex('scanner.lock');
    if (!$lock) {
        throw new RuntimeException('Wait for the active scanner before uninstall');
    }
    $bridge = null;
    try {
        $bridge = Modules_Help4DiskUsage_Store::mutex('bridge.lock');
        if (!$bridge) { throw new RuntimeException('Wait for the active integration request before uninstall'); }
        $state = Modules_Help4DiskUsage_Store::read('state');
        foreach ($state['pending'] ?? [] as $pending) {
            if (Modules_Help4DiskUsage_Store::alive($pending)) {
                throw new RuntimeException('Finish or cancel pending scans before uninstall');
            }
        }
        $config = Modules_Help4DiskUsage_Store::read('bridge');
        $config['enabled'] = false;
        Modules_Help4DiskUsage_Store::write('bridge', $config);
        Modules_Help4DiskUsage_Scheduler::remove();
    } finally {
        if ($bridge) { flock($bridge, LOCK_UN); fclose($bridge); }
        flock($lock, LOCK_UN); fclose($lock);
    }
});
