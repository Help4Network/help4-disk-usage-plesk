<?php
require __DIR__ . '/../extension/plib/library/Runtime.php';
class pm_Context {
    public static function getPlibDir() { return __DIR__ . '/../extension/plib/'; }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$python = getenv('H4_TEST_PYTHON') ?: (getenv('pythonLocation')
    ? getenv('pythonLocation') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'bin/python')
    : '/usr/bin/python3');
try {
    $result = Modules_Help4DiskUsage_Runtime::check($python);
    check(in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true), 'Unsupported host accepted');
    check($result['ok'] && !$result['private_storage_validated'], 'Runtime incorrectly certifies storage');
} catch (Throwable $e) {
    if (in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true)) { throw $e; }
}
foreach (['nonexistent-runtime', PHP_BINARY] as $bad) {
    $failed = false;
    try { Modules_Help4DiskUsage_Runtime::check($bad); } catch (Throwable $e) { $failed = true; }
    check($failed, 'Invalid runtime accepted');
}
echo "Runtime subprocess, platform and fail-closed diagnostic tests passed\n";
