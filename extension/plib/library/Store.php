<?php
class Modules_Help4DiskUsage_Store
{
    public static function directory()
    {
        $dir = rtrim(pm_Context::getVarDir(), '/\\') . DIRECTORY_SEPARATOR . 'audit';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Private audit storage unavailable');
        }
        return $dir;
    }

    public static function read($name, $default = [])
    {
        $file = self::directory() . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($file)) {
            return $default;
        }
        $value = json_decode(file_get_contents($file), true);
        if (!is_array($value)) {
            throw new RuntimeException('Audit storage needs administrator review');
        }
        return $value;
    }

    public static function write($name, array $value)
    {
        $dir = self::directory();
        $temp = tempnam($dir, 'audit-');
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false ||
                !rename($temp, $dir . DIRECTORY_SEPARATOR . $name . '.json')) {
                throw new RuntimeException('Audit storage unavailable');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public static function locked($callback)
    {
        $handle = fopen(self::directory() . DIRECTORY_SEPARATOR . 'state.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Audit queue unavailable');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function policy()
    {
        return self::read('policy') + ['hourly' => 3, 'minimum_interval' => 300,
            'seconds' => 60, 'ttl' => 3600, 'global_hourly' => 60, 'queue_limit' => 16,
            'title' => 'Disk Usage Audit', 'python' => PHP_OS_FAMILY === 'Windows'
                ? 'C:\\Program Files\\Python313\\python.exe' : '/usr/bin/python3', 'overrides' => []];
    }

    public static function validatePolicy(array $data)
    {
        $limits = ['hourly' => [0, 30], 'minimum_interval' => [60, 86400],
            'seconds' => [5, 120], 'ttl' => [300, 86400], 'global_hourly' => [1, 240], 'queue_limit' => [1, 32]];
        foreach ($limits as $key => $bounds) {
            if (!isset($data[$key]) || filter_var($data[$key], FILTER_VALIDATE_INT) === false ||
                $data[$key] < $bounds[0] || $data[$key] > $bounds[1]) {
                throw new RuntimeException('Invalid scan policy: ' . $key);
            }
            $data[$key] = (int)$data[$key];
        }
        if (!isset($data['python']) || !is_string($data['python']) ||
            !preg_match('~^(?:/[\x20-\x7e]+|[A-Za-z]:\\\\[\x20-\x7e]+\\.exe)$~D', $data['python']) ||
            !is_file($data['python'])) {
            throw new RuntimeException('Select an installed absolute Python executable');
        }
        $data['title'] = trim((string)($data['title'] ?? 'Disk Usage Audit'));
        if (strlen($data['title']) > 80 || $data['title'] === '') {
            throw new RuntimeException('Title must contain 1-80 characters');
        }
        $overrides = $data['overrides'] ?? [];
        if (!is_array($overrides) || count($overrides) > 10000) {
            throw new RuntimeException('Invalid subscription overrides');
        }
        foreach ($overrides as $id => &$override) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id) || !is_array($override)) {
                throw new RuntimeException('Invalid subscription override');
            }
            $override = array_intersect_key($override, array_flip(['hourly', 'minimum_interval', 'seconds']));
            foreach ($override as $key => &$value) {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $limits[$key][0] || $value > $limits[$key][1]) {
                    throw new RuntimeException('Invalid subscription override limit');
                }
                $value = (int)$value;
            }
            unset($value);
        }
        unset($override);
        $data['overrides'] = $overrides;
        return array_intersect_key($data, array_flip(array_merge(array_keys($limits), ['python', 'title', 'overrides'])));
    }

    public static function effective($domain)
    {
        $policy = self::policy();
        return ($policy['overrides'][$domain->getId()] ?? []) + $policy;
    }

    public static function reserve($domain, $actor, $admin)
    {
        Modules_Help4DiskUsage_Access::authorize($actor, $domain, $admin);
        $binding = Modules_Help4DiskUsage_Access::binding($domain);
        $policy = self::effective($domain);
        return self::locked(function () use ($domain, $actor, $admin, $binding, $policy) {
            $state = self::read('state', ['attempts' => [], 'pending' => []]);
            $now = time();
            $state['attempts'] = array_values(array_filter($state['attempts'], function ($a) use ($now) {
                return $a['time'] > $now - 3600;
            }));
            // Expired reservations cannot execute: workers verify their token before scanning.
            $state['pending'] = array_filter($state['pending'], function ($p) use ($now) {
                return $p['time'] > $now - 600;
            });
            $id = (string)$domain->getId();
            if (isset($state['pending'][$id])) {
                throw new RuntimeException('A scan for this subscription is already queued or running');
            }
            if (count($state['pending']) >= $policy['queue_limit'] || count($state['attempts']) >= $policy['global_hourly']) {
                throw new RuntimeException('Server scan limit reached; try later');
            }
            $own = array_values(array_filter($state['attempts'], function ($a) use ($id) {
                return (string)$a['domain'] === $id;
            }));
            $actorAttempts = array_filter($state['attempts'], function ($a) use ($actor) {
                return (int)$a['actor'] === (int)$actor->getId();
            });
            if (!$admin && ($policy['hourly'] === 0 || count($own) >= $policy['hourly'] ||
                count($actorAttempts) >= $policy['hourly'] ||
                ($own && $now - end($own)['time'] < $policy['minimum_interval']))) {
                throw new RuntimeException('Refresh limit reached; try later or contact your host');
            }
            $token = bin2hex(random_bytes(16));
            $state['pending'][$id] = ['time' => $now, 'token' => $token, 'binding' => $binding,
                'actor' => $actor->getId(), 'seconds' => $policy['seconds']];
            $state['attempts'][] = ['time' => $now, 'domain' => $id, 'actor' => $actor->getId()];
            self::write('state', $state);
            return $token;
        });
    }

    public static function finish($id, $token, $report = null)
    {
        self::locked(function () use ($id, $token, $report) {
            $state = self::read('state', ['attempts' => [], 'pending' => []]);
            if (isset($state['pending'][$id]) && hash_equals($state['pending'][$id]['token'], $token)) {
                if ($report !== null) {
                    self::write('report-' . (int)$id, $report);
                }
                unset($state['pending'][$id]);
                self::write('state', $state);
            }
        });
    }

    public static function report($domain)
    {
        $r = self::read('report-' . $domain->getId());
        if (!$r || !isset($r['binding']) || !hash_equals(Modules_Help4DiskUsage_Access::binding($domain), $r['binding'])) {
            return null;
        }
        return $r;
    }
}
