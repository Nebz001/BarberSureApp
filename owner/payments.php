<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);
// Capture verification status early
$ownerIsVerified = (int)($user['is_verified'] ?? 0);

// Load shared domain functions (for activate_subscription, etc.)
require_once __DIR__ . '/../config/functions.php';

// Detect existing primary shop for subscription targeting
$primaryShopId = null;
$ps = $pdo->prepare("SELECT shop_id FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC LIMIT 1");
$ps->execute([$ownerId]);
$primaryShopId = (int)($ps->fetchColumn() ?: 0);

// Handle simulated subscription purchase flow
$subscriptionNotice = null;
$subscriptionError = null;
if (isset($_GET['plan']) && in_array($_GET['plan'], ['monthly', 'yearly'], true)) {
    $planSel = $_GET['plan'];
    $baseAmount = $planSel === 'monthly' ? 499.00 : 4999.00; // pricing aligned with dashboard
    $taxRate = 12.0;
    // If user confirms via ?plan=xxx&confirm=1 run activation
    if (isset($_GET['confirm']) && $_GET['confirm'] == '1') {
        if (!$primaryShopId) {
            $subscriptionError = 'Register a shop before purchasing a subscription.';
        } elseif (!$ownerIsVerified) {
            $subscriptionError = 'Your account must be verified before you can activate a subscription. Complete all verification steps first.';
        } else {
            $act = activate_subscription($ownerId, $primaryShopId, $planSel, $baseAmount, $taxRate);
            if ($act['success']) {
                // After successful subscription, re-evaluate verification
                if (function_exists('evaluate_owner_verification')) {
                    $ver = evaluate_owner_verification($ownerId);
                    if ($ver['ready']) {
                        $updVer = $pdo->prepare("UPDATE Users SET is_verified=1 WHERE user_id=?");
                        $updVer->execute([$ownerId]);
                        // Update session copy
                        $_SESSION['user']['is_verified'] = 1;
                    }
                }
                // Redirect (PRG) to show success toast
                header('Location: payments.php?sub=success');
                exit;
            } else {
                $subscriptionError = $act['message'];
            }
        }
    }
}
if (isset($_GET['sub']) && $_GET['sub'] === 'success') {
    $subscriptionNotice = 'Subscription activated successfully!';
}

// Payment status update handling
$statusMessage = null;
$statusError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $statusError = 'Invalid session token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'mark_paid') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);

            if ($appointmentId && $amount > 0) {
                // Verify this appointment belongs to owner's shop and is completed
                $checkStmt = $pdo->prepare("
                    SELECT a.appointment_id, a.status, a.is_paid, s.price, b.shop_name, u.full_name as customer_name
                    FROM Appointments a 
                    JOIN Barbershops b ON a.shop_id = b.shop_id 
                    JOIN Services s ON a.service_id = s.service_id
                    JOIN Users u ON a.customer_id = u.user_id
                    WHERE a.appointment_id = ? AND b.owner_id = ? AND a.status = 'completed'
                    LIMIT 1
                ");
                $checkStmt->execute([$appointmentId, $ownerId]);
                $appointment = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($appointment && !$appointment['is_paid']) {
                    try {
                        $pdo->beginTransaction();

                        // Create payment record
                        $paymentStmt = $pdo->prepare("
                            INSERT INTO Payments (user_id, appointment_id, amount, transaction_type, payment_method, payment_status, paid_at) 
                            VALUES (?, ?, ?, 'appointment', 'cash', 'completed', NOW())
                        ");
                        $paymentStmt->execute([$ownerId, $appointmentId, $amount]);

                        // Mark appointment as paid
                        $updateStmt = $pdo->prepare("UPDATE Appointments SET is_paid = 1 WHERE appointment_id = ?");
                        $updateStmt->execute([$appointmentId]);

                        $pdo->commit();
                        $statusMessage = "Payment recorded successfully for " . $appointment['customer_name'] . ".";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $statusError = "Failed to record payment: " . $e->getMessage();
                    }
                } else {
                    $statusError = "Appointment not found, not completed, or already paid.";
                }
            } else {
                $statusError = "Invalid appointment or amount.";
            }
        }
    }
}

// Pagination and filtering
function in_get($k, $d = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$shopFilter = (int)in_get('shop', 0);
$statusFilter = in_get('status');
$periodFilter = in_get('period', 'month');
$page = max(1, (int)in_get('page', 1));
$perPage = 15;

// Get owner's shops
$ownerShops = $pdo->prepare("SELECT shop_id, shop_name FROM Barbershops WHERE owner_id = ? ORDER BY shop_name ASC");
$ownerShops->execute([$ownerId]);
$shops = $ownerShops->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Calculate revenue analytics
$revenueData = [];
$periods = [
    'today' => 'DATE(p.paid_at) = CURDATE()',
    'week' => 'p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
    'month' => 'p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
    'year' => 'p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)'
];

foreach ($periods as $period => $condition) {
    $revenueStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.payment_id) as total_payments,
            COALESCE(SUM(p.amount), 0) as total_revenue,
            COUNT(DISTINCT a.appointment_id) as total_appointments
        FROM Payments p
        LEFT JOIN Appointments a ON p.appointment_id = a.appointment_id
        LEFT JOIN Barbershops b_appt ON a.shop_id = b_appt.shop_id
        LEFT JOIN Barbershops b_direct ON p.shop_id = b_direct.shop_id
        WHERE (b_appt.owner_id = ? OR b_direct.owner_id = ?) AND p.payment_status = 'completed' AND $condition
    ");
    $revenueStmt->execute([$ownerId, $ownerId]);
    $revenueData[$period] = $revenueStmt->fetch(PDO::FETCH_ASSOC);
}

// Get unpaid completed appointments
$unpaidStmt = $pdo->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.payment_option,
        u.full_name as customer_name,
        u.phone as customer_phone,
        b.shop_name,
        s.service_name,
        s.price
    FROM Appointments a
    JOIN Barbershops b ON a.shop_id = b.shop_id
    JOIN Users u ON a.customer_id = u.user_id
    JOIN Services s ON a.service_id = s.service_id
    WHERE b.owner_id = ? AND a.status = 'completed' AND a.is_paid = 0 AND a.payment_option = 'cash'
    ORDER BY a.appointment_date DESC
    LIMIT 10
");
$unpaidStmt->execute([$ownerId]);
$unpaidAppointments = $unpaidStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Build query for payment transactions
$where = ['(b_appt.owner_id = :owner_id OR b_direct.owner_id = :owner_id2)', 'p.payment_status = "completed"'];
$params = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];

if ($shopFilter) {
    $where[] = '(a.shop_id = :shop_id OR p.shop_id = :shop_id3)';
    $params[':shop_id'] = $shopFilter;
    $params[':shop_id3'] = $shopFilter;
}

if ($statusFilter === 'appointment') {
    $where[] = 'p.transaction_type = "appointment"';
} elseif ($statusFilter === 'subscription') {
    $where[] = 'p.transaction_type = "subscription"';
}

$periodCondition = '';
if ($periodFilter === 'today') {
    $periodCondition = 'AND DATE(p.paid_at) = CURDATE()';
} elseif ($periodFilter === 'week') {
    $periodCondition = 'AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($periodFilter === 'month') {
    $periodCondition = 'AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
} elseif ($periodFilter === 'year') {
    $periodCondition = 'AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)';
}

$whereClause = implode(' AND ', $where) . ' ' . $periodCondition;

// Count total payments
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT p.payment_id) 
    FROM Payments p
    LEFT JOIN Appointments a ON p.appointment_id = a.appointment_id
    LEFT JOIN Barbershops b_appt ON a.shop_id = b_appt.shop_id
    LEFT JOIN Barbershops b_direct ON p.shop_id = b_direct.shop_id
    WHERE $whereClause
");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalPayments = (int)$countStmt->fetchColumn();

$maxPage = $totalPayments ? (int)ceil($totalPayments / $perPage) : 1;
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

// Fetch payment transactions
$paymentsStmt = $pdo->prepare("
    SELECT 
        p.payment_id,
        p.amount,
        p.tax_amount,
        p.transaction_type,
        p.payment_method,
        p.paid_at,
        p.created_at,
        COALESCE(u_customer.full_name, u_owner.full_name) as payer_name,
        COALESCE(b_appt.shop_name, b_direct.shop_name) as shop_name,
        a.appointment_date,
        s.service_name,
        a.appointment_id
    FROM Payments p
    LEFT JOIN Appointments a ON p.appointment_id = a.appointment_id
    LEFT JOIN Users u_customer ON a.customer_id = u_customer.user_id
    LEFT JOIN Users u_owner ON p.user_id = u_owner.user_id
    LEFT JOIN Services s ON a.service_id = s.service_id
    LEFT JOIN Barbershops b_appt ON a.shop_id = b_appt.shop_id
    LEFT JOIN Barbershops b_direct ON p.shop_id = b_direct.shop_id
    WHERE $whereClause
    ORDER BY p.paid_at DESC, p.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $paymentsStmt->bindValue($key, $value);
}
$paymentsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$paymentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$paymentsStmt->execute();
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Helper functions
function format_currency($amount)
{
    return '₱' . number_format((float)$amount, 2);
}

function format_payment_time($datetime)
{
    $timestamp = strtotime($datetime);
    if (!$timestamp) return $datetime;
    return date('M j, Y g:i A', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Payments • Owner • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin: .5rem 0 1.2rem;
        }

        form label {
            display: flex;
            flex-direction: column;
            font-size: .65rem;
            font-weight: 600;
            letter-spacing: .5px;
            gap: .35rem;
            color: var(--o-text-soft);
        }

        form input,
        form textarea,
        form select {
            background: #111c27;
            border: 1px solid #253344;
            border-radius: 8px;
            padding: .55rem .65rem;
            color: #e9eef3;
            font-size: .72rem;
            font-family: inherit;
        }

        .toast-stack {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin: 0 0 1rem;
        }

        .toast {
            background: #102231;
            border: 1px solid #253748;
            padding: .55rem .7rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: .7rem;
            font-size: .63rem;
            box-shadow: 0 4px 18px -6px #0008;
        }

        .toast.t-error {
            border-color: #5c1f2c;
            background: #2a1218;
            color: #fda4af;
        }

        .toast.t-success {
            border-color: #1c5030;
            background: #0d2a17;
            color: #6ee7b7;
        }

        .toast .t-close {
            background: none;
            border: 0;
            color: inherit;
            font-size: .9rem;
            cursor: pointer;
            line-height: 1;
            padding: .25rem .4rem;
            border-radius: 6px;
        }

        .toast .t-close:hover {
            background: #ffffff12;
        }

        .payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .62rem;
        }

        .payments-table th,
        .payments-table td {
            padding: .6rem .7rem;
            text-align: left;
            border-bottom: 1px solid #1f2b36;
        }

        .payments-table th {
            font-weight: 600;
            font-size: .58rem;
            letter-spacing: .5px;
            color: #93adc7;
            text-transform: uppercase;
        }

        .revenue-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .revenue-card {
            background: var(--o-surface);
            border: 1px solid var(--o-border-soft);
            border-radius: var(--o-radius-sm);
            padding: 1rem;
            text-align: center;
        }

        .revenue-card h3 {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--o-text-soft);
            margin: 0 0 .5rem;
        }

        .revenue-amount {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--o-accent);
            margin: 0 0 .3rem;
        }

        .revenue-count {
            font-size: .6rem;
            color: var(--o-text-soft);
        }

        .unpaid-section {
            background: #3b2f12;
            border: 1px solid #92400e;
            border-radius: var(--o-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .unpaid-section h3 {
            color: #fcd34d;
            margin: 0 0 .75rem;
            font-size: .9rem;
        }

        .unpaid-list {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .unpaid-item {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 6px;
            padding: .6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .65rem;
        }

        .btn-mark-paid {
            background: #059669;
            color: white;
            border: 1px solid #059669;
            padding: .3rem .6rem;
            border-radius: 4px;
            font-size: .55rem;
        }

        .btn-mark-paid:hover {
            background: #047857;
        }

        .payment-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
        }

        .payment-modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--o-bg-alt);
            border: 1px solid var(--o-border);
            border-radius: var(--o-radius);
            padding: 1.5rem;
            max-width: 400px;
            width: 90%;
        }

        .mini-note {
            font-size: .55rem;
            color: #69839b;
            margin: .2rem 0 .4rem;
        }

        /* Owner subscription card UI (blue theme) */
        #owner-card-section {
            background: #0b1a34;
            border: 1px solid #1e3a8a;
            border-radius: 12px;
            padding: 0.9rem 1rem 1rem;
            box-shadow: 0 6px 24px -12px rgba(11, 26, 52, 0.8);
        }
        #owner-card-section h3 {
            color: #93c5fd;
        }
        #owner-card-section .form-grid {
            gap: 0.8rem;
            margin: 0.4rem 0 0.2rem;
        }
        #owner-card-section label {
            color: #93c5fd;
            font-size: .66rem;
            letter-spacing: .4px;
        }
        #owner-card-section input,
        #owner-card-section select {
            background: #0a1530;
            border: 1px solid #1e3a8a;
            color: #e6f1ff;
            border-radius: 10px;
            padding: .6rem .7rem;
            font-size: .72rem;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        #owner-card-section input::placeholder {
            color: #98b4da;
            opacity: .75;
        }
        #owner-card-section select {
            appearance: none;
        }
        #owner-card-section input:focus,
        #owner-card-section select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .18);
            background: #0a1740;
        }
        #owner-card-section .input-icon {
            position: relative;
            display: block;
        }
        #owner-card-section .input-icon > i {
            position: absolute;
            left: .6rem;
            top: 50%;
            transform: translateY(-50%);
            color: #60a5fa;
            font-size: .9rem;
            pointer-events: none;
        }
        #owner-card-section .input-icon > input {
            padding-left: 2rem;
        }
        #owner-card-section #card-error {
            border-color: #5c1f2c;
            background: #2a1218;
            color: #fda4af;
        }
    </style>
</head>

<body class="owner-shell owner-wrapper">
    <header class="owner-header">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="ownerNav">☰</button>
        <?php $__first = $user ? e(explode(' ', trim($user['full_name']))[0]) : 'Owner'; ?>
        <div class="owner-brand">BarberSure <span style="opacity:.55;font-weight:500;">Owner</span><span class="owner-badge">Welcome <?= $__first ?></span></div>
        <nav id="ownerNav" class="owner-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="manage_shop.php">Manage Shop</a>
            <a href="bookings.php">Bookings</a>
            <a href="messages.php">Messages</a>
            <a class="active" href="payments.php">Payments</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="owner-main" style="padding-top:1.25rem;">
        <?php if ($subscriptionNotice || $subscriptionError): ?>
            <div class="toast-stack" style="margin-top:-.5rem;margin-bottom:1rem;">
                <?php if ($subscriptionNotice): ?><div class="toast t-success">
                        <div class="t-body"><?= e($subscriptionNotice) ?></div><button type="button" class="t-close">×</button>
                    </div><?php endif; ?>
                <?php if ($subscriptionError): ?><div class="toast t-error">
                        <div class="t-body"><?= e($subscriptionError) ?></div><button type="button" class="t-close">×</button>
                    </div><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($planSel) && !$subscriptionNotice && !$subscriptionError): ?>
            <section class="card" style="margin-bottom:1.5rem;">
                <h2 style="margin:0 0 .75rem;font-size:1rem;"><i class="bi bi-bag-check" aria-hidden="true"></i> Confirm <?= $planSel === 'monthly' ? 'Monthly' : 'Yearly' ?> Subscription</h2>
                <?php
                $amt = $baseAmount;
                // No tax applies to subscriptions (business rule); show straight amount
                $taxAmt = 0.00;
                $totalAmt = $amt;
                ?>
                <p style="font-size:.7rem;line-height:1.5;color:#6b8299;max-width:640px;">You're about to activate the <strong><?= $planSel === 'monthly' ? 'Monthly' : 'Yearly' ?></strong> plan for your primary shop. This is a simulated payment (no real gateway). The subscription becomes active immediately upon confirmation.</p>
                <ul style="list-style:none;padding:0;margin:.6rem 0 1rem;font-size:.65rem;color:#93adc7;display:grid;gap:.4rem;max-width:420px;">
                    <li>Subscription Price: <strong style="color:#fcd34d;">₱<?= number_format($amt, 2) ?></strong></li>
                    <li>Total Charge (No Tax): <strong style="color:#10b981;">₱<?= number_format($totalAmt, 2) ?></strong></li>
                    <li>Validity: <?= $planSel === 'monthly' ? '30 days' : '365 days' ?> (starting today)</li>
                    <?php if (!$ownerIsVerified): ?>
                        <li style="color:#f87171;font-weight:600;">Account not verified – subscription activation is blocked.</li>
                    <?php endif; ?>
                </ul>
                <!-- Card details required before confirming (mirrors customer online payment UI) -->
                <div id="owner-card-section" style="margin:.8rem 0 1rem;max-width:520px;">
                    <h3 style="margin:0 0 .5rem;font-size:.85rem;color:#9fb6cb;display:flex;align-items:center;gap:.4rem;">
                        <i class="bi bi-credit-card" aria-hidden="true"></i> Card details
                    </h3>
                    <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin:.4rem 0 0;">
                        <label>Card Number
                            <span class="input-icon">
                                <i class="bi bi-credit-card-2-front"></i>
                                <input id="card-number" name="card_number" type="text" class="control" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" maxlength="19" />
                            </span>
                        </label>
                        <label>Expiration (MM/YY)
                            <input id="card-exp" name="card_exp" type="text" class="control" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" maxlength="5" />
                        </label>
                        <label>CVC
                            <input id="card-cvc" name="card_cvc" type="text" class="control" inputmode="numeric" autocomplete="cc-csc" placeholder="123" maxlength="4" />
                        </label>
                        <label>Billing Country
                            <select id="card-country" name="card_country" class="control">
                                <option value="">Select country</option>
                                <option value="PH">Philippines</option>
                                <option value="US">United States</option>
                                <option value="CA">Canada</option>
                                <option value="GB">United Kingdom</option>
                                <option value="AU">Australia</option>
                                <option value="SG">Singapore</option>
                                <option value="MY">Malaysia</option>
                                <option value="AE">United Arab Emirates</option>
                            </select>
                        </label>
                    </div>
                    <p id="card-error" role="alert" style="display:none;margin:.6rem 0 0;padding:.5rem .6rem;border-radius:8px;border:1px solid #5c1f2c;background:#2a1218;color:#fda4af;font-size:.62rem;">Please fill in valid card details before confirming.</p>
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <?php if ($ownerIsVerified): ?>
                        <a id="confirm-activate" href="payments.php?plan=<?= e($planSel) ?>&confirm=1" class="btn btn-primary" style="background:#059669;border-color:#059669;">Confirm & Activate</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" disabled style="background:#374151;border-color:#374151;opacity:.6;cursor:not-allowed;">Verify Account First</button>
                    <?php endif; ?>
                    <a href="payments.php" class="btn">Cancel</a>
                </div>
            </section>
        <?php endif; ?>

        <div class="toast-stack" aria-live="polite" aria-atomic="true" style="position:relative;min-height:0;">
            <?php if ($statusError): ?>
                <div class="toast t-error" role="alert">
                    <div class="t-body"><?= e($statusError) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endif; ?>
            <?php if ($statusMessage): ?>
                <div class="toast t-success" role="status">
                    <div class="t-body"><?= e($statusMessage) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Revenue Overview -->
        <section class="card" style="margin-bottom:1.5rem;">
            <h2 style="margin:0 0 1rem;font-size:1rem;"><i class="bi bi-graph-up" aria-hidden="true"></i> Revenue Overview</h2>
            <div class="revenue-cards">
                <div class="revenue-card">
                    <h3>Today</h3>
                    <div class="revenue-amount"><?= format_currency($revenueData['today']['total_revenue']) ?></div>
                    <div class="revenue-count"><?= (int)$revenueData['today']['total_payments'] ?> payments</div>
                </div>
                <div class="revenue-card">
                    <h3>This Week</h3>
                    <div class="revenue-amount"><?= format_currency($revenueData['week']['total_revenue']) ?></div>
                    <div class="revenue-count"><?= (int)$revenueData['week']['total_payments'] ?> payments</div>
                </div>
                <div class="revenue-card">
                    <h3>This Month</h3>
                    <div class="revenue-amount"><?= format_currency($revenueData['month']['total_revenue']) ?></div>
                    <div class="revenue-count"><?= (int)$revenueData['month']['total_payments'] ?> payments</div>
                </div>
                <div class="revenue-card">
                    <h3>This Year</h3>
                    <div class="revenue-amount"><?= format_currency($revenueData['year']['total_revenue']) ?></div>
                    <div class="revenue-count"><?= (int)$revenueData['year']['total_payments'] ?> payments</div>
                </div>
            </div>
        </section>

        <!-- Unpaid Cash Appointments -->
        <?php if ($unpaidAppointments): ?>
            <div class="unpaid-section">
                <h3>⚠️ Unpaid Cash Appointments (<?= count($unpaidAppointments) ?>)</h3>
                <div class="unpaid-list">
                    <?php foreach ($unpaidAppointments as $unpaid): ?>
                        <div class="unpaid-item">
                            <div>
                                <strong><?= e($unpaid['customer_name']) ?></strong> - <?= e($unpaid['service_name']) ?>
                                <br>
                                <span style="color:#d97706;"><?= date('M j, Y g:i A', strtotime($unpaid['appointment_date'])) ?></span>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:600;color:#fcd34d;"><?= format_currency($unpaid['price']) ?></div>
                                <button class="btn-mark-paid" onclick="openPaymentModal(<?= (int)$unpaid['appointment_id'] ?>, '<?= e($unpaid['customer_name']) ?>', <?= $unpaid['price'] ?>)">
                                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Mark Paid
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <section class="card" style="margin-bottom:1.5rem;">
            <h2 style="margin:0 0 .75rem;font-size:1rem;"><i class="bi bi-funnel" aria-hidden="true"></i> Payment History Filters</h2>
            <form method="get" action="payments.php">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
                    <label>Shop
                        <select name="shop">
                            <option value="">All Shops</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?= (int)$shop['shop_id'] ?>" <?= $shopFilter === (int)$shop['shop_id'] ? 'selected' : '' ?>>
                                    <?= e($shop['shop_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Type
                        <select name="status">
                            <option value="">All Types</option>
                            <option value="appointment" <?= $statusFilter === 'appointment' ? 'selected' : '' ?>>Service Payments</option>
                            <option value="subscription" <?= $statusFilter === 'subscription' ? 'selected' : '' ?>>Subscriptions</option>
                        </select>
                    </label>

                    <label>Period
                        <select name="period">
                            <option value="all" <?= $periodFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="today" <?= $periodFilter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $periodFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                            <option value="month" <?= $periodFilter === 'month' ? 'selected' : '' ?>>This Month</option>
                            <option value="year" <?= $periodFilter === 'year' ? 'selected' : '' ?>>This Year</option>
                        </select>
                    </label>
                </div>
                <div style="margin-top:.8rem;">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>Filter</button>
                    <a href="payments.php" class="btn" style="margin-left:.5rem;"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset</a>
                </div>
            </form>
        </section>

        <!-- Payment History -->
        <section class="card" style="display:flex;flex-direction:column;gap:.9rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                <h2 style="margin:0;font-size:1rem;"><i class="bi bi-journal-check" aria-hidden="true"></i> Payment History <span style="font-size:.6rem;color:#6b8299;">(<?= $totalPayments ?>)</span></h2>
                <?php if ($totalPayments > $perPage): ?>
                    <span style="font-size:.6rem;color:#6b8299;">Page <?= $page ?> of <?= $maxPage ?></span>
                <?php endif; ?>
            </div>
            <div style="overflow:auto;">
                <table class="payments-table" aria-label="Payments table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Customer/Payer</th>
                            <th>Service/Type</th>
                            <th>Shop</th>
                            <th>Method</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$payments): ?>
                            <tr>
                                <td colspan="6" style="padding:.9rem .6rem;color:#6e859c;font-size:.6rem;text-align:center;">
                                    No payments found with current filters. <a href="payments.php" style="color:#3b82f6;">View all</a>
                                </td>
                            </tr>
                            <?php else: foreach ($payments as $payment): ?>
                                <tr>
                                    <td>
                                        <div><?= date('M j, Y', strtotime($payment['paid_at'])) ?></div>
                                        <div style="font-size:.55rem;color:#6b8299;"><?= date('g:i A', strtotime($payment['paid_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><?= e($payment['payer_name'] ?? 'Unknown') ?></div>
                                        <?php if ($payment['transaction_type'] === 'appointment'): ?>
                                            <div style="font-size:.55rem;color:#6b8299;">Customer</div>
                                        <?php else: ?>
                                            <div style="font-size:.55rem;color:#6b8299;">Shop Owner</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($payment['transaction_type'] === 'appointment'): ?>
                                            <div><?= e($payment['service_name'] ?? 'Service') ?></div>
                                            <div style="font-size:.55rem;color:#6b8299;">Service Payment</div>
                                        <?php else: ?>
                                            <div>Subscription</div>
                                            <div style="font-size:.55rem;color:#6b8299;">Annual Fee</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($payment['shop_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge" style="background:<?= $payment['payment_method'] === 'cash' ? '#3b2f12' : '#1e3a8a1a' ?>;color:<?= $payment['payment_method'] === 'cash' ? '#fcd34d' : '#3b82f6' ?>;">
                                            <?= strtoupper($payment['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:#10b981;"><?= format_currency($payment['amount']) ?></div>
                                        <?php if ($payment['tax_amount'] > 0): ?>
                                            <div style="font-size:.55rem;color:#6b8299;">+<?= format_currency($payment['tax_amount']) ?> tax</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Pagination -->
        <?php if ($maxPage > 1): ?>
            <div class="pagination">
                <?php
                $baseUrl = 'payments.php?' . http_build_query([
                    'shop' => $shopFilter ?: '',
                    'status' => $statusFilter,
                    'period' => $periodFilter
                ]);

                if ($page > 1): ?>
                    <a href="<?= e($baseUrl . '&page=' . ($page - 1)) ?>">‹ Prev</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($maxPage, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e($baseUrl . '&page=' . $i) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $maxPage): ?>
                    <a href="<?= e($baseUrl . '&page=' . ($page + 1)) ?>">Next ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <footer class="footer" style="margin-top:2rem;">&copy; <?= date('Y') ?> BarberSure</footer>
    </main>

    <!-- Payment Modal -->
    <div id="paymentModal" class="payment-modal">
        <div class="payment-modal-content">
            <h3 style="margin:0 0 1rem;font-size:1rem;">Mark Payment as Received</h3>
            <form method="post" id="paymentForm">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <input type="hidden" name="action" value="mark_paid" />
                <input type="hidden" name="appointment_id" id="modalAppointmentId" />

                <div style="margin-bottom:1rem;">
                    <label>Customer
                        <input type="text" id="modalCustomerName" readonly style="background:#0f1419;color:#6b8299;" />
                    </label>
                </div>

                <div style="margin-bottom:1rem;">
                    <label>Amount Received
                        <input type="number" name="amount" id="modalAmount" step="0.01" min="0" required />
                    </label>
                    <p class="mini-note">Confirm the exact amount received in cash from the customer.</p>
                </div>

                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.5rem;">
                    <button type="button" class="btn" onclick="closePaymentModal()"><i class="bi bi-x-circle" aria-hidden="true"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-receipt" aria-hidden="true"></i>Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Owner subscription: card formatting and validation, and gate confirm
        (function() {
            const num = document.getElementById('card-number');
            const exp = document.getElementById('card-exp');
            const cvc = document.getElementById('card-cvc');
            const country = document.getElementById('card-country');
            const err = document.getElementById('card-error');
            const confirmBtn = document.getElementById('confirm-activate');

            function showError(msg) {
                if (!err) return;
                err.textContent = msg || 'Please fill in valid card details.';
                err.style.display = '';
            }

            function hideError() {
                if (err) err.style.display = 'none';
            }

            function formatCardNumber(value) {
                const digits = (value || '').replace(/\D+/g, '').slice(0, 16);
                return digits.replace(/(.{4})/g, '$1 ').trim();
            }

            function onNumberInput(e) {
                const el = e.target;
                const start = el.selectionStart;
                const before = el.value;
                el.value = formatCardNumber(el.value);
                // naive caret correction
                const diff = el.value.length - before.length;
                el.selectionStart = el.selectionEnd = (start || 0) + diff;
                hideError();
            }

            function onExpInput(e) {
                let v = (e.target.value || '').replace(/[^\d]/g, '').slice(0, 4);
                if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
                e.target.value = v;
                hideError();
            }

            function isValidCard() {
                const digits = (num?.value || '').replace(/\D+/g, '');
                if (digits.length < 16) {
                    showError('Card number must be 16 digits.');
                    return false;
                }
                const ev = exp?.value || '';
                if (!/^\d{2}\/\d{2}$/.test(ev)) {
                    showError('Expiration must be in MM/YY format.');
                    return false;
                }
                const mm = parseInt(ev.slice(0, 2), 10);
                const yy = 2000 + parseInt(ev.slice(3), 10);
                if (!(mm >= 1 && mm <= 12) || isNaN(yy)) {
                    showError('Invalid expiration.');
                    return false;
                }
                const now = new Date();
                const curY = now.getFullYear();
                const curM = now.getMonth() + 1;
                if (yy < curY || (yy === curY && mm < curM)) {
                    showError('Card has expired.');
                    return false;
                }
                if (!/^\d{3,4}$/.test(cvc?.value || '')) {
                    showError('CVC must be 3 or 4 digits.');
                    return false;
                }
                if (!country?.value) {
                    showError('Please select a billing country.');
                    return false;
                }
                hideError();
                return true;
            }

            num?.addEventListener('input', onNumberInput);
            exp?.addEventListener('input', onExpInput);
            cvc?.addEventListener('input', hideError);
            country?.addEventListener('change', hideError);

            if (confirmBtn) {
                confirmBtn.addEventListener('click', function(e) {
                    if (!isValidCard()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
        })();
    </script>
    <script>
        (function initToasts() {
            const stack = document.querySelector('.toast-stack');
            if (!stack) return;
            stack.querySelectorAll('.toast').forEach(t => {
                const close = t.querySelector('.t-close');
                let timer = setTimeout(() => dismiss(t), 4000);
                close?.addEventListener('click', () => {
                    clearTimeout(timer);
                    dismiss(t);
                });
            });

            function dismiss(t) {
                if (!t) return;
                t.style.transition = 'opacity .4s,transform .4s';
                t.style.opacity = '0';
                t.style.transform = 'translateY(-6px)';
                setTimeout(() => t.remove(), 410);
            }
        })();

        function openPaymentModal(appointmentId, customerName, amount) {
            document.getElementById('modalAppointmentId').value = appointmentId;
            document.getElementById('modalCustomerName').value = customerName;
            document.getElementById('modalAmount').value = amount;
            document.getElementById('paymentModal').style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            if (event.target === modal) {
                closePaymentModal();
            }
        }
    </script>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>