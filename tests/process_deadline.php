<?php
namespace ProcessDeadlineFixture {
    class State {
        public static $time = 0;
        public static $stage = '';
        public static $elapsed = 0;
        public static $counts = [];
        public static $running = false;
        public static $exit = 0;
        public static $body = 'synthetic-output';
        public static $command;
        public static $options;
        public static function hit($stage) {
            self::$counts[$stage] = (self::$counts[$stage] ?? 0) + 1;
            if (self::$stage === $stage) { self::$time = self::$elapsed; }
        }
    }
    function hrtime($asNumber = false) { State::hit('clock'); return State::$time; }
    function tmpfile() { State::hit('storage'); return \tmpfile(); }
    function fopen($path, $mode) { State::hit('storage'); return \fopen($path, $mode); }
    function proc_open($command, $descriptors, &$pipes, $cwd, $environment, $options) {
        State::hit('launch'); State::$command = $command; State::$options = $options;
        $pipes = [];
        fwrite($descriptors[1], State::$body);
        State::$running = State::$stage === 'sleep' || State::$stage === 'running-status';
        return \tmpfile();
    }
    function proc_get_status($process) {
        State::hit(State::$stage === 'running-status' ? 'running-status' : 'status');
        return ['running' => State::$running, 'exitcode' => State::$exit];
    }
    function fstat($stream) { State::hit('stat'); return \fstat($stream); }
    function usleep($microseconds) {
        State::hit('sleep');
        // No OS sleep or child launch is needed for deterministic expiry controls.
        State::$running = false;
    }
    function stream_get_contents($stream, $length) {
        $body = \stream_get_contents($stream, $length); State::hit('read'); return $body;
    }
    function proc_terminate($process, $signal) {
        State::hit('terminate');
        if ($signal !== 9) { throw new \RuntimeException('Direct child termination contract changed'); }
        State::$running = false; return true;
    }
    function proc_close($process) { State::hit('close'); fclose($process); return State::$exit; }
}
namespace {
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
$checks = 0;
$source = __DIR__ . '/../extension/plib/library/Process.php';
eval('namespace ProcessDeadlineFixture; use \\RuntimeException;' . substr(file_get_contents($source), 5));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-process-deadline-' . bin2hex(random_bytes(8));
check(mkdir($directory, 0700), 'Private fixture directory unavailable');
$command = ['synthetic-python-not-executed', '-I', '-S', 'literal spaces " & | $(argument)'];
try {
    foreach ([null, $directory] as $storage) {
        foreach (['storage', 'launch', 'status', 'stat', 'read', 'running-status', 'sleep'] as $stage) {
            foreach ([999999999, 1000000000, 1000000001] as $elapsed) {
                $state = 'ProcessDeadlineFixture\\State';
                $state::$time = 0; $state::$stage = $stage; $state::$elapsed = $elapsed;
                $state::$counts = []; $state::$running = false; $state::$exit = 3;
                $state::$command = null; $state::$options = null;
                $streams = count(get_resources('stream'));
                $result = null; $error = null;
                try { $result = \ProcessDeadlineFixture\Modules_Help4DiskUsage_Process::run($command, 1, 4096, $storage); }
                catch (RuntimeException $e) { $error = $e->getMessage(); }
                $expired = $elapsed >= 1000000000;
                $label = ($storage === null ? 'temporary' : 'private') . '/' . $stage . '/' . $elapsed;
                check(($state::$counts[$stage] ?? 0) >= 1, 'Intended operation not reached: ' . $label);
                check(($result !== null) === !$expired, 'Expired result admitted: ' . $label);
                check($error === ($expired ? 'Subprocess exceeded safety limit' : null), 'Deadline error changed: ' . $label);
                check(($state::$counts['storage'] ?? 0) === 1, 'Output storage count changed: ' . $label);
                $launched = !($expired && $stage === 'storage');
                check(($state::$counts['launch'] ?? 0) === (int)$launched, 'Launch after exhausted preparation: ' . $label);
                check(($state::$counts['close'] ?? 0) === (int)$launched, 'Child handle cleanup mismatch: ' . $label);
                check(($state::$counts['terminate'] ?? 0) === (int)($expired && $stage === 'running-status'),
                    'Running child termination mismatch: ' . $label);
                $read = !$expired || $stage === 'read';
                check(($state::$counts['read'] ?? 0) === (int)$read, 'Late output was read or valid output lost: ' . $label);
                check(count(get_resources('stream')) === $streams, 'Output/child stream leaked: ' . $label);
                check(glob($directory . DIRECTORY_SEPARATOR . '*') === [], 'Private capture left behind: ' . $label);
                if ($launched) {
                    check($state::$command === $command && ($state::$options['bypass_shell'] ?? false) === true,
                        'Command/direct launch binding changed: ' . $label);
                }
                if (!$expired) {
                    check($result === ['exit' => 3, 'output' => $state::$body], 'Valid exit/output changed: ' . $label);
                }
            }
        }
    }
    foreach ([[[], 1, 4096], [$command, 0, 4096], [$command, 136, 4096], [$command, 1, 0],
        [$command, 1, 4194305]] as $arguments) {
        \ProcessDeadlineFixture\State::$counts = [];
        $failed = false;
        try { \ProcessDeadlineFixture\Modules_Help4DiskUsage_Process::run(...$arguments); }
        catch (RuntimeException $e) { $failed = $e->getMessage() === 'Invalid subprocess limits'; }
        check($failed, 'Invalid subprocess limits admitted');
        check(\ProcessDeadlineFixture\State::$counts === [], 'Invalid limits reached clock/storage/launch');
    }
    echo "Process deadline: $checks preparation/launch/poll/read/cleanup controls passed (clock/process fixtures, not native Plesk proof)\n";
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $path) { unlink($path); }
    rmdir($directory);
}
}
