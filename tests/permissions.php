<?php
require __DIR__ . '/../extension/plib/library/Permissions.php';
class pm_Context {
    public static function getPlibDir() { return __DIR__ . '/../extension/plib/'; }
    public static function getHtdocsDir() { return __DIR__ . '/../extension/htdocs/'; }
}
class Modules_Help4DiskUsage_Store {
    public static function directory() { return 'C:\\private\\audit'; }
    public static function policy() { return ['python' => 'C:\\Python313\\python.exe']; }
}
class Modules_Help4DiskUsage_Process {
    public static $response;
    public static $command;
    public static function run($command, $seconds, $bytes) {
        self::$command = $command;
        if ($seconds !== 12 || $bytes !== 16384) { throw new RuntimeException('Unbounded subprocess'); }
        return self::$response;
    }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$safe = ['schema' => 1, 'ok' => true, 'objects_checked' => 5,
    'scope' => 'private flat storage and selected protected trees', 'native_panel_validated' => false,
    'continuous_enforcement' => false, 'built_by' => 'https://help4network.com'];
$responses = [['exit' => 2, 'output' => json_encode($safe)], ['exit' => 0, 'output' => '{']];
foreach (['schema' => 2, 'ok' => false, 'objects_checked' => 4097, 'scope' => 'other',
    'native_panel_validated' => true, 'continuous_enforcement' => true, 'built_by' => 'other'] as $key => $value) {
    $responses[] = ['exit' => 0, 'output' => json_encode(array_replace($safe, [$key => $value]))];
}
foreach ($responses as $response) {
    Modules_Help4DiskUsage_Process::$response = $response;
    $failed = false;
    try { Modules_Help4DiskUsage_Permissions::check(); } catch (Throwable $e) { $failed = true; }
    check($failed, 'Invalid permission result accepted');
}
if (PHP_OS_FAMILY === 'Windows') {
    Modules_Help4DiskUsage_Process::$response = ['exit' => 0, 'output' => json_encode($safe + ['private_path' => 'excluded'])];
    check(Modules_Help4DiskUsage_Permissions::check() === $safe, 'Unexpected fields returned');
    $command = Modules_Help4DiskUsage_Process::$command;
    check(!in_array('-ExecutionPolicy', $command, true) && !in_array('Bypass', $command, true), 'Execution policy bypass');
    check(count(json_decode(base64_decode($command[array_search('-ProtectedPathsBase64', $command, true) + 1], true), true)) === 3,
        'Collector, web assets and executable not all selected');
    Modules_Help4DiskUsage_Permissions::check('C:\\Candidate\\python.exe');
    $command = Modules_Help4DiskUsage_Process::$command;
    $paths = json_decode(base64_decode($command[array_search('-ProtectedPathsBase64', $command, true) + 1], true), true);
    check($paths[0] === 'C:\\Candidate\\python.exe', 'Selected candidate executable was not inspected');
}
echo "Permission diagnostic platform, bounded command and fail-closed response tests passed\n";
