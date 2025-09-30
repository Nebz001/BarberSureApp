<?php
// CLI / cron entry to process notification broadcasts & queue
require_once __DIR__ . '/../config/functions.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI" . PHP_EOL;
    exit(1);
}

$batch = isset($argv[1]) ? (int)$argv[1] : 200;
$loop = in_array('--loop', $argv, true);
$interval = 15; // seconds between loops

echo "[Notification Queue] Starting processor (batch=$batch, loop=" . ($loop ? 'yes' : 'no') . ")" . PHP_EOL;

do {
    $processed = process_due_notification_broadcasts($batch);
    if ($processed) {
        echo date('c') . " processed queue IDs: " . implode(',', $processed) . PHP_EOL;
    } else {
        echo date('c') . " no pending items" . PHP_EOL;
    }
    if ($loop) sleep($interval);
} while ($loop);

echo "Done." . PHP_EOL;
