<?php
require __DIR__ . '/../extension/plib/library/Access.php';
class DomainViewerFixture {
    public static $stage = '', $change = '', $injections = 0, $inventory = 0, $filter = 0;
    public static function inject($stage) {
        if (self::$stage !== $stage) { return; }
        self::$stage = ''; self::$injections++;
        if (self::$change === 'logout') { pm_Session::$client = null; }
        elseif (self::$change === 'admin') { pm_Session::$client->admin = !pm_Session::$client->admin; }
        elseif (self::$change === 'impersonation') { pm_Session::$impersonated = !pm_Session::$impersonated; }
        else {
            $before = pm_Session::$client;
            $client = new DomainViewerClient();
            $client->id = self::$change === 'switch' ? 2 : $before->id;
            $client->admin = $before->admin; $client->allowed = $before->allowed;
            pm_Session::$client = $client;
        }
    }
}
class DomainViewerClient {
    public $id = 1, $admin = false, $allowed = [1, 3];
    public function getId() { return $this->id; }
    public function isAdmin() { return $this->admin; }
    public function hasAccessToDomain($id) {
        DomainViewerFixture::$filter++;
        $allowed = in_array($id, $this->allowed, true);
        DomainViewerFixture::inject('filter');
        return $allowed;
    }
}
class pm_Session {
    public static $client, $impersonated = false, $reads = 0;
    public static function getClient() { self::$reads++; return self::$client; }
    public static function isImpersonated() { return self::$impersonated; }
}
class pm_Domain {
    public $id;
    public function __construct($id) { $this->id = $id; }
    public function getId() { return $this->id; }
    public static function getAllDomains($include) {
        if ($include !== true) { throw new RuntimeException('Changed inventory selector'); }
        DomainViewerFixture::$inventory++;
        DomainViewerFixture::inject('inventory');
        return array_map(function ($id) { return new self($id); }, [1, 2, 3]);
    }
}
$checks = 0;
function checkDomainViewer($value, $message) {
    if (!$value) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function resetDomainViewer($admin = false, $impersonated = false) {
    pm_Session::$client = new DomainViewerClient(); pm_Session::$client->admin = $admin;
    pm_Session::$impersonated = $impersonated; pm_Session::$reads = 0;
    DomainViewerFixture::$stage = ''; DomainViewerFixture::$change = ''; DomainViewerFixture::$injections = 0;
    DomainViewerFixture::$inventory = 0; DomainViewerFixture::$filter = 0;
}
foreach ([[false, false], [false, true], [true, false], [true, true]] as [$admin, $impersonated]) {
    resetDomainViewer($admin, $impersonated);
    $ids = array_map(function ($domain) { return $domain->getId(); }, Modules_Help4DiskUsage_Access::domains());
    checkDomainViewer($ids === ($admin && !$impersonated ? [1, 2, 3] : [1, 3]), 'Stable viewer visibility changed');
    checkDomainViewer(DomainViewerFixture::$inventory === 1, 'Inventory queried repeatedly');
    foreach (['inventory', ...($admin && !$impersonated ? [] : ['filter'])] as $stage) {
        foreach (['switch', 'logout', 'admin', 'impersonation', 'same-id'] as $change) {
            resetDomainViewer($admin, $impersonated);
            DomainViewerFixture::$stage = $stage; DomainViewerFixture::$change = $change;
            $domains = null; $error = null; $streams = count(get_resources('stream'));
            try { $domains = Modules_Help4DiskUsage_Access::domains(); } catch (Throwable $e) { $error = $e->getMessage(); }
            checkDomainViewer(DomainViewerFixture::$injections === 1, 'Viewer injection did not reach intended stage');
            checkDomainViewer(DomainViewerFixture::$inventory === 1, 'Request changed inventory count');
            checkDomainViewer(count(get_resources('stream')) === $streams, 'Viewer enumeration leaked streams');
            if ($change === 'same-id') {
                checkDomainViewer($error === null && array_map(function ($d) { return $d->getId(); }, $domains) ===
                    ($admin && !$impersonated ? [1, 2, 3] : [1, 3]), 'Equivalent SDK client replacement refused');
            } else {
                checkDomainViewer($domains === null && $error === 'Subscription unavailable', 'Changed viewer received subscription list');
                if ($stage === 'inventory') {
                    checkDomainViewer(DomainViewerFixture::$filter === 0, 'Inventory actor loss still evaluated original permissions');
                }
            }
        }
    }
}
foreach ([null, false, [], new stdClass(), '', -1, '1e1', 'invalid', '12345678901'] as $id) {
    resetDomainViewer(); pm_Session::$client->id = $id;
    $error = null;
    try { Modules_Help4DiskUsage_Access::domains(); } catch (Throwable $e) { $error = $e->getMessage(); }
    checkDomainViewer($error === 'Subscription unavailable', 'Invalid viewer identity accepted');
    checkDomainViewer(DomainViewerFixture::$inventory === 0, 'Invalid viewer reached private inventory');
}
foreach ([0, '0', '1'] as $id) {
    resetDomainViewer(); pm_Session::$client->id = $id;
    checkDomainViewer(count(Modules_Help4DiskUsage_Access::domains()) === 2, 'Stable numeric SDK viewer refused');
}
foreach (['admin', 'impersonated'] as $field) {
    resetDomainViewer();
    if ($field === 'admin') { pm_Session::$client->admin = 'false'; } else { pm_Session::$impersonated = 0; }
    $error = null;
    try { Modules_Help4DiskUsage_Access::domains(); } catch (Throwable $e) { $error = $e->getMessage(); }
    checkDomainViewer($error === 'Subscription unavailable' && DomainViewerFixture::$inventory === 0,
        'Malformed SDK role/impersonation flag reached inventory');
}
resetDomainViewer(); pm_Session::$client->allowed = [];
checkDomainViewer(Modules_Help4DiskUsage_Access::domains() === [], 'Empty visible set changed');
echo "Domain viewer: $checks unchanged-source identity/inventory/filter controls passed (SDK fixtures, not native Plesk session or permission-reload proof)\n";
