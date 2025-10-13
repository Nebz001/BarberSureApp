<?php
// Create a Stripe Checkout Session for an appointment and return the redirect URL
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
$pub    = $cfg['publishable_key'] ?? '';
$currency = $cfg['currency'] ?? 'usd';
$base = base_url('');

// Key sanity check
$bad = function ($k) {
    if (!$k) return true;
    $k = trim((string)$k);
    if (stripos($k, 'XXXX') !== false || stripos($k, 'REPLACE') !== false) return true;
    return !preg_match('/^sk_(test|live)_[A-Za-z0-9]{10,}$/', $k);
};
if ($bad($secret)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_config']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];
$appointmentId = (int)($data['appointment_id'] ?? 0);
if (!$appointmentId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'input']);
    exit;
}

// Validate appointment belongs to user and is unpaid
$stmt = $pdo->prepare("SELECT a.appointment_id,a.shop_id,a.service_id,a.is_paid,a.status,s.price,s.service_name,b.shop_name
                       FROM Appointments a
                       JOIN Services s ON a.service_id=s.service_id
                       JOIN Barbershops b ON a.shop_id=b.shop_id
                       WHERE a.appointment_id=? AND a.customer_id=? LIMIT 1");
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
$serviceName = (string)($row['service_name'] ?? 'Service');
$shopName    = (string)($row['shop_name'] ?? 'Barbershop');

$successUrl = $cfg['success_url'] ?: base_url('api/stripe_checkout_success.php') . '?session_id={CHECKOUT_SESSION_ID}&appointment_id=' . $appointmentId;
$cancelUrl  = $cfg['cancel_url']  ?: base_url('customer/booking.php');

// Create Checkout Session
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $secret . ':',
    CURLOPT_POSTFIELDS => http_build_query([
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url'  => $cancelUrl,
        'line_items[0][price_data][currency]' => $currency,
        'line_items[0][price_data][product_data][name]' => $serviceName . ' @ ' . $shopName,
        'line_items[0][price_data][unit_amount]' => $amountMinor,
        'line_items[0][quantity]' => 1,
        'metadata[appointment_id]' => $appointmentId,
        'metadata[user_id]' => $uid,
    ]),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
if ($err || $code < 200 || $code >= 300) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_error', 'detail' => $resp]);
    exit;
}
$sess = json_decode($resp, true);
if (!is_array($sess) || empty($sess['url']) || empty($sess['id'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bad_response']);
    exit;
}

echo json_encode(['ok' => true, 'url' => $sess['url'], 'id' => $sess['id']]);
