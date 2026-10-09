<?php
class Modules_Help4DiskUsage_Runtime
{
    public static function check($python)
    {
        if (!is_string($python) || !is_file($python)) { throw new RuntimeException('Python runtime unavailable'); }
        $command = [$python, '-I', '-S', pm_Context::getPlibDir() . 'collector' . DIRECTORY_SEPARATOR . 'runtime.py'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Runtime diagnostic unavailable'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $body = '';
        $deadline = microtime(true) + 5;
        $exit = -1;
        try {
            do {
                $body .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (strlen($body) > 16384 || microtime(true) > $deadline) {
                    proc_terminate($process);
                    throw new RuntimeException('Runtime diagnostic exceeded safety limit');
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                usleep(50000);
            } while (true);
            $body .= stream_get_contents($pipes[1]);
            if (strlen($body) > 16384) { throw new RuntimeException('Runtime diagnostic exceeded safety limit'); }
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        $result = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $expected = PHP_OS_FAMILY === 'Windows' ? 'win32' : 'linux';
        if ($exit !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true ||
            ($result['platform'] ?? '') !== $expected) {
            throw new RuntimeException('Use Python 3.10+ with the required native filesystem APIs');
        }
        return $result;
    }
}
