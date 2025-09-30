<?php
// Simple helper to tail the notifications_all.log for quick browser viewing.
// WARNING: For development/testing only. Remove or protect in production.

$logFile = dirname(__DIR__) . '/logs/notifications_all.log';
header('Content-Type: text/plain; charset=utf-8');
if (!is_readable($logFile)) {
    echo "notifications_all.log not found or unreadable\n";
    exit;
}
// Stream last N lines (default 100)
$limit = isset($_GET['n']) ? max(1, min(1000, (int)$_GET['n'])) : 100;
$lines = [];
$fh = fopen($logFile, 'r');
if ($fh) {
    $buffer = '';
    $pos = -1;
    $fileSize = filesize($logFile);
    $count = 0;
    $max = $limit;
    while ($count < $max && (-$pos) <= $fileSize) {
        fseek($fh, $pos, SEEK_END);
        $ch = fgetc($fh);
        if ($ch === "\n") {
            if ($buffer !== '') {
                $lines[] = strrev($buffer);
                $buffer = '';
                $count++;
            }
        } else {
            $buffer .= $ch;
        }
        $pos--;
    }
    if ($buffer !== '') $lines[] = strrev($buffer);
    fclose($fh);
}
$lines = array_reverse($lines);
foreach ($lines as $ln) echo $ln . "\n";
