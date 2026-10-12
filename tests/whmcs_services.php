<?php
ob_start();
require __DIR__ . '/whmcs_native.php';
$baselineProof = ob_get_clean();

use Help4\DiskUsagePlesk\Native;
use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Authentication\Client;

$checks = 0;
$network = $GLOBALS['network_calls'];
function resetListingActor() {
    Capsule::$afterGet = null;
    CurrentUser::$user = 100;
    CurrentUser::$client = new Client();
    CurrentUser::$masquerading = false;
    Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'clientArea')->update(['value' => 'on']);
}
function mutateListingActor($change) {
    if ($change === 'products') { CurrentUser::$client->products = false; }
    if ($change === 'manage') { CurrentUser::$client->manage = false; }
    if ($change === 'logout') { CurrentUser::$user = null; }
    if ($change === 'client_missing') { CurrentUser::$client = null; }
    if ($change === 'masquerade') { CurrentUser::$masquerading = true; }
    if ($change === 'user') { CurrentUser::$user = 101; }
    if ($change === 'account') { CurrentUser::$client->id = 2; }
    if ($change === 'disabled') {
        Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'clientArea')->update(['value' => '']);
    }
}
function listingSnapshot() {
    $snapshot = [];
    foreach ([Native::SERVERS, Native::BINDINGS, Native::LIMITS] as $table) {
        $snapshot[$table] = Capsule::$pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}
resetListingActor();
$snapshot = listingSnapshot();
Capsule::$pdo->exec("INSERT INTO tblproducts VALUES (2,'cpanel')");
$insert = Capsule::$pdo->prepare('INSERT INTO tblhosting VALUES (?,?,?,?,?,?,?)');
foreach (['Suspended', 'Cancelled', 'Terminated', 'Pending'] as $index => $status) {
    $insert->execute([$index + 3, 1, 1, 1, 'synthetic-private-user', 'hidden-' . $index . '.example.test', $status]);
}
$insert->execute([7, 1, 1, 2, 'synthetic-private-user', 'other-panel.example.test', 'Active']);
for ($id = 100; $id < 160; $id++) {
    $insert->execute([$id, 1, 1, 1, 'synthetic-private-user', 'owned-' . $id . '.example.test', 'Active']);
}
$rows = Native::services();
check(count($rows) === 50, 'Service picker lost 50-row cap');
check(array_map(function ($row) { return (int)$row->id; }, $rows) === array_merge([1], range(100, 148)),
    'Service picker lost current-client/active/Plesk scoping or ID ordering');
foreach ($rows as $row) {
    check(array_keys((array)$row) === ['id', 'domain'], 'Service picker exposed private columns');
}
CurrentUser::$client->id = 2;
check(array_map(function ($row) { return (int)$row->id; }, Native::services()) === [2], 'Selected client account ignored');
resetListingActor();
foreach (['products', 'manage', 'logout', 'client_missing', 'masquerade', 'disabled'] as $change) {
    $queries = 0;
    Capsule::$afterGet = function ($table) use (&$queries) {
        if ($table === 'tblhosting as h') { $queries++; }
    };
    mutateListingActor($change);
    try { denied(function () { Native::services(); }, 'Unauthorized actor received a service picker'); }
    finally { resetListingActor(); }
    check($queries === 0, 'Unauthorized actor queried customer services');
}
foreach (['products', 'manage', 'logout', 'client_missing', 'masquerade', 'user', 'account', 'disabled'] as $change) {
    $injected = 0;
    $inject = function ($table) use ($change, &$injected) {
        if ($table !== 'tblhosting as h') { return; }
        Capsule::$afterGet = null;
        $injected++;
        mutateListingActor($change);
    };
    Capsule::$afterGet = $inject;
    try { denied(function () { Native::services(); }, 'Service picker returned after actor change: ' . $change); }
    finally { resetListingActor(); }
    check($injected === 1, 'Actor-change test missed the completed service query');
    $injected = 0;
    Capsule::$afterGet = $inject;
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = [];
    try { $page = help4_disk_usage_plesk_clientarea([]); }
    finally { resetListingActor(); }
    $html = $page['vars']['audit_html'];
    check($injected === 1 && $page['requirelogin'] === true, 'Client page did not reach the completed service query');
    check(strpos($html, 'Service unavailable') !== false && strpos($html, 'help4network.com') !== false,
        'Client page lost generic denial or attribution');
    foreach (['demo.example.test', 'owned-', 'foreign.example.test', 'hidden-', 'synthetic-private-user'] as $canary) {
        check(strpos($html, $canary) === false, 'Client page exposed a service after actor change');
    }
    check(count(Native::services()) === 50, 'Restored actor did not recover service picker');
}
Capsule::table('tblhosting')->where('userid', 1)->delete();
check(Native::services() === [], 'Empty selected account exposed neighboring services');
check($GLOBALS['network_calls'] === $network, 'Service picker performed outbound collection');
check(listingSnapshot() === $snapshot, 'Service picker mutated mappings, observations or rate counters');
check(!Capsule::$afterGet, 'Service picker fixture leaked database hook');
echo $baselineProof . "WHMCS services: $checks selected-account/post-query/page/cap controls passed (SQLite/framework fixtures, not native WHMCS proof)\n";
