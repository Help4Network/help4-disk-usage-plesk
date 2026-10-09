<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Store.php';
require __DIR__ . '/../extension/plib/library/Releases.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function denied($callback) {
    try { $callback(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected controller denial');
}
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
    public static function getBaseUrl() { return '/modules/help4-disk-usage/'; }
    public static function getActionUrl($controller, $action) { return self::getBaseUrl() . $controller . '/' . $action; }
}
class Zend_Session_Namespace {
    public $csrf = 'fixture-token';
    public function __construct($name) {}
}
class ControllerClient {
    public $admin = false;
    public function isAdmin() { return $this->admin; }
    public function hasAccessToDomain($id) { return $id === 1; }
}
class pm_Session {
    public static $client;
    public static $impersonated = false;
    public static function getClient() { return self::$client; }
    public static function isImpersonated() { return self::$impersonated; }
}
class pm_Domain {
    public $id;
    public function getId() { return $this->id; }
    public static function getByDomainId($id) { $d = new self(); $d->id = $id; return $d; }
    public static function getAllDomains($includeSubdomains) { return [self::getByDomainId(1), self::getByDomainId(2)]; }
}
class pm_Extension {
    public static $reads = 0;
    public static function getById($id) { self::$reads++; return new self(); }
    public function getVersion() { return '0.2.0'; }
}
class ControllerRequest {
    public $post = false;
    public $values = [];
    public function isPost() { return $this->post; }
    public function getPost($key = null) { return $key === null ? $this->values : ($this->values[$key] ?? null); }
}
class ControllerAssets {
    public function appendStylesheet($url) {}
    public function appendFile($url) {}
}
#[AllowDynamicProperties]
class ControllerView {
    public function headLink() { return new ControllerAssets(); }
    public function headScript() { return new ControllerAssets(); }
}
class ControllerResponse {
    public $headers = [];
    public function setHeader($name, $value, $replace) { $this->headers[$name] = $value; return $this; }
}
class ControllerStatus {
    public $messages = [];
    public function addMessage($kind, $message) { $this->messages[] = [$kind, $message]; }
}
class pm_Controller_Action {
    public $view, $request, $response, $_status, $redirect;
    public function __construct() {
        $this->view = new ControllerView(); $this->request = new ControllerRequest();
        $this->response = new ControllerResponse(); $this->_status = new ControllerStatus();
    }
    public function init() {}
    public function getRequest() { return $this->request; }
    public function getResponse() { return $this->response; }
    public function getParam($key, $default = null) { return $this->request->values[$key] ?? $default; }
    public function _redirect($url, $options = []) { $this->redirect = [$url, $options]; }
}
require __DIR__ . '/../extension/plib/controllers/IndexController.php';
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-controller-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
pm_Session::$client = new ControllerClient();
try {
    $c = new IndexController(); $c->init();
    check($c->response->headers['Cache-Control'] === 'no-store', 'Missing private response policy');
    foreach (['refreshAction', 'settingsAction', 'updatesAction'] as $action) {
        denied(function () use ($c, $action) { $c->$action(); });
    }
    check(pm_Extension::$reads === 0, 'Customer reached installed-version lookup');
    $c->request->post = true;
    $c->request->values = ['domain' => 2, 'csrf' => 'fixture-token'];
    foreach (['indexAction', 'refreshAction', 'exportAction', 'openAction'] as $action) {
        denied(function () use ($c, $action) { $c->$action(); });
    }
    check(!is_file(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . 'state.json'), 'Foreign request changed queue');
    pm_Session::$client->admin = true;
    pm_Session::$impersonated = true;
    denied(function () use ($c) { $c->updatesAction(); });
    denied(function () use ($c) { $c->settingsAction(); });
    check(pm_Extension::$reads === 0, 'Impersonated admin reached update data');
    pm_Session::$impersonated = false;
    foreach ([null, '', 'wrong-token', ['fixture-token']] as $badToken) {
        $c->request->values = ['csrf' => $badToken];
        foreach (['refreshAction', 'settingsAction', 'updatesAction'] as $action) {
            denied(function () use ($c, $action) { $c->$action(); });
        }
    }
    check(!is_file(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . 'release.json'), 'Invalid CSRF changed release cache');
    $c->request->post = false; $c->request->values = [];
    $c->updatesAction();
    check($c->view->installedVersion === '0.2.0' && $c->view->release === [], 'Update GET data');
    check(!is_file(Modules_Help4DiskUsage_Store::directory() . DIRECTORY_SEPARATOR . 'release.json'), 'Update GET triggered outbound check');
    // A live cooldown guarantees this valid POST never contacts GitHub in the fixture.
    Modules_Help4DiskUsage_Store::write('release', ['attempted_at' => time()]);
    $c->request->post = true; $c->request->values = ['csrf' => 'fixture-token'];
    $c->updatesAction();
    check($c->redirect === [pm_Context::getActionUrl('index', 'updates'), ['code' => 303]], 'Update POST did not use PRG');
    check($c->_status->messages[0][0] === 'warning', 'Cooldown warning unavailable');
    echo "Controller foreign-scope, impersonation, CSRF, passive GET and POST-303 tests passed\n";
} finally {
    $audit = pm_Context::$directory . DIRECTORY_SEPARATOR . 'audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
}
