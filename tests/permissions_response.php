<?php
// Exercise the unchanged preflight body without invoking a shell or modifying an ACL.
class PermissionResponseFixture {
    public static $root, $shellExists, $sysnative, $response, $calls, $policyReads;
    public static function reset() {
        self::$root = 'C:\\Windows'; self::$shellExists = true; self::$sysnative = false;
        self::$calls = []; self::$policyReads = 0;
    }
}
class PermissionResponseContext {
    public static function getPlibDir() { return 'C:\\Plesk\\module\\plib\\'; }
    public static function getHtdocsDir() { return 'C:\\Plesk\\module\\htdocs\\'; }
}
class PermissionResponseStore {
    public static function directory() { return 'C:\\Plesk\\private\\audit'; }
    public static function policy() {
        PermissionResponseFixture::$policyReads++;
        return ['python' => 'C:\\Policy Runtime\\python.exe'];
    }
}
class PermissionResponseProcess {
    public static function run($command, $seconds, $bytes) {
        PermissionResponseFixture::$calls[] = [$command, $seconds, $bytes];
        return PermissionResponseFixture::$response;
    }
}
$checks = 0;
function verifyPermissionResponse($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
$safe = ['schema' => 1, 'ok' => true, 'objects_checked' => 5,
    'scope' => 'private flat storage and selected protected trees', 'native_panel_validated' => false,
    'continuous_enforcement' => false, 'built_by' => 'https://help4network.com'];
foreach ([4, 8] as $size) {
    $ns = 'PermissionResponse' . $size;
    define($ns . '\\PHP_OS_FAMILY', 'Windows');
    define($ns . '\\PHP_INT_SIZE', $size);
    define($ns . '\\DIRECTORY_SEPARATOR', '\\');
    foreach (['pm_Context' => 'PermissionResponseContext', 'Modules_Help4DiskUsage_Store' => 'PermissionResponseStore',
        'Modules_Help4DiskUsage_Process' => 'PermissionResponseProcess'] as $alias => $class) {
        class_alias($class, $ns . '\\' . $alias);
    }
    eval('namespace ' . $ns . '; function getenv($name) {
        if ($name !== "SystemRoot") { throw new \\RuntimeException("Unexpected environment lookup"); }
        return \\PermissionResponseFixture::$root;
    }
    function is_dir($path) {
        if ($path !== \\PermissionResponseFixture::$root . "\\\\Sysnative") {
            throw new \\RuntimeException("Unexpected directory probe");
        }
        return \\PermissionResponseFixture::$sysnative;
    }
    function is_file($path) {
        $system = ' . $size . ' === 4 && \\PermissionResponseFixture::$sysnative ? "Sysnative" : "System32";
        if ($path !== \\PermissionResponseFixture::$root . "\\\\" . $system . "\\\\WindowsPowerShell\\\\v1.0\\\\powershell.exe") {
            throw new \\RuntimeException("Unexpected shell probe");
        }
        return \\PermissionResponseFixture::$shellExists;
    }');
    eval('namespace ' . $ns . '; use \\RuntimeException; ' .
        substr(file_get_contents(__DIR__ . '/../extension/plib/library/Permissions.php'), 5));
    $permissions = $ns . '\\Modules_Help4DiskUsage_Permissions';
    foreach ([false, true] as $sysnative) {
        foreach ([null, 'C:\\Captured Runtime\\python.exe'] as $python) {
            foreach ([1, 4096] as $count) {
                PermissionResponseFixture::reset(); PermissionResponseFixture::$sysnative = $sysnative;
                $value = array_replace($safe, ['objects_checked' => $count]);
                PermissionResponseFixture::$response = ['exit' => 0, 'output' => json_encode($value + ['private_path' => 'excluded'])];
                verifyPermissionResponse($permissions::check($python) === $value, 'Valid result or field stripping changed');
                verifyPermissionResponse(count(PermissionResponseFixture::$calls) === 1, 'Valid preflight did not invoke exactly once');
                [$command, $seconds, $bytes] = PermissionResponseFixture::$calls[0];
                $system = $size === 4 && $sysnative ? 'Sysnative' : 'System32';
                $paths = [$python ?? 'C:\\Policy Runtime\\python.exe', 'C:\\Plesk\\module\\plib', 'C:\\Plesk\\module\\htdocs'];
                $expected = ['C:\\Windows\\' . $system . '\\WindowsPowerShell\\v1.0\\powershell.exe',
                    '-NoLogo', '-NoProfile', '-NonInteractive', '-File',
                    'C:\\Plesk\\module\\plib\\collector\\windows-acl.ps1',
                    '-PrivateDirectory', 'C:\\Plesk\\private\\audit', '-ProtectedPathsBase64',
                    base64_encode(json_encode($paths, JSON_THROW_ON_ERROR))];
                verifyPermissionResponse($command === $expected, 'Exact shell/private tree/captured executable command changed');
                verifyPermissionResponse($seconds === 12 && $bytes === 16384, 'Subprocess budget changed');
                verifyPermissionResponse(PermissionResponseFixture::$policyReads === ($python === null ? 1 : 0),
                    'Captured executable was replaced by a policy lookup');
            }
        }
    }
    $responses = [['exit' => 1, 'output' => json_encode($safe)], ['exit' => '0', 'output' => json_encode($safe)]];
    foreach (array_keys($safe) as $key) {
        $value = $safe; unset($value[$key]);
        $responses[] = ['exit' => 0, 'output' => json_encode($value)];
    }
    foreach (['schema' => [0, '1', true, null], 'ok' => [false, 1, 'true', null],
        'objects_checked' => [0, -1, 4097, '5', 5.5, true, null],
        'scope' => ['other', null], 'native_panel_validated' => [true, 0, 'false', null],
        'continuous_enforcement' => [true, 0, 'false', null], 'built_by' => ['other', null]] as $key => $values) {
        foreach ($values as $value) {
            $responses[] = ['exit' => 0, 'output' => json_encode(array_replace($safe, [$key => $value]))];
        }
    }
    foreach (['{', 'null', 'false', '[]', '"text"', '{"nested":[[[[[[[[0]]]]]]]]}'] as $body) {
        $responses[] = ['exit' => 0, 'output' => $body];
    }
    foreach ($responses as $response) {
        PermissionResponseFixture::reset(); PermissionResponseFixture::$response = $response;
        $failed = false;
        try { $permissions::check('C:\\Captured Runtime\\python.exe'); } catch (Throwable $e) { $failed = true; }
        verifyPermissionResponse($failed, 'Invalid diagnostic response accepted');
        verifyPermissionResponse(count(PermissionResponseFixture::$calls) === 1, 'Diagnostic response case did not reach transport');
        verifyPermissionResponse(PermissionResponseFixture::$calls[0][1] === 12 &&
            PermissionResponseFixture::$calls[0][2] === 16384, 'Rejected diagnostic lost subprocess limits');
        verifyPermissionResponse(PermissionResponseFixture::$policyReads === 0, 'Rejected diagnostic changed captured executable');
    }
    foreach ([false, null, '', 'Windows', '\\\\server\\Windows', 'C:\\Windows:alternate', "C:\\Windows\n"] as $root) {
        PermissionResponseFixture::reset(); PermissionResponseFixture::$root = $root;
        $error = null;
        try { $permissions::check(); } catch (RuntimeException $e) { $error = $e->getMessage(); }
        verifyPermissionResponse($error === 'Windows runtime unavailable', 'Unsafe SystemRoot not rejected generically');
        verifyPermissionResponse(PermissionResponseFixture::$calls === [] && PermissionResponseFixture::$policyReads === 0,
            'Unsafe SystemRoot reached policy or subprocess');
    }
    PermissionResponseFixture::reset(); PermissionResponseFixture::$shellExists = false;
    $error = null;
    try { $permissions::check(); } catch (RuntimeException $e) { $error = $e->getMessage(); }
    verifyPermissionResponse($error === 'Windows runtime unavailable', 'Missing shell not rejected generically');
    verifyPermissionResponse(PermissionResponseFixture::$calls === [] && PermissionResponseFixture::$policyReads === 0,
        'Missing shell reached policy or subprocess');
}
echo "Permission response: $checks unchanged-source architecture/command/response controls passed (fixtures, not native Plesk or effective Windows ACL proof)\n";
