<?php
require __DIR__ . '/../extension/plib/library/Access.php';
require __DIR__ . '/../extension/plib/library/Report.php';
require __DIR__ . '/../extension/plib/library/Store.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function denied($callback) { try { $callback(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected denial'); }
class FakeClient { public $allowed = [1]; public $admin = false; public function isAdmin() { return $this->admin; } public function hasAccessToDomain($id) { return in_array($id, $this->allowed, true); } }
class FakeDomain { public function getId() { return 1; } }
$client = new FakeClient();
$domain = new FakeDomain();
Modules_Help4DiskUsage_Access::authorize($client, $domain);
$client->allowed = [];
denied(function () use ($client, $domain) { Modules_Help4DiskUsage_Access::authorize($client, $domain); });
denied(function () use ($client, $domain) { Modules_Help4DiskUsage_Access::authorize($client, $domain, true); });
$client->admin = true;
denied(function () use ($client, $domain) { Modules_Help4DiskUsage_Access::authorizeQueued($client, $domain, ['admin' => false]); });
Modules_Help4DiskUsage_Access::authorizeQueued($client, $domain, ['admin' => true]);
$client->admin = false;
denied(function () use ($client, $domain) { Modules_Help4DiskUsage_Access::authorizeQueued($client, $domain, ['admin' => true]); });
denied(function () use ($client, $domain) { Modules_Help4DiskUsage_Access::authorizeQueued($client, $domain, []); });
foreach (['../neighbor', '/etc/passwd', 'C:/secret', 'a\\b', "x\0y", 'a//b'] as $path) {
    denied(function () use ($path) { Modules_Help4DiskUsage_Access::relative($path); });
}
$report = ['binding' => 'private', 'largest_files' => [['path' => 'httpdocs/a & b.txt', 'kind' => 'file', 'bytes' => 50, 'hint' => 'Review']]];
check(!isset(Modules_Help4DiskUsage_Report::publicReport($report)['binding']), 'Private binding in export');
$url = Modules_Help4DiskUsage_Report::fileManagerUrl($domain, $report, 'httpdocs/a & b.txt', 'file');
check($url === '/smb/file-manager/list/domainId/1?currentDir=%2Fhttpdocs', 'Wrong parent jump');
denied(function () use ($domain, $report) { Modules_Help4DiskUsage_Report::fileManagerUrl($domain, $report, 'unknown', 'file'); });
foreach (['=HYPERLINK("bad")', ' +1', '@SUM(A1)', '-2'] as $cell) {
    check(Modules_Help4DiskUsage_Report::csvCell($cell)[0] === "'", 'Formula injection');
}
check(Modules_Help4DiskUsage_Report::rows($report, 'bad', 'not found', 'bytes', 100)['count'] === 0, 'Search failed');
echo "Permission, traversal, cached-membership, file-manager and export tests passed\n";
