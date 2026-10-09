<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Store.php';
require __DIR__ . '/../extension/plib/library/Releases.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function denied($callback) { try { $callback(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected denial'); }
class pm_Context {
    public static $directory;
    public static function getVarDir() { return self::$directory; }
}
class ReleaseClient { public $admin = true; public function isAdmin() { return $this->admin; } }
class pm_Session {
    public static $client;
    public static $impersonated = false;
    public static function getClient() { return self::$client; }
    public static function isImpersonated() { return self::$impersonated; }
}
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-releases-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
pm_Session::$client = new ReleaseClient();
try {
    $body = json_encode(['tag_name' => 'v1.0.0', 'draft' => false, 'prerelease' => false,
        'html_url' => 'https://attacker.example.test', 'body' => '<script>bad</script>']);
    $result = Modules_Help4DiskUsage_Releases::decode($body);
    check($result === ['state' => 'available', 'version' => '1.0.0', 'tag' => 'v1.0.0'], 'Remote fields leaked');
    foreach (['v1.0.0-rc1', '01.0.0', '1.0', '1.0.0/../../secret', '<script>', 'v99999.1.1'] as $tag) {
        denied(function () use ($tag) { Modules_Help4DiskUsage_Releases::decode(json_encode(['tag_name' => $tag, 'draft' => false, 'prerelease' => false])); });
    }
    denied(function () { Modules_Help4DiskUsage_Releases::decode(str_repeat('x', 65537)); });
    denied(function () { Modules_Help4DiskUsage_Releases::decode('{"tag_name":"v1.0.0","draft":"false","prerelease":false}'); });
    check(Modules_Help4DiskUsage_Releases::decode('{"tag_name":"v1.0.0","draft":true,"prerelease":false}')['state'] === 'no_release', 'Draft admitted');
    $calls = 0;
    $fetch = function () use (&$calls, $body) { $calls++; return ['status' => 200, 'body' => $body]; };
    pm_Session::$client->admin = false;
    denied(function () use ($fetch) { Modules_Help4DiskUsage_Releases::check($fetch); });
    denied(function () { Modules_Help4DiskUsage_Releases::cached(); });
    pm_Session::$client->admin = true;
    pm_Session::$impersonated = true;
    denied(function () use ($fetch) { Modules_Help4DiskUsage_Releases::check($fetch); });
    check($calls === 0, 'Unauthorized outbound request');
    pm_Session::$impersonated = false;
    Modules_Help4DiskUsage_Releases::check($fetch);
    check($calls === 1, 'Stable release not checked');
    denied(function () use ($fetch) { Modules_Help4DiskUsage_Releases::check($fetch); });
    check($calls === 1, 'Cooldown did not prevent network spam');
    $cache = Modules_Help4DiskUsage_Store::read('release');
    $cache['attempted_at'] = time() - 301;
    Modules_Help4DiskUsage_Store::write('release', $cache);
    denied(function () { Modules_Help4DiskUsage_Releases::check(function () { return ['status' => 429, 'body' => '']; }); });
    $cache = Modules_Help4DiskUsage_Releases::cached();
    check(isset($cache['failed_at']) && $cache['result']['version'] === '1.0.0', 'Failed check erased prior result');
    $cache['attempted_at'] = time() - 301;
    Modules_Help4DiskUsage_Store::write('release', $cache);
    Modules_Help4DiskUsage_Releases::check(function () { return ['status' => 404, 'body' => '']; });
    check(Modules_Help4DiskUsage_Releases::cached()['result']['state'] === 'no_release', '404 not distinguished');
    check(!isset(Modules_Help4DiskUsage_Releases::cached()['failed_at']), 'Success retained failure flag');
    echo "Stable release validation, admin isolation, cooldown and stale-cache tests passed\n";
} finally {
    $audit = pm_Context::$directory . DIRECTORY_SEPARATOR . 'audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
}
