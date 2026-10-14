<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Store.php';
require __DIR__ . '/../extension/plib/library/Report.php';

class IndexViewerFixture {
    public static $stage = '', $change = '', $injections = 0, $inventory = 0, $plans = 0;
    public static function inject($stage) {
        if (self::$stage !== $stage) { return; }
        self::$stage = ''; self::$injections++;
        $before = pm_Session::$client;
        if (self::$change === 'logout') { pm_Session::$client = null; }
        elseif (self::$change === 'admin') { $before->admin = !$before->admin; }
        elseif (self::$change === 'impersonation') { pm_Session::$impersonated = !pm_Session::$impersonated; }
        elseif (self::$change === 'invalid-id') { $before->id = []; }
        else {
            $client = new IndexViewerClient();
            $client->id = self::$change === 'switch' ? 2 : $before->id;
            $client->admin = $before->admin; $client->allowed = $before->allowed;
            pm_Session::$client = $client;
        }
    }
}
class IndexViewerClient {
    public $id = 1, $admin = false, $allowed = [1, 3];
    public function getId() { return $this->id; }
    public function isAdmin() { return $this->admin; }
    public function hasAccessToDomain($id) { return in_array($id, $this->allowed, true); }
}
class pm_Session {
    public static $client, $impersonated = false;
    public static function getClient() { return self::$client; }
    public static function isImpersonated() { return self::$impersonated; }
}
class pm_Domain {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function getId() { return $this->id; }
    public function getName() { return 'private-subscription-' . $this->id . '.example.test'; }
    public function getGuid() { return 'synthetic-guid-' . $this->id; }
    public function getHomePath() { return pm_Context::$directory . DIRECTORY_SEPARATOR . 'home-' . $this->id; }
    public function getClient() { $client = new IndexViewerClient(); $client->id = 10 + $this->id; return $client; }
    public function getPlanItems() { IndexViewerFixture::$plans++; IndexViewerFixture::inject('plan'); return ['audit_default']; }
    public static function getByDomainId($id) { return new self($id); }
    public static function getAllDomains($include) {
        if ($include !== true) { throw new RuntimeException('Changed inventory selector'); }
        IndexViewerFixture::$inventory++;
        return array_map(function ($id) { return new self($id); }, [1, 2, 3]);
    }
}
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
    public static function getBaseUrl() { return '/modules/help4-disk-usage/'; }
    public static function getActionUrl($controller, $action) { return self::getBaseUrl() . $controller . '/' . $action; }
}
#[AllowDynamicProperties]
class IndexViewerView {
    public function render() {
        ob_start();
        try { require __DIR__ . '/../extension/plib/views/scripts/index/index.phtml'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
class pm_Controller_Action {
    public $view, $values = [];
    public function __construct() { $this->view = new IndexViewerView(); $this->view->csrf = 'synthetic-csrf'; }
    public function getParam($name, $default = null) {
        $value = $this->values[$name] ?? $default;
        IndexViewerFixture::inject($name);
        return $value;
    }
}
require __DIR__ . '/../extension/plib/controllers/IndexController.php';

$checks = 0;
function checkIndexViewer($value, $message) {
    if (!$value) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function resetIndexViewer($admin = false, $impersonated = false, $id = 1, $empty = false) {
    pm_Session::$client = new IndexViewerClient(); pm_Session::$client->id = $id;
    pm_Session::$client->admin = $admin; pm_Session::$impersonated = $impersonated;
    if ($empty) { pm_Session::$client->allowed = []; }
    IndexViewerFixture::$stage = ''; IndexViewerFixture::$change = '';
    IndexViewerFixture::$injections = IndexViewerFixture::$inventory = IndexViewerFixture::$plans = 0;
    return new IndexController();
}
function indexPrivateHashes() {
    $hashes = [];
    foreach (['policy', 'state', 'bridge', 'rotation', 'report-1', 'report-2', 'report-3', 'status-1'] as $name) {
        $hashes[$name] = hash_file('sha256', Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . $name . '.json');
    }
    return $hashes;
}
function runIndexViewer($controller) {
    $error = null; $streams = count(get_resources('stream')); $hashes = indexPrivateHashes();
    try { $controller->indexAction(); } catch (Throwable $e) { $error = $e->getMessage(); }
    checkIndexViewer(count(get_resources('stream')) === $streams, 'Audit page leaked streams');
    checkIndexViewer(indexPrivateHashes() === $hashes, 'Audit page changed synthetic report/policy/queue/rate/bridge/rotation state');
    return $error;
}
function checkIndexViewerDenied($controller, $error) {
    checkIndexViewer($error === 'Subscription unavailable', 'Changed viewer received prepared audit page');
    checkIndexViewer($controller->view->domains === [] && $controller->view->admin === false &&
        $controller->view->report === null && $controller->view->domain === null &&
        $controller->view->scanStatus === null && $controller->view->notice === '', 'Private audit view retained data after denial');
    foreach (['ttl', 'pending', 'search', 'sort', 'result'] as $field) {
        checkIndexViewer(!property_exists($controller->view, $field), 'Prepared audit field retained after denial: ' . $field);
    }
    $html = $controller->view->render();
    foreach (['private-subscription-', 'private-offender-', 'private-status-', 'Scan settings', 'Software updates'] as $canary) {
        checkIndexViewer(strpos($html, $canary) === false, 'Cleared template retained private content');
    }
    checkIndexViewer(strpos($html, 'https://help4network.com') !== false, 'Credit missing from cleared template');
}

pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-index-viewer-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    Modules_Help4DiskUsage_Store::write('policy', ['title' => 'Disk Usage Audit']);
    Modules_Help4DiskUsage_Store::write('state', ['pending' => [], 'attempts' => []]);
    Modules_Help4DiskUsage_Store::write('bridge', ['enabled' => false]);
    Modules_Help4DiskUsage_Store::write('rotation', ['attempted_at' => 1]);
    foreach ([1, 2, 3] as $id) {
        $domain = new pm_Domain($id);
        Modules_Help4DiskUsage_Store::write('report-' . $id, [
            'binding' => Modules_Help4DiskUsage_Access::binding($domain), 'scanned_at' => gmdate('c'),
            'duration_seconds' => 1, 'complete' => true, 'errors' => 0, 'skipped' => 0,
            'bytes' => 42, 'entries' => 1, 'files' => 1, 'growth_bytes' => null, 'categories' => ['other' => 42],
            'largest_files' => [['path' => 'private-offender-' . $id . '.log', 'kind' => 'file', 'bytes' => 42,
                'hint' => 'Review synthetic file']], 'stale_files' => [], 'largest_trees' => [], 'entry_trees' => []]);
    }
    Modules_Help4DiskUsage_Store::write('status-1', ['binding' => Modules_Help4DiskUsage_Access::binding(new pm_Domain(1)),
        'state' => 'failed', 'at' => 'private-status-1']);
    foreach ([[false, false], [false, true], [true, false], [true, true]] as [$admin, $impersonated]) {
        $controller = resetIndexViewer($admin, $impersonated);
        checkIndexViewer(runIndexViewer($controller) === null, 'Stable audit viewer rejected');
        $ids = array_map(function ($domain) { return $domain->getId(); }, $controller->view->domains);
        checkIndexViewer($ids === ($admin && !$impersonated ? [1, 2, 3] : [1, 3]), 'Stable audit subscription scope changed');
        checkIndexViewer($controller->view->admin === ($admin && !$impersonated), 'Mixed administrator decision');
        checkIndexViewer($controller->view->result['items'][0]['path'] === 'private-offender-1.log', 'Valid retained report missing');
        checkIndexViewer(IndexViewerFixture::$inventory === 1, 'Audit page repeated inventory query');
        $html = $controller->view->render();
        checkIndexViewer((strpos($html, 'private-subscription-2.example.test') !== false) === ($admin && !$impersonated),
            'Rendered picker scope changed');
        checkIndexViewer(strpos($html, 'private-offender-1.log') !== false && strpos($html, 'private-offender-2.log') === false,
            'Rendered report scope changed');
        foreach (['queued', 'plan', 'page'] as $stage) {
            foreach (['switch', 'logout', 'admin', 'impersonation', 'invalid-id', 'same-id'] as $change) {
                $controller = resetIndexViewer($admin, $impersonated);
                IndexViewerFixture::$stage = $stage; IndexViewerFixture::$change = $change;
                $error = runIndexViewer($controller);
                checkIndexViewer(IndexViewerFixture::$injections === 1, 'Audit viewer injection did not run');
                checkIndexViewer(IndexViewerFixture::$inventory === 1, 'Audit viewer changed inventory query count');
                if ($change === 'same-id') {
                    checkIndexViewer($error === null && $controller->view->result['count'] === 1,
                        'Equivalent SDK viewer replacement rejected');
                } else {
                    checkIndexViewerDenied($controller, $error);
                    checkIndexViewer(IndexViewerFixture::$plans === ($stage === 'queued' ? 0 : 1),
                        'Audit viewer loss crossed unexpected private policy stage');
                }
            }
        }
    }
    foreach ([0, '0', '1'] as $id) {
        checkIndexViewer(runIndexViewer(resetIndexViewer(false, false, $id)) === null, 'Stable numeric viewer identity rejected');
    }
    foreach ([null, false, [], '', -1, '1e1', '12345678901'] as $id) {
        $controller = resetIndexViewer(false, false, $id);
        checkIndexViewerDenied($controller, runIndexViewer($controller));
        checkIndexViewer(IndexViewerFixture::$inventory === 0, 'Invalid viewer reached inventory');
    }
    foreach (['switch', 'logout', 'admin', 'impersonation', 'invalid-id', 'same-id'] as $change) {
        $controller = resetIndexViewer(false, false, 1, true);
        IndexViewerFixture::$stage = 'queued'; IndexViewerFixture::$change = $change;
        $error = runIndexViewer($controller);
        checkIndexViewer(IndexViewerFixture::$injections === 1, 'Empty-viewer injection did not run');
        if ($change === 'same-id') {
            checkIndexViewer($error === null && $controller->view->domains === [] && $controller->view->domain === null,
                'Stable empty subscription view changed');
        } else { checkIndexViewerDenied($controller, $error); }
    }
    $controller = resetIndexViewer(); $controller->values = ['domain' => 2];
    checkIndexViewerDenied($controller, runIndexViewer($controller));
    echo "Audit index viewer: $checks original-identity/private-read/cleared-template controls passed (SDK fixtures, not native session/render or continuous entitlement proof)\n";
} finally {
    $audit = pm_Context::$directory . DIRECTORY_SEPARATOR . 'audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
}
