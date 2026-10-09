<?php
class Modules_Help4DiskUsage_Report
{
    public static function publicReport(array $report)
    {
        unset($report['binding']);
        return $report;
    }

    public static function csvCell($value)
    {
        $value = (string)$value;
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
    }

    public static function rows(array $report, $section, $search, $sort, $page)
    {
        if (!in_array($section, ['largest_files', 'stale_files', 'largest_trees', 'entry_trees'], true)) {
            $section = 'largest_files';
        }
        $search = substr((string)$search, 0, 128);
        $rows = array_filter($report[$section] ?? [], function ($row) use ($search) {
            return $search === '' || stripos($row['path'], $search) !== false;
        });
        $sort = in_array($sort, ['path', 'bytes', 'entries', 'modified'], true) ? $sort : 'bytes';
        usort($rows, function ($a, $b) use ($sort) {
            return $sort === 'path' ? strcmp($a['path'], $b['path']) : (($b[$sort] ?? 0) <=> ($a[$sort] ?? 0));
        });
        $count = count($rows);
        $page = max(1, min(max(1, (int)ceil($count / 25)), (int)$page));
        return ['items' => array_slice($rows, ($page - 1) * 25, 25), 'count' => $count, 'page' => $page,
            'pages' => max(1, (int)ceil($count / 25)), 'section' => $section];
    }

    public static function fileManagerUrl($domain, array $report, $path, $kind)
    {
        Modules_Help4DiskUsage_Access::relative($path);
        $found = false;
        foreach (['largest_files', 'stale_files', 'largest_trees', 'entry_trees'] as $section) {
            foreach ($report[$section] ?? [] as $row) {
                if ($row['path'] === $path && $row['kind'] === $kind) {
                    $found = true;
                }
            }
        }
        if (!$found) {
            throw new RuntimeException('Path unavailable; refresh the report');
        }
        $directory = $kind === 'directory' ? $path : dirname($path);
        $directory = $directory === '.' ? '/' : '/' . $directory;
        return '/smb/file-manager/list/domainId/' . (int)$domain->getId() . '?' .
            http_build_query(['currentDir' => $directory], '', '&', PHP_QUERY_RFC3986);
    }
}
