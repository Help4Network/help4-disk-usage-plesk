<?php
namespace Help4\DiskUsagePlesk;

final class Report
{
    const SECTIONS = ['largest_files', 'stale_files', 'largest_trees', 'entry_trees'];
    const MAX_NUMBER = 9007199254740991;
    const MAX_EXPORT_BYTES = 524288;
    const HINTS = [
        'cache' => 'Review application cache retention and purge through the application.',
        'mail' => 'Review mailbox retention with its owner; do not delete Maildir files blindly.',
        'logs' => 'Check rotation and retention; preserve logs needed for incident investigation.',
        'temporary' => 'Confirm no active process needs these files before clearing temporary data.',
        'dependencies' => 'Review generated dependencies with the application owner; preserve source.',
        'backups' => 'Verify a usable off-server backup before removing redundant archives.',
        'other' => 'Review ownership and purpose in File Manager before changing anything.',
    ];

    public static function read($serviceId, callable $actorReader, callable $entityReader, callable $read, $ttl = 3600)
    {
        try {
            return self::normalize(Scope::read($serviceId, $actorReader, $entityReader, $read), null, $ttl);
        } catch (\Throwable $e) { throw new \RuntimeException('Service unavailable'); }
    }

    public static function normalize($raw, $now = null, $ttl = 3600)
    {
        try {
            $now = $now ?? time();
            if (PHP_INT_SIZE < 8 || !is_int($now) || $now < 1 || !is_int($ttl) || $ttl < 300 || $ttl > 86400 ||
                !is_array($raw) || ($raw['schema'] ?? null) !== 1 ||
                !in_array($raw['platform'] ?? null, ['linux', 'windows'], true) ||
                !is_bool($raw['complete'] ?? null) ||
                strlen(json_encode($raw, JSON_THROW_ON_ERROR, 16)) > Scope::MAX_PAYLOAD_BYTES) {
                throw new \RuntimeException();
            }
            $date = $raw['scanned_at'] ?? null;
            if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)$/D', $date)) {
                throw new \RuntimeException();
            }
            $date = substr($date, -1) === 'Z' ? substr($date, 0, -1) . '+00:00' : $date;
            $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $date);
            if (!$instant || $instant->format('Y-m-d\TH:i:sP') !== $date ||
                $instant->getTimestamp() < 1 || $instant->getTimestamp() > $now + 5) { throw new \RuntimeException(); }
            $report = ['schema' => 1, 'platform' => $raw['platform'], 'scanned_at' => $instant->format('Y-m-d\TH:i:s\Z')];
            foreach (['bytes' => self::MAX_NUMBER, 'entries' => 2000000, 'files' => 2000000,
                'directories' => 50000, 'skipped' => 2000000, 'errors' => 2050000] as $key => $maximum) {
                $report[$key] = self::number($raw[$key] ?? null, $maximum);
            }
            if ($report['directories'] < 1 || $report['files'] + $report['directories'] - 1 > $report['entries'] ||
                $report['skipped'] > $report['entries'] || $report['errors'] > $report['entries'] + $report['directories']) {
                throw new \RuntimeException();
            }
            $duration = $raw['duration_seconds'] ?? null;
            if ((!is_int($duration) && !is_float($duration)) || !is_finite((float)$duration) ||
                $duration < 0 || $duration > 135 || !array_key_exists('limit', $raw) ||
                !in_array($raw['limit'], [null, 'time', 'entries', 'depth_or_directories'], true) ||
                !array_key_exists('growth_bytes', $raw)) { throw new \RuntimeException(); }
            $growth = $raw['growth_bytes'];
            if ($growth !== null && (!is_int($growth) || $growth < -self::MAX_NUMBER || $growth > self::MAX_NUMBER)) {
                throw new \RuntimeException();
            }
            $complete = $raw['complete'] && $report['errors'] === 0 && $report['skipped'] === 0 && $raw['limit'] === null;
            $report += ['duration_seconds' => $duration, 'complete' => $complete, 'limit' => $raw['limit'],
                'growth_bytes' => $complete ? $growth : null, 'totals_are_lower_bounds' => !$complete,
                'age_seconds' => max(0, $now - $instant->getTimestamp()), 'stale' => $now - $instant->getTimestamp() > $ttl];
            if (!is_array($raw['categories'] ?? null) || array_diff(array_keys($raw['categories']), array_keys(self::HINTS))) {
                throw new \RuntimeException();
            }
            $report['categories'] = [];
            foreach ($raw['categories'] as $category => $size) {
                $report['categories'][$category] = self::number($size, $report['bytes']);
            }
            if (array_sum($report['categories']) !== $report['bytes']) { throw new \RuntimeException(); }
            $retained = [];
            foreach (self::SECTIONS as $section) {
                $rows = $raw[$section] ?? null;
                if (!is_array($rows) || !array_is_list($rows) || count($rows) > 200) { throw new \RuntimeException(); }
                $report[$section] = [];
                $seen = [];
                $kind = in_array($section, ['largest_files', 'stale_files'], true) ? 'file' : 'directory';
                if (count($rows) > $report[$kind === 'file' ? 'files' : 'directories']) { throw new \RuntimeException(); }
                foreach ($rows as $row) {
                    if (!is_array($row) || ($row['kind'] ?? null) !== $kind ||
                        !isset(self::HINTS[$row['category'] ?? ''])) { throw new \RuntimeException(); }
                    $path = self::path($row['path'] ?? null, $kind, $raw['platform']);
                    if (isset($seen[$path])) { throw new \RuntimeException(); }
                    $seen[$path] = true;
                    $clean = ['path' => $path, 'kind' => $kind, 'bytes' => self::number($row['bytes'] ?? null, $report['bytes']),
                        'category' => $row['category'], 'hint' => self::HINTS[$row['category']]];
                    if ($kind === 'file') {
                        $modified = $row['modified'] ?? null;
                        if (!is_int($modified) || $modified < -62135596800 || $modified > 253402300799) {
                            throw new \RuntimeException();
                        }
                        $clean['modified'] = $modified;
                    } else {
                        $clean['entries'] = self::number($row['entries'] ?? null, $report['entries']);
                        $clean['direct_bytes'] = self::number($row['direct_bytes'] ?? null, $clean['bytes']);
                        $clean['direct_files'] = self::number($row['direct_files'] ?? null, min($report['files'], $clean['entries']));
                    }
                    $key = $kind . ':' . $path;
                    if (isset($retained[$key]) && $retained[$key] !== $clean) { throw new \RuntimeException(); }
                    $retained[$key] = $clean;
                    $report[$section][] = $clean;
                }
            }
            $report['built_by'] = ['name' => 'Help4 Network', 'url' => 'https://help4network.com'];
            if (strlen(json_encode($report, JSON_THROW_ON_ERROR, 16)) > Scope::MAX_PAYLOAD_BYTES) {
                throw new \RuntimeException();
            }
            return $report;
        } catch (\Throwable $e) { throw new \RuntimeException('Report unavailable'); }
    }

    public static function json($report, $ttl = 3600)
    {
        return json_encode(self::normalize($report, null, $ttl), JSON_THROW_ON_ERROR, 16);
    }

    public static function csv($report, $ttl = 3600)
    {
        $report = self::normalize($report, null, $ttl);
        $stream = fopen('php://memory', 'w+');
        if (!$stream) { throw new \RuntimeException('Report unavailable'); }
        try {
            $write = function (array $cells) use ($stream) {
                if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false || ftell($stream) > self::MAX_EXPORT_BYTES) {
                    throw new \RuntimeException('Report unavailable');
                }
            };
            $write(['section', 'path', 'kind', 'bytes', 'entries', 'modified', 'review']);
            $write(['summary', 'scanned_at: ' . $report['scanned_at'], $report['complete'] ? 'complete' : 'partial',
                $report['bytes'], $report['entries'], '', 'Stale: ' . ($report['stale'] ? 'yes' : 'no') .
                '; totals: ' . ($report['complete'] ? 'scanned metadata' : 'lower bounds') . '; not quota reconciliation.']);
            foreach (self::SECTIONS as $section) {
                foreach ($report[$section] as $row) {
                    // A visible text prefix avoids making a filename a spreadsheet formula.
                    $write([$section, 'path: ' . $row['path'], $row['kind'], $row['bytes'], $row['entries'] ?? '',
                        $row['modified'] ?? '', $row['hint']]);
                }
            }
            $write(['built_by', 'Help4 Network', '', '', '', '', 'https://help4network.com']);
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) { throw new \RuntimeException('Report unavailable'); }
            return $csv;
        } finally { fclose($stream); }
    }

    public static function navigationIntent($report, $path, $kind, $ttl = 3600)
    {
        $report = self::normalize($report, null, $ttl);
        $path = self::path($path, $kind, $report['platform']);
        foreach (self::SECTIONS as $section) {
            foreach ($report[$section] as $row) {
                if ($row['path'] === $path && $row['kind'] === $kind) {
                    $parts = explode('/', $path);
                    if ($kind === 'file') { array_pop($parts); }
                    return ['path' => $path, 'kind' => $kind, 'directory' => implode('/', $parts) ?: '.'];
                }
            }
        }
        throw new \RuntimeException('Path unavailable');
    }

    private static function number($value, $maximum)
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) { throw new \RuntimeException(); }
        return $value;
    }

    private static function path($path, $kind, $platform)
    {
        if (!in_array($kind, ['file', 'directory'], true) || !is_string($path) || strlen($path) > 4096 || $path === '' ||
            !preg_match('//u', $path) || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}\\\\:]/u', $path) || $path[0] === '/' ||
            ($path === '.' && $kind !== 'directory')) { throw new \RuntimeException('Path unavailable'); }
        if ($path === '.') { return $path; }
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..'], true) || ($platform === 'windows' &&
                (preg_match('/[. ]$/u', $part) || preg_match('/[<>"|?*]/u', $part) ||
                preg_match('/^(CON|PRN|AUX|NUL|(?:COM|LPT)[1-9\x{00b9}\x{00b2}\x{00b3}])(?:\.|$)/iuD', $part)))) {
                throw new \RuntimeException('Path unavailable');
            }
        }
        return $path;
    }
}
