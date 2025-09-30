<?php
// CLI utility: process due report schedules.
// Usage examples:
//   php scripts/run_report_schedules.php
//   php scripts/run_report_schedules.php --once
// Environment assumptions:
//   - Run from project root OR adjust path below.
//   - DB credentials loaded via config/db.php indirectly by functions.php.

$root = dirname(__DIR__);
require_once $root . '/config/functions.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}

$start = microtime(true);
$processed = process_due_report_schedules();
$count = count($processed);
$elapsed = round((microtime(true) - $start) * 1000, 2);

echo '[' . date('Y-m-d H:i:s') . "] Processed $count schedule(s) in {$elapsed}ms" . PHP_EOL;
if ($count) {
    foreach ($processed as $row) {
        echo "  - schedule_id={$row['schedule_id']} log_id={$row['log_id']}" . PHP_EOL;
    }
}

// If invoked with --loop, keep running every 5 minutes (simple daemon style)
if (in_array('--loop', $argv, true)) {
    echo "Entering loop mode (5 minute interval). Press Ctrl+C to exit." . PHP_EOL;
    while (true) {
        sleep(300); // 5 minutes
        $start = microtime(true);
        $processed = process_due_report_schedules();
        $count = count($processed);
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        echo '[' . date('Y-m-d H:i:s') . "] Loop run: $count schedule(s) in {$elapsed}ms" . PHP_EOL;
    }
}
