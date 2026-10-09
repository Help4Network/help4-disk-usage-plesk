<?php
class Modules_Help4DiskUsage_Task_Scan extends pm_LongTask_Task
{
    public $poolSize = 1;
    public $hidden = true;

    public function getConcurrencyRules(): array
    {
        return ['help4-disk-usage-scanner'];
    }

    public function run()
    {
        $id = (int)$this->getParam('domain');
        $token = (string)$this->getParam('token');
        try {
            pm_ApiCli::call('extension', ['--exec', 'help4-disk-usage', 'worker.php', (string)$id, $token]);
        } finally {
            Modules_Help4DiskUsage_Store::finish($id, $token);
        }
    }

    public function statusMessage()
    {
        return 'Disk audit scan';
    }
}
