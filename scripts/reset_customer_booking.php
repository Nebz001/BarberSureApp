<?php
// Utility script to reset/cancel/delete bookings (appointments)
// - Web: requires admin login (has_role('admin')).
// - CLI: no auth required (run on server only).
//
// Actions:
//   reset  (default) -> Sets status='pending', is_paid=0; deletes pending Payments.
//   cancel            -> Sets status='cancelled', is_paid=0; deletes pending Payments.
//   delete            -> Deletes the appointment and any Payments linked to it (use with care!).
// Options:
//   force=1 (optional, with action=reset) also marks completed Payments as failed and clears paid_at.
//
// Single-target usage (CLI):
//   php scripts/reset_customer_booking.php --appointment=123 --action=reset --force=0
//
// Bulk usage (CLI):
//   php scripts/reset_customer_booking.php --bulk=1 --action=reset --confirm=ALL [--customer=ID] [--shop=ID] [--status=pending,confirmed] [--after=YYYY-MM-DD] [--before=YYYY-MM-DD] [--payment_option=cash|online] [--dry_run=1]
//
// Single-target usage (Web - admin only):
//   /scripts/reset_customer_booking.php?appointment_id=123&action=reset&force=0
//
// Bulk usage (Web - admin only):
//   /scripts/reset_customer_booking.php?bulk=1&action=reset&confirm=ALL&status=pending,confirmed

header('Content-Type: text/plain; charset=utf-8');

$BASE_DIR = dirname(__DIR__);
require_once $BASE_DIR . '/config/helpers.php';
require_once $BASE_DIR . '/config/db.php';
require_once $BASE_DIR . '/config/auth.php';

$cli = (php_sapi_name() === 'cli' || defined('STDIN'));

function parse_cli_args(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $k = substr($arg, 2, $eq - 2);
                $v = substr($arg, $eq + 1);
                $out[$k] = $v;
            } else {
                $out[substr($arg, 2)] = '1';
            }
        }
    }
    return $out;
}

if ($cli) {
    $args = parse_cli_args($argv ?? []);
    $appointmentId = isset($args['appointment']) ? (int)$args['appointment'] : 0;
    $action = isset($args['action']) ? trim((string)$args['action']) : 'reset';
    $force  = isset($args['force']) ? (int)$args['force'] : 0;
    // Bulk & filters
    $bulk   = (int)($args['bulk'] ?? $args['all'] ?? 0) ? 1 : 0;
    $customerId = isset($args['customer']) ? (int)$args['customer'] : 0;
    $shopId     = isset($args['shop']) ? (int)$args['shop'] : 0;
    $statusStr  = isset($args['status']) ? trim((string)$args['status']) : '';
    $paymentOpt = isset($args['payment_option']) ? trim((string)$args['payment_option']) : '';
    $after      = isset($args['after']) ? trim((string)$args['after']) : '';
    $before     = isset($args['before']) ? trim((string)$args['before']) : '';
    $dryRun     = (int)($args['dry_run'] ?? 0) ? 1 : 0;
    $limit      = isset($args['limit']) ? max(0, (int)$args['limit']) : 0;
    $confirmAll = isset($args['confirm']) ? trim((string)$args['confirm']) : '';
} else {
    if (!is_logged_in() || !has_role('admin')) {
        http_response_code(403);
        echo "403 Forbidden: Admin login required.\n";
        exit;
    }
    $appointmentId = (int)($_GET['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'reset'));
    $force  = (int)($_GET['force'] ?? $_POST['force'] ?? 0);
    // Bulk & filters
    $bulk   = (int)(($_GET['bulk'] ?? $_POST['bulk'] ?? $_GET['all'] ?? $_POST['all'] ?? 0)) ? 1 : 0;
    $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $shopId     = (int)($_GET['shop_id'] ?? $_POST['shop_id'] ?? 0);
    $statusStr  = trim((string)($_GET['status'] ?? $_POST['status'] ?? ''));
    $paymentOpt = trim((string)($_GET['payment_option'] ?? $_POST['payment_option'] ?? ''));
    $after      = trim((string)($_GET['after'] ?? $_POST['after'] ?? ''));
    $before     = trim((string)($_GET['before'] ?? $_POST['before'] ?? ''));
    $dryRun     = (int)($_GET['dry_run'] ?? $_POST['dry_run'] ?? 0) ? 1 : 0;
    $limit      = max(0, (int)($_GET['limit'] ?? $_POST['limit'] ?? 0));
    $confirmAll = trim((string)($_GET['confirm'] ?? $_POST['confirm'] ?? ''));
}

$action = $action ?: 'reset';
$allowed = ['reset', 'cancel', 'delete'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo "Invalid action. Use one of: reset, cancel, delete.\n";
    exit;
}

// If bulk mode (or filters provided), handle bulk operation
$filtersProvided = $bulk || $customerId || $shopId || $statusStr !== '' || $paymentOpt !== '' || $after !== '' || $before !== '';
if ($filtersProvided && $appointmentId <= 0) {
    // Build filter conditions
    $conds = [];
    $params = [];
    if ($customerId) {
        $conds[] = 'a.customer_id = ?';
        $params[] = $customerId;
    }
    if ($shopId) {
        $conds[] = 'a.shop_id = ?';
        $params[] = $shopId;
    }
    if ($paymentOpt !== '') {
        $conds[] = 'a.payment_option = ?';
        $params[] = $paymentOpt;
    }
    if ($statusStr !== '') {
        $statuses = array_values(array_filter(array_map('trim', explode(',', $statusStr))));
        if ($statuses) {
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $conds[] = 'a.status IN (' . $place . ')';
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }
    }
    if ($after !== '') {
        $afterVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $after) ? ($after . ' 00:00:00') : $after;
        $conds[] = 'a.appointment_date >= ?';
        $params[] = $afterVal;
    }
    if ($before !== '') {
        $beforeVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) ? ($before . ' 23:59:59') : $before;
        $conds[] = 'a.appointment_date <= ?';
        $params[] = $beforeVal;
    }

    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

    // Safety: if no filters at all, require explicit confirm=ALL
    if (!$conds && strcasecmp($confirmAll, 'ALL') !== 0) {
        http_response_code(400);
        echo "Refusing to run bulk without filters. Add confirm=ALL to proceed (dangerous).\n";
        echo "Example (CLI): php scripts/reset_customer_booking.php --bulk=1 --action={$action} --confirm=ALL\n";
        echo "Example (Web): /scripts/reset_customer_booking.php?bulk=1&action={$action}&confirm=ALL\n";
        exit;
    }

    $sql = "SELECT a.appointment_id FROM Appointments a $where ORDER BY a.appointment_id ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }
    $stmtIds = $pdo->prepare($sql);
    $stmtIds->execute($params);
    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $total = count($ids);
    echo "Matched appointments: $total\n";
    if ($total === 0) {
        exit;
    }
    if ($dryRun) {
        echo "Dry-run only. First 50 IDs: " . implode(',', array_slice($ids, 0, 50)) . "\n";
        exit;
    }

    $ok = 0;
    $fail = 0;
    try {
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $id = (int)$id;
            // Fetch appointment to ensure it exists (and for potential price/relations if needed later)
            $stmt = $pdo->prepare("SELECT appointment_id FROM Appointments WHERE appointment_id=? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                $fail++;
                continue;
            }

            if ($action === 'reset') {
                // Reset appointment and remove pending payments
                $pdo->prepare("UPDATE Appointments SET status='pending', is_paid=0 WHERE appointment_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM Payments WHERE appointment_id=? AND payment_status='pending'")->execute([$id]);
                if ($force) {
                    $pdo->prepare("UPDATE Payments SET payment_status='failed', paid_at=NULL WHERE appointment_id=? AND payment_status='completed'")->execute([$id]);
                }
                $ok++;
                continue;
            }

            if ($action === 'cancel') {
                $pdo->prepare("UPDATE Appointments SET status='cancelled', is_paid=0 WHERE appointment_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM Payments WHERE appointment_id=? AND payment_status='pending'")->execute([$id]);
                $ok++;
                continue;
            }

            if ($action === 'delete') {
                $pdo->prepare("DELETE FROM Payments WHERE appointment_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM Appointments WHERE appointment_id=?")->execute([$id]);
                // Best-effort chat cleanup
                $chatDir = realpath($BASE_DIR . '/storage/chat');
                if ($chatDir && is_dir($chatDir)) {
                    foreach (glob($chatDir . DIRECTORY_SEPARATOR . 'bk_' . $id . '_*.json') ?: [] as $f) {
                        @unlink($f);
                    }
                }
                $ok++;
                continue;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo "Bulk operation failed: " . $e->getMessage() . "\n";
        exit;
    }

    echo "Bulk {$action} complete. OK={$ok}, Fail={$fail}.\n";
    exit;
}

// Single-item mode requires an appointment id
if ($appointmentId <= 0) {
    http_response_code(400);
    echo "Missing or invalid appointment id.\n";
    exit;
}

// Fetch appointment details
$stmt = $pdo->prepare("SELECT a.appointment_id,a.customer_id,a.shop_id,a.service_id,a.status,a.is_paid,a.payment_option,s.price
                       FROM Appointments a
                       JOIN Services s ON a.service_id=s.service_id
                       WHERE a.appointment_id=? LIMIT 1");
$stmt->execute([$appointmentId]);
$appt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appt) {
    http_response_code(404);
    echo "Appointment not found: #{$appointmentId}\n";
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'reset') {
        // Reset to pending and unpaid
        $upd = $pdo->prepare("UPDATE Appointments SET status='pending', is_paid=0 WHERE appointment_id=?");
        $upd->execute([$appointmentId]);

        // Delete pending Payments (keep completed unless force is set)
        $delPending = $pdo->prepare("DELETE FROM Payments WHERE appointment_id=? AND payment_status='pending'");
        $delPending->execute([$appointmentId]);

        if ($force) {
            // Mark completed as failed and clear paid_at
            $updPay = $pdo->prepare("UPDATE Payments SET payment_status='failed', paid_at=NULL WHERE appointment_id=? AND payment_status='completed'");
            $updPay->execute([$appointmentId]);
        }

        $pdo->commit();
        echo "OK: Appointment #{$appointmentId} reset to pending & unpaid. Pending payments removed" . ($force ? "; completed marked failed" : "") . ".\n";
        exit;
    }

    if ($action === 'cancel') {
        $upd = $pdo->prepare("UPDATE Appointments SET status='cancelled', is_paid=0 WHERE appointment_id=?");
        $upd->execute([$appointmentId]);

        $delPending = $pdo->prepare("DELETE FROM Payments WHERE appointment_id=? AND payment_status='pending'");
        $delPending->execute([$appointmentId]);

        $pdo->commit();
        echo "OK: Appointment #{$appointmentId} cancelled. Pending payments removed.\n";
        exit;
    }

    if ($action === 'delete') {
        // Remove payments first, then appointment
        $delP = $pdo->prepare("DELETE FROM Payments WHERE appointment_id=?");
        $delP->execute([$appointmentId]);

        $delA = $pdo->prepare("DELETE FROM Appointments WHERE appointment_id=?");
        $delA->execute([$appointmentId]);

        // Best-effort cleanup of any appointment-specific chat transcript files
        $chatDir = realpath($BASE_DIR . '/storage/chat');
        if ($chatDir && is_dir($chatDir)) {
            foreach (glob($chatDir . DIRECTORY_SEPARATOR . 'bk_' . $appointmentId . '_*.json') ?: [] as $f) {
                @unlink($f);
            }
        }

        $pdo->commit();
        echo "OK: Appointment #{$appointmentId} and related payments deleted.\n";
        exit;
    }

    // Should not reach here
    $pdo->rollBack();
    http_response_code(500);
    echo "Unexpected state.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
