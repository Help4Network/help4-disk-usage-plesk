<?php
class Modules_Help4DiskUsage_ApiRpc extends pm_Hook_ApiRpc
{
    public function call($params)
    {
        try {
            if (!is_array($params)) { throw new RuntimeException(); }
            return Modules_Help4DiskUsage_Remote::call($params);
        }
        catch (Throwable $e) { throw new pm_Exception('Disk audit integration unavailable'); }
    }
}
