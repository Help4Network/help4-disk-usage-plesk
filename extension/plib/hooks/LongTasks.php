<?php
class Modules_Help4DiskUsage_LongTasks extends pm_Hook_LongTasks
{
    public function getLongTasks()
    {
        return [new Modules_Help4DiskUsage_Task_Scan()];
    }
}
