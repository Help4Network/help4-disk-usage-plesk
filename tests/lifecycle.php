<?php
require __DIR__ . '/../extension/plib/library/Store.php';
class pm_Context {
    public static $directory;
    public static function init($module) { if ($module !== 'help4-disk-usage') { throw new RuntimeException('Wrong module'); } }
    public static function getVarDir() { return self::$directory; }
}
class Modules_Help4DiskUsage_Scheduler {
    public static $removals = 0;
    public static function remove() { self::$removals++; }
}
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function uninstall() { require __DIR__ . '/../extension/plib/scripts/pre-uninstall.php'; }
function denied($callback) {
    try { $callback(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Lifecycle guard failed');
}
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-lifecycle-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    Modules_Help4DiskUsage_Store::write('bridge', ['enabled' => true, 'installation_id' => str_repeat('a', 64)]);
    foreach (['scanner.lock', 'bridge.lock'] as $name) {
        $lock = Modules_Help4DiskUsage_Store::mutex($name);
        check(is_resource($lock), 'Mutex unavailable');
        denied('uninstall');
        check(Modules_Help4DiskUsage_Scheduler::$removals === 0, 'Busy uninstall removed schedule');
        check(Modules_Help4DiskUsage_Store::read('bridge')['enabled'], 'Busy uninstall disabled bridge');
        flock($lock, LOCK_UN); fclose($lock);
    }
    Modules_Help4DiskUsage_Store::write('state', ['pending' => [1 => ['expires_at' => time() + 600]]]);
    denied('uninstall');
    check(Modules_Help4DiskUsage_Scheduler::$removals === 0, 'Pending uninstall removed schedule');
    Modules_Help4DiskUsage_Store::write('state', ['pending' => [1 => ['expires_at' => time() - 1]]]);
    uninstall();
    check(Modules_Help4DiskUsage_Scheduler::$removals === 1, 'Clear uninstall missed scheduler');
    check(Modules_Help4DiskUsage_Store::read('bridge') === ['enabled' => false, 'installation_id' => str_repeat('a', 64)], 'Uninstall lost or re-enabled pin');
    denied(function () { Modules_Help4DiskUsage_Store::mutex('../arbitrary'); });
    if (PHP_OS_FAMILY !== 'Windows') {
        foreach (['state.lock', 'scanner.lock', 'bridge.lock'] as $name) {
            clearstatcache();
            check((fileperms(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . $name) & 0777) === 0600, 'Exposed lock mode');
        }
    }
    $worker = file_get_contents(__DIR__ . '/../extension/plib/scripts/worker.php');
    check(strpos($worker, 'Permissions::check($python)') < strpos($worker, 'Runtime::check($python)'), 'Worker executes candidate before bound Windows preflight');
    echo "Lifecycle scanner/RPC/pending guards, private locks and preflight ordering passed (SDK fixtures)\n";
} finally {
    $dir = Modules_Help4DiskUsage_Store::directory();
    foreach (new DirectoryIterator($dir) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
    rmdir($dir); rmdir(pm_Context::$directory);
}
