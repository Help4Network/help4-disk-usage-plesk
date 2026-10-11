<?php
namespace ScheduledClockFixture {
    class Clock {
        public static $now = 0, $wallCalls = 0, $stage, $elapsed, $hits = [];
        public static function after($stage) {
            self::$hits[$stage] = (self::$hits[$stage] ?? 0) + 1;
            if (self::$stage === $stage) { self::$now = self::$elapsed; }
        }
    }
    function hrtime($number = false) { return Clock::$now; }
    function microtime($float = false) { Clock::$wallCalls++; return 0.0; }
    class Modules_Help4DiskUsage_Store {
        public static function __callStatic($name, $args) {
            $value = \Modules_Help4DiskUsage_Store::$name(...$args);
            if ($name === 'write' && $args[0] === 'rotation' && isset($args[1]['attempted_at']) &&
                !isset($args[1]['completed_at'])) {
                Clock::after('claim');
            }
            if ($name === 'report' || $name === 'reserve') { Clock::after($name); }
            return $value;
        }
    }
    class pm_Domain {
        public static function getAllDomains($include) {
            $domains = \pm_Domain::getAllDomains($include);
            Clock::after('inventory');
            return $domains;
        }
        public static function getByDomainId($id) {
            $domain = \pm_Domain::getByDomainId($id);
            Clock::after('lookup');
            return $domain;
        }
    }
    class Modules_Help4DiskUsage_Task_Scan {
        public $params;
        public function setParams($params) { $this->params = $params; Clock::after('task'); }
    }
    class pm_LongTask_Manager {
        public function __construct() { Clock::after('manager'); }
        public function start($task, $domain) {
            try { (new \pm_LongTask_Manager())->start($task, $domain); }
            finally { Clock::after('start'); }
        }
    }
}
namespace {
require __DIR__ . '/scheduler.php';
// Inject only clocks and post-operation delays; evaluate the unchanged scheduler body.
eval('namespace ScheduledClockFixture; use \\RuntimeException; use \\Throwable;' .
    substr(file_get_contents(__DIR__ . '/../extension/plib/library/Scheduler.php'), 5));
pm_Context::$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'h4-rotation-clock-' . bin2hex(random_bytes(8));
mkdir(pm_Context::$directory, 0700);
$checks = 0;
function deadlineCheck($value, $message) { check($value, $message); $GLOBALS['checks']++; }
try {
    $policy = Modules_Help4DiskUsage_Store::policy();
    $policy['scheduled_enabled'] = true;
    $policy['scheduled_batch'] = 1;
    $policy['python'] = PHP_BINARY;
    Modules_Help4DiskUsage_Store::write('policy', Modules_Help4DiskUsage_Store::validatePolicy($policy));
    pm_Domain::$domains = [1 => new pm_Domain()];
    foreach (['claim', 'inventory', 'report', 'lookup', 'reserve', 'task', 'manager', 'start'] as $stage) {
        foreach ([9999000000, 10000000000, 10001000000] as $elapsed) {
            resetQueue();
            Modules_Help4DiskUsage_Store::write('rotation', []);
            pm_LongTask_Manager::$started = [];
            pm_LongTask_Manager::$fail = false;
            \ScheduledClockFixture\Clock::$now = 0;
            \ScheduledClockFixture\Clock::$wallCalls = 0;
            \ScheduledClockFixture\Clock::$stage = $stage;
            \ScheduledClockFixture\Clock::$elapsed = $elapsed;
            \ScheduledClockFixture\Clock::$hits = [];
            $streams = count(get_resources('stream'));
            $result = \ScheduledClockFixture\Modules_Help4DiskUsage_Scheduler::run();
            $late = $elapsed >= 10000000000;
            $started = !$late || $stage === 'start';
            $reserved = $started || in_array($stage, ['reserve', 'task', 'manager'], true);
            $label = $stage . '/' . $elapsed;
            $state = Modules_Help4DiskUsage_Store::read('state');
            deadlineCheck((\ScheduledClockFixture\Clock::$hits[$stage] ?? 0) === 1, 'Delay injection missed: ' . $label);
            deadlineCheck(\ScheduledClockFixture\Clock::$wallCalls === 0, 'Wall clock used for budget: ' . $label);
            deadlineCheck($result === ['queued' => (int)$started, 'skipped' => 0, 'limited' => $late],
                'Deadline summary mismatch: ' . $label);
            deadlineCheck(pm_LongTask_Manager::$started === ($started ? [1] : []), 'Late task admitted: ' . $label);
            deadlineCheck(count($state['pending']) === (int)$started, 'Reservation leaked/lost: ' . $label);
            deadlineCheck(count($state['attempts']) === (int)$reserved, 'Rate attempt refunded/fabricated: ' . $label);
            deadlineCheck(count(get_resources('stream')) === $streams, 'Deadline lock leaked: ' . $label);
            deadlineCheck(isset(Modules_Help4DiskUsage_Store::read('rotation')['completed_at']),
                'Limited rotation lost completion marker: ' . $label);
            if ($started) {
                deadlineCheck($state['pending'][1]['source'] === 'scheduled' && !$state['pending'][1]['admin'],
                    'Scheduled role/source changed: ' . $label);
            }
            if ($late && $stage === 'claim') {
                deadlineCheck(!isset(\ScheduledClockFixture\Clock::$hits['inventory']), 'Expired claim queried inventory');
            }
            if ($late && $stage === 'reserve') {
                deadlineCheck(!isset(\ScheduledClockFixture\Clock::$hits['task']), 'Expired reservation constructed task');
            }
        }
    }
    foreach ([9999000000, 10000000000, 10001000000] as $elapsed) {
        resetQueue();
        Modules_Help4DiskUsage_Store::write('rotation', []);
        pm_LongTask_Manager::$started = [];
        pm_LongTask_Manager::$fail = true;
        \ScheduledClockFixture\Clock::$now = 0;
        \ScheduledClockFixture\Clock::$stage = 'start';
        \ScheduledClockFixture\Clock::$elapsed = $elapsed;
        \ScheduledClockFixture\Clock::$hits = [];
        $streams = count(get_resources('stream'));
        $result = \ScheduledClockFixture\Modules_Help4DiskUsage_Scheduler::run();
        $state = Modules_Help4DiskUsage_Store::read('state');
        deadlineCheck((\ScheduledClockFixture\Clock::$hits['start'] ?? 0) === 1, 'Failed-start injection missed');
        deadlineCheck($result === ['queued' => 0, 'skipped' => 1, 'limited' => $elapsed >= 10000000000],
            'Failed start lost deadline or counted task');
        deadlineCheck(pm_LongTask_Manager::$started === [], 'Failed task counted as accepted');
        deadlineCheck($state['pending'] === [] && count($state['attempts']) === 1, 'Failed start leaked/refunded admission');
        deadlineCheck(count(get_resources('stream')) === $streams, 'Failed-start lock leaked');
        deadlineCheck(isset(Modules_Help4DiskUsage_Store::read('rotation')['completed_at']), 'Failed rotation lost marker');
    }
    echo "Scheduled rotation: $checks unchanged-source monotonic/admission/cleanup checks passed (SDK fixtures, not native Plesk proof)\n";
} finally { removeFixture(pm_Context::$directory); }
}
