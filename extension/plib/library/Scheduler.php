<?php
class Modules_Help4DiskUsage_Scheduler
{
    const COMMAND = 'rotate.php';

    public static function register()
    {
        $manager = pm_Scheduler::getInstance();
        $task = null;
        foreach ($manager->listTasks() as $existing) {
            if ($existing->getCmd() === self::COMMAND) {
                if ($task === null) { $task = $existing; }
                else { $manager->removeTask($existing); }
            }
        }
        $task = $task ?? new pm_Scheduler_Task();
        $task->setCmd(self::COMMAND);
        $task->setArguments([]);
        $task->setSchedule(pm_Scheduler::$EVERY_HOUR);
        $manager->putTask($task);
        $manager->enableTask($task);
    }

    public static function remove()
    {
        foreach (pm_Scheduler::getInstance()->listTasks() as $task) {
            if ($task->getCmd() === self::COMMAND) { pm_Scheduler::getInstance()->removeTask($task); }
        }
    }

    public static function run()
    {
        $deadline = hrtime(true) + 10000000000;
        $claimed = Modules_Help4DiskUsage_Store::locked(function () {
            $policy = Modules_Help4DiskUsage_Store::policy();
            $previous = Modules_Help4DiskUsage_Store::read('rotation');
            if (!$policy['scheduled_enabled'] || ($previous['attempted_at'] ?? 0) > time() - $policy['scheduled_interval']) {
                return false;
            }
            Modules_Help4DiskUsage_Store::write('rotation', ['attempted_at' => time()]);
            return true;
        });
        if (!$claimed) { return ['queued' => 0, 'skipped' => 0, 'limited' => false]; }
        $policy = Modules_Help4DiskUsage_Store::policy();
        $candidates = [];
        $examined = 0;
        $skipped = 0;
        $limited = hrtime(true) >= $deadline;
        $domains = $limited ? [] : pm_Domain::getAllDomains(true);
        foreach ($domains as $domain) {
            if (++$examined > 10000 || hrtime(true) >= $deadline) { $limited = true; break; }
            try {
                $effective = Modules_Help4DiskUsage_Store::effective($domain);
                if ($effective['hourly'] === 0) { $skipped++; continue; }
                $report = Modules_Help4DiskUsage_Store::report($domain);
                $at = $report ? strtotime($report['scanned_at'] ?? '') : false;
                if ($at !== false && $at > time() - $effective['ttl']) { continue; }
                $candidates[] = ['id' => $domain->getId(), 'at' => $at === false ? 0 : $at];
            } catch (Throwable $e) { $skipped++; }
        }
        if (hrtime(true) >= $deadline) { $limited = true; }
        usort($candidates, function ($a, $b) { return ($a['at'] <=> $b['at']) ?: ($a['id'] <=> $b['id']); });
        $queued = 0;
        $attempted = 0;
        foreach ($candidates as $candidate) {
            if ($queued >= $policy['scheduled_batch'] || ++$attempted > $policy['scheduled_batch'] * 2 ||
                hrtime(true) >= $deadline) { $limited = true; break; }
            $token = null;
            try {
                $domain = pm_Domain::getByDomainId((int)$candidate['id']);
                $owner = $domain->getClient();
                if (hrtime(true) >= $deadline) { $limited = true; break; }
                $token = Modules_Help4DiskUsage_Store::reserve($domain, $owner, false, 'scheduled');
                if (hrtime(true) >= $deadline) {
                    $limited = true;
                    Modules_Help4DiskUsage_Store::finish($candidate['id'], $token);
                    break;
                }
                $task = new Modules_Help4DiskUsage_Task_Scan();
                $task->setParams(['domain' => $domain->getId(), 'token' => $token]);
                if (hrtime(true) >= $deadline) {
                    $limited = true;
                    Modules_Help4DiskUsage_Store::finish($candidate['id'], $token);
                    break;
                }
                $manager = new pm_LongTask_Manager();
                if (hrtime(true) >= $deadline) {
                    $limited = true;
                    Modules_Help4DiskUsage_Store::finish($candidate['id'], $token);
                    break;
                }
                $manager->start($task, $domain);
                $queued++;
                // Plesk may already accept a task during a slow call; keep its live reservation.
                if (hrtime(true) >= $deadline) { $limited = true; break; }
            } catch (Throwable $e) {
                if ($token !== null) { Modules_Help4DiskUsage_Store::finish($candidate['id'], $token); }
                $skipped++;
            }
        }
        if (hrtime(true) >= $deadline) { $limited = true; }
        $summary = ['queued' => $queued, 'skipped' => $skipped, 'limited' => $limited];
        Modules_Help4DiskUsage_Store::locked(function () use ($summary) {
            $state = Modules_Help4DiskUsage_Store::read('rotation');
            $state['completed_at'] = gmdate('c');
            $state['result'] = $summary;
            Modules_Help4DiskUsage_Store::write('rotation', $state);
        });
        return $summary;
    }
}
