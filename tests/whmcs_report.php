<?php
require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Scope.php';
require __DIR__ . '/../integrations/whmcs/modules/addons/help4_disk_usage_plesk/lib/Report.php';
require __DIR__ . '/../extension/plib/library/Process.php';
use Help4\DiskUsagePlesk\Scope;
use Help4\DiskUsagePlesk\Report;
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function denied($callback, $expected = 'Report unavailable') {
    $message = '';
    try { $callback(); } catch (Throwable $e) { $message = $e->getMessage(); }
    check($message === $expected, 'Invalid report/intent accepted or internal details leaked: ' . $message);
}
function fixture() {
    $file = ['path' => 'httpdocs/cache/item.bin', 'kind' => 'file', 'bytes' => 100,
        'modified' => time() - 60, 'category' => 'cache', 'hint' => 'untrusted hint'];
    $tree = ['path' => '.', 'kind' => 'directory', 'bytes' => 100, 'entries' => 1,
        'direct_bytes' => 100, 'direct_files' => 1, 'category' => 'other', 'hint' => 'untrusted hint'];
    return ['schema' => 1, 'platform' => 'linux', 'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'bytes' => 100, 'entries' => 1, 'files' => 1, 'directories' => 1, 'skipped' => 0, 'errors' => 0,
        'complete' => true, 'limit' => null, 'duration_seconds' => 0.01, 'growth_bytes' => -20,
        'categories' => ['cache' => 100], 'largest_files' => [$file], 'stale_files' => [],
        'largest_trees' => [$tree], 'entry_trees' => [$tree]];
}
$raw = fixture();
$raw['binding'] = '/private/home'; $raw['credentials'] = 'not-for-export';
$raw['built_by'] = ['url' => 'https://untrusted.example.test'];
$raw['largest_files'][0] += ['contents' => 'not-for-export', 'url' => 'https://untrusted.example.test', 'owner' => 'not-for-export'];
$clean = Report::normalize($raw);
check($clean['complete'] && !$clean['stale'] && !$clean['totals_are_lower_bounds'], 'Complete/fresh coverage lost');
check($clean['growth_bytes'] === -20, 'Comparable signed growth lost');
check($clean['largest_files'][0]['hint'] === Report::HINTS['cache'], 'Remote hint trusted');
check($clean['built_by'] === ['name' => 'Help4 Network', 'url' => 'https://help4network.com'], 'Credit/remote link trusted');
check(!str_contains(Report::json($raw), 'not-for-export') && !str_contains(Report::json($raw), '/private/home') &&
    !str_contains(Report::json($raw), 'untrusted.example.test'), 'Unknown private metadata escaped');
check(Report::normalize($clean) === $clean, 'Normalized report not idempotent');
foreach (array_keys(fixture()) as $field) {
    $bad = fixture(); unset($bad[$field]);
    denied(function () use ($bad) { Report::normalize($bad); });
}
foreach ([['schema', '1'], ['platform', 'macos'], ['complete', 1], ['entries', '1'], ['bytes', -1],
    ['bytes', Report::MAX_NUMBER + 1], ['files', 2], ['directories', 0], ['directories', 50001],
    ['skipped', 2], ['errors', 3], ['duration_seconds', INF], ['duration_seconds', NAN],
    ['duration_seconds', -1], ['duration_seconds', 136], ['duration_seconds', '1'],
    ['growth_bytes', 1.0], ['growth_bytes', Report::MAX_NUMBER + 1], ['limit', '/private/internal-error']] as [$key, $value]) {
    $bad = fixture(); $bad[$key] = $value;
    denied(function () use ($bad) { Report::normalize($bad); });
}
foreach (['2026-02-30T01:02:03Z', '2026-10-10 01:02:03Z', '2026-10-10T01:02:03+01:00',
    '2026-10-10T24:00:00Z', '2026-10-10T01:02:03Zjunk', gmdate('Y-m-d\TH:i:s\Z', time() + 60)] as $date) {
    $bad = fixture(); $bad['scanned_at'] = $date;
    denied(function () use ($bad) { Report::normalize($bad); });
}
$raw = fixture(); $raw['scanned_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 3601);
check(Report::normalize($raw)['stale'], 'Stale report labelled fresh');
check(!Report::normalize($raw, null, 7200)['stale'], 'Current bounded TTL ignored');
check(json_decode(Report::json($raw, 7200), true)['stale'] === false, 'Export TTL lost');
foreach ([299, 86401, '3600', false] as $ttl) { denied(function () use ($raw, $ttl) { Report::normalize($raw, null, $ttl); }); }
foreach ([['complete', false], ['errors', 1], ['skipped', 1], ['limit', 'time']] as [$key, $value]) {
    $raw = fixture(); $raw[$key] = $value; $partial = Report::normalize($raw);
    check(!$partial['complete'] && $partial['totals_are_lower_bounds'] && $partial['growth_bytes'] === null,
        'Incomplete coverage retained complete/growth claim');
    check(str_contains(Report::csv($raw), 'lower bounds'), 'Partial export lost coverage warning');
}
foreach ([['unknown' => 100], ['cache' => 99], ['cache' => '100'], ['cache' => -1]] as $categories) {
    $raw = fixture(); $raw['categories'] = $categories;
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (Report::SECTIONS as $section) {
    $raw = fixture(); $raw[$section] = ['named' => $raw['largest_files'][0]];
    denied(function () use ($raw) { Report::normalize($raw); });
    $raw = fixture(); $raw[$section] = array_fill(0, 201, $raw['largest_files'][0]);
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (['path' => '../neighbor', 'kind' => 'directory', 'bytes' => 101, 'modified' => '1', 'category' => 'unknown'] as $key => $value) {
    $raw = fixture(); $raw['largest_files'][0][$key] = $value;
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (['path', 'kind', 'bytes', 'modified', 'category'] as $key) {
    $raw = fixture(); unset($raw['largest_files'][0][$key]);
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (['entries', 'direct_bytes', 'direct_files'] as $key) {
    $raw = fixture(); unset($raw['largest_trees'][0][$key]);
    denied(function () use ($raw) { Report::normalize($raw); });
}
$raw = fixture(); $raw['bytes'] = $raw['entries'] = $raw['files'] = 0; $raw['categories'] = []; $raw['growth_bytes'] = null;
$raw['largest_files'] = [];
foreach (['largest_trees', 'entry_trees'] as $section) {
    foreach (['bytes', 'entries', 'direct_bytes', 'direct_files'] as $key) { $raw[$section][0][$key] = 0; }
}
check(Report::normalize($raw)['bytes'] === 0 && Report::normalize($raw)['complete'], 'Measured empty report rejected');
$raw['entries'] = $raw['files'] = 200;
foreach (['largest_trees', 'entry_trees'] as $section) {
    $raw[$section][0]['entries'] = $raw[$section][0]['direct_files'] = 200;
}
for ($i = 0; $i < 200; $i++) {
    $raw['largest_files'][] = ['path' => 'item-' . $i, 'kind' => 'file', 'bytes' => 0,
        'modified' => -1, 'category' => 'other'];
}
check(count(Report::normalize($raw)['largest_files']) === 200, 'Exact retained-row ceiling rejected');
$raw = fixture(); $raw['files'] = 2; $raw['entries'] = 2; $raw['largest_files'][] = $raw['largest_files'][0];
denied(function () use ($raw) { Report::normalize($raw); });
$raw = fixture(); $raw['entry_trees'][0]['bytes'] = 99;
denied(function () use ($raw) { Report::normalize($raw); });
$raw = fixture(); $raw['largest_trees'][0]['direct_files'] = 2;
denied(function () use ($raw) { Report::normalize($raw); });
foreach (['../neighbor', '/etc/passwd', 'C:/secret', 'a\\b', 'a//b', 'a/./b', 'a/../b', '.', '',
    "x\0y", "x\ty", "x\ny", "x\x7fy", "x\xc2\x85y", "x\xe2\x80\xaey", "x\xe2\x80\xa8y", "bad\xff", str_repeat('x', 4097)] as $path) {
    $raw = fixture(); $raw['largest_files'][0]['path'] = $path;
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (['CON', 'a/NUL.txt', 'COM1.log', 'a/trailing.', 'a/trailing ', 'bad?name', 'bad"name',
    "COM\xc2\xb9", "COM\xc2\xb2.txt", "COM\xc2\xb3", "LPT\xc2\xb9", "a/LPT\xc2\xb2.log", "LPT\xc2\xb3"] as $path) {
    $raw = fixture(); $raw['platform'] = 'windows'; $raw['largest_files'][0]['path'] = $path;
    denied(function () use ($raw) { Report::normalize($raw); });
}
foreach (['=HYPERLINK(1)', ' +1', '@SUM(A1)', '-2', "\xef\xbc\x9d1+2", "\xc2\xa0=1+2", 'a,";=1+2', 'literal%2Fname',
    "valid-\xc3\xa9-\xf0\x9f\x93\x81.txt"] as $path) {
    $raw = fixture(); $raw['largest_files'][0]['path'] = $path;
    $csv = Report::csv($raw);
    $stream = fopen('php://memory', 'w+'); fwrite($stream, $csv); rewind($stream);
    $rows = []; while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) { $rows[] = $row; } fclose($stream);
    check($rows[2][1] === 'path: ' . $path && count($rows[2]) === 7, 'Filename formula/cell separator not neutralized');
    check(json_decode(Report::json($raw), true)['largest_files'][0]['path'] === $path, 'JSON lost exact filename');
    check(str_contains($csv, 'https://help4network.com') && strlen($csv) <= Report::MAX_EXPORT_BYTES, 'CSV credit/cap lost');
}
$raw = fixture();
check(Report::navigationIntent($raw, 'httpdocs/cache/item.bin', 'file') ===
    ['path' => 'httpdocs/cache/item.bin', 'kind' => 'file', 'directory' => 'httpdocs/cache'], 'Wrong file parent intent');
check(Report::navigationIntent($raw, '.', 'directory')['directory'] === '.', 'Root-directory intent broken');
$raw['largest_files'][0]['path'] = 'top.txt';
check(Report::navigationIntent($raw, 'top.txt', 'file')['directory'] === '.', 'Root-file intent broken');
denied(function () use ($raw) { Report::navigationIntent($raw, 'unknown.txt', 'file'); }, 'Path unavailable');
denied(function () use ($raw) { Report::navigationIntent($raw, 'top.txt', 'directory'); }, 'Path unavailable');
denied(function () use ($raw) { Report::navigationIntent($raw, '../neighbor', 'file'); }, 'Path unavailable');
denied(function () use ($raw) { Report::navigationIntent($raw, 'top.txt', 'symlink'); }, 'Path unavailable');
$raw = fixture(); $raw['extra'] = str_repeat('x', Scope::MAX_PAYLOAD_BYTES);
denied(function () use ($raw) { Report::normalize($raw); });
$raw = fixture(); $nested = []; for ($i = 0; $i < 17; $i++) { $nested = [$nested]; } $raw['extra'] = $nested;
denied(function () use ($raw) { Report::normalize($raw); });

$actor = function () { return ['authenticated' => true, 'user_id' => 100, 'client_id' => 10,
    'products_allowed' => true, 'manage_products_allowed' => true, 'masquerading' => false]; };
$entity = function () { return ['service' => ['id' => 1, 'client_id' => 10, 'server_id' => 2, 'status' => 'Active',
    'module' => 'plesk', 'server_enabled' => true, 'username' => 'demo', 'domain' => 'demo.example.test', 'server_binding' => str_repeat('a', 64)],
    'binding' => ['service_id' => 1, 'client_id' => 10, 'server_id' => 2, 'username' => 'demo', 'domain' => 'demo.example.test',
    'server_binding' => str_repeat('a', 64), 'subscription_guid' => '12345678-1234-1234-1234-123456789abc',
    'owner_guid' => '22345678-1234-1234-1234-123456789abc', 'identity_binding' => str_repeat('c', 64), 'revision' => str_repeat('b', 64)]]; };
$read = function ($scope) { return array_intersect_key($scope, array_flip(['server_id', 'server_binding', 'subscription_guid',
    'owner_guid', 'identity_binding', 'revision'])) + ['identity_checked_at' => time(), 'payload' => fixture()]; };
check(Report::read(1, $actor, $entity, $read)['largest_files'][0]['hint'] === Report::HINTS['cache'], 'Scoped parser not composed');
denied(function () use ($actor, $entity, $read) { Report::read(2, $actor, $entity, $read); }, 'Service unavailable');
denied(function () use ($actor, $entity, $read) { Report::read(1, $actor, $entity, function ($scope) use ($read) {
    $envelope = $read($scope); $envelope['payload']['bytes'] = -1; return $envelope;
}); }, 'Service unavailable');
$changed = false;
denied(function () use ($actor, $entity, $read, &$changed) { Report::read(1, $actor, function () use ($entity, &$changed) {
    $row = $entity(); if ($changed) { $row['service']['client_id'] = 11; } return $row;
}, function ($scope) use ($read, &$changed) { $changed = true; return $read($scope); }); }, 'Service unavailable');

$python = getenv('H4_TEST_PYTHON') ?: (getenv('pythonLocation')
    ? getenv('pythonLocation') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'bin/python')
    : '/usr/bin/python3');
$capture = Modules_Help4DiskUsage_Process::run([$python, '-I', '-S', __DIR__ . '/fixtures/whmcs_report.py'],
    15, Scope::MAX_PAYLOAD_BYTES);
check($capture['exit'] === 0, 'Collector interoperability fixture failed');
$collector = json_decode($capture['output'], true, 16, JSON_THROW_ON_ERROR);
$parsed = Report::normalize($collector);
check($parsed['platform'] === (PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux'), 'Collector platform lost');
check($parsed['files'] === 6 && $parsed['complete'], 'Collector totals incompatible');
foreach (Report::SECTIONS as $section) {
    check(count($parsed[$section]) === count($collector[$section]), 'Native collector rankings lost');
    foreach ($parsed[$section] as $index => $row) {
        check($row['path'] === $collector[$section][$index]['path'] && $row['hint'] === $collector[$section][$index]['hint'],
            'Collector metadata/hints changed during parse');
    }
}
check(!str_contains(Report::json($parsed), 'private-not-for-export'), 'Private collector binding escaped');
echo "Plesk WHMCS metadata/export/navigation and collector interoperability: $checks checks passed\n";
