<?php
require __DIR__ . '/../extension/plib/library/Process.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$python = getenv('H4_TEST_PYTHON') ?: (getenv('pythonLocation')
    ? getenv('pythonLocation') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'bin/python')
    : '/usr/bin/python3');
$fixture = __DIR__ . '/fixtures/process.py';
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-process-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $argument = 'spaces " quotes & | ; $(literal)';
    $result = Modules_Help4DiskUsage_Process::run([$python, '-I', '-S', $fixture, 'success', $argument], 5, 8192, $directory);
    check($result['exit'] === 0 && json_decode($result['output'], true)['argument'] === $argument, 'Command argument changed');
    check(glob($directory . DIRECTORY_SEPARATOR . '*') === [], 'Successful capture left private files');
    $result = Modules_Help4DiskUsage_Process::run([$python, '-I', '-S', $fixture, 'nonzero'], 5, 8192, $directory);
    check($result['exit'] === 3, 'Nonzero process status lost');
    foreach (['oversized', 'timeout', 'ignore-term'] as $mode) {
        $started = microtime(true); $failed = false;
        try { Modules_Help4DiskUsage_Process::run([$python, '-I', '-S', $fixture, $mode], 1, 8192, $directory); }
        catch (Throwable $e) { $failed = true; }
        check($failed && microtime(true) - $started < 6, 'Process limit/cleanup failed: ' . $mode);
        check(glob($directory . DIRECTORY_SEPARATOR . '*') === [], 'Failed capture left private files');
    }
    echo "Private subprocess capture, literal arguments, exit status, timeout and cleanup tests passed\n";
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $path) { unlink($path); }
    rmdir($directory);
}
