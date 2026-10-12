<?php
require __DIR__ . '/../extension/plib/library/Store.php';
class pm_Context {
    public static $directory;
    public static function init($module) {
        if ($module !== 'help4-disk-usage') { throw new RuntimeException('Wrong module'); }
    }
    public static function getVarDir() { return self::$directory; }
}
class Modules_Help4DiskUsage_Scheduler {
    public static $calls = 0;
    public static $fail = false;
    public static function remove() {
        self::$calls++;
        if (self::$fail) { throw new RuntimeException('Synthetic scheduler failure'); }
        $schedule = Modules_Help4DiskUsage_Store::read('fixture_schedule');
        $schedule['tasks'] = array_values(array_diff($schedule['tasks'], ['rotate.php']));
        $schedule['removals']++;
        Modules_Help4DiskUsage_Store::write('fixture_schedule', $schedule);
    }
}
$checks = 0;
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function probe() {
    return Modules_Help4DiskUsage_Store::locked(function () {
        $scanner = Modules_Help4DiskUsage_Store::mutex('scanner.lock');
        if (!$scanner) { throw new RuntimeException('Leaked scanner lock'); }
        $bridge = null;
        try {
            $bridge = Modules_Help4DiskUsage_Store::mutex('bridge.lock');
            if (!$bridge) { throw new RuntimeException('Leaked bridge lock'); }
            return 'acquired';
        } finally {
            if ($bridge) { flock($bridge, LOCK_UN); fclose($bridge); }
            flock($scanner, LOCK_UN); fclose($scanner);
        }
    });
}
if (($argv[1] ?? '') === '--child') {
    umask(0077);
    pm_Context::$directory = $argv[2];
    $operation = $argv[3];
    Modules_Help4DiskUsage_Scheduler::$fail = $operation === 'scheduler-failure';
    $streams = count(get_resources('stream'));
    $start = hrtime(true);
    try {
        if ($operation === 'probe') { $value = probe(); }
        else { require __DIR__ . '/../extension/plib/scripts/pre-uninstall.php'; $value = 'uninstalled'; }
        $error = null;
    } catch (RuntimeException $e) { $value = null; $error = $e->getMessage(); }
    echo json_encode(['value' => $value, 'error' => $error,
        'elapsed' => (hrtime(true) - $start) / 1e9,
        'stream_delta' => count(get_resources('stream')) - $streams,
        'scheduler_calls' => Modules_Help4DiskUsage_Scheduler::$calls], JSON_THROW_ON_ERROR);
    exit;
}
function child($operation = 'uninstall') {
    $dir = pm_Context::$directory;
    $process = null;
    try {
        // Captured files plus a parent watchdog make blocking regressions finite on both OSes.
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $dir, $operation],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/output', 'w'], 2 => ['file', $dir . '/error', 'w']], $pipes);
        check(is_resource($process), 'Lifecycle child unavailable');
        fclose($pipes[0]);
        $deadline = hrtime(true) + 5000000000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (hrtime(true) >= $deadline) { throw new RuntimeException('Lifecycle child exceeded parent watchdog'); }
            usleep(10000);
        } while (true);
        $closed = proc_close($process); $process = null;
        check(($status['exitcode'] >= 0 ? $status['exitcode'] : $closed) === 0, 'Lifecycle child failed');
        check(file_get_contents($dir . '/error') === '', 'Lifecycle child emitted unexpected diagnostics');
        $result = json_decode(file_get_contents($dir . '/output'), true, 8, JSON_THROW_ON_ERROR);
        check($result['stream_delta'] === 0, 'Lifecycle child leaked a stream');
        check($result['elapsed'] < 4.5, 'Lifecycle child exceeded acquisition bound');
        return $result;
    } finally {
        if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        foreach (['output', 'error'] as $name) {
            if (is_file($dir . '/' . $name)) { unlink($dir . '/' . $name); }
        }
    }
}
function seed($pending = []) {
    Modules_Help4DiskUsage_Store::write('bridge', ['enabled' => true, 'installation_id' => str_repeat('a', 64)]);
    Modules_Help4DiskUsage_Store::write('state', ['pending' => $pending, 'attempts' => ['synthetic' => [time()]]]);
    Modules_Help4DiskUsage_Store::write('policy', ['scheduled_enabled' => true, 'hourly' => 3]);
    Modules_Help4DiskUsage_Store::write('rotation', ['attempted_at' => time()]);
    Modules_Help4DiskUsage_Store::write('fixture_schedule', ['tasks' => ['rotate.php', 'unrelated.php'], 'removals' => 0]);
}
function hashes() {
    $hashes = [];
    foreach (['bridge', 'state', 'policy', 'rotation', 'fixture_schedule'] as $name) {
        $hashes[$name] = hash_file('sha256', Modules_Help4DiskUsage_Store::directory() . '/' . $name . '.json');
    }
    return $hashes;
}
function recovered() {
    $before = hashes();
    $result = child('probe');
    check($result['value'] === 'acquired' && $result['error'] === null, 'Lifecycle locks did not recover');
    check($result['scheduler_calls'] === 0 && hashes() === $before, 'Lock probe changed lifecycle state');
}
$mask = umask(0077);
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-lifecycle-process-' . bin2hex(random_bytes(8));
check(mkdir(pm_Context::$directory, 0700), 'Private fixture unavailable');
try {
    foreach (['state.lock' => 'Audit queue busy; retry later',
        'scanner.lock' => 'Wait for the active scanner before uninstall',
        'bridge.lock' => 'Wait for the active integration request before uninstall'] as $name => $error) {
        seed(); $before = hashes();
        $path = Modules_Help4DiskUsage_Store::directory() . '/' . $name;
        $holder = fopen($path, 'c');
        check(is_resource($holder) && flock($holder, LOCK_EX | LOCK_NB), 'Fixture could not hold lifecycle lock');
        try {
            $result = child();
            check($result['value'] === null && $result['error'] === $error, 'Uninstall did not reject held ' . $name);
            check($result['scheduler_calls'] === 0, 'Busy uninstall reached scheduler removal');
            check(hashes() === $before, 'Busy uninstall changed private state');
            if ($name === 'state.lock') { check($result['elapsed'] >= 1.8, 'Queue deadline was not exercised'); }
            $other = fopen($path, 'c');
            check(is_resource($other), 'Holder probe unavailable');
            try { check(!flock($other, LOCK_EX | LOCK_NB), 'Child released another process lock'); }
            finally { fclose($other); }
        } finally { flock($holder, LOCK_UN); fclose($holder); }
        recovered();
    }
    seed([1 => ['expires_at' => time() + 600]]); $before = hashes();
    $result = child();
    check($result['value'] === null && $result['error'] === 'Finish or cancel pending scans before uninstall', 'Live pending scan admitted');
    check($result['scheduler_calls'] === 0 && hashes() === $before, 'Pending refusal changed lifecycle state');
    recovered();
    seed([1 => ['expires_at' => time() - 1]]); $before = hashes();
    $result = child('scheduler-failure');
    check($result['value'] === null && $result['error'] === 'Synthetic scheduler failure', 'Scheduler exception lost');
    check($result['scheduler_calls'] === 1, 'Scheduler failure fixture was not reached');
    $after = hashes();
    check(array_diff_assoc($before, $after) === ['bridge' => $before['bridge']], 'Failed removal changed unrelated state');
    check(Modules_Help4DiskUsage_Store::read('bridge') === ['enabled' => false, 'installation_id' => str_repeat('a', 64)],
        'Failed removal did not leave integration disabled with pin intact');
    recovered();
    $result = child();
    check($result['value'] === 'uninstalled' && $result['error'] === null && $result['scheduler_calls'] === 1,
        'Quiescent uninstall did not recover after scheduler failure');
    check(Modules_Help4DiskUsage_Store::read('fixture_schedule') === ['tasks' => ['unrelated.php'], 'removals' => 1],
        'Uninstall removed unrelated schedule or missed module schedule');
    $final = hashes();
    foreach (['state', 'policy', 'rotation', 'bridge'] as $name) {
        check($final[$name] === $after[$name], 'Quiescent uninstall changed retained ' . $name);
    }
    recovered();
    echo "Lifecycle processes: $checks contention/pending/failure/recovery checks passed (OS locks with scheduler fixture, not native Plesk lifecycle certification)\n";
} finally {
    $audit = pm_Context::$directory . '/audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
    umask($mask);
}
