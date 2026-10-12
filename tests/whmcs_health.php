<?php
require __DIR__ . '/whmcs_native.php';

use Help4\DiskUsagePlesk\Native;
use Help4\DiskUsagePlesk\Transport;
use Help4\DiskUsagePlesk\Health;
use Help4\DiskUsagePlesk\View;
use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

$checks = 0;
$network = $GLOBALS['network_calls'];
$server = (array)Capsule::table('tblservers')->where('id', 1)->first();
$pin = str_repeat('e', 64);
$observation = ['schema' => 1, 'server_id' => 1, 'server_binding' => Transport::binding($server, $pin),
    'measured_at' => time(), 'collection_ok' => true, 'version' => '0.3.0', 'runtime_ok' => true,
    'storage_ok' => true, 'pending' => 0, 'active_scanners' => 0, 'failed_scans' => 0, 'last_success_at' => time()];
$state = ['installation_id' => $pin, 'observation' => $observation];
$neighbor = (array)Capsule::table('tblservers')->where('id', 2)->first();
$neighborPin = str_repeat('f', 64);
$neighborObservation = array_replace($observation, ['server_id' => 2,
    'server_binding' => Transport::binding($neighbor, $neighborPin), 'pending' => 2]);
Capsule::table(Native::SERVERS)->updateOrInsert(['server_id' => 2],
    ['data' => json_encode(['installation_id' => $neighborPin, 'observation' => $neighborObservation])]);
function healthState($data) {
    Capsule::table(Native::SERVERS)->where('server_id', 1)->update(['data' => is_string($data) ? $data : json_encode($data)]);
}
function firstHealth() {
    $page = Native::health();
    check(count($page['rows']) === 20 && $page['rows'][1]['server_id'] === 2, 'Bad row lost bounded neighboring server');
    check($page['rows'][1]['status'] === 'observed' && $page['rows'][1]['pending'] === 2, 'Bad row hid valid neighboring measurement');
    return $page['rows'][0];
}
healthState($state);
check(firstHealth()['status'] === 'observed', 'Valid cached health lost');

Capsule::$pdo->exec('INSERT INTO tbladmins VALUES (11,1,0)');
foreach (['role', 'access', 'disabled', 'logout', 'masquerade', 'actor'] as $change) {
    $injected = 0;
    Capsule::$afterGet = function ($table) use ($change, &$injected) {
        if ($table !== Native::SERVERS) { return; }
        Capsule::$afterGet = null;
        $injected++;
        if ($change === 'role') { Capsule::$pdo->exec('UPDATE tbladmins SET roleid=2 WHERE id=10'); }
        if ($change === 'access') { Capsule::$pdo->exec("UPDATE tbladdonmodules SET value='' WHERE setting='access'"); }
        if ($change === 'disabled') { Capsule::$pdo->exec('UPDATE tbladmins SET disabled=1 WHERE id=10'); }
        if ($change === 'logout') { CurrentUser::$admin = null; }
        if ($change === 'masquerade') { CurrentUser::$masquerading = true; }
        if ($change === 'actor') { CurrentUser::$admin = 11; }
    };
    try { denied(function () { Native::health(); }, 'Health returned after administrator change: ' . $change); }
    finally {
        Capsule::$afterGet = null;
        CurrentUser::$admin = 10;
        CurrentUser::$masquerading = false;
        Capsule::$pdo->exec('UPDATE tbladmins SET roleid=1,disabled=0 WHERE id=10');
        Capsule::$pdo->exec("UPDATE tbladdonmodules SET value='1' WHERE setting='access'");
    }
    check($injected === 1, 'Final access test never reached saved server-state read');
    check(firstHealth()['status'] === 'observed', 'Health did not recover after access restoration');
}

foreach (['json', 'oversize', 'pin', 'null_pin', 'endpoint'] as $invalid) {
    healthState($state);
    if ($invalid === 'json') { healthState('{private-state-not-json'); }
    if ($invalid === 'oversize') { healthState(str_repeat('x', 350001)); }
    if ($invalid === 'pin') { healthState(array_replace($state, ['installation_id' => 'private-invalid-pin'])); }
    if ($invalid === 'null_pin') { healthState(array_replace($state, ['installation_id' => null])); }
    if ($invalid === 'endpoint') { Capsule::$pdo->exec("UPDATE tblservers SET hostname='private invalid host' WHERE id=1"); }
    try {
        $row = firstHealth();
        check($row['status'] === 'unavailable' && $row['reason'] === 'configuration_unavailable', 'Malformed row fabricated health');
        foreach (['measured_at', 'version', 'pending', 'active_scanners', 'failed_scans', 'last_success_at'] as $field) {
            check($row[$field] === null, 'Unavailable row exposed cached field: ' . $field);
        }
        $html = View::health(Native::health(), 'synthetic-token', 1);
        check(strpos($html, 'unavailable') !== false && strpos($html, 'Not measured') !== false, 'Unavailable state missing from admin view');
        check(strpos($html, 'private-') === false && strpos($html, 'private invalid host') === false &&
            strpos($html, 'synthetic-key') === false, 'Configuration failure leaked private details');
        Capsule::$pdo->exec('UPDATE tblservers SET disabled=1 WHERE id=1');
        check(firstHealth()['status'] === 'disabled', 'Malformed configuration lost disabled status');
    } finally {
        Capsule::$pdo->exec("UPDATE tblservers SET disabled=0,hostname='panel.example.test' WHERE id=1");
        healthState($state);
    }
    check(firstHealth()['status'] === 'observed', 'Restored row did not recover cached observation');
}

healthState(['observation' => $observation]);
check(firstHealth()['status'] === 'unmeasured' && firstHealth()['pending'] === null, 'Unpinned connection reused cached health');
healthState($state);
$record = ['id' => 1, 'module' => 'plesk', 'enabled' => true, 'server_binding' => $observation['server_binding'],
    'configuration_available' => false];
check(Health::page([$record], [1 => $observation])['rows'][0]['status'] === 'unavailable', 'Unavailable flag reused measurement');
foreach ([null, 0, 'false', []] as $invalid) {
    denied(function () use ($record, $invalid) { Health::page([array_replace($record, ['configuration_available' => $invalid])], []); },
        'Invalid configuration availability accepted');
}
check(count(Native::health(2)['rows']) === 2, 'Second page changed');
check($GLOBALS['network_calls'] === $network, 'Passive health read performed outbound collection');
check(!Capsule::$afterGet, 'Health fixture leaked database hook');
echo "WHMCS health: $checks cached-row/access/unknown-state controls passed (SQLite/framework fixtures, not native WHMCS proof)\n";
