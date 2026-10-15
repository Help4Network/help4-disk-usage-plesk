<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Store.php';
require __DIR__ . '/../extension/plib/library/Report.php';

class ReportActionFixture {
    public static $stage = '', $change = '', $injections = 0, $lookups = 0, $homeReads = 0, $reportChecks = 0;
    public static $guid = 'synthetic-guid', $owner = 10, $name = 'synthetic.example.test', $home = '/synthetic/home';
    public static function inject($stage) {
        if (self::$stage !== $stage) { return; }
        self::$stage = ''; self::$injections++;
        $client = pm_Session::$client;
        if (self::$change === 'logout') { pm_Session::$client = null; }
        elseif (self::$change === 'role') { $client->admin = !$client->admin; }
        elseif (self::$change === 'impersonation') { pm_Session::$impersonated = !pm_Session::$impersonated; }
        elseif (self::$change === 'permission') { $client->allowed = []; $client->admin = false; }
        elseif (self::$change === 'invalid-id') { $client->id = []; }
        elseif (self::$change === 'guid') { self::$guid = 'replacement-guid'; }
        elseif (self::$change === 'owner') { self::$owner = 20; }
        elseif (self::$change === 'name') { self::$name = 'replacement.example.test'; }
        elseif (self::$change === 'home') { self::$home = '/synthetic/replacement'; }
        else {
            $replacement = new ReportActionClient();
            $replacement->id = self::$change === 'switch' ? 2 : $client->id;
            $replacement->admin = $client->admin; $replacement->allowed = $client->allowed;
            pm_Session::$client = $replacement;
        }
    }
}
class ReportActionClient {
    public $id = 1, $admin = false, $allowed = [1];
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
    public function getId() {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        if (($caller['class'] ?? '') === 'Modules_Help4DiskUsage_Report') { ReportActionFixture::inject('url'); }
        return $this->id;
    }
    public function getGuid() { return ReportActionFixture::$guid . '-' . $this->id; }
    public function getName() { return ReportActionFixture::$name; }
    public function getClient() { $client = new ReportActionClient(); $client->id = ReportActionFixture::$owner; return $client; }
    public function getHomePath() {
        ReportActionFixture::$homeReads++;
        $home = ReportActionFixture::$home;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        // The actual private report read precedes Store::report's binding check.
        if (($trace[2]['class'] ?? '') === 'Modules_Help4DiskUsage_Store' &&
            ($trace[2]['function'] ?? '') === 'report') {
            ReportActionFixture::$reportChecks++; ReportActionFixture::inject('report');
        }
        if (($trace[2]['class'] ?? '') === 'IndexController' && ($trace[2]['function'] ?? '') === 'reportScope') {
            ReportActionFixture::inject('scope-' . ReportActionFixture::$lookups);
        }
        return $home;
    }
    public static function getByDomainId($id) {
        ReportActionFixture::$lookups++;
        ReportActionFixture::inject('lookup-' . ReportActionFixture::$lookups);
        return new self($id);
    }
}
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
}
class ReportActionResponse {
    public $headers = [], $body = '', $writes = 0;
    public function setHeader($name, $value, $replace) { $this->headers[$name] = $value; $this->writes++; return $this; }
    public function setBody($body) { $this->body = $body; $this->writes++; return $this; }
}
class ReportActionHelpers {
    public $viewRenderer, $layout;
    public function __construct() { $this->viewRenderer = $this; $this->layout = $this; }
    public function setNoRender() { ReportActionFixture::inject('render'); }
    public function disableLayout() { ReportActionFixture::inject('layout'); }
}
class pm_Controller_Action {
    public $values, $response, $redirect = null, $_helper;
    public function __construct($format) {
        $this->values = ['domain' => 1, 'format' => $format, 'path' => 'httpdocs/private-offender & space.log', 'kind' => 'file'];
        $this->response = new ReportActionResponse(); $this->_helper = new ReportActionHelpers();
    }
    public function getParam($name, $default = null) {
        $value = $this->values[$name] ?? $default; ReportActionFixture::inject($name); return $value;
    }
    public function getResponse() { ReportActionFixture::inject('response'); return $this->response; }
    public function _redirect($url, $options = []) { $this->redirect = [$url, $options]; }
}
require __DIR__ . '/../extension/plib/controllers/IndexController.php';

$checks = 0;
function checkReportAction($value, $message) {
    if (!$value) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function resetReportAction($format, $admin = false, $impersonated = false, $id = 1) {
    ReportActionFixture::$stage = ReportActionFixture::$change = '';
    ReportActionFixture::$injections = ReportActionFixture::$lookups = ReportActionFixture::$homeReads = ReportActionFixture::$reportChecks = 0;
    ReportActionFixture::$guid = 'synthetic-guid'; ReportActionFixture::$owner = 10;
    ReportActionFixture::$name = 'synthetic.example.test'; ReportActionFixture::$home = '/synthetic/home';
    pm_Session::$client = new ReportActionClient(); pm_Session::$client->id = $id;
    pm_Session::$client->admin = $admin; pm_Session::$impersonated = $impersonated;
    return new IndexController($format);
}
function reportActionHashes() {
    $values = [];
    foreach (['policy', 'state', 'bridge', 'rotation', 'report-1', 'report-2', 'status-1'] as $name) {
        $values[$name] = hash_file('sha256', Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . $name . '.json');
    }
    return $values;
}
function runReportAction($controller, $action) {
    $before = reportActionHashes(); $streams = count(get_resources('stream')); $error = null;
    try { $controller->$action(); } catch (Throwable $e) { $error = $e->getMessage(); }
    checkReportAction(count(get_resources('stream')) === $streams, 'Report action leaked streams');
    checkReportAction(reportActionHashes() === $before, 'Read-only report action changed private state');
    return $error;
}
function checkReportActionDenied($controller, $error) {
    checkReportAction($error === 'Subscription unavailable', 'Changed scope did not receive generic denial');
    checkReportAction($controller->response->body === '' && $controller->response->headers === [] &&
        $controller->response->writes === 0, 'Denied download published attachment headers or private body');
    checkReportAction($controller->redirect === null, 'Denied File Manager action emitted a redirect');
}
function checkReportActionSuccess($controller, $action, $format) {
    if ($action === 'openAction') {
        checkReportAction($controller->redirect === ['/smb/file-manager/list/domainId/1?currentDir=%2Fhttpdocs', []],
            'Native same-session File Manager parent changed');
        checkReportAction($controller->response->writes === 0, 'File Manager action emitted a download');
    } else {
        checkReportAction(strpos($controller->response->body, 'private-offender & space.log') !== false, 'Valid retained file missing');
        $credit = $format === 'csv' ? strpos($controller->response->body, 'https://help4network.com') !== false :
            (json_decode($controller->response->body, true, 16, JSON_THROW_ON_ERROR)['built_by']['url'] ?? '') === 'https://help4network.com';
        checkReportAction($credit, 'Export credit missing');
        checkReportAction(strpos($controller->response->body, 'foreign-private-report') === false, 'Export retained foreign report');
        checkReportAction($controller->response->headers['Content-Disposition'] ===
            'attachment; filename="disk-audit.' . $format . '"', 'Download filename changed');
        if ($format === 'csv') { checkReportAction(strpos($controller->response->body, "'=formula.log") !== false, 'CSV formula neutralization changed'); }
        else { checkReportAction(!array_key_exists('binding', json_decode($controller->response->body, true, 16, JSON_THROW_ON_ERROR)), 'JSON binding exposed'); }
    }
}

pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-report-actions-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    resetReportAction('json');
    foreach (['policy', 'state', 'bridge', 'rotation', 'status-1'] as $name) {
        Modules_Help4DiskUsage_Store::write($name, ['synthetic' => $name]);
    }
    $report = ['binding' => Modules_Help4DiskUsage_Access::binding(new pm_Domain(1)),
        'largest_files' => [
            ['path' => 'httpdocs/private-offender & space.log', 'kind' => 'file', 'bytes' => 42, 'hint' => 'Review'],
            ['path' => '=formula.log', 'kind' => 'file', 'bytes' => 7, 'hint' => 'Review']],
        'stale_files' => [], 'largest_trees' => [['path' => 'httpdocs', 'kind' => 'directory', 'bytes' => 42, 'hint' => 'Review']],
        'entry_trees' => [], 'built_by' => ['url' => 'https://help4network.com']];
    Modules_Help4DiskUsage_Store::write('report-1', $report);
    Modules_Help4DiskUsage_Store::write('report-2', array_replace($report,
        ['binding' => Modules_Help4DiskUsage_Access::binding(new pm_Domain(2)), 'canary' => 'foreign-private-report']));
    foreach ([['exportAction', 'json'], ['exportAction', 'csv'], ['openAction', 'json']] as [$action, $format]) {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$admin, $impersonated]) {
            $controller = resetReportAction($format, $admin, $impersonated);
            checkReportAction(runReportAction($controller, $action) === null, 'Stable authorized report action rejected');
            checkReportActionSuccess($controller, $action, $format);
            checkReportAction(ReportActionFixture::$lookups === 4 && ReportActionFixture::$reportChecks === 1,
                'Stable action changed bounded lookups or private report check count');
            $stages = $action === 'exportAction' ? ['domain', 'lookup-2', 'report', 'render', 'layout', 'format', 'response', 'lookup-4'] :
                ['domain', 'lookup-2', 'report', 'path', 'kind', 'url', 'lookup-4'];
            $stages[] = 'scope-4';
            foreach ($stages as $stage) {
                foreach (['switch', 'logout', 'role', 'impersonation', 'permission', 'invalid-id', 'guid', 'owner', 'name', 'home', 'same-id'] as $change) {
                    // Last metadata sampling is not an atomic permission/binding transaction.
                    if ($stage === 'scope-4' && in_array($change, ['permission', 'guid', 'owner', 'name', 'home'], true)) { continue; }
                    $controller = resetReportAction($format, $admin, $impersonated);
                    ReportActionFixture::$stage = $stage; ReportActionFixture::$change = $change;
                    $error = runReportAction($controller, $action);
                    checkReportAction(ReportActionFixture::$injections === 1, 'Intended report-action injection did not run');
                    if ($change === 'same-id') {
                        checkReportAction($error === null, 'Equivalent viewer replacement rejected');
                        checkReportActionSuccess($controller, $action, $format);
                    } elseif ($stage === 'domain' && in_array($change, ['guid', 'owner', 'name', 'home'], true)) {
                        checkReportAction($error === 'Report unavailable' && $controller->response->writes === 0 && $controller->redirect === null,
                            'Pre-admission metadata change reused stale report');
                    } else { checkReportActionDenied($controller, $error); }
                    if ($stage === 'lookup-2' && $change !== 'same-id') {
                        checkReportAction(ReportActionFixture::$reportChecks === 0, 'Admission loss crossed private report IO');
                    }
                }
            }
        }
        foreach ([null, [], false, '', -1, '1e1', '12345678901'] as $id) {
            $controller = resetReportAction($format, false, false, $id);
            checkReportActionDenied($controller, runReportAction($controller, $action));
            checkReportAction(ReportActionFixture::$lookups === 0 && ReportActionFixture::$reportChecks === 0,
                'Malformed viewer reached domain lookup or private report IO');
        }
        foreach ([0, '0', '1'] as $id) {
            $controller = resetReportAction($format, false, false, $id);
            checkReportAction(runReportAction($controller, $action) === null, 'Stable numeric viewer rejected');
        }
        $controller = resetReportAction($format); $controller->values['domain'] = 2;
        checkReportActionDenied($controller, runReportAction($controller, $action));
        checkReportAction(ReportActionFixture::$reportChecks === 0, 'Foreign request reached report IO');
        $controller = resetReportAction($format); pm_Session::$client->allowed = [];
        checkReportActionDenied($controller, runReportAction($controller, $action));
        checkReportAction(ReportActionFixture::$reportChecks === 0, 'Initially revoked permission reached report IO');
        Modules_Help4DiskUsage_Store::write('report-1', array_replace($report, ['binding' => 'stale-binding']));
        $controller = resetReportAction($format); ReportActionFixture::$stage = 'report'; ReportActionFixture::$change = 'switch';
        checkReportActionDenied($controller, runReportAction($controller, $action));
        checkReportAction(ReportActionFixture::$injections === 1, 'Stale-report actor switch did not run');
        $controller = resetReportAction($format);
        checkReportAction(runReportAction($controller, $action) === 'Report unavailable' && $controller->response->writes === 0 &&
            $controller->redirect === null, 'Stable stale report did not remain unavailable');
        Modules_Help4DiskUsage_Store::write('report-1', $report);
        $controller = resetReportAction($format);
        if ($action === 'openAction') {
            foreach (['unknown.log', '../foreign', '/etc/passwd'] as $path) {
                $controller->values['path'] = $path;
                checkReportAction(runReportAction($controller, $action) !== null && $controller->redirect === null, 'Unretained or unsafe jump accepted');
            }
            $controller = resetReportAction($format); $controller->values['path'] = 'httpdocs'; $controller->values['kind'] = 'directory';
            checkReportAction(runReportAction($controller, $action) === null &&
                $controller->redirect[0] === '/smb/file-manager/list/domainId/1?currentDir=%2Fhttpdocs', 'Tree did not jump into itself');
        }
    }
    echo $checks . " report export/File Manager scope controls passed\n";
} finally {
    $audit = pm_Context::$directory . DIRECTORY_SEPARATOR . 'audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
}
