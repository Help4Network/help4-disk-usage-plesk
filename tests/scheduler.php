<?php
require __DIR__ . '/policy.php';
require __DIR__ . '/../extension/plib/library/Scheduler.php';
class pm_Domain extends PolicyDomain {
    public static $domains = [];
    public static function getAllDomains($include) { return array_values(self::$domains); }
    public static function getByDomainId($id) { return self::$domains[$id]; }
}
class pm_LongTask_Task {
    public $params;
    public function setParams($params) { $this->params = $params; }
}
require __DIR__ . '/../extension/plib/library/Task/Scan.php';
class pm_LongTask_Manager {
    public static $started = [];
    public static $fail = false;
    public function start($task, $domain) {
        if (self::$fail) { throw new RuntimeException('Fixture start failure'); }
        self::$started[] = $domain->getId();
    }
}
class pm_Scheduler_Task {
    public $command, $arguments, $schedule;
    public function getCmd() { return $this->command; }
    public function setCmd($cmd) { $this->command = $cmd; }
    public function setArguments($args) { $this->arguments = $args; }
    public function setSchedule($schedule) { $this->schedule = $schedule; }
}
class pm_Scheduler {
    public static $instance;
    public static $EVERY_HOUR = ['0', '*', '*', '*', '*'];
    public $tasks = [];
    public static function getInstance() { return self::$instance ?? (self::$instance = new self()); }
    public function listTasks() { return $this->tasks; }
    public function removeTask($task) { $this->tasks = array_filter($this->tasks, function ($t) use ($task) { return $t !== $task; }); }
    public function putTask($task) { if (!in_array($task, $this->tasks, true)) { $this->tasks[] = $task; } }
    public function enableTask($task) {}
}
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-scheduler-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
try {
    $policy = Modules_Help4DiskUsage_Store::policy();
    $policy['python'] = PHP_BINARY;
    foreach ([0, 5] as $batch) {
        $bad = $policy; $bad['scheduled_batch'] = $batch;
        denied(function () use ($bad) { Modules_Help4DiskUsage_Store::validatePolicy($bad); });
    }
    $bad = $policy; $bad['scheduled_enabled'] = 'yes';
    denied(function () use ($bad) { Modules_Help4DiskUsage_Store::validatePolicy($bad); });
    Modules_Help4DiskUsage_Store::write('policy', Modules_Help4DiskUsage_Store::validatePolicy($policy));
    check(Modules_Help4DiskUsage_Scheduler::run()['queued'] === 0, 'Default schedule enabled');
    check(Modules_Help4DiskUsage_Store::read('rotation') === [], 'Disabled tick changed rotation');
    for ($id = 1; $id <= 4; $id++) {
        $d = new pm_Domain(); $d->id = $id; $d->owner->id = $id; $d->owner->allowed = [$id];
        pm_Domain::$domains[$id] = $d;
    }
    pm_Domain::$domains[4]->items = ['audit_disabled'];
    $policy['scheduled_enabled'] = true;
    Modules_Help4DiskUsage_Store::write('policy', Modules_Help4DiskUsage_Store::validatePolicy($policy));
    foreach ([1 => time() - 7200, 2 => time(), 3 => time() - 14400] as $id => $at) {
        Modules_Help4DiskUsage_Store::write('report-' . $id, ['scanned_at' => gmdate('c', $at),
            'binding' => Modules_Help4DiskUsage_Access::binding(pm_Domain::$domains[$id])]);
    }
    $result = Modules_Help4DiskUsage_Scheduler::run();
    check(pm_LongTask_Manager::$started === [3, 1], 'Oldest-first, fresh/disabled exclusion');
    check($result['queued'] === 2, 'Batch bound');
    $state = Modules_Help4DiskUsage_Store::read('state');
    check($state['pending'][3]['source'] === 'scheduled' && !$state['pending'][3]['admin'], 'Scheduled administrator bypass');
    check(Modules_Help4DiskUsage_Scheduler::run()['queued'] === 0, 'Tick cooldown bypass');
    $policy['scheduled_enabled'] = false;
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    denied(function () use ($state) { Modules_Help4DiskUsage_Store::validateReservation($state['pending'][3], pm_Domain::$domains[3]); });
    denied(function () use ($state) {
        Modules_Help4DiskUsage_Store::finish(3, $state['pending'][3]['token'], ['bytes' => 999], function ($p) {
            Modules_Help4DiskUsage_Store::validateReservation($p, pm_Domain::$domains[3]);
        });
    });
    check(!isset(Modules_Help4DiskUsage_Store::read('report-3')['bytes']), 'Revoked publication succeeded');
    resetQueue();
    Modules_Help4DiskUsage_Store::write('rotation', []);
    $policy['scheduled_enabled'] = true; $policy['global_hourly'] = 1;
    Modules_Help4DiskUsage_Store::write('policy', $policy);
    pm_LongTask_Manager::$started = [];
    check(Modules_Help4DiskUsage_Scheduler::run()['queued'] === 1, 'Scheduled host cap bypass');
    resetQueue();
    Modules_Help4DiskUsage_Store::write('rotation', []);
    pm_LongTask_Manager::$fail = true;
    check(Modules_Help4DiskUsage_Scheduler::run()['queued'] === 0, 'Failed start counted as queued');
    check(Modules_Help4DiskUsage_Store::read('state')['pending'] === [], 'Failed start leaked reservation');
    $foreign = new pm_Scheduler_Task(); $foreign->setCmd('unrelated.php');
    pm_Scheduler::getInstance()->putTask($foreign);
    Modules_Help4DiskUsage_Scheduler::register();
    Modules_Help4DiskUsage_Scheduler::register();
    check(count(pm_Scheduler::getInstance()->listTasks()) === 2, 'Duplicate scheduled task');
    Modules_Help4DiskUsage_Scheduler::remove();
    check(pm_Scheduler::getInstance()->listTasks() === [$foreign], 'Unrelated task removed');
    echo "Scheduled policy, rotation, limits, revocation, failure and lifecycle tests passed\n";
} finally { removeFixture(pm_Context::$directory); }
