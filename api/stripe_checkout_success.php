<?php
// Handle Stripe Checkout success: verify session, mark appointment paid, record payment, then redirect to history
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
if (!is_logged_in() || !has_role('customer')) {
    // If not logged in, redirect to login then back here (best-effort)
    redirect('../login.php?next=' . urlencode('api/stripe_checkout_success.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '')));
}
$user = current_user();
$uid  = (int)($user['user_id'] ?? 0);

$cfg = require __DIR__ . '/../config/stripe.php';
$secret = $cfg['secret_key'] ?? '';

$sessionId = isset($_GET['session_id']) ? trim((string)$_GET['session_id']) : '';
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
if ($sessionId === '' || !$appointmentId) {
    redirect('../customer/bookings_history.php?payment=invalid');
}

// Retrieve Session and verify payment status
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $secret . ':',
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
if ($err || $code < 200 || $code >= 300) {
    redirect('../customer/bookings_history.php?payment=error');
}
$sess = json_decode($resp, true);
if (!is_array($sess) || ($sess['payment_status'] ?? '') !== 'paid') {
    redirect('../customer/bookings_history.php?payment=unpaid');
}

// Validate appointment belongs to user and is unpaid
$stmt = $pdo->prepare("SELECT a.appointment_id,a.shop_id,a.service_id,a.is_paid,a.status,s.price
                       FROM Appointments a
                       JOIN Services s ON a.service_id=s.service_id
                       WHERE a.appointment_id=? AND a.customer_id=? LIMIT 1");
$stmt->execute([$appointmentId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    redirect('../customer/bookings_history.php?payment=notfound');
}
if ($row['is_paid']) {
    redirect('../customer/bookings_history.php?payment=already');
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE Appointments SET is_paid=1 WHERE appointment_id=?')->execute([$appointmentId]);
    $pdo->prepare("INSERT INTO Payments (user_id, appointment_id, amount, transaction_type, payment_method, payment_status, paid_at) VALUES (?,?,?,?,?,?,NOW())")
        ->execute([$uid, $appointmentId, (float)$row['price'], 'appointment', 'online', 'completed']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('../customer/bookings_history.php?payment=dbfail');
}

redirect('../customer/bookings_history.php?payment=success');
