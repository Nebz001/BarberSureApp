<?php
// Ephemeral chat fetch endpoint
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$user = current_user();
$role = $user['role'] ?? '';
if (!in_array($role, ['customer', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$channel = $_GET['channel'] ?? '';
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
if (!preg_match('/^(pre_(?:\d+|all)_[A-Fa-f0-9]{6,40}|bk_\d+_[A-Fa-f0-9]{6,40}|bk_[A-Fa-f0-9]{6,40})$/', $channel)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_channel']);
    exit;
}

if (strpos($channel, 'pre_') === 0 && !in_array($role, ['customer', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'role_pre_forbidden']);
    exit;
}
if (strpos($channel, 'bk_') === 0 && !in_array($role, ['customer', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'role_booking_forbidden']);
    exit;
}
$chatDir = realpath(__DIR__ . '/../storage/chat');
if (!$chatDir) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'chat_storage']);
    exit;
}
$file = $chatDir . DIRECTORY_SEPARATOR . $channel . '.json';
if (!is_file($file)) {
    echo json_encode(['ok' => true, 'messages' => [], 'now' => time()]);
    exit;
}
$contents = @file_get_contents($file);
$arr = [];
if ($contents) {
    $decoded = json_decode($contents, true);
    if (is_array($decoded)) $arr = $decoded;
}
// Filter messages > since timestamp if provided
if ($since > 0) {
    $arr = array_values(array_filter($arr, function ($m) use ($since) {
        return isset($m['ts']) && $m['ts'] > $since;
    }));
}
// Sanitize output
foreach ($arr as &$m) {
    $m['msg'] = (string)$m['msg'];
    $m['role'] = $m['role'] === 'owner' ? 'owner' : 'customer';
}
unset($m);
echo json_encode(['ok' => true, 'messages' => $arr, 'now' => time()]);
