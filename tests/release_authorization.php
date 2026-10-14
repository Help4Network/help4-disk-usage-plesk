<?php
ob_start();
require __DIR__ . '/releases.php';
ob_end_clean();

class ReleaseAuthorizationHooks {
    public static $stage = '', $change = '', $injections = 0, $locks = 0, $reads = 0, $writes = 0, $fetches = 0;
    public static function inject($stage) {
        if (self::$stage !== $stage) { return; }
        self::$stage = ''; self::$injections++;
        if (self::$change === 'demote') { pm_Session::$client->admin = false; }
        elseif (self::$change === 'impersonate') { pm_Session::$impersonated = true; }
        elseif (self::$change === 'logout') { pm_Session::$client = null; }
        elseif (self::$change === 'invalid-id') { pm_Session::$client->id = []; }
        else {
            $before = pm_Session::$client;
            $client = new ReleaseClient(); $client->id = self::$change === 'switch' ? 2 : $before->id;
            pm_Session::$client = $client;
        }
    }
}
$namespace = 'ReleaseAuthorizationFixture';
foreach (['pm_Context', 'Modules_Help4DiskUsage_Access'] as $class) { class_alias($class, $namespace . '\\' . $class); }
// Hook only after genuine private reads, lock acquisition and atomic replacement.
eval('namespace ' . $namespace . ';
    function file_get_contents($path) {
        $value = \\file_get_contents($path);
        if (basename($path) === "release.json") {
            \\ReleaseAuthorizationHooks::$reads++;
            \\ReleaseAuthorizationHooks::inject("read-" . \\ReleaseAuthorizationHooks::$reads);
        }
        return $value;
    }
    function flock($handle, $operation, &$wouldBlock = null) {
        $value = \\flock($handle, $operation, $wouldBlock);
        if ($value && $operation === (LOCK_EX | LOCK_NB)) {
            \\ReleaseAuthorizationHooks::$locks++;
            \\ReleaseAuthorizationHooks::inject("lock-" . \\ReleaseAuthorizationHooks::$locks);
        }
        return $value;
    }
    function rename($from, $to) {
        $value = \\rename($from, $to);
        if ($value && basename($to) === "release.json") {
            \\ReleaseAuthorizationHooks::$writes++;
            \\ReleaseAuthorizationHooks::inject("write-" . \\ReleaseAuthorizationHooks::$writes);
        }
        return $value;
    }');
foreach (['Store', 'Releases'] as $class) {
    eval('namespace ' . $namespace . '; use \\RuntimeException; use \\Throwable;' .
        substr(file_get_contents(__DIR__ . '/../extension/plib/library/' . $class . '.php'), 5));
}
$store = $namespace . '\\Modules_Help4DiskUsage_Store';
$releases = $namespace . '\\Modules_Help4DiskUsage_Releases';
$checks = 0;
function verifyReleaseAuthorization($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function releaseActor() {
    pm_Session::$client = new ReleaseClient(); pm_Session::$impersonated = false;
    ReleaseAuthorizationHooks::$stage = ''; ReleaseAuthorizationHooks::$change = '';
    ReleaseAuthorizationHooks::$injections = ReleaseAuthorizationHooks::$locks = ReleaseAuthorizationHooks::$reads =
        ReleaseAuthorizationHooks::$writes = ReleaseAuthorizationHooks::$fetches = 0;
}
function releaseSeed($store) {
    releaseActor();
    $seed = ['attempted_at' => time() - 301, 'result' => ['state' => 'available', 'version' => '0.9.0', 'tag' => 'v0.9.0'],
        'checked_at' => '2026-01-01T00:00:00+00:00', 'failed_at' => 'prior-failure'];
    $store::write('release', $seed);
    ReleaseAuthorizationHooks::$writes = 0;
    return $seed;
}
function releaseFetch($failure) {
    return function () use ($failure) {
        ReleaseAuthorizationHooks::$fetches++;
        ReleaseAuthorizationHooks::inject('fetch');
        return $failure ? ['status' => 429, 'body' => ''] :
            ['status' => 200, 'body' => '{"tag_name":"v1.2.3","draft":false,"prerelease":false}'];
    };
}
function releasePrivateHashes($store) {
    $hashes = [];
    foreach (['state', 'policy', 'bridge', 'rotation', 'report-1'] as $name) {
        $hashes[$name] = hash_file('sha256', $store::directory() . DIRECTORY_SEPARATOR . $name . '.json');
    }
    return $hashes;
}
function releaseProbe($store, $callback) {
    $result = null; $error = null; $streams = count(get_resources('stream')); $hashes = releasePrivateHashes($store);
    try { $result = $callback(); } catch (Throwable $e) { $error = $e->getMessage(); }
    verifyReleaseAuthorization(count(get_resources('stream')) === $streams, 'Release request leaked streams');
    verifyReleaseAuthorization(releasePrivateHashes($store) === $hashes, 'Release request changed unrelated private state');
    return [$result, $error];
}

pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-release-authorization-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    foreach (['state', 'policy', 'bridge', 'rotation', 'report-1'] as $name) { $store::write($name, ['synthetic' => $name]); }
    foreach ([false, true] as $failure) {
        foreach (['lock-1', 'read-1', 'write-1', 'fetch', 'lock-2', 'read-2', 'write-2'] as $stage) {
            foreach (['demote', 'impersonate', 'logout', 'switch', 'invalid-id', 'same-id'] as $change) {
                $seed = releaseSeed($store);
                ReleaseAuthorizationHooks::$stage = $stage; ReleaseAuthorizationHooks::$change = $change;
                [$result, $error] = releaseProbe($store, function () use ($releases, $failure) {
                    return $releases::check(releaseFetch($failure));
                });
                verifyReleaseAuthorization(ReleaseAuthorizationHooks::$injections === 1, 'Release injection missed intended stage');
                $cache = json_decode(file_get_contents($store::directory() . DIRECTORY_SEPARATOR . 'release.json'), true, 16, JSON_THROW_ON_ERROR);
                if ($change === 'same-id') {
                    verifyReleaseAuthorization(ReleaseAuthorizationHooks::$fetches === 1 && ReleaseAuthorizationHooks::$writes === 2,
                        'Equivalent administrator changed release admission/publication');
                    verifyReleaseAuthorization($failure ? ($result === null && $error === 'Release check unavailable; previous result may be stale') :
                        ($error === null && $result['version'] === '1.2.3'), 'Equivalent SDK administrator rejected');
                } else {
                    verifyReleaseAuthorization($result === null && $error === 'Administrator access required',
                        'Changed administrator received release result');
                    $beforeAdmission = in_array($stage, ['lock-1', 'read-1'], true);
                    verifyReleaseAuthorization(ReleaseAuthorizationHooks::$fetches ===
                        (in_array($stage, ['lock-1', 'read-1', 'write-1'], true) ? 0 : 1), 'Unauthorized release transport continued');
                    verifyReleaseAuthorization(ReleaseAuthorizationHooks::$writes === ($beforeAdmission ? 0 : ($stage === 'write-2' ? 2 : 1)),
                        'Release request wrote after detected access loss');
                    if ($beforeAdmission) {
                        verifyReleaseAuthorization($cache === $seed, 'Rejected reservation changed release cache/cooldown');
                    } else {
                        verifyReleaseAuthorization($cache['attempted_at'] > $seed['attempted_at'], 'Consumed cooldown attempt refunded');
                        if ($stage === 'write-2') {
                            // A replacement completed while authorized is retained; subsequent revocation denies return.
                            verifyReleaseAuthorization($failure ? ($cache['result'] === $seed['result'] && $cache['failed_at'] !== 'prior-failure') :
                                ($cache['result']['version'] === '1.2.3' && !isset($cache['failed_at'])), 'Accepted publication was undone or rewritten');
                        } else {
                            verifyReleaseAuthorization($cache['result'] === $seed['result'] && $cache['checked_at'] === $seed['checked_at'] &&
                                $cache['failed_at'] === $seed['failed_at'], 'Revocation overwrote prior result/failure metadata');
                        }
                    }
                    if ($stage === 'lock-1') {
                        verifyReleaseAuthorization(ReleaseAuthorizationHooks::$reads === 0, 'Revoked lock admission read private cache');
                    }
                }
                $handle = fopen($store::directory() . DIRECTORY_SEPARATOR . 'state.lock', 'c');
                verifyReleaseAuthorization(flock($handle, LOCK_EX | LOCK_NB), 'Release request retained state lock');
                flock($handle, LOCK_UN); fclose($handle);
            }
        }
    }
    foreach (['demote', 'impersonate', 'logout', 'switch', 'invalid-id', 'same-id'] as $change) {
        $seed = releaseSeed($store); $before = hash_file('sha256', $store::directory() . DIRECTORY_SEPARATOR . 'release.json');
        ReleaseAuthorizationHooks::$stage = 'read-1'; ReleaseAuthorizationHooks::$change = $change;
        [$result, $error] = releaseProbe($store, function () use ($releases) { return $releases::cached(); });
        verifyReleaseAuthorization(ReleaseAuthorizationHooks::$injections === 1, 'Cached-read injection missed intended stage');
        verifyReleaseAuthorization($change === 'same-id' ? ($error === null && $result === $seed) :
            ($result === null && $error === 'Administrator access required'), 'Cached release returned to changed administrator');
        verifyReleaseAuthorization(hash_file('sha256', $store::directory() . DIRECTORY_SEPARATOR . 'release.json') === $before &&
            ReleaseAuthorizationHooks::$fetches === 0 && ReleaseAuthorizationHooks::$writes === 0,
            'Passive cached GET performed network or cache mutation');
    }
    foreach ([0, '0', '1'] as $id) {
        releaseSeed($store); pm_Session::$client->id = $id;
        [$result, $error] = releaseProbe($store, function () use ($releases) { return $releases::check(releaseFetch(false)); });
        verifyReleaseAuthorization($error === null && $result['version'] === '1.2.3', 'Stable numeric administrator rejected');
    }
    foreach (['demote', 'impersonate', 'logout', 'invalid-id'] as $change) {
        foreach (['check', 'cached'] as $method) {
            $seed = releaseSeed($store); ReleaseAuthorizationHooks::$stage = 'initial'; ReleaseAuthorizationHooks::$change = $change;
            ReleaseAuthorizationHooks::inject('initial');
            [$result, $error] = releaseProbe($store, function () use ($releases, $method) {
                return $method === 'check' ? $releases::check(releaseFetch(false)) : $releases::cached();
            });
            verifyReleaseAuthorization($result === null && $error === 'Administrator access required' &&
                ReleaseAuthorizationHooks::$reads === 0 && ReleaseAuthorizationHooks::$writes === 0 &&
                ReleaseAuthorizationHooks::$locks === 0 && ReleaseAuthorizationHooks::$fetches === 0,
                'Unauthorized release request reached private state or transport');
        }
    }
    echo "Release authorization: $checks original-admin/lock/transport/publication/cached-read controls passed (SDK and transport fixtures, not native session/TLS or continuous enforcement proof)\n";
} finally {
    $audit = pm_Context::$directory . DIRECTORY_SEPARATOR . 'audit';
    if (is_dir($audit)) {
        foreach (new DirectoryIterator($audit) as $entry) { if (!$entry->isDot()) { unlink($entry->getPathname()); } }
        rmdir($audit);
    }
    rmdir(pm_Context::$directory);
}
