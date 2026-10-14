<?php
class Modules_Help4DiskUsage_Runtime
{
    public static function check($python)
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true)) {
            throw new RuntimeException('Native runtime requires Linux or Windows');
        }
        if (!is_string($python) || !is_file($python)) { throw new RuntimeException('Python runtime unavailable'); }
        $command = [$python, '-I', '-S', pm_Context::getPlibDir() . 'collector' . DIRECTORY_SEPARATOR . 'runtime.py'];
        $capture = Modules_Help4DiskUsage_Process::run($command, 5, 16384);
        $result = json_decode($capture['output'], true, 8, JSON_THROW_ON_ERROR);
        $expected = PHP_OS_FAMILY === 'Windows' ? 'win32' : 'linux';
        $version = is_array($result) ? ($result['python'] ?? null) : null;
        if ($capture['exit'] !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true ||
            ($result['platform'] ?? '') !== $expected || !is_array($version) || array_keys($version) !== [0, 1, 2] ||
            !is_int($version[0]) || !is_int($version[1]) || !is_int($version[2]) ||
            $version[0] < 3 || $version[1] < 0 || $version[2] < 0 || ($version[0] === 3 && $version[1] < 10) ||
            ($result['errors'] ?? null) !== [] || ($result['native_panel_validated'] ?? null) !== false ||
            ($result['private_storage_validated'] ?? null) !== false ||
            ($result['built_by'] ?? '') !== 'https://help4network.com') {
            throw new RuntimeException('Use Python 3.10+ with the required native filesystem APIs');
        }
        return array_intersect_key($result, array_flip(['ok', 'platform', 'python', 'errors',
            'native_panel_validated', 'private_storage_validated', 'built_by']));
    }
}
