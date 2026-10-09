<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
pm_Context::init('help4-disk-usage');
$id = $argv[1] ?? '';
$token = $argv[2] ?? '';
if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
    exit(2);
}
$lock = fopen(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . 'scanner.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(3);
}
try {
    $state = Modules_Help4DiskUsage_Store::read('state');
    $pending = $state['pending'][$id] ?? null;
    if (!$pending || !hash_equals($pending['token'], $token) || !Modules_Help4DiskUsage_Store::alive($pending)) {
        throw new RuntimeException('Expired scan reservation');
    }
    $domain = pm_Domain::getByDomainId((int)$id);
    $actor = pm_Client::getByClientId((int)$pending['actor']);
    Modules_Help4DiskUsage_Access::authorizeQueued($actor, $domain, $pending);
    Modules_Help4DiskUsage_Store::validateReservation($pending, $domain);
    $binding = Modules_Help4DiskUsage_Access::binding($domain);
    $python = Modules_Help4DiskUsage_Store::policy()['python'];
    if (!is_file($python)) {
        throw new RuntimeException('Python runtime not configured');
    }
    Modules_Help4DiskUsage_Runtime::check($python);
    $command = [$python, '-I', '-S', pm_Context::getPlibDir() . 'collector' . DIRECTORY_SEPARATOR . 'scan.py',
        '--root', $domain->getHomePath(), '--seconds', (string)min(120, max(5, (int)$pending['seconds']))];
    $capture = Modules_Help4DiskUsage_Process::run($command,
        min(120, max(5, (int)$pending['seconds'])) + 15, 4 * 1024 * 1024,
        Modules_Help4DiskUsage_Store::directory());
    $report = json_decode($capture['output'], true, 64, JSON_THROW_ON_ERROR);
    if ($capture['exit'] !== 0 || !is_array($report) || ($report['schema'] ?? 0) !== 1) {
        throw new RuntimeException('Collector did not produce a valid report');
    }
    $current = pm_Domain::getByDomainId((int)$id);
    Modules_Help4DiskUsage_Access::authorizeQueued(pm_Client::getByClientId((int)$pending['actor']), $current, $pending);
    Modules_Help4DiskUsage_Store::validateReservation($pending, $current);
    $previous = Modules_Help4DiskUsage_Store::report($current);
    $report['growth_bytes'] = $previous && $previous['complete'] && $report['complete']
        ? $report['bytes'] - $previous['bytes'] : null;
    $report['binding'] = $binding;
    $report['built_by'] = ['name' => 'Help4 Network', 'url' => 'https://help4network.com'];
    Modules_Help4DiskUsage_Store::finish($id, $token, $report, function ($reservation) use ($id) {
        // Serialize the final policy/lease check with report publication and admission.
        $current = pm_Domain::getByDomainId((int)$id);
        Modules_Help4DiskUsage_Access::authorizeQueued(pm_Client::getByClientId((int)$reservation['actor']), $current, $reservation);
        Modules_Help4DiskUsage_Store::validateReservation($reservation, $current);
    });
} catch (Throwable $e) {
    Modules_Help4DiskUsage_Store::finish($id, $token);
    // Absolute paths and subprocess diagnostics stay out of customer-facing task output.
    fwrite(STDERR, "Disk audit failed. Check runtime, storage permissions and subscription status.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
