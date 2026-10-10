<?php
class Modules_Help4DiskUsage_Remote
{
    const MAX_REQUEST = 8192;
    const MAX_RESPONSE = 350000;

    public static function configure($enabled)
    {
        if ($enabled) {
            $python = Modules_Help4DiskUsage_Store::policy()['python'];
            if (PHP_OS_FAMILY === 'Windows') { Modules_Help4DiskUsage_Permissions::check($python); }
            Modules_Help4DiskUsage_Runtime::check($python);
        }
        return Modules_Help4DiskUsage_Store::locked(function () use ($enabled) {
            $config = Modules_Help4DiskUsage_Store::read('bridge');
            $config = ['enabled' => $enabled === true,
                'installation_id' => $config['installation_id'] ?? bin2hex(random_bytes(32))];
            Modules_Help4DiskUsage_Store::write('bridge', $config);
            return $config;
        });
    }

    private static function admin()
    {
        if (!pm_Session::isExist() || !Modules_Help4DiskUsage_Access::admin()) {
            throw new RuntimeException('Integration unavailable');
        }
        return (int)pm_Session::getClient()->getId();
    }

    public static function call(array $params)
    {
        $actor = self::admin();
        if (array_keys($params) !== ['audit'] || !is_array($params['audit']) ||
            array_keys($params['audit']) !== ['request'] || !is_string($params['audit']['request']) ||
            strlen($params['audit']['request']) > self::MAX_REQUEST * 2) {
            throw new RuntimeException('Integration unavailable');
        }
        $raw = base64_decode($params['audit']['request'], true);
        if ($raw === false || strlen($raw) > self::MAX_REQUEST) { throw new RuntimeException('Integration unavailable'); }
        $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($request) || array_diff(array_keys($request),
            ['schema', 'operation', 'nonce', 'installation_id', 'domain', 'scope']) ||
            ($request['schema'] ?? null) !== 1 ||
            !in_array($request['operation'] ?? null, ['hello', 'identity', 'report', 'refresh', 'health'], true) ||
            !is_string($request['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['nonce'])) {
            throw new RuntimeException('Integration unavailable');
        }
        $config = Modules_Help4DiskUsage_Store::read('bridge');
        if (($config['enabled'] ?? null) !== true || !is_string($config['installation_id'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $config['installation_id']) ||
            ((!in_array($request['operation'], ['hello', 'identity'], true) || isset($request['installation_id'])) &&
             ($request['installation_id'] ?? null) !== $config['installation_id'])) {
            throw new RuntimeException('Integration unavailable');
        }
        $lock = Modules_Help4DiskUsage_Store::mutex('bridge.lock');
        if (!$lock) {
            throw new RuntimeException('Integration busy');
        }
        try {
            // Failed calls consume the same finite budget as successful calls.
            Modules_Help4DiskUsage_Store::locked(function () {
                $now = time();
                $state = Modules_Help4DiskUsage_Store::read('bridge-attempts');
                $attempts = array_values(array_filter($state['attempts'] ?? [], function ($at) use ($now) {
                    return is_int($at) && $at > $now - 3600;
                }));
                if (count($attempts) >= 600) { throw new RuntimeException('Integration limit reached'); }
                $attempts[] = $now;
                Modules_Help4DiskUsage_Store::write('bridge-attempts', ['attempts' => $attempts]);
            });
            $response = ['schema' => 1, 'nonce' => $request['nonce'],
                'installation_id' => $config['installation_id'], 'identity_checked_at' => time()];
            if ($request['operation'] === 'hello') {
                $response['payload'] = [];
            } elseif ($request['operation'] === 'health') {
                $response['payload'] = self::health();
            } else {
                $domain = $request['operation'] === 'identity' ? self::byName($request['domain'] ?? null)
                    : pm_Domain::getByGuid(self::guid($request['scope']['subscription_guid'] ?? null));
                $before = self::identity($domain);
                if ($request['operation'] !== 'identity') { self::matches($before, $request['scope'] ?? []); }
                if ($request['operation'] === 'refresh') {
                    $token = Modules_Help4DiskUsage_Store::reserve($domain, $domain->getClient(), false);
                    try {
                        $task = new Modules_Help4DiskUsage_Task_Scan();
                        $task->setParams(['domain' => $domain->getId(), 'token' => $token]);
                        (new pm_LongTask_Manager())->start($task, $domain);
                    } catch (Throwable $e) {
                        Modules_Help4DiskUsage_Store::finish($domain->getId(), $token);
                        throw $e;
                    }
                    $response['payload'] = ['queued' => true];
                } elseif ($request['operation'] === 'report') {
                    $report = Modules_Help4DiskUsage_Store::report($domain);
                    if (!$report) { throw new RuntimeException('Integration unavailable'); }
                    $response['payload'] = Modules_Help4DiskUsage_Report::publicReport($report);
                    $response['ttl'] = Modules_Help4DiskUsage_Store::effective($domain)['ttl'];
                } else { $response['payload'] = []; }
                $after = self::identity(pm_Domain::getByGuid($before['subscription_guid']));
                if ($after !== $before) { throw new RuntimeException('Integration unavailable'); }
                $response['identity'] = $after;
                $response['identity_checked_at'] = time();
            }
            if (self::admin() !== $actor || Modules_Help4DiskUsage_Store::read('bridge') !== $config) {
                throw new RuntimeException('Integration unavailable');
            }
            $json = json_encode($response, JSON_THROW_ON_ERROR, 16);
            if (strlen($json) > self::MAX_RESPONSE) { throw new RuntimeException('Integration response limit reached'); }
            return ['audit' => ['response' => base64_encode($json)]];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function byName($name)
    {
        if (!is_string($name) || strlen($name) > 253 ||
            !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $name)) {
            throw new RuntimeException('Integration unavailable');
        }
        $domain = pm_Domain::getByName($name);
        if ($domain->getName() !== $name) { throw new RuntimeException('Integration unavailable'); }
        return $domain;
    }

    private static function guid($value)
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $value) ||
            $value === '00000000-0000-0000-0000-000000000000') { throw new RuntimeException('Integration unavailable'); }
        return $value;
    }

    private static function identity($domain)
    {
        if (!$domain->isActive() || !$domain->hasHosting() || !in_array($domain->getProperty('webspace_id'), [0, '0'], true)) {
            throw new RuntimeException('Integration unavailable');
        }
        $username = $domain->getSysUserLogin();
        if (!is_string($username) || $username === '' || strlen($username) > 253 || preg_match('/[\x00-\x20\x7f]/', $username)) {
            throw new RuntimeException('Integration unavailable');
        }
        return ['domain_id' => (int)$domain->getId(), 'subscription_guid' => self::guid($domain->getGuid()),
            'owner_guid' => self::guid($domain->getClient()->getProperty('guid')),
            'identity_binding' => Modules_Help4DiskUsage_Access::binding($domain),
            'domain' => $domain->getName(), 'username' => $username];
    }

    private static function matches(array $identity, array $scope)
    {
        if (array_diff(array_keys($scope), ['subscription_guid', 'owner_guid', 'identity_binding', 'domain', 'username'])) {
            throw new RuntimeException('Integration unavailable');
        }
        foreach (['subscription_guid', 'owner_guid', 'identity_binding', 'domain', 'username'] as $key) {
            if (($scope[$key] ?? null) !== $identity[$key]) { throw new RuntimeException('Integration unavailable'); }
        }
    }

    private static function health()
    {
        $summary = Modules_Help4DiskUsage_Store::read('health');
        $state = Modules_Help4DiskUsage_Store::read('state');
        $pending = array_filter($state['pending'] ?? [], [Modules_Help4DiskUsage_Store::class, 'alive']);
        $lock = Modules_Help4DiskUsage_Store::mutex('scanner.lock');
        $idle = $lock !== null;
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        $python = Modules_Help4DiskUsage_Store::policy()['python'];
        $storage = self::storage($python);
        $runtime = false;
        try {
            if (PHP_OS_FAMILY === 'Windows' && !$storage) { throw new RuntimeException('Windows preflight failed'); }
            Modules_Help4DiskUsage_Runtime::check($python); $runtime = true;
        }
        catch (Throwable $e) { }
        // Missing history or native storage evidence must not become healthy-zero status.
        return ['collection_ok' => isset($summary['failed_scans']) && count($pending) <= 32,
            'version' => pm_Extension::getById('help4-disk-usage')->getVersion(),
            'runtime_ok' => $runtime, 'storage_ok' => $storage, 'pending' => count($pending),
            'active_scanners' => $idle ? 0 : 1, 'failed_scans' => $summary['failed_scans'] ?? null,
            'last_success_at' => $summary['last_success_at'] ?? null];
    }

    private static function storage($python)
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') { Modules_Help4DiskUsage_Permissions::check($python); return true; }
            if (!function_exists('posix_getpwnam')) { return false; }
            $panel = posix_getpwnam('psaadm');
            if (!$panel) { return false; }
            $dir = Modules_Help4DiskUsage_Store::directory();
            $until = microtime(true) + 2;
            $count = 0;
            $paths = (function () use ($dir) {
                yield $dir;
                foreach (new DirectoryIterator($dir) as $entry) {
                    if (!$entry->isDot()) { yield $entry->getPathname(); }
                }
            })();
            foreach ($paths as $path) {
                if (++$count > 512 || microtime(true) > $until || is_link($path)) { return false; }
                $stat = lstat($path);
                if (!$stat || !in_array($stat['uid'], [0, $panel['uid']], true) || ($stat['mode'] & 0077) !== 0 ||
                    ($path === $dir ? ($stat['mode'] & 0170000) !== 0040000 : ($stat['mode'] & 0170000) !== 0100000)) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) { return false; }
    }
}
