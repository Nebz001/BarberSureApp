<?php
// Creates a PaymentIntent for an appointment based on selected shop/service.
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
    // Basic shape check for Stripe secret keys
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
$shopId = (int)($data['shop_id'] ?? 0);
$serviceId = (int)($data['service_id'] ?? 0);
$appointmentId = (int)($data['appointment_id'] ?? 0);

// Validate appointment belongs to this user and is pending/unpaid
$stmt = $pdo->prepare("SELECT a.appointment_id,a.shop_id,a.service_id,a.is_paid,a.status,s.price FROM Appointments a JOIN Services s ON a.service_id=s.service_id WHERE a.appointment_id=? AND a.customer_id=? LIMIT 1");
$stmt->execute([$appointmentId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}
if ($row['is_paid']) {
    echo json_encode(['ok' => false, 'error' => 'already_paid']);
    exit;
}
if (!in_array($row['status'], ['pending', 'confirmed'], true)) {
    echo json_encode(['ok' => false, 'error' => 'bad_status']);
    exit;
}

$amount = (float)$row['price'];
$amountMinor = (int)round($amount * 100);

// Call Stripe API to create PaymentIntent
$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $secret . ':',
    CURLOPT_POSTFIELDS => http_build_query([
        'amount' => $amountMinor,
        'currency' => $cfg['currency'] ?? 'usd',
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[appointment_id]' => $appointmentId,
        'metadata[user_id]' => $uid,
    ])
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
if (!is_array($pi) || empty($pi['client_secret'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bad_response']);
    exit;
}

echo json_encode(['ok' => true, 'client_secret' => $pi['client_secret'], 'pi' => $pi]);
