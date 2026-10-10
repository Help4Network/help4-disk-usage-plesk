<?php
if (!defined('WHMCS')) { http_response_code(404); exit; }
require_once __DIR__ . '/help4_disk_usage_plesk.php';
add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    try {
        $actor = Help4\DiskUsagePlesk\Native::actor();
        if (!$actor['products_allowed'] || !$actor['manage_products_allowed']) { return; }
        $parent = $sidebar->getChild('Service Details Actions');
        if ($parent) {
            $id = Help4\DiskUsagePlesk\Scope::id($_GET['id'] ?? null);
            $entity = Help4\DiskUsagePlesk\Native::entity($id);
            if ($entity['service']['client_id'] !== $actor['client_id']) { return; }
            $parent->addChild('Plesk Disk Usage Audit', ['label' => 'Disk Usage Audit',
                'uri' => Help4\DiskUsagePlesk\View::base($id), 'order' => 25]);
        }
    } catch (Throwable $e) { }
});
