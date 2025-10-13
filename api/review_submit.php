<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if (!is_logged_in() || !has_role('customer')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid session token']);
    exit;
}

$uid = (int)(current_user()['user_id'] ?? 0);
$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
$comment = trim((string)($_POST['comment'] ?? ''));

if ($appointment_id <= 0 || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    // Verify appointment belongs to user, is completed, and get shop_id
    $stmt = $pdo->prepare("SELECT a.appointment_id, a.customer_id, a.shop_id, a.status FROM Appointments a WHERE a.appointment_id = ? LIMIT 1");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appt || (int)$appt['customer_id'] !== $uid) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Appointment not found']);
        exit;
    }
    if ($appt['status'] !== 'completed') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only completed appointments can be reviewed']);
        exit;
    }

    // Prevent duplicate review per appointment by this user
    $chk = $pdo->prepare("SELECT review_id FROM Reviews WHERE appointment_id = ? AND customer_id = ? LIMIT 1");
    $chk->execute([$appointment_id, $uid]);
    if ($chk->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'You already reviewed this appointment']);
        exit;
    }

    // Insert review
    $ins = $pdo->prepare("INSERT INTO Reviews (customer_id, shop_id, rating, comment, appointment_id) VALUES (?,?,?,?,?)");
    $ok = $ins->execute([$uid, (int)$appt['shop_id'], $rating, $comment !== '' ? $comment : null, $appointment_id]);
    if (!$ok) {
        throw new Exception('Insert failed');
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
