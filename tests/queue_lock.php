<?php
namespace QueueClockFixture {
    class pm_Context {
        public static $directory;
        public static function getVarDir() { return self::$directory; }
    }
    class Clock {
        public static $time = 0;
        public static $mode = '';
        public static $attempts = 0;
        public static $sleeps = 0;
    }
    function hrtime($asNumber = false) { return Clock::$time; }
    function usleep($microseconds) { Clock::$sleeps++; Clock::$time += $microseconds * 1000; }
    function flock($handle, $operation, &$wouldBlock = null) {
        if ($operation === LOCK_UN) { return \flock($handle, $operation); }
        Clock::$attempts++;
        $wouldBlock = 0;
        if (Clock::$mode === 'overslept' && Clock::$attempts === 1) {
            Clock::$time = 1990000000; $wouldBlock = 1; return false;
        }
        if (Clock::$mode === 'error') { return false; }
        $result = \flock($handle, $operation, $wouldBlock);
        if (Clock::$mode === 'late-acquisition') { Clock::$time = 2100000000; }
        return $result;
    }
}
namespace {
require __DIR__ . '/../extension/plib/library/Store.php';
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
}
$checks = 0;
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
if (($argv[1] ?? '') === '--child') {
    pm_Context::$directory = $argv[2];
    $streams = count(get_resources('stream'));
    $called = 0;
    $start = hrtime(true);
    file_put_contents(pm_Context::$directory . '/ready', 'ready');
    try {
        $value = Modules_Help4DiskUsage_Store::locked(function () use (&$called) {
            $called++;
            Modules_Help4DiskUsage_Store::write('sentinel', ['changed' => true]);
            return 'acquired';
        });
        $error = null;
    } catch (RuntimeException $e) { $value = null; $error = $e->getMessage(); }
    echo json_encode(['value' => $value, 'error' => $error, 'called' => $called,
        'elapsed' => (hrtime(true) - $start) / 1e9,
        'stream_delta' => count(get_resources('stream')) - $streams], JSON_THROW_ON_ERROR);
    exit;
}
function contender($releaseEarly) {
    $dir = pm_Context::$directory;
    Modules_Help4DiskUsage_Store::write('sentinel', ['changed' => false]);
    $lock = fopen(Modules_Help4DiskUsage_Store::directory() . '/state.lock', 'c');
    check($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Fixture could not hold queue lock');
    $process = null;
    try {
        // File capture and a parent watchdog keep the old blocking implementation from hanging CI.
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $dir],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/output', 'w'], 2 => ['file', $dir . '/error', 'w']], $pipes);
        check(is_resource($process), 'Queue contender unavailable');
        fclose($pipes[0]);
        $deadline = hrtime(true) + 5000000000;
        $ready = null;
        do {
            clearstatcache();
            if ($ready === null && is_file($dir . '/ready')) { $ready = hrtime(true); }
            if ($releaseEarly && is_resource($lock) && $ready !== null && hrtime(true) - $ready >= 200000000) {
                flock($lock, LOCK_UN); fclose($lock); $lock = null;
            }
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (hrtime(true) >= $deadline) { throw new RuntimeException('Queue wait exceeded parent watchdog'); }
            usleep(10000);
        } while (true);
        $closed = proc_close($process); $process = null;
        check(($status['exitcode'] >= 0 ? $status['exitcode'] : $closed) === 0, 'Queue contender exit failed');
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); $lock = null; }
        check(file_get_contents($dir . '/error') === '', 'Queue contender emitted diagnostic details');
        $result = json_decode(file_get_contents($dir . '/output'), true, 8, JSON_THROW_ON_ERROR);
        check($result['stream_delta'] === 0, 'Queue contender leaked a stream');
        check($result['called'] === ($releaseEarly ? 1 : 0), 'Contended queue entered callback');
        check($result['value'] === ($releaseEarly ? 'acquired' : null), 'Queue callback return changed');
        check($result['error'] === ($releaseEarly ? null : 'Audit queue busy; retry later'), 'Queue failure contract changed');
        check($result['elapsed'] >= ($releaseEarly ? 0.15 : 1.8) && $result['elapsed'] < 4.5,
            'Queue wait did not respect deadline or release');
        check(Modules_Help4DiskUsage_Store::read('sentinel') === ['changed' => $releaseEarly], 'Rejected queue request changed state');
    } finally {
        if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        foreach (['ready', 'output', 'error'] as $name) {
            if (is_file($dir . '/' . $name)) { unlink($dir . '/' . $name); }
        }
    }
}
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-queue-lock-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    contender(false);
    contender(true);
    // Clock-backed unchanged source controls cover expiry without a flaky near-deadline OS sleep.
    eval('namespace QueueClockFixture; use \\RuntimeException; use \\Throwable;' .
        substr(file_get_contents(__DIR__ . '/../extension/plib/library/Store.php'), 5));
    \QueueClockFixture\pm_Context::$directory = pm_Context::$directory;
    foreach (['overslept', 'late-acquisition', 'error', 'allowed'] as $mode) {
        \QueueClockFixture\Clock::$mode = $mode;
        \QueueClockFixture\Clock::$time = 0;
        \QueueClockFixture\Clock::$attempts = 0;
        \QueueClockFixture\Clock::$sleeps = 0;
        $streams = count(get_resources('stream')); $called = false; $value = null; $error = null;
        try { $value = \QueueClockFixture\Modules_Help4DiskUsage_Store::locked(function () use (&$called) {
            $called = true; return 'unchanged';
        }); } catch (RuntimeException $e) { $error = $e->getMessage(); }
        check($called === ($mode === 'allowed'), 'Expired/error queue callback admitted: ' . $mode);
        check($value === ($mode === 'allowed' ? 'unchanged' : null), 'Clock queue return mismatch');
        check($error === ($mode === 'allowed' ? null : ($mode === 'error'
            ? 'Audit queue unavailable' : 'Audit queue busy; retry later')), 'Clock queue error mismatch');
        check(\QueueClockFixture\Clock::$attempts === 1, 'Queue retried after expiry or noncontention failure');
        check(\QueueClockFixture\Clock::$sleeps === ($mode === 'overslept' ? 1 : 0), 'Queue slept after error or acquisition');
        check(count(get_resources('stream')) === $streams, 'Clock queue failure leaked handle');
    }
    $streams = count(get_resources('stream'));
    $failed = false;
    try { Modules_Help4DiskUsage_Store::locked(function () { throw new RuntimeException('Synthetic callback failure'); }); }
    catch (RuntimeException $e) { $failed = $e->getMessage() === 'Synthetic callback failure'; }
    check($failed, 'Queue callback exception lost');
    check(count(get_resources('stream')) === $streams, 'Callback exception leaked queue handle');
    check(Modules_Help4DiskUsage_Store::locked(function () { return ['unchanged' => true]; }) === ['unchanged' => true],
        'Queue did not recover after callback failure');
    if (PHP_OS_FAMILY !== 'Windows') {
        $lockPath = Modules_Help4DiskUsage_Store::directory() . '/state.lock';
        check((fileperms($lockPath) & 0777) === 0600, 'Queue lock exposed');
        unlink($lockPath);
        file_put_contents(pm_Context::$directory . '/outside', 'synthetic');
        check(symlink(pm_Context::$directory . '/outside', $lockPath), 'Queue link fixture unavailable');
        $called = false; $failed = false;
        try { Modules_Help4DiskUsage_Store::locked(function () use (&$called) { $called = true; }); }
        catch (RuntimeException $e) { $failed = $e->getMessage() === 'Audit queue unavailable'; }
        check($failed && !$called, 'Queue followed linked lock');
    }
    echo "Queue lock: $checks contention/deadline/return/cleanup checks passed (OS processes, not native Plesk certification)\n";
} finally {
    $audit = pm_Context::$directory . '/audit';
    foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
    rmdir($audit);
    if (is_file(pm_Context::$directory . '/outside')) { unlink(pm_Context::$directory . '/outside'); }
    rmdir(pm_Context::$directory);
}
}
