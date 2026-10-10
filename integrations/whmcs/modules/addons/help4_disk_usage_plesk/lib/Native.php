<?php
namespace Help4\DiskUsagePlesk;
use WHMCS\Database\Capsule;

final class Native
{
    const MODULE = 'help4_disk_usage_plesk';
    const SERVERS = 'mod_help4_du_plesk_servers';
    const BINDINGS = 'mod_help4_du_plesk_bindings';
    const LIMITS = 'mod_help4_du_plesk_limits';

    public static function migrate()
    {
        foreach ([self::SERVERS => 'server_id', self::BINDINGS => 'service_id', self::LIMITS => 'key'] as $name => $key) {
            if (!Capsule::schema()->hasTable($name)) {
                Capsule::schema()->create($name, function ($table) use ($key) {
                    if ($key === 'key') { $table->string($key, 96)->primary(); }
                    else { $table->unsignedInteger($key)->primary(); }
                    if ($key === 'service_id') { $table->unsignedInteger('server_id')->index(); }
                    $table->text('data');
                });
            }
        }
    }

    public static function setting($name, $default = null)
    {
        $row = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $name)->first();
        return $row ? $row->value : $default;
    }

    public static function actor()
    {
        $current = new \WHMCS\Authentication\CurrentUser();
        $user = $current->user();
        $client = $current->client();
        if (!$current->isAuthenticatedUser() || !$user || !$client || $current->isMasqueradingAdmin() ||
            self::setting('clientArea', '') !== 'on') { throw new \RuntimeException('Service unavailable'); }
        return ['authenticated' => true, 'user_id' => Scope::id($user->id), 'client_id' => Scope::id($client->id),
            'products_allowed' => $client->hasPermission('products') === true,
            'manage_products_allowed' => $client->hasPermission('manageproducts') === true, 'masquerading' => false];
    }

    public static function admin()
    {
        $current = new \WHMCS\Authentication\CurrentUser();
        $admin = $current->admin();
        if (!$current->isAuthenticatedAdmin() || !$admin || $current->isMasqueradingAdmin()) {
            throw new \RuntimeException('Administrator access required');
        }
        $row = Capsule::table('tbladmins')->where('id', Scope::id($admin->id))->first();
        $roles = explode(',', (string)self::setting('access', ''));
        if (!$row || !in_array($row->disabled, [0, '0'], true) ||
            !in_array((string)Scope::id($row->roleid), $roles, true)) { throw new \RuntimeException('Administrator access required'); }
        return Scope::id($admin->id);
    }

    public static function server($id)
    {
        $row = Capsule::table('tblservers')->where('id', Scope::id($id))->first();
        if (!$row || $row->type !== 'plesk' || !in_array($row->disabled, [0, '0'], true)) {
            throw new \RuntimeException('Server unavailable');
        }
        $server = (array)$row;
        $server['id'] = Scope::id($row->id);
        Transport::endpoint($server);
        return $server;
    }

    public static function service($id)
    {
        $row = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', Scope::id($id))->select('h.id', 'h.userid', 'h.server', 'h.username', 'h.domain',
                'h.domainstatus', 'p.servertype')->first();
        if (!$row || $row->domainstatus !== 'Active' || $row->servertype !== 'plesk') {
            throw new \RuntimeException('Service unavailable');
        }
        $server = self::server($row->server);
        return ['id' => Scope::id($row->id), 'client_id' => Scope::id($row->userid), 'server_id' => $server['id'],
            'status' => 'Active', 'module' => 'plesk', 'server_enabled' => true,
            'username' => $row->username, 'domain' => $row->domain];
    }

    private static function read($table, $key, $id)
    {
        $row = Capsule::table($table)->where($key, $id)->first();
        if (!$row) { return []; }
        if (!is_string($row->data) || strlen($row->data) > 350000) { throw new \RuntimeException('Integration unavailable'); }
        $data = json_decode($row->data, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Integration unavailable'); }
        return $data;
    }

    private static function write($table, $key, $id, array $data)
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR, 16);
        if (strlen($json) > 350000) { throw new \RuntimeException('Integration unavailable'); }
        $fields = ['data' => $json];
        if ($table === self::BINDINGS) { $fields['server_id'] = Scope::id($data['server_id']); }
        Capsule::table($table)->updateOrInsert([$key => $id], $fields);
    }

    private static function lockedServerState($id)
    {
        // Serialize pin commits and revocations, including the first connection.
        Capsule::table(self::SERVERS)->insertOrIgnore(['server_id' => $id, 'data' => '[]']);
        $row = Capsule::table(self::SERVERS)->where('server_id', $id)->lockForUpdate()->first();
        if (!$row) { throw new \RuntimeException('Server unavailable'); }
        return self::read(self::SERVERS, 'server_id', $id);
    }

    public static function entity($id)
    {
        return Capsule::connection()->transaction(function () use ($id) {
            $service = self::service($id);
            $server = self::server($service['server_id']);
            $state = self::read(self::SERVERS, 'server_id', $server['id']);
            $service['server_binding'] = Transport::binding($server, $state['installation_id'] ?? null);
            return ['service' => $service, 'binding' => self::read(self::BINDINGS, 'service_id', $service['id'])];
        });
    }

    private static function credentials(array $server)
    {
        if (($server['accesshash'] ?? '') === '') {
            if (!function_exists('decrypt')) { throw new \RuntimeException('Server credentials unavailable'); }
            $server['password'] = decrypt($server['password'] ?? '');
        }
        return $server;
    }

    private static function remote(array $scope, $operation)
    {
        $server = self::server($scope['server_id']);
        $state = self::read(self::SERVERS, 'server_id', $server['id']);
        if (Transport::binding($server, $state['installation_id'] ?? null) !== $scope['server_binding']) {
            throw new \RuntimeException('Service unavailable');
        }
        self::admit('client:' . $scope['user_id'], self::cap('clientReadsHourly', 30, 120), 0);
        return self::leased($server['id'], function () use ($server, $state, $scope, $operation) {
            if ($operation !== 'report') {
                return Transport::scoped(self::credentials($server), $state['installation_id'], $scope, $operation);
            }
            $expected = array_intersect_key($scope, array_flip(['subscription_guid', 'owner_guid', 'identity_binding', 'domain', 'username']));
            $response = Transport::call(self::credentials($server), $state['installation_id'], 'report', ['scope' => $expected]);
            foreach ($expected as $key => $value) {
                if (($response['identity'][$key] ?? null) !== $value) { throw new \RuntimeException('Service unavailable'); }
            }
            return array_intersect_key($scope, array_flip(['server_id', 'server_binding', 'subscription_guid',
                'owner_guid', 'identity_binding', 'revision'])) + ['identity_checked_at' => $response['identity_checked_at'],
                'payload' => ['report' => $response['payload'], 'ttl' => $response['ttl'] ?? null,
                    'domain_id' => Scope::id($response['identity']['domain_id'] ?? null), 'endpoint' => Transport::endpoint($server)]];
        });
    }

    public static function report($id)
    {
        $bundle = self::bundle($id);
        return Report::normalize($bundle['report'], null, $bundle['ttl']) + ['policy_ttl' => $bundle['ttl']];
    }

    private static function bundle($id)
    {
        return Scope::read($id, [self::class, 'actor'], [self::class, 'entity'], function ($scope) {
            return self::remote($scope, 'report');
        });
    }

    public static function refresh($id)
    {
        return Scope::read($id, [self::class, 'actor'], [self::class, 'entity'], function ($scope) {
            return self::remote($scope, 'refresh');
        });
    }

    public static function approve($id)
    {
        $admin = self::admin();
        $before = self::service($id);
        $server = self::server($before['server_id']);
        $initial = self::read(self::SERVERS, 'server_id', $server['id']);
        if (!isset($initial['installation_id'])) { throw new \RuntimeException('Connect the server first'); }
        self::admit('admin:' . $admin, 30, 5);
        $response = self::leased($server['id'], function () use ($server, $before, $initial) {
            return Transport::call(self::credentials($server), $initial['installation_id'], 'identity', ['domain' => $before['domain']]);
        });
        $identity = $response['identity'] ?? [];
        foreach (['username', 'domain'] as $field) {
            if (($identity[$field] ?? null) !== $before[$field]) { throw new \RuntimeException('Service unavailable'); }
        }
        self::assertIdentity($identity);
        Capsule::connection()->transaction(function () use ($admin, $before, $server, $identity, $response, $initial) {
            $state = self::lockedServerState($server['id']);
            if (self::admin() !== $admin || self::service($before['id']) !== $before ||
                self::endpointSnapshot(self::server($server['id'])) !== self::endpointSnapshot($server)) {
                throw new \RuntimeException('Service unavailable');
            }
            if ($state !== $initial) { throw new \RuntimeException('Server mapping changed'); }
            if (isset($state['installation_id']) && $state['installation_id'] !== $response['installation_id']) {
                throw new \RuntimeException('Server installation changed; remove the old mapping after review');
            }
            $state['installation_id'] = $response['installation_id'];
            self::write(self::SERVERS, 'server_id', $server['id'], $state);
            $binding = $before + $identity;
            $binding['service_id'] = $before['id'];
            $binding['server_binding'] = Transport::binding($server, $state['installation_id']);
            $binding['revision'] = bin2hex(random_bytes(32));
            self::write(self::BINDINGS, 'service_id', $before['id'], $binding);
        });
    }

    public static function connect($id)
    {
        $admin = self::admin();
        $server = self::server($id);
        $state = self::read(self::SERVERS, 'server_id', $server['id']);
        self::admit('admin:' . $admin, 30, 5);
        $response = self::leased($server['id'], function () use ($server, $state) {
            return Transport::call(self::credentials($server), $state['installation_id'] ?? null, 'hello');
        });
        Capsule::connection()->transaction(function () use ($admin, $id, $server, $state, $response) {
            $current = self::lockedServerState($server['id']);
            if (self::admin() !== $admin || self::endpointSnapshot(self::server($id)) !== self::endpointSnapshot($server) ||
                $current !== $state) { throw new \RuntimeException('Server unavailable'); }
            $state['installation_id'] = $response['installation_id'];
            self::write(self::SERVERS, 'server_id', $server['id'], $state);
        });
    }

    public static function disconnect($id)
    {
        self::admin();
        $id = Scope::id($id);
        Capsule::connection()->transaction(function () use ($id) {
            self::lockedServerState($id);
            self::admin();
            Capsule::table(self::BINDINGS)->where('server_id', $id)->delete();
            self::write(self::SERVERS, 'server_id', $id, ['revoked' => bin2hex(random_bytes(32))]);
        });
    }

    public static function checkServer($id)
    {
        $admin = self::admin();
        $server = self::server($id);
        $state = self::read(self::SERVERS, 'server_id', $server['id']);
        $binding = Transport::binding($server, $state['installation_id'] ?? null);
        self::admit('admin:' . $admin, 30, 5);
        try {
            $response = self::leased($server['id'], function () use ($server, $state) {
                return Transport::call(self::credentials($server), $state['installation_id'], 'health');
            });
            $observation = array_intersect_key($response['payload'], array_flip(['collection_ok', 'version',
                'runtime_ok', 'storage_ok', 'pending', 'active_scanners', 'failed_scans', 'last_success_at']));
            $observation += ['schema' => 1, 'server_id' => $server['id'], 'server_binding' => $binding,
                'measured_at' => $response['identity_checked_at']];
        } catch (\Throwable $e) {
            $observation = ['schema' => 1, 'server_id' => $server['id'], 'server_binding' => $binding,
                'measured_at' => time(), 'collection_ok' => false];
        }
        Capsule::connection()->transaction(function () use ($admin, $id, $server, $state, $observation) {
            $current = self::lockedServerState($server['id']);
            if (self::admin() !== $admin || self::endpointSnapshot(self::server($id)) !== self::endpointSnapshot($server) ||
                $current !== $state) { throw new \RuntimeException('Server unavailable'); }
            $state['observation'] = $observation;
            self::write(self::SERVERS, 'server_id', $server['id'], $state);
        });
    }

    public static function health($page = 1)
    {
        self::admin();
        $page = max(1, min(10000, (int)$page));
        $servers = Capsule::table('tblservers')->where('type', 'plesk')->orderBy('id')
            ->offset(($page - 1) * 20)->limit(20)->get();
        $records = $observations = [];
        foreach ($servers as $server) {
            $state = self::read(self::SERVERS, 'server_id', Scope::id($server->id));
            $binding = isset($state['installation_id']) ? Transport::binding((array)$server, $state['installation_id']) : str_repeat('0', 64);
            $records[] = ['id' => Scope::id($server->id), 'module' => 'plesk',
                'enabled' => in_array($server->disabled, [0, '0'], true), 'server_binding' => $binding];
            $observations[$server->id] = $state['observation'] ?? null;
        }
        return Health::page($records, $observations);
    }

    private static function endpointSnapshot(array $server)
    {
        return [$server['id'], Transport::endpoint($server), $server['username'] ?? '',
            hash('sha256', ($server['accesshash'] ?? '') . "\0" . ($server['password'] ?? ''))];
    }

    public static function services()
    {
        $actor = self::actor();
        if (!$actor['products_allowed'] || !$actor['manage_products_allowed']) { throw new \RuntimeException('Service unavailable'); }
        return Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', $actor['client_id'])->where('h.domainstatus', 'Active')->where('p.servertype', 'plesk')
            ->orderBy('h.id')->limit(50)->select('h.id', 'h.domain')->get();
    }

    public static function jump($id, $path, $kind)
    {
        $bundle = self::bundle($id);
        Report::navigationIntent($bundle['report'], $path, $kind, $bundle['ttl']);
        return $bundle['endpoint'] . '/modules/help4-disk-usage/index.php/index/open?' .
            http_build_query(['domain' => $bundle['domain_id'], 'path' => $path, 'kind' => $kind], '', '&', PHP_QUERY_RFC3986);
    }

    private static function assertIdentity(array $identity)
    {
        Scope::id($identity['domain_id'] ?? null);
        foreach (['subscription_guid', 'owner_guid'] as $key) {
            if (!is_string($identity[$key] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $identity[$key]) ||
                $identity[$key] === '00000000-0000-0000-0000-000000000000') { throw new \RuntimeException('Service unavailable'); }
        }
        if (!is_string($identity['identity_binding'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $identity['identity_binding'])) {
            throw new \RuntimeException('Service unavailable');
        }
    }

    private static function admit($key, $maximum, $interval)
    {
        Capsule::connection()->transaction(function () use ($key, $maximum, $interval) {
            Capsule::table(self::LIMITS)->insertOrIgnore(['key' => $key, 'data' => '[]']);
            $row = Capsule::table(self::LIMITS)->where('key', $key)->lockForUpdate()->first();
            if (!$row) { throw new \RuntimeException('Integration unavailable'); }
            $attempts = json_decode($row->data, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($attempts) || count($attempts) > 240 || array_filter($attempts, function ($a) { return !is_int($a); })) {
                throw new \RuntimeException('Integration unavailable');
            }
            $attempts = array_values(array_filter($attempts, function ($at) { return $at > time() - 3600; }));
            if (count($attempts) >= $maximum || ($attempts && end($attempts) > time() - $interval)) {
                throw new \RuntimeException('Request limit reached; try later');
            }
            $attempts[] = time();
            self::write(self::LIMITS, 'key', $key, $attempts);
        });
    }

    private static function cap($name, $default, $maximum)
    {
        $value = self::setting($name, (string)$default);
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,2}$/D', $value) || (int)$value > $maximum) {
            throw new \RuntimeException('Invalid integration limit');
        }
        return (int)$value;
    }

    private static function leased($id, callable $callback)
    {
        $key = 'server:' . Scope::id($id);
        self::admit('server-attempts:' . $id, self::cap('serverRequestsHourly', 120, 240), 0);
        $token = bin2hex(random_bytes(16));
        Capsule::connection()->transaction(function () use ($key, $token) {
            Capsule::table(self::LIMITS)->insertOrIgnore(['key' => $key, 'data' => '{}']);
            $row = Capsule::table(self::LIMITS)->where('key', $key)->lockForUpdate()->first();
            $lease = json_decode($row->data, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($lease) || ($lease['until'] ?? 0) > time()) { throw new \RuntimeException('Server request in progress'); }
            self::write(self::LIMITS, 'key', $key, ['token' => $token, 'until' => time() + Transport::DEADLINE + 10]);
        });
        try { return $callback(); }
        finally {
            Capsule::connection()->transaction(function () use ($key, $token) {
                $row = Capsule::table(self::LIMITS)->where('key', $key)->lockForUpdate()->first();
                $lease = json_decode($row->data, true, 4, JSON_THROW_ON_ERROR);
                if (($lease['token'] ?? null) === $token) { self::write(self::LIMITS, 'key', $key, []); }
            });
        }
    }
}
