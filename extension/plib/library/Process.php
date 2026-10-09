<?php
class Modules_Help4DiskUsage_Process
{
    public static function run(array $command, $seconds, $maximumBytes, $privateDirectory = null)
    {
        if (!$command || $seconds < 1 || $seconds > 135 || $maximumBytes < 1 || $maximumBytes > 4194304) {
            throw new RuntimeException('Invalid subprocess limits');
        }
        $path = null;
        $created = false;
        $output = null;
        $process = null;
        try {
            if ($privateDirectory === null) {
                // Only content-free runtime diagnostics use the system temporary directory.
                $output = @tmpfile();
            } else {
                $path = rtrim($privateDirectory, '/\\') . DIRECTORY_SEPARATOR . 'process-' . bin2hex(random_bytes(16));
                $output = @fopen($path, 'x+b');
                $created = is_resource($output);
                if ($output && PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
                    throw new RuntimeException('Private subprocess storage unavailable');
                }
            }
            if (!is_resource($output)) { throw new RuntimeException('Subprocess storage unavailable'); }
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $pipes = [];
            // Windows anonymous pipes can block despite stream_set_blocking(false).
            $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => $output, 2 => ['file', $null, 'w']],
                $pipes, null, null, ['bypass_shell' => true, 'suppress_errors' => true]);
            if (!is_resource($process)) { throw new RuntimeException('Subprocess unavailable'); }
            $deadline = hrtime(true) + $seconds * 1000000000;
            do {
                $status = proc_get_status($process);
                $stat = fstat($output);
                if (!$stat || $stat['size'] > $maximumBytes || hrtime(true) > $deadline) {
                    throw new RuntimeException('Subprocess exceeded safety limit');
                }
                if (!$status['running']) { break; }
                usleep(50000);
            } while (true);
            rewind($output);
            $body = stream_get_contents($output, $maximumBytes + 1);
            if ($body === false || strlen($body) > $maximumBytes) {
                throw new RuntimeException('Subprocess exceeded output limit');
            }
            return ['exit' => $status['exitcode'], 'output' => $body];
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    // Kill the directly launched collector before waiting; no shell is involved.
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            if (is_resource($output)) { fclose($output); }
            if ($created && is_file($path)) { unlink($path); }
        }
    }
}
