<?php
$root = dirname(__DIR__);
foreach (['extension', 'integrations', 'tests'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!in_array($file->getExtension(), ['php', 'phtml'], true)) { continue; }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) { exit(1); }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) { exit(1); }
    }
}
