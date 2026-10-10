<?php
namespace Help4\DiskUsagePlesk;

final class Health
{
    const MAX_SERVERS = 20;
    const TTL = 300;

    public static function page(array $servers, array $observations, $now = null)
    {
        $now = $now ?? time();
        if (!is_int($now) || $now < 1 || count($servers) > self::MAX_SERVERS) {
            throw new \RuntimeException('Invalid health batch');
        }
        $seen = [];
        $rows = [];
        foreach ($servers as $server) {
            if (!is_array($server)) { throw new \RuntimeException('Invalid health batch'); }
            $id = Scope::id($server['id'] ?? null);
            $binding = $server['server_binding'] ?? null;
            if (isset($seen[$id]) || ($server['module'] ?? null) !== 'plesk' ||
                !is_bool($server['enabled'] ?? null) || !is_string($binding) ||
                !preg_match('/^[a-f0-9]{64}$/D', $binding)) { throw new \RuntimeException('Invalid health batch'); }
            $seen[$id] = true;
            $row = ['server_id' => $id, 'scope' => 'extension only', 'status' => 'unmeasured',
                'reason' => 'no_observation', 'measured_at' => null, 'version' => null,
                'pending' => null, 'active_scanners' => null, 'failed_scans' => null, 'last_success_at' => null];
            if (!$server['enabled']) {
                $row['status'] = 'disabled'; $row['reason'] = 'server_disabled';
            } else {
                $raw = $observations[$id] ?? null;
                if ($raw !== null) {
                    try { $row = self::observation($raw, $id, $binding, $now, $row); }
                    catch (\Throwable $e) { $row['reason'] = 'invalid_observation'; }
                }
            }
            $rows[] = $row;
        }
        return ['schema' => 1, 'scope' => 'extension only', 'rows' => $rows,
            'native_panel_validated' => false, 'built_by' => 'https://help4network.com'];
    }

    private static function observation($raw, $id, $binding, $now, array $row)
    {
        if (!is_array($raw) || ($raw['schema'] ?? null) !== 1 || ($raw['server_id'] ?? null) !== $id ||
            ($raw['server_binding'] ?? null) !== $binding || !is_int($raw['measured_at'] ?? null) ||
            $raw['measured_at'] < 1 || $raw['measured_at'] > $now + 5) { throw new \RuntimeException(); }
        $row['measured_at'] = $raw['measured_at'];
        if ($raw['measured_at'] < $now - self::TTL) {
            $row['status'] = 'stale'; $row['reason'] = 'observation_expired';
            return $row;
        }
        if (($raw['collection_ok'] ?? null) === false) {
            $row['status'] = 'degraded'; $row['reason'] = 'collection_failed';
            return $row;
        }
        if (($raw['collection_ok'] ?? null) !== true || !is_string($raw['version'] ?? null) ||
            !preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/D', $raw['version']) ||
            !is_bool($raw['runtime_ok'] ?? null) || !is_bool($raw['storage_ok'] ?? null)) { throw new \RuntimeException(); }
        foreach (['pending' => 32, 'active_scanners' => 1, 'failed_scans' => 10000] as $key => $maximum) {
            if (!is_int($raw[$key] ?? null) || $raw[$key] < 0 || $raw[$key] > $maximum) { throw new \RuntimeException(); }
            $row[$key] = $raw[$key];
        }
        if (!array_key_exists('last_success_at', $raw)) { throw new \RuntimeException(); }
        $last = $raw['last_success_at'];
        if ($last !== null && (!is_int($last) || $last < 1 || $last > $now + 5)) { throw new \RuntimeException(); }
        $row['version'] = $raw['version'];
        $row['last_success_at'] = $last;
        $row['status'] = 'observed'; $row['reason'] = 'measurement_only';
        if (!$raw['runtime_ok'] || !$raw['storage_ok'] || $raw['failed_scans'] > 0) {
            $row['status'] = 'degraded'; $row['reason'] = 'extension_check_failed';
        } elseif ($last === null) {
            $row['status'] = 'no_report'; $row['reason'] = 'no_successful_scan';
        } elseif ($last < $now - 86400) {
            $row['status'] = 'stale_report'; $row['reason'] = 'successful_scan_expired';
        }
        return $row;
    }
}
