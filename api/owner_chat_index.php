<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (!is_logged_in() || !has_role('owner')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

// Optional ?shop= parameter to target a specific owned shop
$requestedShopId = isset($_GET['shop']) ? (int)$_GET['shop'] : 0;

if ($requestedShopId > 0) {
    $stmt = $pdo->prepare('SELECT shop_id FROM Barbershops WHERE owner_id=? AND shop_id=? LIMIT 1');
    $stmt->execute([$ownerId, $requestedShopId]);
    $shopId = (int)$stmt->fetchColumn();
} else {
    // fallback to first shop if none specified
    $stmt = $pdo->prepare('SELECT shop_id FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC LIMIT 1');
    $stmt->execute([$ownerId]);
    $shopId = (int)$stmt->fetchColumn();
}

if (!$shopId) {
    echo json_encode(['ok' => true, 'conversations' => [], 'shop_id' => null]);
    exit;
}
$chatDir = realpath(__DIR__ . '/../storage/chat');
if (!$chatDir) {
    echo json_encode(['ok' => false, 'error' => 'chat_dir']);
    exit;
}
$idxFile = $chatDir . DIRECTORY_SEPARATOR . 'index_shop_' . $shopId . '.json';
if (!is_file($idxFile)) {
    echo json_encode(['ok' => true, 'conversations' => []]);
    exit;
}
$raw = @file_get_contents($idxFile);
$dec = json_decode($raw, true);
if (!is_array($dec)) $dec = [];
// Sanitize
foreach ($dec as &$c) {
    $c['channel'] = (string)($c['channel'] ?? '');
    $c['last_msg'] = (string)($c['last_msg'] ?? '');
    $c['type'] = (string)($c['type'] ?? '');
}
unset($c);
echo json_encode(['ok' => true, 'conversations' => $dec, 'now' => time(), 'shop_id' => $shopId]);
