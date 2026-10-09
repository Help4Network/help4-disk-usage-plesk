<?php
class Modules_Help4DiskUsage_Runtime
{
    public static function check($python)
    {
        if (!is_string($python) || !is_file($python)) { throw new RuntimeException('Python runtime unavailable'); }
        $command = [$python, '-I', '-S', pm_Context::getPlibDir() . 'collector' . DIRECTORY_SEPARATOR . 'runtime.py'];
        $capture = Modules_Help4DiskUsage_Process::run($command, 5, 16384);
        $result = json_decode($capture['output'], true, 8, JSON_THROW_ON_ERROR);
        $expected = PHP_OS_FAMILY === 'Windows' ? 'win32' : 'linux';
        if ($capture['exit'] !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true ||
            ($result['platform'] ?? '') !== $expected) {
            throw new RuntimeException('Use Python 3.10+ with the required native filesystem APIs');
        }
        return $result;
    }
}
