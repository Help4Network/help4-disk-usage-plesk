<?php
// Reuse the SDK controller fixtures; the source bodies below remain unchanged.
ob_start();
require __DIR__ . '/controllers.php';
ob_end_clean();
class SettingsHooks {
    public static $stage = '';
    public static $change = '';
    public static $injections = 0;
    public static $locks = 0;
    public static function inject($stage) {
        if (self::$stage !== $stage) { return; }
        self::$stage = '';
        self::$injections++;
        if (self::$change === 'demote') { pm_Session::$client->admin = false; }
        elseif (self::$change === 'impersonate') { pm_Session::$impersonated = true; }
        elseif (self::$change === 'logout') { pm_Session::$client = null; }
        else {
            $client = new ControllerClient(); $client->admin = true;
            $client->id = self::$change === 'switch' ? 2 : 1;
            pm_Session::$client = $client;
        }
    }
}
class SettingsPermissions {
    public static $calls = [];
    public static $fail = false;
    public static function check($python) {
        self::$calls[] = $python;
        SettingsHooks::inject('acl');
        if (self::$fail) { throw new RuntimeException('Synthetic private ACL failure'); }
    }
}
class SettingsRuntime {
    public static $calls = [];
    public static $fail = false;
    public static function check($python) {
        self::$calls[] = $python;
        SettingsHooks::inject('runtime');
        if (self::$fail) { throw new RuntimeException('Synthetic private runtime failure'); }
    }
}
class SettingsRequest extends ControllerRequest {
    public function isPost() {
        $value = parent::isPost();
        if (!$value) { SettingsHooks::inject('return'); }
        return $value;
    }
}
$settingsChecks = 0;
function verify($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['settingsChecks']++;
}
function actor() {
    pm_Session::$client = new ControllerClient(); pm_Session::$client->admin = true;
    pm_Session::$impersonated = false;
    SettingsHooks::$stage = ''; SettingsHooks::$change = ''; SettingsHooks::$injections = 0; SettingsHooks::$locks = 0;
    SettingsPermissions::$calls = []; SettingsPermissions::$fail = false;
    SettingsRuntime::$calls = []; SettingsRuntime::$fail = false;
}
function unchanged($store, $before) {
    $dir = $store::directory();
    verify(hash_file('sha256', $dir . '/policy.json') === $before, 'Rejected settings changed server policy');
    verify($store::read('state') === ['pending' => [], 'attempts' => ['synthetic' => [1]]], 'Settings changed scan/rate state');
    verify($store::read('bridge') === ['enabled' => false, 'installation_id' => str_repeat('a', 64)], 'Settings changed integration pin');
    verify($store::read('rotation') === ['attempted_at' => 1], 'Settings changed rotation');
}
foreach (['Linux', 'Windows'] as $platform) {
    $ns = 'Settings' . $platform . 'Fixture';
    define($ns . '\\PHP_OS_FAMILY', $platform);
    foreach (['pm_Context', 'pm_Session', 'pm_Controller_Action', 'Zend_Session_Namespace',
        'Modules_Help4DiskUsage_Access'] as $class) { class_alias($class, $ns . '\\' . $class); }
    class_alias('SettingsPermissions', $ns . '\\Modules_Help4DiskUsage_Permissions');
    class_alias('SettingsRuntime', $ns . '\\Modules_Help4DiskUsage_Runtime');
    // Inject only after genuine private reads/OS lock acquisition, never replace the source guards.
    eval('namespace ' . $ns . '; function file_get_contents($path) {
        $value = \\file_get_contents($path);
        if (basename($path) === "policy.json") { \\SettingsHooks::inject("read"); }
        return $value;
    }
    function flock($handle, $operation, &$wouldBlock = null) {
        $result = \\flock($handle, $operation, $wouldBlock);
        if ($result && $operation === (LOCK_EX | LOCK_NB)) {
            \\SettingsHooks::$locks++; \\SettingsHooks::inject("lock");
        }
        return $result;
    }');
    foreach (['library/Store.php', 'controllers/IndexController.php'] as $source) {
        eval('namespace ' . $ns . '; use \\RuntimeException; use \\Throwable; use \\DirectoryIterator;' .
            substr(file_get_contents(__DIR__ . '/../extension/plib/' . $source), 5));
    }
    $store = $ns . '\\Modules_Help4DiskUsage_Store';
    $controller = $ns . '\\IndexController';
    $mask = umask(0077);
    pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-settings-' . bin2hex(random_bytes(8));
    verify(mkdir(pm_Context::$directory, 0700), 'Private settings fixture unavailable');
    try {
        $original = ['hourly' => 3, 'minimum_interval' => 300, 'seconds' => 60, 'ttl' => 3600,
            'global_hourly' => 60, 'queue_limit' => 16, 'title' => 'Synthetic private policy', 'python' => PHP_BINARY,
            'overrides' => [], 'profiles' => [], 'scheduled_enabled' => false, 'scheduled_batch' => 2,
            'scheduled_interval' => 21600];
        $store::write('state', ['pending' => [], 'attempts' => ['synthetic' => [1]]]);
        $store::write('bridge', ['enabled' => false, 'installation_id' => str_repeat('a', 64)]);
        $store::write('rotation', ['attempted_at' => 1]);
        $requested = array_replace($original, ['hourly' => 4, 'title' => 'Synthetic changed policy']);
        $posted = $requested + ['csrf' => 'fixture-token', 'overrides_json' => '{}', 'profiles_json' => '{}'];
        foreach (['read', 'runtime', 'lock', ...($platform === 'Windows' ? ['acl'] : [])] as $stage) {
            foreach (['demote', 'impersonate', 'logout', 'switch', 'same-id'] as $change) {
                actor(); $store::write('policy', $original);
                $before = hash_file('sha256', $store::directory() . '/policy.json');
                $c = new $controller(); $c->init(); $c->request->post = true; $c->request->values = $posted;
                SettingsHooks::$stage = $stage; SettingsHooks::$change = $change;
                $error = null; $streams = count(get_resources('stream'));
                try { $c->settingsAction(); } catch (RuntimeException $e) { $error = $e->getMessage(); }
                verify(SettingsHooks::$injections === 1, 'Settings actor injection not reached: ' . $platform . '/' . $stage);
                verify(count(get_resources('stream')) === $streams, 'Settings request leaked a stream');
                if ($change === 'same-id') {
                    verify($error === null && $store::read('policy') === $requested, 'Same administrator replacement rejected');
                    verify($c->redirect === [pm_Context::getActionUrl('index', 'settings'), ['code' => 303]], 'Settings missed POST-303');
                    verify($c->_status->messages === [['info', 'Settings saved']], 'Settings success changed');
                    verify(SettingsHooks::$locks === 1 && SettingsRuntime::$calls === [PHP_BINARY], 'Valid settings did not use runtime and queue lock');
                } else {
                    unchanged($store, $before);
                    verify($error === 'Administrator access required', 'Changed settings administrator not rejected generically: ' . $stage . '/' . $change);
                    verify(!isset($c->view->policy), 'Rejected settings retained private policy for rendering');
                    verify($c->redirect === null, 'Rejected settings redirected as saved');
                    verify(SettingsRuntime::$calls === (in_array($stage, ['runtime', 'lock'], true) ? [PHP_BINARY] : []),
                        'Runtime executed after earlier authorization loss');
                    verify(SettingsHooks::$locks === (in_array($stage, ['runtime', 'lock'], true) ? 1 : 0),
                        'Rejected settings reached wrong write-lock stage: ' . $platform . '/' . $stage . '/' . $change);
                }
                verify(SettingsPermissions::$calls === ($platform === 'Windows' && ($stage !== 'read' || $change === 'same-id')
                    ? [PHP_BINARY] : []), 'Captured-runtime ACL platform/order mismatch');
            }
        }
        foreach (['read', 'return'] as $stage) {
        foreach (['demote', 'impersonate', 'logout', 'switch'] as $change) {
            actor(); $store::write('policy', $original);
            $before = hash_file('sha256', $store::directory() . '/policy.json');
            $c = new $controller(); $c->init(); $c->request = new SettingsRequest();
            SettingsHooks::$stage = $stage; SettingsHooks::$change = $change;
            $error = null;
            try { $c->settingsAction(); } catch (RuntimeException $e) { $error = $e->getMessage(); }
            verify(SettingsHooks::$injections === 1 && $error === 'Administrator access required', 'Settings GET returned after actor change');
            verify(!isset($c->view->policy), 'Settings GET exposed private policy after actor change');
            verify(SettingsPermissions::$calls === [] && SettingsRuntime::$calls === [] && SettingsHooks::$locks === 0,
                'Passive settings GET started diagnostic or write');
            unchanged($store, $before);
        }
        }
        foreach (['runtime', ...($platform === 'Windows' ? ['acl'] : [])] as $failure) {
            actor(); $store::write('policy', $original);
            $before = hash_file('sha256', $store::directory() . '/policy.json');
            SettingsRuntime::$fail = $failure === 'runtime'; SettingsPermissions::$fail = $failure === 'acl';
            $c = new $controller(); $c->init(); $c->request->post = true; $c->request->values = $posted;
            $c->settingsAction();
            unchanged($store, $before);
            verify($c->_status->messages === [['warning', 'Settings not saved. Check the limits, Python path and subscription overrides.']],
                'Diagnostic failure exposed private details');
            verify($c->redirect === null && SettingsHooks::$locks === 0, 'Failed diagnostic reached save');
            verify(isset($c->view->policy), 'Still-authorized diagnostic failure lost original settings view');
            verify(SettingsRuntime::$calls === ($failure === 'acl' ? [] : [PHP_BINARY]), 'ACL failure did not precede runtime');
        }
        foreach ([null, false, [], new stdClass(), '', -1, 'not-an-id', '1e1', '12345678901'] as $invalid) {
            actor(); $store::write('policy', $original); pm_Session::$client->id = $invalid;
            $before = hash_file('sha256', $store::directory() . '/policy.json');
            $c = new $controller(); $c->init(); $c->request->post = true; $c->request->values = $posted;
            $error = null;
            try { $c->settingsAction(); } catch (RuntimeException $e) { $error = $e->getMessage(); }
            verify($error === 'Administrator access required' && !isset($c->view->policy), 'Invalid administrator identity accepted');
            verify(SettingsPermissions::$calls === [] && SettingsRuntime::$calls === [] && SettingsHooks::$locks === 0,
                'Invalid administrator identity reached diagnostic or write');
            unchanged($store, $before);
        }
        actor(); $store::write('policy', $original); pm_Session::$client->id = '0';
        $c = new $controller(); $c->init(); $c->request->post = true; $c->request->values = $posted;
        $c->settingsAction();
        verify($store::read('policy') === $requested && $c->redirect !== null, 'Stable zero-valued SDK identity rejected');
        actor();
        verify($store::locked(function () { return 'recovered'; }) === 'recovered', 'Settings lock did not recover');
    } finally {
        SettingsHooks::$stage = '';
        $dir = pm_Context::$directory . '/audit';
        foreach (new DirectoryIterator($dir) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($dir); rmdir(pm_Context::$directory); umask($mask);
    }
}
echo "Settings authorization: $settingsChecks current-actor/read/ACL/runtime/lock/write controls passed (unchanged-source SDK fixtures, not native Plesk session or ACL proof)\n";
