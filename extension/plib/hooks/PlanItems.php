<?php
class Modules_Help4DiskUsage_PlanItems extends pm_Hook_PlanItems
{
    public function getPlanItems()
    {
        return ['audit_default' => 'Disk audit: host default limits',
            'audit_extended' => 'Disk audit: extended limits',
            'audit_disabled' => 'Disk audit: customer refresh disabled'];
    }

    public function isExclusive()
    {
        return true;
    }
}
