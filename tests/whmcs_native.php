<?php
namespace WHMCS\Authentication {
    class CurrentUser {
        public static $client;
        public static $user = 100;
        public static $admin = 10;
        public static $masquerading = false;
        public function user() { return self::$user ? (object)['id' => self::$user] : null; }
        public function client() { return self::$client; }
        public function admin() { return self::$admin ? (object)['id' => self::$admin] : null; }
        public function isAuthenticatedUser() { return self::$user !== null; }
        public function isAuthenticatedAdmin() { return self::$admin !== null; }
        public function isMasqueradingAdmin() { return self::$masquerading; }
    }
    class Client {
        public $id = 1;
        public $products = true;
        public $manage = true;
        public function hasPermission($permission) { return $permission === 'products' ? $this->products : $this->manage; }
    }
}
namespace WHMCS\Database {
    class Capsule {
        public static $pdo;
        public static $locks = 0;
        public static function table($name) { return new Query($name); }
        public static function connection() { return new self(); }
        public static function schema() { return new Schema(); }
        public function transaction($callback) {
            self::$pdo->beginTransaction();
            try { $result = $callback(); self::$pdo->commit(); return $result; }
            catch (\Throwable $e) { if (self::$pdo->inTransaction()) { self::$pdo->rollBack(); } throw $e; }
        }
    }
    class Column {
        public $schema;
        public $name;
        public function __construct($schema, $name) { $this->schema = $schema; $this->name = $name; }
        public function primary() { $this->schema->primary = $this->name; return $this; }
        public function index() { return $this; }
    }
    class Blueprint {
        public $columns = [];
        public $primary;
        public function unsignedInteger($name) { return $this->add($name, 'INTEGER'); }
        public function string($name, $size) { return $this->add($name, 'TEXT'); }
        public function text($name) { return $this->add($name, 'TEXT'); }
        private function add($name, $type) { $this->columns[$name] = $type; return new Column($this, $name); }
    }
    class Schema {
        public function hasTable($name) {
            $stmt = Capsule::$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?"); $stmt->execute([$name]);
            return $stmt->fetchColumn() !== false;
        }
        public function create($name, $callback) {
            $blueprint = new Blueprint(); $callback($blueprint); $columns = [];
            foreach ($blueprint->columns as $column => $type) { $columns[] = '"' . $column . '" ' . $type . ($column === $blueprint->primary ? ' PRIMARY KEY' : ''); }
            Capsule::$pdo->exec('CREATE TABLE ' . $name . ' (' . implode(',', $columns) . ')');
        }
    }
    class Query {
        public $table;
        public $where = [];
        public $values = [];
        public $joins = [];
        public $columns = '*';
        public $order = '';
        public $limit = null;
        public $offset = 0;
        public function __construct($table) { $this->table = $table; }
        public function where($column, $value) { $this->where[] = $column . '=?'; $this->values[] = $value; return $this; }
        public function join($table, $left, $operator, $right) { $this->joins[] = ' JOIN ' . $table . ' ON ' . $left . $operator . $right; return $this; }
        public function select(...$columns) { $this->columns = implode(',', $columns); return $this; }
        public function orderBy($column) { $this->order = ' ORDER BY ' . $column; return $this; }
        public function limit($limit) { $this->limit = (int)$limit; return $this; }
        public function offset($offset) { $this->offset = (int)$offset; return $this; }
        public function lockForUpdate() { Capsule::$locks++; return $this; }
        private function clause() { return $this->where ? ' WHERE ' . implode(' AND ', $this->where) : ''; }
        public function get() {
            $sql = 'SELECT ' . $this->columns . ' FROM ' . $this->table . implode('', $this->joins) . $this->clause() . $this->order;
            if ($this->limit !== null) { $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset; }
            $stmt = Capsule::$pdo->prepare($sql); $stmt->execute($this->values); return $stmt->fetchAll(\PDO::FETCH_OBJ);
        }
        public function first() { $rows = $this->limit(1)->get(); return $rows[0] ?? null; }
        public function insertOrIgnore($data) { return $this->insert($data, true); }
        private function insert($data, $ignore = false) {
            $sql = 'INSERT ' . ($ignore ? 'OR IGNORE ' : '') . 'INTO ' . $this->table . ' ("' . implode('","', array_keys($data)) . '") VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
            $stmt = Capsule::$pdo->prepare($sql); return $stmt->execute(array_values($data));
        }
        public function updateOrInsert($key, $data) {
            $query = new self($this->table); foreach ($key as $column => $value) { $query->where($column, $value); }
            if (!$query->first()) { return $this->insert($key + $data); }
            return $query->update($data);
        }
        public function update($data) {
            $set = []; foreach ($data as $key => $value) { $set[] = '"' . $key . '"=?'; }
            $stmt = Capsule::$pdo->prepare('UPDATE ' . $this->table . ' SET ' . implode(',', $set) . $this->clause());
            return $stmt->execute(array_merge(array_values($data), $this->values));
        }
        public function delete() { $stmt = Capsule::$pdo->prepare('DELETE FROM ' . $this->table . $this->clause()); return $stmt->execute($this->values); }
    }
}
namespace {
    use WHMCS\Database\Capsule;
    use WHMCS\Authentication\CurrentUser;
    use Help4\DiskUsagePlesk\Native;
    use Help4\DiskUsagePlesk\View;
    use Help4\DiskUsagePlesk\Transport;
    ob_start();
    require __DIR__ . '/whmcs_transport.php';
    $transportProof = ob_get_contents(); ob_clean();
    define('WHMCS', true);
    require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/help4_disk_usage_plesk.php';
    function generate_token($type) { return 'synthetic-token'; }
    function check_token($namespace) { if (($_POST['token'] ?? '') !== 'synthetic-token') { throw new \RuntimeException('CSRF'); } }
    function decrypt($value) { return $value; }
    Capsule::$pdo = new \PDO('sqlite::memory:');
    Capsule::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    Capsule::$pdo->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT); CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, roleid INTEGER, disabled INTEGER); CREATE TABLE tblservers (id INTEGER PRIMARY KEY, type TEXT, disabled INTEGER, hostname TEXT, ipaddress TEXT, port INTEGER, username TEXT, password TEXT, accesshash TEXT); CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT); CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, server INTEGER, packageid INTEGER, username TEXT, domain TEXT, domainstatus TEXT)');
    foreach (['clientArea' => 'on', 'access' => '1', 'clientReadsHourly' => '30', 'serverRequestsHourly' => '120'] as $name => $value) {
        $stmt = Capsule::$pdo->prepare('INSERT INTO tbladdonmodules VALUES (?,?,?)'); $stmt->execute([Native::MODULE, $name, $value]);
    }
    Capsule::$pdo->exec("INSERT INTO tbladmins VALUES (10,1,0); INSERT INTO tblservers VALUES (1,'plesk',0,'panel.example.test','',8443,'admin','','synthetic-key'); INSERT INTO tblproducts VALUES (1,'plesk'); INSERT INTO tblhosting VALUES (1,1,1,1,'demo','demo.example.test','Active'); INSERT INTO tblhosting VALUES (2,2,1,1,'foreign','foreign.example.test','Active')");
    CurrentUser::$client = new WHMCS\Authentication\Client();
    pm_Context::$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-native-' . bin2hex(random_bytes(8));
    mkdir(pm_Context::$dir, 0700);
    Modules_Help4DiskUsage_Remote::configure(true);
    $report = ['schema' => 1, 'platform' => PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux',
        'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'bytes' => 8, 'entries' => 1, 'files' => 1, 'directories' => 1,
        'skipped' => 0, 'errors' => 0, 'duration_seconds' => 0.1, 'complete' => true, 'limit' => null,
        'growth_bytes' => null, 'categories' => ['other' => 8], 'largest_files' => [
            ['path' => 'safe.txt', 'kind' => 'file', 'bytes' => 8, 'modified' => time(), 'category' => 'other']],
        'stale_files' => [], 'largest_trees' => [], 'entry_trees' => [],
        'binding' => Modules_Help4DiskUsage_Access::binding(pm_Domain::$domain)];
    Modules_Help4DiskUsage_Store::write('report-10', $report);
    function clearRates() { Capsule::$pdo->exec('DELETE FROM ' . Native::LIMITS); }
    function mapped() {
        $stmt = Capsule::$pdo->query('SELECT data FROM ' . Native::BINDINGS . ' WHERE service_id=1');
        return json_decode($stmt->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    }
    try {
        $checks = 0;
        check(help4_disk_usage_plesk_activate()['status'] === 'success', 'Activation');
        check(help4_disk_usage_plesk_activate()['status'] === 'success', 'Idempotent activation');
        check(Capsule::schema()->hasTable(Native::SERVERS) && Capsule::schema()->hasTable(Native::BINDINGS), 'Distinct migrations');
        Native::connect(1); clearRates();
        Native::approve(1); clearRates();
        $binding = mapped();
        check($binding['service_id'] === 1 && $binding['client_id'] === 1 && $binding['domain_id'] === 10, 'Mapping identities');
        check(strlen($binding['revision']) === 64 && strlen($binding['server_binding']) === 64, 'Mapping revision');
        $clean = Native::report(1);
        check($clean['bytes'] === 8 && $clean['policy_ttl'] === 3600 && !isset($clean['binding']), 'Fresh authorized report');
        $calls = $GLOBALS['network_calls'];
        denied(function () { Native::report(2); }, 'Foreign service report');
        check($GLOBALS['network_calls'] === $calls, 'Foreign service reached network');
        $link = Native::jump(1, 'safe.txt', 'file');
        check(strpos($link, 'https://panel.example.test:8443/modules/help4-disk-usage/') === 0 && strpos($link, 'domain=10') !== false, 'Session-preserving native jump');
        check(strpos($link, 'password') === false && strpos($link, 'synthetic-key') === false, 'Credentials in jump');
        denied(function () { Native::jump(1, '../foreign', 'file'); }, 'Foreign path jump');
        $GLOBALS['after_network'] = function () { Capsule::$pdo->exec('UPDATE tblhosting SET userid=2 WHERE id=1'); };
        denied(function () { Native::report(1); }, 'WHMCS transfer during IO');
        Capsule::$pdo->exec('UPDATE tblhosting SET userid=1 WHERE id=1');
        $GLOBALS['after_network'] = function () { CurrentUser::$client->id = 2; };
        denied(function () { Native::report(1); }, 'Selected-client switch during IO');
        CurrentUser::$client->id = 1;
        $GLOBALS['after_network'] = function () { Capsule::$pdo->exec("UPDATE tblservers SET hostname='changed.example.test' WHERE id=1"); };
        denied(function () { Native::report(1); }, 'Endpoint change during IO');
        Capsule::$pdo->exec("UPDATE tblservers SET hostname='panel.example.test' WHERE id=1");
        $GLOBALS['after_network'] = function () {
            $binding = mapped(); $binding['revision'] = str_repeat('a', 64);
            Capsule::table(Native::BINDINGS)->where('service_id', 1)->update(['data' => json_encode($binding)]);
        };
        denied(function () { Native::report(1); }, 'Mapping revision change during IO');
        Capsule::table(Native::BINDINGS)->where('service_id', 1)->update(['data' => json_encode($binding)]);
        foreach (['products', 'manage'] as $permission) {
            CurrentUser::$client->$permission = false;
            denied(function () { Native::report(1); }, 'Missing product permission');
            CurrentUser::$client->$permission = true;
        }
        CurrentUser::$masquerading = true;
        denied(function () { Native::report(1); }, 'Masquerading customer');
        denied(function () { Native::admin(); }, 'Masquerading admin');
        CurrentUser::$masquerading = false;
        CurrentUser::$user = null;
        denied(function () { Native::report(1); }, 'Client ID without User');
        CurrentUser::$user = 100;
        Capsule::$pdo->exec("UPDATE tblhosting SET domainstatus='Suspended' WHERE id=1");
        denied(function () { Native::report(1); }, 'Suspended WHMCS service');
        Capsule::$pdo->exec("UPDATE tblhosting SET domainstatus='Active' WHERE id=1");
        Capsule::$pdo->exec('UPDATE tblservers SET disabled=1 WHERE id=1');
        denied(function () { Native::report(1); }, 'Disabled WHMCS server');
        Capsule::$pdo->exec('UPDATE tblservers SET disabled=0 WHERE id=1');
        Capsule::$pdo->exec('UPDATE tbladmins SET roleid=2 WHERE id=10');
        denied(function () { Native::health(); }, 'Unapproved addon role');
        Capsule::$pdo->exec('UPDATE tbladmins SET roleid=1 WHERE id=10');
        Capsule::$pdo->exec('UPDATE tbladmins SET disabled=1 WHERE id=10');
        denied(function () { Native::connect(1); }, 'Disabled native admin');
        Capsule::$pdo->exec('UPDATE tbladmins SET disabled=0 WHERE id=10');
        clearRates();
        Native::checkServer(1);
        $health = Native::health();
        check($health['rows'][0]['status'] === 'degraded' && $health['rows'][0]['pending'] === null, 'Missing history fabricated healthy zero');
        for ($i = 2; $i <= 22; $i++) { Capsule::$pdo->exec("INSERT INTO tblservers VALUES ($i,'plesk',0,'panel$i.example.test','',8443,'admin','','synthetic-key')"); }
        check(count(Native::health()['rows']) === 20 && count(Native::health(2)['rows']) === 2, 'Bounded health pagination');
        Capsule::table(Native::LIMITS)->updateOrInsert(['key' => 'client:100'], ['data' => json_encode(array_fill(0, 30, time()))]);
        denied(function () { Native::report(1); }, 'Client read hourly limit');
        clearRates();
        Capsule::table(Native::LIMITS)->updateOrInsert(['key' => 'server:1'], ['data' => json_encode(['token' => str_repeat('a', 32), 'until' => time() + 30])]);
        denied(function () { Native::report(1); }, 'Concurrent WHMCS server action');
        clearRates();
        Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'serverRequestsHourly')->update(['value' => '240']);
        Capsule::table(Native::LIMITS)->updateOrInsert(['key' => 'server-attempts:1'], ['data' => json_encode(array_fill(0, 121, time()))]);
        check(Native::report(1)['bytes'] === 8, 'Advertised server cap rejected more than 120 attempts');
        Capsule::table(Native::LIMITS)->updateOrInsert(['key' => 'server-attempts:1'], ['data' => json_encode(array_fill(0, 240, time()))]);
        denied(function () { Native::report(1); }, 'Maximum editable server cap exceeded');
        Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'serverRequestsHourly')->update(['value' => '120']);
        clearRates();
        Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'clientReadsHourly')->update(['value' => '999']);
        denied(function () { Native::report(1); }, 'Invalid editable cap');
        Capsule::table('tbladdonmodules')->where('module', Native::MODULE)->where('setting', 'clientReadsHourly')->update(['value' => '30']);
        $html = View::report(1, $clean, ['search' => '"><script>alert(1)</script>'], '"><img src=x>');
        check(strpos($html, '<script>') === false && strpos($html, '<img src=x>') === false, 'WHMCS HTML injection');
        check(strpos($html, 'help4network.com') !== false && strpos($html, 'method="post"') !== false, 'Footer or POST control missing');
        $html = View::services([(object)['id' => 1, 'domain' => '<img src=x onerror=alert(1)>']]);
        check(strpos($html, '<img') === false, 'Service name not escaped');
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['action' => 'refresh', 'serviceid' => 1, 'token' => 'invalid']; $_GET = [];
        $calls = $GLOBALS['network_calls'];
        $page = help4_disk_usage_plesk_clientarea([]);
        check($page['requirelogin'] === true && strpos($page['vars']['audit_html'], 'Service unavailable') !== false, 'CSRF or requirelogin');
        check($GLOBALS['network_calls'] === $calls, 'Invalid CSRF caused network side effect');
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['serviceid' => 2]; $_POST = [];
        $page = help4_disk_usage_plesk_clientarea([]);
        check(strpos($page['vars']['audit_html'], 'foreign.example.test') === false, 'Foreign identity rendered');
        clearRates();
        $GLOBALS['after_network'] = function () { Native::disconnect(1); };
        denied(function () { Native::connect(1); }, 'Disconnect during connect resurrected pin');
        denied(function () { Native::entity(1); }, 'Disconnected service still scoped');
        check(!Capsule::table(Native::BINDINGS)->where('service_id', 1)->first(), 'Disconnect retained approved bindings');
        $state = json_decode(Capsule::table(Native::SERVERS)->where('server_id', 1)->first()->data, true);
        check(isset($state['revoked']) && !isset($state['installation_id']), 'Disconnect resurrected server pin');
        check(help4_disk_usage_plesk_deactivate()['status'] === 'success' && Native::setting('clientArea') === '', 'Deactivation retained customer access');
        check(Capsule::schema()->hasTable(Native::BINDINGS), 'Private data unexpectedly deleted');
        check(Capsule::$locks > 0, 'Rate/lease code omitted row locks');
        echo $transportProof . "WHMCS native-adapter, SQLite lifecycle and page fixtures: $checks checks passed (not licensed WHMCS/MySQL proof)\n";
    } finally { removeFixture(pm_Context::$dir); ob_end_flush(); }
}
