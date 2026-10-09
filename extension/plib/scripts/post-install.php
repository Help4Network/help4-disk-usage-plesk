<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
pm_Context::init('help4-disk-usage');
Modules_Help4DiskUsage_Store::directory();
Modules_Help4DiskUsage_Scheduler::register();
