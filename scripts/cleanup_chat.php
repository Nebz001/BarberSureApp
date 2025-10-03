<?php
// Removes ephemeral chat files older than 2 hours.
$dir = __DIR__ . '/../storage/chat';
$ttl = 2 * 3600; // 2 hours
$now = time();
if (!is_dir($dir)) exit;
$files = glob($dir . '/*.json');
foreach ($files as $f) {
    $age = $now - @filemtime($f);
    if ($age > $ttl) @unlink($f);
}
