<?php
class Modules_Help4DiskUsage_Releases
{
    const REPOSITORY = 'https://github.com/Help4Network/help4-disk-usage-plesk';
    const ENDPOINT = 'https://api.github.com/repos/Help4Network/help4-disk-usage-plesk/releases/latest';

    public static function decode($body)
    {
        if (!is_string($body) || strlen($body) > 65536) {
            throw new RuntimeException('Release metadata exceeds safety limit');
        }
        $release = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($release) || !isset($release['tag_name'], $release['draft'], $release['prerelease']) ||
            !is_bool($release['draft']) || !is_bool($release['prerelease']) ||
            !is_string($release['tag_name']) || !preg_match('/^v?(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})$/D', $release['tag_name'])) {
            throw new RuntimeException('Invalid stable release metadata');
        }
        if ($release['draft'] || $release['prerelease']) {
            return ['state' => 'no_release'];
        }
        return ['state' => 'available', 'version' => ltrim($release['tag_name'], 'v'), 'tag' => $release['tag_name']];
    }

    private static function fetch()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('HTTPS client unavailable');
        }
        $handle = curl_init(self::ENDPOINT);
        $body = '';
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'Help4-Disk-Usage-Plesk',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false) { throw new RuntimeException('Release check unavailable'); }
            return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            unset($handle);
        }
    }

    public static function check($fetch = null)
    {
        if (!Modules_Help4DiskUsage_Access::admin()) { throw new RuntimeException('Administrator access required'); }
        Modules_Help4DiskUsage_Store::locked(function () {
            $cache = Modules_Help4DiskUsage_Store::read('release');
            if (($cache['attempted_at'] ?? 0) > time() - 300) {
                throw new RuntimeException('Wait five minutes before checking again');
            }
            $cache['attempted_at'] = time();
            Modules_Help4DiskUsage_Store::write('release', $cache);
        });
        try {
            // A test transport may be supplied by PHP callers, never by HTTP input.
            $response = $fetch ? $fetch() : self::fetch();
            if ($response['status'] === 404) {
                $release = ['state' => 'no_release'];
            } elseif ($response['status'] === 200) {
                $release = self::decode($response['body']);
            } else {
                throw new RuntimeException('Release service unavailable');
            }
            Modules_Help4DiskUsage_Store::locked(function () use ($release) {
                $cache = Modules_Help4DiskUsage_Store::read('release');
                $cache['result'] = $release;
                $cache['checked_at'] = gmdate('c');
                unset($cache['failed_at']);
                Modules_Help4DiskUsage_Store::write('release', $cache);
            });
            return $release;
        } catch (Throwable $e) {
            Modules_Help4DiskUsage_Store::locked(function () {
                $cache = Modules_Help4DiskUsage_Store::read('release');
                $cache['failed_at'] = gmdate('c');
                Modules_Help4DiskUsage_Store::write('release', $cache);
            });
            throw new RuntimeException('Release check unavailable; previous result may be stale');
        }
    }

    public static function cached()
    {
        if (!Modules_Help4DiskUsage_Access::admin()) { throw new RuntimeException('Administrator access required'); }
        return Modules_Help4DiskUsage_Store::read('release');
    }
}
