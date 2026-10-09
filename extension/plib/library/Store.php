<?php
class Modules_Help4DiskUsage_Store
{
    const LIMITS = ['hourly' => [0, 30], 'minimum_interval' => [60, 86400],
        'seconds' => [5, 120], 'ttl' => [300, 86400], 'global_hourly' => [1, 240], 'queue_limit' => [1, 32]];
    const PROFILES = ['audit_default', 'audit_extended', 'audit_disabled'];

    public static function directory()
    {
        $dir = rtrim(pm_Context::getVarDir(), '/\\') . DIRECTORY_SEPARATOR . 'audit';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Private audit storage unavailable');
        }
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $panel = posix_getpwnam('psaadm');
            if (!$panel || !chown($dir, $panel['uid']) || !chgrp($dir, $panel['gid']) || !chmod($dir, 0700)) {
                throw new RuntimeException('Private panel storage ownership unavailable');
            }
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
            if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                if (!chown($temp, fileowner($dir)) || !chgrp($temp, filegroup($dir))) {
                    throw new RuntimeException('Report ownership unavailable');
                }
            }
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
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $path = self::directory() . DIRECTORY_SEPARATOR . 'state.lock';
            if (!chown($path, fileowner(self::directory())) || !chgrp($path, filegroup(self::directory())) || !chmod($path, 0600)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new RuntimeException('Queue lock ownership unavailable');
            }
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
                ? 'C:\\Program Files\\Python313\\python.exe' : '/usr/bin/python3', 'overrides' => [],
            'profiles' => ['audit_default' => [], 'audit_extended' => ['hourly' => 6, 'seconds' => 90]]];
    }

    public static function validatePolicy(array $data)
    {
        $limits = self::LIMITS;
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
            $override = self::validateOverride($override);
        }
        unset($override);
        $data['overrides'] = $overrides;
        $profiles = $data['profiles'] ?? self::policy()['profiles'];
        if (!is_array($profiles) || array_diff(array_keys($profiles), ['audit_default', 'audit_extended'])) {
            throw new RuntimeException('Invalid service-plan profiles');
        }
        foreach ($profiles as &$profile) {
            if (!is_array($profile)) {
                throw new RuntimeException('Invalid service-plan profile');
            }
            $profile = self::validateOverride($profile);
        }
        unset($profile);
        $data['profiles'] = $profiles;
        return array_intersect_key($data, array_flip(array_merge(array_keys($limits), ['python', 'title', 'overrides', 'profiles'])));
    }

    private static function validateOverride(array $override)
    {
        if (array_diff(array_keys($override), ['hourly', 'minimum_interval', 'seconds'])) {
            throw new RuntimeException('Unknown override limit');
        }
        foreach ($override as $key => &$value) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false ||
                $value < self::LIMITS[$key][0] || $value > self::LIMITS[$key][1]) {
                throw new RuntimeException('Invalid override limit');
            }
            $value = (int)$value;
        }
        unset($value);
        return $override;
    }

    public static function effective($domain)
    {
        $policy = self::policy();
        try {
            $items = $domain->getPlanItems();
            if (!is_array($items) || array_filter($items, function ($v) { return !is_string($v); })) {
                throw new RuntimeException('Invalid service-plan items');
            }
            $selected = array_values(array_intersect(array_unique($items), self::PROFILES));
            if (count($selected) > 1) {
                throw new RuntimeException('Conflicting service-plan items');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Subscription scan policy unavailable; contact your host');
        }
        $profile = $selected[0] ?? 'audit_default';
        $effective = ($policy['overrides'][$domain->getId()] ?? []) + ($policy['profiles'][$profile] ?? []) + $policy;
        if ($profile === 'audit_disabled') {
            $effective['hourly'] = 0;
        }
        $effective['profile'] = $profile;
        $effective['policy_binding'] = hash('sha256', json_encode([$profile, $effective['hourly'],
            $effective['minimum_interval'], $effective['seconds']], JSON_THROW_ON_ERROR));
        return $effective;
    }

    public static function alive(array $pending, $now = null)
    {
        return ($pending['expires_at'] ?? (($pending['time'] ?? 0) + 600)) > ($now ?? time());
    }

    private static function prune(array $state)
    {
        $now = time();
        $state['attempts'] = array_values(array_filter($state['attempts'] ?? [], function ($a) use ($now) {
            return $a['time'] > $now - 3600;
        }));
        $pending = $state['pending'] ?? [];
        foreach ($pending as $id => $reservation) {
            if (!self::alive($reservation, $now)) {
                self::write('status-' . (int)$id, ['binding' => $reservation['binding'],
                    'state' => 'failed', 'at' => gmdate('c')]);
                unset($pending[$id]);
            }
        }
        $state['pending'] = $pending;
        return $state;
    }

    public static function pending($domain)
    {
        $binding = Modules_Help4DiskUsage_Access::binding($domain);
        return self::locked(function () use ($domain, $binding) {
            $state = self::prune(self::read('state'));
            $pending = $state['pending'][$domain->getId()] ?? null;
            self::write('state', $state);
            return $pending && hash_equals($binding, $pending['binding']);
        });
    }

    public static function status($domain)
    {
        $status = self::read('status-' . $domain->getId());
        return $status && isset($status['binding']) &&
            hash_equals(Modules_Help4DiskUsage_Access::binding($domain), $status['binding']) ? $status : null;
    }

    public static function validateReservation(array $pending, $domain)
    {
        if (!self::alive($pending) || !isset($pending['binding'], $pending['policy_binding']) ||
            !hash_equals($pending['binding'], Modules_Help4DiskUsage_Access::binding($domain)) ||
            !hash_equals($pending['policy_binding'], self::effective($domain)['policy_binding'])) {
            throw new RuntimeException('Scan reservation or subscription policy changed');
        }
    }

    public static function reserve($domain, $actor, $admin)
    {
        Modules_Help4DiskUsage_Access::authorize($actor, $domain, $admin);
        $binding = Modules_Help4DiskUsage_Access::binding($domain);
        $policy = self::effective($domain);
        return self::locked(function () use ($domain, $actor, $admin, $binding, $policy) {
            $state = self::prune(self::read('state'));
            $now = time();
            $id = (string)$domain->getId();
            if (isset($state['pending'][$id]) && !hash_equals($binding, $state['pending'][$id]['binding'])) {
                unset($state['pending'][$id]);
            }
            if (isset($state['pending'][$id])) {
                throw new RuntimeException('A scan for this subscription is already queued or running');
            }
            if (count($state['pending']) >= $policy['queue_limit'] || count($state['attempts']) >= $policy['global_hourly']) {
                throw new RuntimeException('Server scan limit reached; try later');
            }
            $own = array_values(array_filter($state['attempts'], function ($a) use ($id, $binding) {
                return (string)$a['domain'] === $id && ($a['binding'] ?? $binding) === $binding;
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
                'actor' => $actor->getId(), 'admin' => $admin === true && $actor->isAdmin(),
                'seconds' => $policy['seconds'], 'policy_binding' => $policy['policy_binding'],
                // Allow the entire bounded queue to drain at the hard maximum runtime.
                'expires_at' => $now + $policy['queue_limit'] * 135 + 600];
            $state['attempts'][] = ['time' => $now, 'domain' => $id, 'actor' => $actor->getId(), 'binding' => $binding];
            self::write('status-' . (int)$id, ['binding' => $binding, 'state' => 'queued', 'at' => gmdate('c')]);
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
                self::write('status-' . (int)$id, ['binding' => $state['pending'][$id]['binding'],
                    'state' => $report === null ? 'failed' : 'complete', 'at' => gmdate('c')]);
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
