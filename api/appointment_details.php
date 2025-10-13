<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_login();
    if (!has_role('customer')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $user = current_user();
    $userId = (int)($user['user_id'] ?? 0);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$userId || !$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $sql = "SELECT a.appointment_id, a.appointment_date, a.status, a.payment_option, a.notes,
                   b.shop_id, b.shop_name, b.city,
                   s.service_id, s.service_name, s.duration_minutes, s.price
            FROM Appointments a
            JOIN Barbershops b ON a.shop_id = b.shop_id
            JOIN Services s ON a.service_id = s.service_id
            WHERE a.customer_id = ? AND a.appointment_id = ?
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Appointment not found']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'appointment' => [
            'appointment_id'   => (int)$row['appointment_id'],
            'appointment_date' => $row['appointment_date'],
            'status'           => $row['status'],
            'payment_option'   => $row['payment_option'],
            'notes'            => $row['notes'],
            'shop' => [
                'shop_id'   => (int)$row['shop_id'],
                'shop_name' => $row['shop_name'],
                'city'      => $row['city']
            ],
            'service' => [
                'service_id'       => (int)$row['service_id'],
                'service_name'     => $row['service_name'],
                'duration_minutes' => (int)$row['duration_minutes'],
                'price'            => (float)$row['price']
            ]
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
