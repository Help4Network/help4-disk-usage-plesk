<?php
class Modules_Help4DiskUsage_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        return [[
            'place' => [self::PLACE_ADMIN_NAVIGATION, self::PLACE_RESELLER_NAVIGATION, self::PLACE_HOSTING_PANEL_NAVIGATION],
            'title' => 'Disk Usage Audit', 'description' => 'File and filesystem-entry audit',
            'link' => pm_Context::getActionUrl('index', 'index'),
            'icon' => pm_Context::getBaseUrl() . 'images/h4.png',
            'newWindow' => false, 'order' => 100,
        ]];
    }
}
