<?php
// Finalize an online payment: verify intent status and mark appointment paid.
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (!is_logged_in() || !has_role('customer')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}
$user = current_user();
$uid  = (int)($user['user_id'] ?? 0);

$cfg = require __DIR__ . '/../config/stripe.php';
$secret = $cfg['secret_key'] ?? '';
$isBadKey = (function ($k) {
    if (!$k) return true;
    $k = trim((string)$k);
    if (stripos($k, 'XXXX') !== false || stripos($k, 'REPLACE') !== false) return true;
    if (!preg_match('/^sk_(test|live)_[A-Za-z0-9]{10,}$/', $k)) return true;
    return false;
})($secret);
if ($isBadKey) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_config']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];
$appointmentId = (int)($data['appointment_id'] ?? 0);
$paymentIntentId = trim((string)($data['payment_intent_id'] ?? ''));
if (!$appointmentId || $paymentIntentId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'input']);
    exit;
}

// Retrieve PaymentIntent from Stripe
$ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $secret . ':',
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
if ($err || $code < 200 || $code >= 300) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_error', 'detail' => $resp]);
    exit;
}
$pi = json_decode($resp, true);
$status = $pi['status'] ?? '';
if (!in_array($status, ['succeeded', 'requires_capture', 'processing'], true)) {
    echo json_encode(['ok' => false, 'error' => 'not_succeeded', 'status' => $status]);
    exit;
}

// Validate appointment belongs to user and is unpaid
$stmt = $pdo->prepare("SELECT a.appointment_id,a.shop_id,a.service_id,a.is_paid,a.status,s.price FROM Appointments a JOIN Services s ON a.service_id=s.service_id WHERE a.appointment_id=? AND a.customer_id=? LIMIT 1");
$stmt->execute([$appointmentId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}
if ($row['is_paid']) {
    echo json_encode(['ok' => true, 'already' => 1]);
    exit;
}

try {
    $pdo->beginTransaction();
    // Mark appointment as paid
    $upd = $pdo->prepare('UPDATE Appointments SET is_paid=1 WHERE appointment_id=?');
    $upd->execute([$appointmentId]);
    // Insert into Payments
    $ins = $pdo->prepare("INSERT INTO Payments (user_id, appointment_id, amount, transaction_type, payment_method, payment_status, paid_at) VALUES (?,?,?,?,?,?,NOW())");
    $amount = (float)$row['price'];
    $ins->execute([$uid, $appointmentId, $amount, 'appointment', 'online', 'completed']);
    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
