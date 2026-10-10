<?php
namespace ExecutableFixture {
    // Execute unchanged source bodies with a simulated Windows constant and SDK/process stubs.
    const PHP_OS_FAMILY = 'Windows';
    class pm_Context { public static function init($id) {} public static function getPlibDir() { return 'synthetic/'; } }
    class pm_Domain { public static function getByDomainId($id) { return new self(); } public function getHomePath() { return 'synthetic'; } }
    class pm_Client { public static function getByClientId($id) { return new self(); } }
    class pm_Extension { public static function getById($id) { return new self(); } public function getVersion() { return '0.3.0'; } }
    class Modules_Help4DiskUsage_Access {
        public static function authorizeQueued($actor, $domain, $pending) {}
        public static function binding($domain) { return str_repeat('a', 64); }
    }
    class Modules_Help4DiskUsage_Store {
        public static $policies = 0;
        public static function policy() { return ['python' => self::$policies++ === 0 ? PHP_BINARY : 'changed-protected-runtime']; }
        public static function read($name) {
            if ($name === 'health') { return ['failed_scans' => 0, 'last_success_at' => time()]; }
            return ['pending' => [1 => ['token' => str_repeat('a', 32), 'actor' => 1, 'seconds' => 5]]];
        }
        public static function alive($pending) { return true; }
        public static function mutex($name) { return tmpfile(); }
        public static function locked($callback) { return $callback(); }
        public static function write($name, $value) {}
        public static function validateReservation($pending, $domain) {}
        public static function report($domain) { return null; }
        public static function directory() { return sys_get_temp_dir(); }
        public static function finish($id, $token, $report = null, $callback = null) {
            if ($callback) { $callback(['actor' => 1]); }
            echo json_encode(['checked' => Modules_Help4DiskUsage_Permissions::$checked,
                'runtime' => Modules_Help4DiskUsage_Runtime::$called, 'collector' => Modules_Help4DiskUsage_Process::$called,
                'completed' => $report !== null], JSON_THROW_ON_ERROR);
        }
    }
    class Modules_Help4DiskUsage_Permissions {
        public static $allow = false;
        public static $checked = [];
        public static function check($python = null) {
            $python = $python ?? Modules_Help4DiskUsage_Store::policy()['python'];
            self::$checked[] = $python;
            if (!self::$allow && $python === PHP_BINARY) { throw new \RuntimeException('Rejected executable ACL fixture'); }
            return [];
        }
    }
    class Modules_Help4DiskUsage_Runtime {
        public static $called = [];
        public static function check($python) { self::$called[] = $python; return []; }
    }
    class Modules_Help4DiskUsage_Process {
        public static $called = [];
        public static function run($command, $seconds, $bytes, $directory) {
            self::$called[] = $command[0];
            return ['exit' => 0, 'output' => json_encode(['schema' => 1, 'complete' => true, 'bytes' => 1])];
        }
    }
}
namespace {
    $checks = 0;
    function check($ok, $message) {
        if (!$ok) { throw new RuntimeException($message); }
        $GLOBALS['checks']++;
    }
    $root = dirname(__DIR__) . '/extension/plib/';
    if (($argv[1] ?? '') === '--worker') {
        \ExecutableFixture\Modules_Help4DiskUsage_Permissions::$allow = ($argv[2] ?? '') === 'allow';
        $argv = [__FILE__, '1', str_repeat('a', 32)];
        eval('namespace ExecutableFixture; use \\RuntimeException; use \\Throwable;' . substr(file_get_contents($root . 'scripts/worker.php'), 5));
        exit;
    }
    foreach (($argv[1] ?? '') === '--remote-only' ? [] : ['reject' => 1, 'allow' => 0] as $mode => $expectedExit) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        check(proc_close($process) === $expectedExit, 'Worker exit behavior');
        $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        check($result['checked'] === [PHP_BINARY], 'Worker checked changed policy instead of captured executable');
        check($result['runtime'] === ($mode === 'allow' ? [PHP_BINARY] : []), 'Worker runtime bypass');
        check($result['collector'] === ($mode === 'allow' ? [PHP_BINARY] : []), 'Worker collector bypass');
        check($result['completed'] === ($mode === 'allow'), 'Worker publication behavior');
    }
    eval('namespace ExecutableFixture; use \\RuntimeException; use \\Throwable; use \\DirectoryIterator;' . substr(file_get_contents($root . 'library/Remote.php'), 5));
    foreach ([false, true] as $allowed) {
        \ExecutableFixture\Modules_Help4DiskUsage_Permissions::$allow = $allowed;
        foreach (['health', 'enable'] as $operation) {
            \ExecutableFixture\Modules_Help4DiskUsage_Store::$policies = 0;
            \ExecutableFixture\Modules_Help4DiskUsage_Permissions::$checked = [];
            \ExecutableFixture\Modules_Help4DiskUsage_Runtime::$called = [];
            if ($operation === 'health') {
                $method = new ReflectionMethod(\ExecutableFixture\Modules_Help4DiskUsage_Remote::class, 'health');
                $result = $method->invoke(null);
                check($result['runtime_ok'] === $allowed && $result['storage_ok'] === $allowed, 'Health preflight status');
            } else {
                $failed = false;
                try { \ExecutableFixture\Modules_Help4DiskUsage_Remote::configure(true); } catch (RuntimeException $e) { $failed = true; }
                check($failed === !$allowed, 'Bridge enable failure contract');
            }
            check(\ExecutableFixture\Modules_Help4DiskUsage_Permissions::$checked === [PHP_BINARY], 'Remote executable mismatch');
            check(\ExecutableFixture\Modules_Help4DiskUsage_Runtime::$called === ($allowed ? [PHP_BINARY] : []), 'Remote executed before failed ACL check');
        }
    }
    echo "Executable preflight: $checks worker/health/enable checks passed (simulated Windows control flow, not NTFS certification)\n";
}
