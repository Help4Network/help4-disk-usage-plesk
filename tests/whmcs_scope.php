<?php
require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Scope.php';
require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Health.php';
use Help4\DiskUsagePlesk\Scope;
use Help4\DiskUsagePlesk\Health;
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function denied($action) {
    $message = '';
    try { $action(); } catch (Throwable $e) { $message = $e->getMessage(); }
    check($message === 'Service unavailable', 'Denial leaked identity, state or reader error');
}
function actor() { return ['authenticated' => true, 'products_allowed' => true, 'manage_products_allowed' => true, 'masquerading' => false,
    'user_id' => 100, 'client_id' => 10]; }
function entity() {
    $service = ['id' => 1, 'client_id' => 10, 'server_id' => 2, 'status' => 'Active', 'module' => 'plesk',
        'server_enabled' => true, 'username' => 'demo', 'domain' => 'demo.example.test', 'server_binding' => str_repeat('a', 64)];
    $binding = ['service_id' => 1, 'client_id' => 10, 'server_id' => 2, 'username' => 'demo',
        'domain' => 'demo.example.test', 'server_binding' => str_repeat('a', 64), 'revision' => str_repeat('b', 64),
        'subscription_guid' => '12345678-1234-1234-1234-123456789abc',
        'owner_guid' => '22345678-1234-1234-1234-123456789abc', 'identity_binding' => str_repeat('c', 64)];
    return ['service' => $service, 'binding' => $binding];
}
function envelope($scope) {
    return array_intersect_key($scope, array_flip(['server_id', 'server_binding', 'subscription_guid',
        'owner_guid', 'identity_binding', 'revision'])) + ['identity_checked_at' => time(),
        'payload' => ['summary' => ['logical_bytes' => 1024], 'files' => [['path' => 'httpdocs/cache.tmp']]]];
}
$actors = 0; $entities = 0; $reads = 0;
$result = Scope::read('1', function () use (&$actors) { $actors++; return actor(); },
    function ($id) use (&$entities) { check($id === 1, 'Invalid service lookup'); $entities++; return entity(); },
    function ($scope) use (&$reads) { $reads++; check($scope['user_id'] === 100 && $scope['client_id'] === 10,
        'User identity incorrectly equated to selected client'); return envelope($scope) + ['private_extra' => '/not-returned']; });
check($actors === 2 && $entities === 2 && $reads === 1, 'Current scope not rechecked around read');
check($result === envelope([])['payload'], 'Unexpected envelope fields escaped');
$never = function () { throw new RuntimeException('Read must never run'); };
foreach ([['authenticated', false], ['products_allowed', false], ['manage_products_allowed', false],
    ['masquerading', true], ['user_id', null], ['client_id', null], ['client_id', 11]] as [$key, $value]) {
    $read = false;
    denied(function () use ($key, $value, &$read) { Scope::read(1, function () use ($key, $value) {
        return array_replace(actor(), [$key => $value]); }, 'entity', function () use (&$read) { $read = true; }); });
    check(!$read, 'Foreign/unauthorized actor reached private data');
}
foreach ([0, -1, 1.0, true, '01', '1x', '2147483648', null, []] as $id) {
    denied(function () use ($id, $never) { Scope::read($id, $never, $never, $never); });
}
foreach (['client_id' => 11, 'status' => 'Suspended', 'module' => 'cpanel', 'server_enabled' => false,
    'server_id' => 3, 'username' => 'foreign', 'domain' => 'foreign.example.test', 'server_binding' => str_repeat('d', 64)] as $key => $value) {
    $read = false;
    denied(function () use ($key, $value, &$read) { Scope::read(1, 'actor', function () use ($key, $value) {
        $row = entity(); $row['service'][$key] = $value; return $row;
    }, function () use (&$read) { $read = true; }); });
    check(!$read, 'Stale binding or ineligible service reached private data');
}
foreach (['subscription_guid' => '', 'owner_guid' => '00000000-0000-0000-0000-000000000000',
    'identity_binding' => 'bad', 'revision' => '', 'server_binding' => str_repeat('d', 64), 'service_id' => 99] as $key => $value) {
    denied(function () use ($key, $value, $never) { Scope::read(1, 'actor', function () use ($key, $value) {
        $row = entity(); $row['binding'][$key] = $value; return $row;
    }, $never); });
}
foreach (['server_id' => 3, 'server_binding' => str_repeat('d', 64), 'subscription_guid' => '32345678-1234-1234-1234-123456789abc',
    'owner_guid' => '32345678-1234-1234-1234-123456789abc', 'identity_binding' => str_repeat('d', 64),
    'revision' => str_repeat('d', 64), 'identity_checked_at' => time() - 31] as $key => $value) {
    denied(function () use ($key, $value) { Scope::read(1, 'actor', 'entity', function ($scope) use ($key, $value) {
        return array_replace(envelope($scope), [$key => $value]);
    }); });
}
foreach (['client_id', 'status', 'username', 'domain', 'server_id', 'server_binding'] as $key) {
    $changed = false;
    denied(function () use ($key, &$changed) { Scope::read(1, 'actor', function () use ($key, &$changed) {
        $row = entity(); if ($changed) { $row['service'][$key] = $key === 'client_id' ? 11 : null; } return $row;
    }, function ($scope) use (&$changed) { $changed = true; return envelope($scope); }); });
}
foreach (['revision', 'subscription_guid', 'owner_guid', 'identity_binding'] as $key) {
    $changed = false;
    denied(function () use ($key, &$changed) { Scope::read(1, 'actor', function () use ($key, &$changed) {
        $row = entity(); if ($changed) { $row['binding'][$key] = null; } return $row;
    }, function ($scope) use (&$changed) { $changed = true; return envelope($scope); }); });
}
foreach (['client_id', 'user_id', 'products_allowed', 'manage_products_allowed', 'masquerading'] as $key) {
    $changed = false;
    denied(function () use ($key, &$changed) { Scope::read(1, function () use ($key, &$changed) {
        $value = actor(); if ($changed) { $value[$key] = null; } return $value;
    }, 'entity', function ($scope) use (&$changed) { $changed = true; return envelope($scope); }); });
}
foreach (['mapping', 'user', 'account', 'server', 'subscription', 'home'] as $change) {
    $changed = false;
    denied(function () use ($change, &$changed) { Scope::read(1, function () use ($change, &$changed) {
        $value = actor();
        if ($changed && $change === 'user') { $value['user_id'] = 101; }
        if ($changed && $change === 'account') { $value['client_id'] = 11; }
        return $value;
    }, function () use ($change, &$changed) {
        $row = entity();
        if ($changed) {
            if ($change === 'account') { $row['service']['client_id'] = $row['binding']['client_id'] = 11; }
            if ($change === 'server') { $row['service']['server_id'] = $row['binding']['server_id'] = 3; }
            if ($change === 'mapping') { $row['binding']['revision'] = str_repeat('d', 64); }
            if ($change === 'subscription') { $row['binding']['subscription_guid'] = '32345678-1234-1234-1234-123456789abc'; }
            if ($change === 'home') { $row['binding']['identity_binding'] = str_repeat('d', 64); }
        }
        return $row;
    }, function ($scope) use (&$changed) { $changed = true; return envelope($scope); }); });
}
denied(function () { Scope::read(1, 'actor', 'entity', function () { throw new RuntimeException('/foreign/private/account'); }); });
denied(function () { Scope::read(1, 'actor', 'entity', function (&$scope) {
    $scope['server_id'] = 3;
    return envelope($scope);
}); });
denied(function () { Scope::read(1, 'actor', 'entity', function ($scope) {
    $value = envelope($scope); $value['payload'] = ['large' => str_repeat('x', Scope::MAX_PAYLOAD_BYTES)]; return $value;
}); });
denied(function () { Scope::read(1, 'actor', 'entity', function ($scope) {
    $value = envelope($scope); $nested = []; for ($i = 0; $i < 17; $i++) { $nested = [$nested]; }
    $value['payload'] = $nested; return $value;
}); });

$now = 2000000000;
$server = ['id' => 2, 'module' => 'plesk', 'enabled' => true, 'server_binding' => str_repeat('a', 64)];
$observation = ['schema' => 1, 'server_id' => 2, 'server_binding' => str_repeat('a', 64),
    'measured_at' => $now, 'collection_ok' => true, 'version' => '0.3.0', 'runtime_ok' => true,
    'storage_ok' => true, 'pending' => 0, 'active_scanners' => 0, 'failed_scans' => 0, 'last_success_at' => $now - 60];
$row = Health::page([$server], [2 => $observation + ['customer' => 'not-returned', 'home' => '/not-returned']], $now);
check($row['rows'][0]['status'] === 'observed' && $row['native_panel_validated'] === false, 'Health incorrectly certifies native panel');
check(!array_key_exists('customer', $row['rows'][0]) && !array_key_exists('home', $row['rows'][0]), 'Health exposes customer metadata');
check(Health::page([$server], [], $now)['rows'][0]['pending'] === null, 'Missing measurement became healthy zero');
foreach (array_keys($observation) as $key) {
    $raw = $observation; unset($raw[$key]);
    $missing = Health::page([$server], [2 => $raw], $now)['rows'][0];
    check($missing['status'] === 'unmeasured' && $missing['pending'] === null, 'Missing field became a successful measurement');
}
foreach ([['measured_at', $now - 301, 'stale'], ['collection_ok', false, 'degraded'],
    ['server_binding', str_repeat('d', 64), 'unmeasured'], ['server_id', 3, 'unmeasured'],
    ['measured_at', $now + 6, 'unmeasured'], ['pending', 33, 'unmeasured'], ['active_scanners', 2, 'unmeasured'],
    ['pending', '0', 'unmeasured'], ['version', '<script>', 'unmeasured'], ['storage_ok', null, 'unmeasured'],
    ['last_success_at', null, 'no_report'], ['last_success_at', $now - 86401, 'stale_report'],
    ['runtime_ok', false, 'degraded'], ['storage_ok', false, 'degraded'], ['failed_scans', 1, 'degraded']] as [$key, $value, $status]) {
    $test = Health::page([$server], [2 => array_replace($observation, [$key => $value])], $now)['rows'][0];
    check($test['status'] === $status, 'Health lost failure/freshness state: ' . $key);
    if (in_array($status, ['stale', 'unmeasured'], true)) { check($test['pending'] === null, 'Stale/invalid snapshot exposed healthy zero'); }
}
check(Health::page([array_replace($server, ['enabled' => false])], [2 => $observation], $now)['rows'][0]['status'] === 'disabled', 'Disabled server used cached health');
foreach ([array_fill(0, 21, $server), [$server, $server], [array_replace($server, ['module' => 'cpanel'])]] as $batch) {
    $failed = false; try { Health::page($batch, [], $now); } catch (Throwable $e) { $failed = true; }
    check($failed, 'Invalid/unbounded health batch accepted');
}
echo "Plesk WHMCS scope/transfer/payload and bounded health foundation: $checks checks passed\n";
