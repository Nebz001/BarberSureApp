<?php
// Quick utility script to generate synthetic SMS log entries for testing notification dashboards.
// Usage (CLI / browser): place in scripts/ and invoke directly. Requires DB connection.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: text/plain');

global $pdo;
if (!$pdo) {
    echo "DB unavailable";
    exit;
}

// Fetch owners & customers
$users = [];
try {
    $stmt = $pdo->query("SELECT user_id, role FROM Users WHERE role IN ('owner','customer') ORDER BY user_id ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo "Query failed";
    exit;
}

if (!$users) {
    echo "No users to simulate";
    exit;
}

$root = dirname(__DIR__);
$logDir = $root . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$smsFile = $logDir . '/sms.log';

$count = 0;
foreach ($users as $u) {
    $uid = (int)$u['user_id'];
    $role = $u['role'];
    $title = 'Test SMS';
    $msg = ($role === 'owner' ? 'Owner promo: ' : 'Customer update: ') . 'Seeded test notification #' . ($count + 1);
    // Use existing helper to ensure consistent format & user phone capture
    send_channel_message('sms', $uid, $title, $msg, [], null);
    $count++;
}

echo "Generated $count SMS log entries into logs/sms.log";
