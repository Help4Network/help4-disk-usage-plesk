<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Store.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function denied($callback) { try { $callback(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected denial'); }
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
}
class PolicyClient {
    public $id = 1;
    public $admin = false;
    public $allowed = [1, 2];
    public function getId() { return $this->id; }
    public function isAdmin() { return $this->admin; }
    public function hasAccessToDomain($id) { return in_array($id, $this->allowed, true); }
}
class PolicyDomain {
    public $id = 1;
    public $items = [];
    public $planError = false;
    public $owner;
    public function __construct() { $this->owner = new PolicyClient(); }
    public function getId() { return $this->id; }
    public function getClient() { return $this->owner; }
    public function getGuid() { return 'dummy-guid-' . $this->id; }
    public function getName() { return 'demo' . $this->id . '.example.test'; }
    public function getHomePath() { return '/synthetic/home/' . $this->id; }
    public function getPlanItems() { if ($this->planError) { throw new RuntimeException('Fixture'); } return $this->items; }
}
function resetQueue() { Modules_Help4DiskUsage_Store::write('state', ['pending' => [], 'attempts' => []]); }
function removeFixture($path) {
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir() && !$entry->isLink()) { removeFixture($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-policy-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    $client = new PolicyClient();
    $domain = new PolicyDomain();
    $policy = Modules_Help4DiskUsage_Store::policy();
    $policy['python'] = PHP_BINARY;
    $policy = Modules_Help4DiskUsage_Store::validatePolicy($policy);
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    check(Modules_Help4DiskUsage_Store::effective($domain)['hourly'] === 3, 'Default policy');
    $domain->items = ['audit_extended'];
    check(Modules_Help4DiskUsage_Store::effective($domain)['hourly'] === 6, 'Extended profile');
    $policy['overrides'][1] = ['hourly' => 8, 'seconds' => 100];
    Modules_Help4DiskUsage_Store::write('policy', Modules_Help4DiskUsage_Store::validatePolicy($policy));
    check(Modules_Help4DiskUsage_Store::effective($domain)['hourly'] === 8, 'Subscription precedence');
    $domain->items = ['audit_disabled'];
    check(Modules_Help4DiskUsage_Store::effective($domain)['hourly'] === 0, 'Disabled profile cannot be overridden');
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, false); });
    $domain->items = ['audit_default', 'audit_extended'];
    denied(function () use ($domain) { Modules_Help4DiskUsage_Store::effective($domain); });
    $domain->planError = true;
    denied(function () use ($domain) { Modules_Help4DiskUsage_Store::effective($domain); });
    $domain->planError = false;
    $domain->items = [];
    $policy['overrides'] = [];
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    foreach ([['hourly' => 31], ['seconds' => 121], ['global_hourly' => 240], ['minimum_interval' => 0]] as $bad) {
        $invalid = $policy;
        $invalid['overrides'][1] = $bad;
        denied(function () use ($invalid) { Modules_Help4DiskUsage_Store::validatePolicy($invalid); });
    }
    $invalid = $policy;
    $invalid['profiles'] = ['audit_disabled' => ['hourly' => 3]];
    denied(function () use ($invalid) { Modules_Help4DiskUsage_Store::validatePolicy($invalid); });

    resetQueue();
    $client->allowed = [];
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, true); });
    check(Modules_Help4DiskUsage_Store::read('state')['attempts'] === [], 'Foreign attempt admitted');
    $client->allowed = [1, 2];
    $token = Modules_Help4DiskUsage_Store::reserve($domain, $client, false);
    check(strlen($token) === 32, 'Token entropy');
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, false); });
    $state = Modules_Help4DiskUsage_Store::read('state');
    check($state['pending'][1]['expires_at'] - $state['pending'][1]['time'] === 2760, 'Queue drain lease');
    $state['pending'][1]['time'] -= 601;
    Modules_Help4DiskUsage_Store::write('state', $state);
    check(Modules_Help4DiskUsage_Store::pending($domain), 'Live tail reservation expired prematurely');
    $binding = Modules_Help4DiskUsage_Access::binding($domain);
    $report = ['binding' => $binding, 'bytes' => 123, 'complete' => true];
    Modules_Help4DiskUsage_Store::finish(1, $token, $report);
    check(Modules_Help4DiskUsage_Store::status($domain)['state'] === 'complete', 'Success status');
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, false); });
    resetQueue();
    $token = Modules_Help4DiskUsage_Store::reserve($domain, $client, false);
    Modules_Help4DiskUsage_Store::finish(1, $token);
    check(Modules_Help4DiskUsage_Store::status($domain)['state'] === 'failed', 'Failure status');
    check(Modules_Help4DiskUsage_Store::report($domain)['bytes'] === 123, 'Failure erased last good report');
    $domain->owner->id = 2;
    check(Modules_Help4DiskUsage_Store::status($domain) === null, 'Foreign status after transfer');
    check(Modules_Help4DiskUsage_Store::report($domain) === null, 'Foreign report after transfer');
    $domain->owner->id = 1;
    resetQueue();
    $token = Modules_Help4DiskUsage_Store::reserve($domain, $client, false);
    $state = Modules_Help4DiskUsage_Store::read('state');
    $state['pending'][1]['expires_at'] = time() - 1;
    Modules_Help4DiskUsage_Store::write('state', $state);
    check(!Modules_Help4DiskUsage_Store::pending($domain), 'Expired status remains pending');
    check(Modules_Help4DiskUsage_Store::status($domain)['state'] === 'failed', 'Expired queue status');
    resetQueue();
    $first = Modules_Help4DiskUsage_Store::reserve($domain, $client, false);
    $reservation = Modules_Help4DiskUsage_Store::read('state')['pending'][1];
    Modules_Help4DiskUsage_Store::validateReservation($reservation, $domain);
    $domain->owner->id = 2;
    denied(function () use ($domain, $reservation) { Modules_Help4DiskUsage_Store::validateReservation($reservation, $domain); });
    check(!Modules_Help4DiskUsage_Store::pending($domain), 'Transferred reservation exposed');
    $next = Modules_Help4DiskUsage_Store::reserve($domain, $client, false);
    check($first !== $next, 'Transferred identity reused token');
    Modules_Help4DiskUsage_Store::finish(1, $first, $report);
    check(Modules_Help4DiskUsage_Store::pending($domain), 'Old worker cleared new reservation');
    $domain->owner->id = 1;
    resetQueue();
    $effective = Modules_Help4DiskUsage_Store::effective($domain)['policy_binding'];
    $domain->items = ['audit_extended'];
    check($effective !== Modules_Help4DiskUsage_Store::effective($domain)['policy_binding'], 'Plan change not bound');
    denied(function () use ($domain, $reservation) { Modules_Help4DiskUsage_Store::validateReservation($reservation, $domain); });
    $domain->items = [];

    $policy['minimum_interval'] = 60;
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    resetQueue();
    $state = ['pending' => [], 'attempts' => []];
    foreach ([1, 2, 3] as $offset) { $state['attempts'][] = ['actor' => 1, 'domain' => 2, 'time' => time() - 300 - $offset]; }
    Modules_Help4DiskUsage_Store::write('state', $state);
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, false); });
    foreach ($state['attempts'] as &$attempt) { $attempt['actor'] = 2; $attempt['domain'] = 1; }
    unset($attempt);
    Modules_Help4DiskUsage_Store::write('state', $state);
    denied(function () use ($domain, $client) { Modules_Help4DiskUsage_Store::reserve($domain, $client, false); });
    $client->admin = true;
    $token = Modules_Help4DiskUsage_Store::reserve($domain, $client, true);
    Modules_Help4DiskUsage_Store::finish(1, $token);
    resetQueue();
    $policy['global_hourly'] = 1;
    $policy['queue_limit'] = 1;
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    $token = Modules_Help4DiskUsage_Store::reserve($domain, $client, true);
    $other = new PolicyDomain(); $other->id = 2;
    denied(function () use ($other, $client) { Modules_Help4DiskUsage_Store::reserve($other, $client, true); });
    Modules_Help4DiskUsage_Store::finish(1, $token);
    denied(function () use ($other, $client) { Modules_Help4DiskUsage_Store::reserve($other, $client, true); });
    echo "Plan policy, admission, lease, transfer, failure and token-isolation tests passed\n";
} finally {
    removeFixture(pm_Context::$directory);
}
