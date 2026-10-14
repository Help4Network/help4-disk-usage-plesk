<?php
class RuntimeResponseState {
    public static $path, $directory, $exists, $response, $calls, $files, $contextReads;
    public static function reset($os) {
        self::$path = $os === 'Windows' ? 'C:\\Captured Runtime\\python.exe' : '/captured runtime/python3';
        self::$directory = $os === 'Windows' ? 'C:\\Plesk\\module\\plib\\' : '/module/plib/';
        self::$exists = true; self::$calls = []; self::$files = []; self::$contextReads = 0;
    }
}
class RuntimeResponseContext {
    public static function getPlibDir() { RuntimeResponseState::$contextReads++; return RuntimeResponseState::$directory; }
}
class RuntimeResponseProcess {
    public static function run($command, $seconds, $bytes) {
        RuntimeResponseState::$calls[] = [$command, $seconds, $bytes];
        return RuntimeResponseState::$response;
    }
}
$checks = 0;
function verifyRuntimeResponse($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function responseCapture($result) {
    return ['exit' => 0, 'output' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
}
$safe = ['ok' => true, 'platform' => 'linux', 'python' => [3, 10, 0], 'errors' => [],
    'native_panel_validated' => false, 'private_storage_validated' => false,
    'built_by' => 'https://help4network.com'];
foreach (['Linux', 'Windows', 'Darwin', 'BSD'] as $os) {
    $ns = 'RuntimeResponse' . $os;
    define($ns . '\\PHP_OS_FAMILY', $os);
    define($ns . '\\DIRECTORY_SEPARATOR', $os === 'Windows' ? '\\' : '/');
    class_alias('RuntimeResponseContext', $ns . '\\pm_Context');
    class_alias('RuntimeResponseProcess', $ns . '\\Modules_Help4DiskUsage_Process');
    eval('namespace ' . $ns . '; function is_file($path) {
        \\RuntimeResponseState::$files[] = $path;
        return \\RuntimeResponseState::$exists && $path === \\RuntimeResponseState::$path;
    }');
    eval('namespace ' . $ns . '; use \\RuntimeException; ' .
        substr(file_get_contents(__DIR__ . '/../extension/plib/library/Runtime.php'), 5));
    $runtime = $ns . '\\Modules_Help4DiskUsage_Runtime';
    $valid = array_replace($safe, ['platform' => $os === 'Windows' ? 'win32' : 'linux']);
    if (!in_array($os, ['Linux', 'Windows'], true)) {
        RuntimeResponseState::reset($os); RuntimeResponseState::$response = responseCapture($valid);
        $error = null;
        try { $runtime::check(RuntimeResponseState::$path); } catch (RuntimeException $e) { $error = $e->getMessage(); }
        verifyRuntimeResponse($error === 'Native runtime requires Linux or Windows', 'Unsupported host accepted or not explicit: ' . $os);
        verifyRuntimeResponse(RuntimeResponseState::$files === [] && RuntimeResponseState::$calls === [] &&
            RuntimeResponseState::$contextReads === 0, 'Unsupported host reached executable/context/transport: ' . $os);
        continue;
    }
    foreach ([[3, 10, 0], [3, 14, 6], [4, 0, 0]] as $version) {
        RuntimeResponseState::reset($os); $value = array_replace($valid, ['python' => $version]);
        RuntimeResponseState::$response = responseCapture($value);
        verifyRuntimeResponse($runtime::check(RuntimeResponseState::$path) === $value, 'Valid typed runtime result changed');
        verifyRuntimeResponse(RuntimeResponseState::$calls === [[[
            RuntimeResponseState::$path, '-I', '-S', RuntimeResponseState::$directory . 'collector' .
            ($os === 'Windows' ? '\\' : '/') . 'runtime.py'], 5, 16384]], 'Captured executable/command/budget changed');
        verifyRuntimeResponse(RuntimeResponseState::$files === [RuntimeResponseState::$path] &&
            RuntimeResponseState::$contextReads === 1, 'Valid runtime admission probes changed');
    }
    $cases = [];
    foreach (array_keys($valid) as $key) {
        $value = $valid; unset($value[$key]); $cases['missing-' . $key] = responseCapture($value);
    }
    foreach (['ok' => [false, 1, 'true', null], 'platform' => ['other', null, 1],
        'python' => [null, '3.10.0', [], [3, 10], [3, 10, 0, 1], [3, 9, 99], [2, 99, 0],
            [3, -1, 0], [3, 10, -1], [3.0, 10, 0], ['3', 10, 0], [3, '10', 0], [3, 10, true],
            [3, 10, null], ['major' => 3, 'minor' => 10, 'patch' => 0]],
        'errors' => [null, false, '', ['native failure']],
        'native_panel_validated' => [true, 0, 'false', null],
        'private_storage_validated' => [true, 0, 'false', null], 'built_by' => ['other', null]] as $key => $values) {
        foreach ($values as $index => $value) { $cases[$key . '-' . $index] = responseCapture(array_replace($valid, [$key => $value])); }
    }
    foreach ([1, '0', 0.0, null] as $index => $exit) {
        $cases['exit-' . $index] = array_replace(responseCapture($valid), ['exit' => $exit]);
    }
    foreach (['{', 'null', 'false', '[]', '"text"', '{"nested":[[[[[[[[0]]]]]]]]}'] as $index => $body) {
        $cases['json-' . $index] = ['exit' => 0, 'output' => $body];
    }
    foreach ($cases as $name => $capture) {
        RuntimeResponseState::reset($os); RuntimeResponseState::$response = $capture;
        $failed = false;
        try { $runtime::check(RuntimeResponseState::$path); } catch (Throwable $e) { $failed = true; }
        verifyRuntimeResponse($failed, 'Invalid runtime response accepted: ' . $os . '/' . $name);
        verifyRuntimeResponse(count(RuntimeResponseState::$calls) === 1 && RuntimeResponseState::$contextReads === 1,
            'Response test did not reach captured transport exactly once: ' . $name);
        verifyRuntimeResponse(RuntimeResponseState::$calls[0][1] === 5 && RuntimeResponseState::$calls[0][2] === 16384,
            'Rejected response widened runtime budgets');
    }
    RuntimeResponseState::reset($os);
    RuntimeResponseState::$response = responseCapture($valid + ['private_path' => 'synthetic-private-canary', 'extra' => ['secret' => 'excluded']]);
    verifyRuntimeResponse($runtime::check(RuntimeResponseState::$path) === $valid, 'Unexpected diagnostic fields were exposed');
    foreach ([null, false, 123, [], '', 'missing-python'] as $path) {
        RuntimeResponseState::reset($os); RuntimeResponseState::$response = responseCapture($valid);
        $failed = false;
        try { $runtime::check($path); } catch (RuntimeException $e) { $failed = $e->getMessage() === 'Python runtime unavailable'; }
        verifyRuntimeResponse($failed, 'Invalid Python candidate accepted');
        verifyRuntimeResponse(RuntimeResponseState::$calls === [] && RuntimeResponseState::$contextReads === 0,
            'Invalid candidate reached context or process');
    }
}
echo "Runtime response: $checks unchanged-source platform/version/claims/command controls passed (transport fixtures, not native Plesk proof)\n";
