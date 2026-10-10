<?php
if (!defined('WHMCS')) { http_response_code(404); exit; }
foreach (['Scope', 'Health', 'Report', 'Transport', 'Native', 'View'] as $library) { require_once __DIR__ . '/lib/' . $library . '.php'; }
use Help4\DiskUsagePlesk\Native;
use Help4\DiskUsagePlesk\View;
use Help4\DiskUsagePlesk\Report;
use Help4\DiskUsagePlesk\Scope;

function help4_disk_usage_plesk_config()
{
    return ['name' => 'Plesk Disk Usage Audit', 'description' => 'Subscription disk reports and bounded Plesk extension health.',
        'version' => '0.3.0', 'author' => 'Help4 Network', 'language' => 'english', 'fields' => [
            'clientArea' => ['FriendlyName' => 'Customer Reports', 'Type' => 'yesno', 'Default' => '',
                'Description' => 'Enable only after native connection and isolation validation.'],
            'clientReadsHourly' => ['FriendlyName' => 'Reads Per User Per Hour', 'Type' => 'text', 'Default' => '30', 'Size' => '6'],
            'serverRequestsHourly' => ['FriendlyName' => 'Requests Per Server Per Hour', 'Type' => 'text', 'Default' => '120', 'Size' => '6'],
        ]];
}
function help4_disk_usage_plesk_activate()
{
    try { Native::migrate(); return ['status' => 'success', 'description' => 'Private integration tables prepared; customer reports disabled by default.']; }
    catch (Throwable $e) { return ['status' => 'error', 'description' => 'Activation failed; review WHMCS database permissions privately.']; }
}
function help4_disk_usage_plesk_upgrade($vars) { Native::migrate(); }
function help4_disk_usage_plesk_deactivate()
{
    WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'clientArea')->update(['value' => '']);
    return ['status' => 'success', 'description' => 'Addon deactivated. Private mappings and observations retained for reviewed reinstall/removal.'];
}
function help4_disk_usage_plesk_token()
{
    if (!function_exists('generate_token')) { throw new RuntimeException('Native token unavailable'); }
    return generate_token('plain');
}
function help4_disk_usage_plesk_post($admin)
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !function_exists('check_token')) { throw new RuntimeException('Invalid request'); }
    if (check_token($admin ? 'WHMCS.admin.default' : 'WHMCS.default') === false) { throw new RuntimeException('Invalid request'); }
}
function help4_disk_usage_plesk_output($vars)
{
    try {
        header('Cache-Control: no-store');
        Native::admin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            help4_disk_usage_plesk_post(true);
            $action = $_POST['action'] ?? '';
            if ($action === 'approve') { Native::approve($_POST['serviceid'] ?? null); }
            elseif ($action === 'connect') { Native::connect($_POST['serverid'] ?? null); }
            elseif ($action === 'check') { Native::checkServer($_POST['serverid'] ?? null); }
            elseif ($action === 'disconnect' && ($_POST['confirm'] ?? '') === 'disconnect') { Native::disconnect($_POST['serverid'] ?? null); }
            else { throw new RuntimeException('Invalid request'); }
            header('Location: addonmodules.php?module=' . Native::MODULE, true, 303);
            return;
        }
        $page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
        echo View::health(Native::health($page), help4_disk_usage_plesk_token(), $page);
    } catch (Throwable $e) { echo '<div class="alert alert-warning">Integration unavailable. Review connection, permissions and request limits.</div>' . View::footer(); }
}
function help4_disk_usage_plesk_clientarea($vars)
{
    $html = '';
    try {
        header('Cache-Control: no-store');
        Native::actor();
        $id = $_GET['serviceid'] ?? $_POST['serviceid'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            help4_disk_usage_plesk_post(false);
            if (($_POST['action'] ?? '') !== 'refresh') { throw new RuntimeException('Invalid request'); }
            Native::refresh($id);
            header('Location: ' . View::base($id), true, 303);
            return ['pagetitle' => 'Disk Usage Audit', 'templatefile' => 'clientarea', 'requirelogin' => true, 'vars' => ['audit_html' => '']];
        }
        if ($id === null) { $html = View::services(Native::services()); }
        else {
            $id = Scope::id($id);
            $action = $_GET['action'] ?? '';
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            if ($action === 'jump') {
                header('Location: ' . Native::jump($id, $_GET['path'] ?? null, $_GET['kind'] ?? null), true, 303);
                exit;
            }
            $report = Native::report($id);
            if ($action === 'export') {
                $csv = ($_GET['format'] ?? '') === 'csv';
                header('Content-Type: ' . ($csv ? 'text/csv' : 'application/json') . '; charset=UTF-8');
                header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: attachment; filename="disk-audit.' . ($csv ? 'csv' : 'json') . '"');
                echo $csv ? Report::csv($report, $report['policy_ttl']) : Report::json($report, $report['policy_ttl']);
                exit;
            }
            $html = View::report($id, $report, $_GET, help4_disk_usage_plesk_token());
        }
    } catch (Throwable $e) {
        $html = '<div class="alert alert-warning">Service unavailable. Contact your host about connection, mapping or scan status.</div>' . View::footer();
    }
    return ['pagetitle' => 'Disk Usage Audit', 'templatefile' => 'clientarea', 'requirelogin' => true, 'vars' => ['audit_html' => $html]];
}
