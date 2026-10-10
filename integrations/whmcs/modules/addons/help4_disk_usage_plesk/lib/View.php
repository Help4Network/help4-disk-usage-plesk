<?php
namespace Help4\DiskUsagePlesk;

final class View
{
    public static function escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function footer() { return '<footer class="h4du-byline">Built by <a href="https://help4network.com" rel="noopener">Help4 Network</a></footer>'; }
    public static function styles()
    {
        return '<style>.h4du-plesk{max-width:100%;letter-spacing:0}.h4du-plesk .table-responsive{max-width:100%;overflow-x:auto}' .
            '.h4du-plesk table{min-width:640px}.h4du-plesk td{vertical-align:top}.h4du-plesk td:first-child{overflow-wrap:anywhere;max-width:320px}' .
            '.h4du-plesk form,.h4du-plesk nav{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0}' .
            '.h4du-plesk .h4du-action{display:inline-flex;margin:0}.h4du-plesk input,.h4du-plesk select{max-width:100%}' .
            '.h4du-byline{font-size:12px;text-align:right;margin-top:16px}</style>';
    }
    public static function base($id = null)
    {
        return 'index.php?m=' . Native::MODULE . ($id === null ? '' : '&serviceid=' . Scope::id($id));
    }
    public static function form($base, $action, $id, $token, $label, $field = 'serverid')
    {
        return '<form method="post" action="' . self::escape($base) . '" class="h4du-action">' .
            '<input type="hidden" name="token" value="' . self::escape($token) . '">' .
            '<input type="hidden" name="action" value="' . self::escape($action) . '">' .
            '<input type="hidden" name="' . self::escape($field) . '" value="' . Scope::id($id) . '">' .
            '<button type="submit" class="btn btn-default btn-sm">' . self::escape($label) . '</button></form>';
    }
    public static function health(array $health, $token, $page)
    {
        $base = 'addonmodules.php?module=' . Native::MODULE;
        $html = self::styles() . '<section class="h4du-plesk"><h2>Plesk Disk Usage Audit</h2><div class="table-responsive">' .
            '<table class="table table-striped"><thead><tr><th>Server</th><th>State</th><th>Version</th>' .
            '<th>Pending</th><th>Active</th><th>Measured</th><th>Actions</th></tr></thead><tbody>';
        foreach ($health['rows'] as $row) {
            $html .= '<tr><td>' . $row['server_id'] . '</td><td>' . self::escape($row['status']) . '</td><td>' .
                self::escape($row['version'] ?? 'Unknown') . '</td><td>' . self::escape($row['pending'] ?? 'Unknown') .
                '</td><td>' . self::escape($row['active_scanners'] ?? 'Unknown') . '</td><td>' .
                self::escape($row['measured_at'] ? gmdate('Y-m-d H:i:s', $row['measured_at']) . ' UTC' : 'Not measured') . '</td><td>' .
                self::form($base, 'connect', $row['server_id'], $token, 'Connect') . ' ' .
                self::form($base, 'check', $row['server_id'], $token, 'Check') . '</td></tr>';
        }
        $html .= '</tbody></table></div><nav>';
        if ($page > 1) { $html .= '<a href="' . self::escape($base . '&page=' . ($page - 1)) . '">Previous</a> '; }
        if (count($health['rows']) === 20) { $html .= '<a href="' . self::escape($base . '&page=' . ($page + 1)) . '">Next</a>'; }
        $html .= '</nav><h3>Subscription Mapping</h3><form method="post" action="' . self::escape($base) . '">' .
            '<input type="hidden" name="token" value="' . self::escape($token) . '">' .
            '<input type="hidden" name="action" value="approve"><label for="h4du-service">WHMCS service ID</label> ' .
            '<input id="h4du-service" type="number" name="serviceid" min="1" required> ' .
            '<button class="btn btn-default" type="submit">Approve Current Subscription</button></form>' .
            '<h3>Deployment</h3><p><a href="https://github.com/Help4Network/help4-disk-usage-plesk/blob/main/docs/whmcs.md" ' .
            'target="_blank" rel="noopener">Linux / Windows installation and connection runbook</a></p>' .
            '<h3>Disconnect</h3><form method="post" action="' . self::escape($base) . '">' .
            '<input type="hidden" name="token" value="' . self::escape($token) . '">' .
            '<input type="hidden" name="action" value="disconnect"><label for="h4du-disconnect">Server ID</label> ' .
            '<input id="h4du-disconnect" name="serverid" type="number" min="1" required> ' .
            '<label><input name="confirm" type="checkbox" value="disconnect" required> Remove approved mappings</label> ' .
            '<button class="btn btn-default" type="submit">Disconnect</button></form>';
        return $html . self::footer() . '</section>';
    }
    public static function services($services)
    {
        $html = '<ul class="list-group">';
        foreach ($services as $service) {
            $html .= '<li class="list-group-item"><a href="' . self::escape(self::base($service->id)) . '">' .
                self::escape($service->domain) . '</a></li>';
        }
        return $html . '</ul>' . self::footer();
    }
    public static function report($id, array $report, array $query, $token)
    {
        $base = self::base($id);
        $section = in_array($query['section'] ?? '', Report::SECTIONS, true) ? $query['section'] : 'largest_files';
        $sort = in_array($query['sort'] ?? '', ['path', 'bytes', 'entries'], true) ? $query['sort'] : 'bytes';
        $search = is_string($query['search'] ?? null) ? substr($query['search'], 0, 128) : '';
        $rows = array_values(array_filter($report[$section], function ($row) use ($search) {
            return $search === '' || stripos($row['path'], $search) !== false;
        }));
        usort($rows, function ($a, $b) use ($sort) {
            return $sort === 'path' ? strcmp($a['path'], $b['path']) : (($b[$sort] ?? 0) <=> ($a[$sort] ?? 0));
        });
        $pages = max(1, (int)ceil(count($rows) / 25));
        $page = max(1, min($pages, (int)($query['page'] ?? 1)));
        $html = self::styles() . '<section class="h4du-plesk"><p><a href="' . self::escape(self::base()) . '">Services</a></p>' .
            '<p>Last scan: ' . self::escape($report['scanned_at']) . ' | ' .
            ($report['complete'] ? 'Complete' : 'Partial; totals are lower bounds') . ($report['stale'] ? ' | Stale' : '') . '</p>' .
            '<p>Logical bytes: ' . number_format($report['bytes']) . ' | Filesystem entries: ' . number_format($report['entries']) . '</p>' .
            self::form($base, 'refresh', $id, $token, 'Refresh Scan', 'serviceid') . ' ' .
            '<a href="' . self::escape($base . '&action=export&format=json') . '">JSON</a> | ' .
            '<a href="' . self::escape($base . '&action=export&format=csv') . '">CSV</a><nav>';
        foreach (Report::SECTIONS as $name) {
            $html .= '<a href="' . self::escape($base . '&section=' . $name) . '">' . self::escape(ucwords(str_replace('_', ' ', $name))) . '</a> ';
        }
        $html .= '</nav><form method="get" action="index.php"><input type="hidden" name="m" value="' . Native::MODULE . '">' .
            '<input type="hidden" name="serviceid" value="' . Scope::id($id) . '"><input type="hidden" name="section" value="' . $section . '">' .
            '<label for="h4du-search">Search</label> <input id="h4du-search" name="search" value="' . self::escape($search) . '">' .
            '<label for="h4du-sort">Sort</label> <select id="h4du-sort" name="sort">';
        foreach (['bytes', 'entries', 'path'] as $name) { $html .= '<option value="' . $name . '"' . ($sort === $name ? ' selected' : '') . '>' . ucfirst($name) . '</option>'; }
        $html .= '</select> <button type="submit" class="btn btn-default">Apply</button></form><div class="table-responsive">' .
            '<table class="table table-striped"><thead><tr><th>Relative Path</th><th>Bytes</th><th>Entries</th><th>Review</th></tr></thead><tbody>';
        foreach (array_slice($rows, ($page - 1) * 25, 25) as $row) {
            $jump = $base . '&action=jump&' . http_build_query(['path' => $row['path'], 'kind' => $row['kind']], '', '&', PHP_QUERY_RFC3986);
            $html .= '<tr><td>' . self::escape($row['path']) . '</td><td>' . number_format($row['bytes']) . '</td><td>' .
                self::escape(isset($row['entries']) ? number_format($row['entries']) : '') . '</td><td>' . self::escape($row['hint']) .
                ' <a href="' . self::escape($jump) . '" target="_blank" rel="noopener noreferrer">Open in File Manager</a></td></tr>';
        }
        $html .= '</tbody></table></div><nav aria-label="Report pages">';
        foreach ([$page - 1 => 'Previous', $page + 1 => 'Next'] as $number => $label) {
            if ($number >= 1 && $number <= $pages) {
                $html .= '<a href="' . self::escape($base . '&' . http_build_query(['section' => $section, 'sort' => $sort,
                    'search' => $search, 'page' => $number], '', '&', PHP_QUERY_RFC3986)) . '">' . $label . '</a> ';
            }
        }
        return $html . 'Page ' . $page . ' of ' . $pages . '</nav>' . self::footer() . '</section>';
    }
}
