<?php
namespace {
    require __DIR__ . '/../extension/plib/library/Access.php';
    require __DIR__ . '/../extension/plib/library/Store.php';
    require __DIR__ . '/../extension/plib/library/Report.php';
    require __DIR__ . '/../extension/plib/library/Remote.php';
    require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Scope.php';
    require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Transport.php';
    abstract class pm_Hook_ApiRpc { abstract public function call($params); }
    class pm_Exception extends \RuntimeException { }
    require __DIR__ . '/../extension/plib/hooks/ApiRpc.php';
    class pm_Context { public static $dir; public static function getVarDir() { return self::$dir; } }
    class RpcClient {
        public $id = 1;
        public $admin = true;
        public $guid = '22222222-2222-4222-8222-222222222222';
        public function getId() { return $this->id; }
        public function isAdmin() { return $this->admin; }
        public function hasAccessToDomain($id) { return true; }
        public function getProperty($name) { return $this->guid; }
    }
    class RpcDomain {
        public $owner;
        public $guid = '11111111-1111-4111-8111-111111111111';
        public $home = '/synthetic/example';
        public $active = true;
        public $webspace = '0';
        public function getId() { return 10; }
        public function getGuid() { return $this->guid; }
        public function getClient() { return $this->owner; }
        public function getName() { return 'demo.example.test'; }
        public function getHomePath() { return $this->home; }
        public function getSysUserLogin() { return 'demo'; }
        public function getProperty($name) { return $this->webspace; }
        public function isActive() { return $this->active; }
        public function hasHosting() { return true; }
        public function getPlanItems() { return []; }
    }
    class pm_Domain {
        public static $domain;
        public static $after;
        public static function getByName($name) { if ($name !== self::$domain->getName()) { throw new \RuntimeException(); } return self::$domain; }
        public static function getByGuid($guid) {
            if (self::$after) { $callback = self::$after; self::$after = null; $callback(); }
            if ($guid !== self::$domain->getGuid()) { throw new \RuntimeException(); }
            return self::$domain;
        }
    }
    class pm_Session {
        public static $client;
        public static $exists = true;
        public static $impersonated = false;
        public static function isExist() { return self::$exists; }
        public static function getClient() { return self::$client; }
        public static function isImpersonated() { return self::$impersonated; }
    }
    class Modules_Help4DiskUsage_Runtime { public static function check($python) { return []; } }
    class Modules_Help4DiskUsage_Permissions { public static function check() { return []; } }
    class pm_Extension {
        public static function getById($id) { return new self(); }
        public function getVersion() { return '0.3.0'; }
    }
    class Modules_Help4DiskUsage_Task_Scan { public function setParams($params) { } }
    class pm_LongTask_Manager {
        public static $fail = false;
        public function start($task, $domain) { if (self::$fail) { throw new \RuntimeException('private failure'); } }
    }
    $checks = 0;
    function check($value, $message) { global $checks; $checks++; if (!$value) { throw new \RuntimeException($message); } }
    function denied($callback, $message) {
        try { $callback(); } catch (\Throwable $e) { check(true, $message); return; }
        check(false, $message);
    }
    function packet($data) {
        return '<packet><extension><call><result><status>ok</status><help4-disk-usage><audit><response>' .
            base64_encode(json_encode($data, JSON_THROW_ON_ERROR)) . '</response></audit></help4-disk-usage></result></call></extension></packet>';
    }
    function decodeRequest($xml) {
        $dom = new \DOMDocument(); $dom->loadXML($xml, LIBXML_NONET);
        return json_decode(base64_decode($dom->getElementsByTagName('request')->item(0)->textContent, true), true, 8, JSON_THROW_ON_ERROR);
    }
    function bridge($url, $xml) {
        $dom = new \DOMDocument(); $dom->loadXML($xml, LIBXML_NONET);
        $result = (new Modules_Help4DiskUsage_ApiRpc())->call(['audit' => ['request' => $dom->getElementsByTagName('request')->item(0)->textContent]]);
        return '<packet><extension><call><result><status>ok</status><help4-disk-usage><audit><response>' .
            $result['audit']['response'] . '</response></audit></help4-disk-usage></result></call></extension></packet>';
    }
    function removeFixture($dir) {
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { removeFixture($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($dir);
    }
}
namespace Help4\DiskUsagePlesk {
    class FixtureCurl { public $url; public $options; }
    function curl_init($url) { $handle = new FixtureCurl(); $handle->url = $url; return $handle; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; $GLOBALS['curl_options'] = $options; return true; }
    function curl_exec($handle) {
        $GLOBALS['network_calls'] = ($GLOBALS['network_calls'] ?? 0) + 1;
        $raw = \bridge($handle->url, $handle->options[CURLOPT_POSTFIELDS]);
        if (isset($GLOBALS['after_network'])) { $callback = $GLOBALS['after_network']; unset($GLOBALS['after_network']); $callback(); }
        return $handle->options[CURLOPT_WRITEFUNCTION]($handle, $raw) === strlen($raw);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_close($handle) { }
}
namespace {
    use Help4\DiskUsagePlesk\Transport;
    pm_Context::$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-rpc-' . bin2hex(random_bytes(8));
    mkdir(pm_Context::$dir, 0700);
    pm_Session::$client = new RpcClient();
    pm_Domain::$domain = new RpcDomain();
    pm_Domain::$domain->owner = new RpcClient();
    pm_Domain::$domain->owner->admin = false;
    $server = ['id' => 1, 'hostname' => 'panel.example.test', 'port' => 8443, 'accesshash' => 'synthetic-key'];
    try {
        foreach (['https://foreign.test', 'host@foreign.test', 'host/path', "host\nKEY: bad", 'host..test'] as $host) {
            denied(function () use ($server, $host) { Transport::endpoint(array_replace($server, ['hostname' => $host])); }, 'Unsafe endpoint accepted');
        }
        foreach ([true, 65536, '8443/other', -1] as $port) {
            denied(function () use ($server, $port) { Transport::endpoint(array_replace($server, ['port' => $port])); }, 'Unsafe port accepted');
        }
        check(Transport::endpoint(array_replace($server, ['hostname' => '2001:db8::1'])) === 'https://[2001:db8::1]:8443', 'IPv6 endpoint');
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Disabled bridge accepted');
        $config = Modules_Help4DiskUsage_Remote::configure(true);
        $hello = Transport::call($server, null, 'hello', [], 'bridge');
        check($hello['installation_id'] === $config['installation_id'], 'Native hello handshake');
        $identity = Transport::call($server, $config['installation_id'], 'identity', ['domain' => 'demo.example.test'], 'bridge')['identity'];
        check($identity['domain_id'] === 10 && $identity['username'] === 'demo', 'Identity discovery');
        check(!isset($identity['home']) && !isset($identity['path']), 'Absolute home exposed');
        $scope = array_intersect_key($identity, array_flip(['subscription_guid', 'owner_guid', 'identity_binding', 'domain', 'username']));
        $report = ['schema' => 1, 'platform' => PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux',
            'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'bytes' => 8, 'entries' => 1, 'files' => 1, 'directories' => 1,
            'skipped' => 0, 'errors' => 0, 'duration_seconds' => 0.1, 'complete' => true, 'limit' => null,
            'growth_bytes' => null, 'categories' => ['other' => 8], 'largest_files' => [
                ['path' => 'safe.txt', 'kind' => 'file', 'bytes' => 8, 'modified' => time(), 'category' => 'other']],
            'stale_files' => [], 'largest_trees' => [], 'entry_trees' => [],
            'binding' => Modules_Help4DiskUsage_Access::binding(pm_Domain::$domain)];
        Modules_Help4DiskUsage_Store::write('report-10', $report);
        $raw = Transport::call($server, $config['installation_id'], 'report', ['scope' => $scope]);
        check(!isset($raw['payload']['binding']) && $raw['payload']['bytes'] === 8 && $raw['ttl'] === 3600, 'Report binding stripping or TTL');
        $options = $GLOBALS['curl_options'];
        check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS verification disabled');
        check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'Redirect enabled');
        check($options[CURLOPT_CONNECTTIMEOUT] === 3 && $options[CURLOPT_TIMEOUT] === 20, 'Unbounded transport');
        check($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_PROXY] === '', 'Unsafe protocol/proxy');
        check($options[CURLOPT_WRITEFUNCTION](null, str_repeat('x', Transport::MAX_BYTES + 1)) === 0, 'Response cap ignored');
        foreach ([['owner_guid' => '33333333-3333-4333-8333-333333333333'], ['identity_binding' => str_repeat('a', 64)],
            ['username' => 'foreign'], ['domain' => 'foreign.example.test'], ['root' => '/foreign']] as $bad) {
            denied(function () use ($server, $config, $scope, $bad) {
                Transport::call($server, $config['installation_id'], 'report', ['scope' => array_replace($scope, $bad)], 'bridge');
            }, 'Foreign or arbitrary scope accepted');
        }
        pm_Session::$impersonated = true;
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Impersonated API allowed');
        pm_Session::$impersonated = false;
        pm_Session::$client->admin = false;
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Non-admin API allowed');
        pm_Session::$client->admin = true;
        pm_Session::$exists = false;
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Missing API identity fell back to admin');
        pm_Session::$exists = true;
        foreach (['1', null, false, 123] as $params) { denied(function () use ($params) { (new Modules_Help4DiskUsage_ApiRpc())->call($params); }, 'Hook parameter bypass'); }
        foreach (['doctype' => '<!DOCTYPE packet [<!ENTITY secret SYSTEM "file:///private">]><packet/>', 'xml' => '<packet>',
            'oversize' => str_repeat('x', Transport::MAX_BYTES + 1), 'nul' => "<packet>\0</packet>"] as $label => $value) {
            denied(function () use ($server, $value) { Transport::call($server, null, 'hello', [], function () use ($value) { return $value; }); }, $label . ' response accepted');
        }
        foreach (['nonce', 'installation_id', 'identity_checked_at', 'schema', 'payload'] as $field) {
            denied(function () use ($server, $config, $field) {
                Transport::call($server, $config['installation_id'], 'hello', [], function ($url, $xml) use ($config, $field) {
                    $request = decodeRequest($xml);
                    $response = ['schema' => 1, 'nonce' => $request['nonce'], 'installation_id' => $config['installation_id'],
                        'identity_checked_at' => time(), 'payload' => []];
                    $response[$field] = $field === 'identity_checked_at' ? time() - 31 : 'wrong';
                    return packet($response);
                });
            }, 'Unbound/replayed envelope accepted');
        }
        pm_Domain::$after = function () { pm_Domain::$domain->home = '/changed'; };
        denied(function () use ($server, $config) {
            Transport::call($server, $config['installation_id'], 'identity', ['domain' => 'demo.example.test'], 'bridge');
        }, 'Native identity change during report IO allowed');
        pm_Domain::$domain->home = '/synthetic/example';
        pm_Domain::$domain->webspace = 'invalid';
        denied(function () use ($server, $config) { Transport::call($server, $config['installation_id'], 'identity', ['domain' => 'demo.example.test'], 'bridge'); }, 'Unknown main-subscription property coerced');
        pm_Domain::$domain->webspace = '0';
        Modules_Help4DiskUsage_Store::write('state', ['attempts' => [], 'pending' => []]);
        pm_LongTask_Manager::$fail = true;
        denied(function () use ($server, $config, $scope) { Transport::call($server, $config['installation_id'], 'refresh', ['scope' => $scope], 'bridge'); }, 'Failed native task accepted');
        check(Modules_Help4DiskUsage_Store::read('state')['pending'] === [], 'Failed start retained reservation');
        pm_LongTask_Manager::$fail = false;
        Modules_Help4DiskUsage_Store::write('state', ['attempts' => [], 'pending' => []]);
        check(Transport::call($server, $config['installation_id'], 'refresh', ['scope' => $scope], 'bridge')['payload']['queued'], 'Bounded refresh failed');
        check(Modules_Help4DiskUsage_Store::read('state')['pending'][10]['admin'] === false, 'Remote refresh bypassed customer limits');
        denied(function () use ($server, $config, $scope) { Transport::call($server, $config['installation_id'], 'refresh', ['scope' => $scope], 'bridge'); }, 'Duplicate refresh admitted');
        $mutex = Modules_Help4DiskUsage_Store::mutex('bridge.lock');
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Concurrent API admitted');
        flock($mutex, LOCK_UN); fclose($mutex);
        Modules_Help4DiskUsage_Store::write('bridge-attempts', ['attempts' => array_fill(0, 600, time())]);
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'API hourly ceiling ignored');
        Modules_Help4DiskUsage_Remote::configure(false);
        denied(function () use ($server) { Transport::call($server, null, 'hello', [], 'bridge'); }, 'Disabled API remained usable');
        echo "Plesk bridge and authenticated-transport contract: $checks checks passed (SDK/network fixtures, not live TLS or panel proof)\n";
    } finally { removeFixture(pm_Context::$dir); }
}
