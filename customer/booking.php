<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Redirect unauthenticated users to login with return to this page (preserve selected shop)
if (!is_logged_in()) {
    $qs = '';
    if (isset($_GET['shop'])) {
        $qs = '?shop=' . (int)$_GET['shop'];
    }
    redirect('../login.php?next=' . urlencode('customer/booking.php' . $qs));
}
if (!has_role('customer')) redirect('../login.php');

$user = current_user();
$userId = (int)($user['user_id'] ?? 0);

// Check phone completion using database to avoid stale session; no redirect, show toast instead
$needsPhone = false;
try {
    $phoneVal = $user['phone'] ?? null;
    if ($userId) {
        $ph = $pdo->prepare('SELECT phone FROM Users WHERE user_id=?');
        $ph->execute([$userId]);
        $dbPhone = $ph->fetchColumn();
        if ($dbPhone !== false) $phoneVal = $dbPhone;
    }
    if (empty(trim((string)$phoneVal))) {
        $needsPhone = true;
    }
} catch (Throwable $e) {
    if (empty($user['phone'])) $needsPhone = true;
}

// Fetch approved shops (limit for performance, can add search later)
$shops = $pdo->query("SELECT shop_id, shop_name, city FROM Barbershops WHERE status='approved' ORDER BY shop_name ASC LIMIT 300")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// If a shop is preselected (via query param)
$selectedShopId = isset($_GET['shop']) ? (int)$_GET['shop'] : 0;
if ($selectedShopId && !in_array($selectedShopId, array_column($shops, 'shop_id'))) {
    $selectedShopId = 0; // invalid
}

// Fetch services for selected shop
$services = [];
$shopHours = null; // will hold open_time/close_time for selected shop if available
if ($selectedShopId) {
    $svcStmt = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC");
    $svcStmt->execute([$selectedShopId]);
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Detect hours columns and fetch if present
    try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='Barbershops' AND COLUMN_NAME IN ('open_time','close_time')");
        $colStmt->execute();
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasOpen = in_array('open_time', $cols, true);
        $hasClose = in_array('close_time', $cols, true);
        if ($hasOpen || $hasClose) {
            $sel = 'shop_id';
            if ($hasOpen) $sel .= ', open_time';
            if ($hasClose) $sel .= ', close_time';
            $hStmt = $pdo->prepare("SELECT $sel FROM Barbershops WHERE shop_id = ? AND status='approved'");
            $hStmt->execute([$selectedShopId]);
            $shopHours = $hStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        // Silently ignore if INFORMATION_SCHEMA not accessible or other issues
    }
}

$errors = [];
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid session token.';
    } else {
        if ($needsPhone) {
            $errors[] = 'Please complete your profile with a phone number before booking.';
        }
        $shopId = (int)($_POST['shop_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $dtRaw = trim($_POST['appointment_date'] ?? '');
        $payment = $_POST['payment_option'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');

        // Basic validation
        if (!$shopId) $errors[] = 'Please select a barbershop.';
        if (!$serviceId) $errors[] = 'Select a service.';
        if ($payment !== 'cash' && $payment !== 'online') $errors[] = 'Invalid payment option.';
        $dt = null;
        if ($dtRaw === '') {
            $errors[] = 'Choose a date & time.';
        } else {
            $dt = strtotime($dtRaw);
            if ($dt === false) {
                $errors[] = 'Invalid date/time format.';
            } elseif ($dt < time()) {
                $errors[] = 'Selected time has already passed.';
            }
        }

        // Ensure shop & service relation
        if (!$errors) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM Services WHERE service_id=? AND shop_id=?");
            $chk->execute([$serviceId, $shopId]);
            if (!(int)$chk->fetchColumn()) {
                $errors[] = 'Service not found for the selected shop.';
            }
        }

        // Anti-spam booking rules
        if (!$errors && $userId && $dt) {
            try {
                // 1) Max 3 bookings per calendar day (for the day of the selected appointment)
                $day = date('Y-m-d', $dt);
                $daily = $pdo->prepare(
                    "SELECT COUNT(*) FROM Appointments
                     WHERE customer_id = ?
                       AND DATE(appointment_date) = ?
                       AND status IN ('pending','confirmed')"
                );
                $daily->execute([$userId, $day]);
                if ((int)$daily->fetchColumn() >= 3) {
                    $errors[] = 'Daily limit reached: You can only book up to 3 appointments on the same day.';
                }

                // 2) Enforce a 2-hour interval between this and any of your other active appointments
                if (!$errors) {
                    $start = date('Y-m-d H:i:s', $dt - 7200); // 2 hours before
                    $end   = date('Y-m-d H:i:s', $dt + 7200); // 2 hours after
                    $near = $pdo->prepare(
                        "SELECT COUNT(*) FROM Appointments
                         WHERE customer_id = ?
                           AND status IN ('pending','confirmed')
                           AND appointment_date BETWEEN ? AND ?"
                    );
                    $near->execute([$userId, $start, $end]);
                    if ((int)$near->fetchColumn() > 0) {
                        $errors[] = 'Please allow at least 2 hours between your appointments.';
                    }
                }
            } catch (Throwable $e) {
                // If the checks fail for any reason, do not block the user, but log in future if needed
            }
        }

        // Enforce that selected time is within shop opening and closing hours (if defined)
        if (!$errors && $shopId && $dt) {
            try {
                $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='Barbershops' AND COLUMN_NAME IN ('open_time','close_time')");
                $colStmt->execute();
                $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (in_array('open_time', $cols, true) && in_array('close_time', $cols, true)) {
                    $h = $pdo->prepare("SELECT open_time, close_time FROM Barbershops WHERE shop_id=?");
                    $h->execute([$shopId]);
                    if ($row = $h->fetch(PDO::FETCH_ASSOC)) {
                        $o = $row['open_time'] ?? null;
                        $c = $row['close_time'] ?? null;
                        if ($o && $c) {
                            $day = date('Y-m-d', $dt);
                            $oTs = strtotime($day . ' ' . (preg_match('/^\d{2}:\d{2}$/', $o) ? $o . ':00' : $o));
                            $cTs = strtotime($day . ' ' . (preg_match('/^\d{2}:\d{2}$/', $c) ? $c . ':00' : $c));
                            if ($oTs !== false && $cTs !== false) {
                                if ($dt < $oTs || $dt >= $cTs) {
                                    $errors[] = 'Selected time is outside shop hours.';
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore if schema lookup not permitted
            }
        }

        if (!$errors && $userId) {
            $ins = $pdo->prepare("INSERT INTO Appointments (customer_id, shop_id, service_id, appointment_date, payment_option, notes) VALUES (?,?,?,?,?,?)");
            $ok = $ins->execute([$userId, $shopId, $serviceId, date('Y-m-d H:i:s', $dt), $payment, $notes ?: null]);
            if ($ok) {
                $success = 'Appointment booked successfully!';
                // Refresh services for selected
                $selectedShopId = $shopId;
                $svcStmt = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC");
                $svcStmt->execute([$selectedShopId]);
                $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $errors[] = 'Failed to create appointment.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Book Appointment • BarberSure</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/customer.css" />
    <link rel="stylesheet" href="../assets/css/toast.css" />
    <style>
        .layout {
            display: flex;
            flex-direction: column;
            gap: 1.4rem;
        }

        .section {
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border-soft);
            border-radius: var(--radius);
            padding: 1.1rem 1.25rem 1.25rem;
            box-shadow: var(--shadow-elev);
        }

        .section h2 {
            font-size: 1.05rem;
            margin: 0 0 .9rem;
            font-weight: 600;
            letter-spacing: .4px;
            color: var(--c-text-soft);
        }

        form.booking-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .field-grid {
            display: grid;
            gap: .9rem 1rem;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            align-items: start;
        }

        /* Prevent intrinsic min-content overflow in grid items */
        .field-grid>div {
            min-width: 0;
        }

        label.field-label {
            font-size: .62rem;
            letter-spacing: .6px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--c-text-soft);
            display: block;
            margin-bottom: .35rem;
        }

        .control {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            padding: .7rem .75rem;
            font-size: .8rem;
            border-radius: var(--radius-sm);
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .control:focus {
            outline: none;
            border-color: var(--c-accent-alt);
            box-shadow: 0 0 0 .12rem rgba(14, 165, 233, .25);
        }

        .inline {
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-wrap: wrap;
        }

        .notes-box {
            min-height: 90px;
            resize: vertical;
            line-height: 1.4;
        }

        .hours-hint {
            margin-top: .35rem;
            font-size: .78rem;
            color: var(--c-text-soft);
            display: flex;
            align-items: center;
            gap: .35rem;
        }

        .muted {
            color: var(--c-text-soft);
            font-size: .68rem;
        }

        /* legacy .alert styles removed (using toast system now) */

        .services-list {
            display: grid;
            gap: .65rem;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            margin-top: .5rem;
        }

        .service-item {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            padding: .7rem .75rem;
            border-radius: var(--radius-sm);
            font-size: .74rem;
            display: flex;
            flex-direction: column;
            gap: .4rem;
            cursor: pointer;
            position: relative;
        }

        .service-item:hover {
            border-color: var(--c-accent-alt);
        }

        .service-item.active {
            border-color: var(--c-accent-alt);
            background: linear-gradient(135deg, #1e2732, #1b2530);
        }

        .service-radio {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .badge-pill {
            background: var(--grad-accent);
            color: #fff;
            font-size: .6rem;
            padding: .3rem .65rem;
            border-radius: 40px;
            font-weight: 600;
            letter-spacing: .55px;
        }



        .actions {
            display: flex;
            gap: .7rem;
            flex-wrap: wrap;
            margin-top: .55rem;
        }

        .divider {
            border: 0;
            border-top: 1px solid var(--c-border);
            margin: 1.2rem 0;
        }

        .loading-msg {
            font-size: .68rem;
            color: var(--c-text-soft);
        }

        .layout {
            max-width: 1260px;
            /* allow space for summary */
            margin: 0 auto;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 1.4rem;
        }

        .page-container {
            max-width: 1260px;
            margin: 0 auto;
            padding: 0 1rem 2rem;
            width: 100%;
        }


        @media (min-width: 1100px) {
            .layout.two-col {
                flex-direction: row;
                align-items: flex-start;
            }

            .primary-col,
            .summary-col {
                flex: 1 1 50%;
            }

            .primary-col.section {
                padding: 1.3rem 1.4rem 1.55rem;
            }
        }

        .booking-form {
            width: 100%;
        }

        .primary-col {
            width: 100%;
        }

        .summary-col {
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border-soft);
            border-radius: var(--radius);
            padding: 1.3rem 1.4rem 1.55rem;
            box-shadow: var(--shadow-elev);
            position: relative;
            margin-top: 0;
        }

        .live-summary-title {
            font-size: 1.05rem;
            margin: 0 0 .9rem;
            font-weight: 600;
            letter-spacing: .4px;
            color: var(--c-text-soft);
        }

        .live-summary {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: .55rem .85rem;
            font-size: .8rem;
            color: var(--c-text-soft);
            margin-bottom: 1rem;
        }

        .live-summary strong {
            color: var(--c-text);
            font-weight: 600;
        }

        .summary-hint {
            font-size: .65rem;
            color: var(--c-text-soft);
            line-height: 1.4;
        }

        .sticky-note {
            position: sticky;
            top: 82px;
        }

        /* Larger, more readable form fields */
        .control {
            font-size: 1rem;
            padding: 0.75rem;
            min-height: 48px;
        }

        .field-label {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .service-item {
            padding: 1rem;
            font-size: 1rem;
        }

        .notes-box {
            min-height: 80px;
            font-size: 1rem;
        }

        /* On narrower screens, force single column to avoid overlap of complex inputs */
        @media (max-width: 640px) {
            .field-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Confirmation Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay[aria-hidden="false"] {
            display: flex;
        }

        .modal-card {
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border-soft);
            border-radius: var(--radius);
            box-shadow: var(--shadow-elev);
            width: min(560px, 92vw);
            padding: 1rem 1.1rem 1.1rem;
            color: var(--c-text);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            margin-bottom: .6rem;
        }

        .modal-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .modal-body {
            font-size: .8rem;
            color: var(--c-text-soft);
        }

        .modal-summary {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: .45rem .75rem;
            margin: .6rem 0 .2rem;
        }

        .modal-actions {
            display: flex;
            gap: .6rem;
            justify-content: flex-end;
            margin-top: .9rem;
        }

        .btn-ghost {
            background: var(--c-surface);
            color: var(--c-text-soft);
            border: 1px solid var(--c-border);
            padding: 0.5rem 0.85rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            letter-spacing: .4px;
            cursor: pointer;
        }

        .btn-ghost:hover {
            color: var(--c-text);
            border-color: var(--c-accent-alt);
        }

        .btn-accent {
            background: var(--grad-accent);
            color: #111827;
            border: 0;
            padding: 0.55rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 800;
            letter-spacing: .45px;
            cursor: pointer;
            min-width: 160px;
        }

        .btn-accent:disabled {
            opacity: .6;
            cursor: not-allowed;
        }
    </style>
</head>

<body class="dashboard-wrapper">
    <header class="header-bar">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
        <div class="header-brand">
            <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
            <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
        </div>
        <nav id="customerNav" class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="search.php">Find Shops</a>
            <a class="active" href="booking.php">Book</a>
            <a href="bookings_history.php">History</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="dashboard-main">
        <div class="page-container">
            <section class="card" style="padding:1.3rem 1.4rem 1.55rem;margin-bottom:1.6rem;">
                <div class="search-header" style="margin-bottom:1rem;">
                    <h1 style="font-size:1.55rem;margin:0;font-weight:600;letter-spacing:.4px;">Create Appointment</h1>
                    <p style="font-size:.8rem;color:var(--c-text-soft);max-width:760px;line-height:1.55;margin:.45rem 0 0;">Choose a barbershop, pick a service, and schedule your preferred time. You can add optional notes for special requests.</p>
                </div>
            </section>
            <div class="layout two-col">
                <div class="primary-col section">
                    <h2 style="display:none;">Create Appointment</h2>
                    <?php if ($needsPhone || $errors || $success): ?>
                        <div class="toast-container" aria-live="polite" aria-atomic="true" style="margin-bottom:.4rem;">
                            <?php if ($needsPhone): ?>
                                <div class="toast toast-error" role="alert" data-duration="7000">
                                    <div class="toast-icon" aria-hidden="true">⚠️</div>
                                    <div class="toast-body">Profile not completed yet — please add your phone number to continue booking.</div>
                                    <button class="toast-close" aria-label="Close notification">&times;</button>
                                    <div class="toast-progress"></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="toast" data-duration="6000" role="alert">
                                    <div class="toast-icon" aria-hidden="true">✅</div>
                                    <div class="toast-body"><?= e($success) ?></div>
                                    <button class="toast-close" aria-label="Close notification">&times;</button>
                                    <div class="toast-progress"></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($errors): foreach ($errors as $er): ?>
                                    <div class="toast toast-error" data-duration="9000" role="alert">
                                        <div class="toast-icon" aria-hidden="true">⚠️</div>
                                        <div class="toast-body"><?= e($er) ?></div>
                                        <button class="toast-close" aria-label="Close error">&times;</button>
                                        <div class="toast-progress"></div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="booking-form" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                        <div class="field-grid">
                            <div>
                                <label class="field-label">Barbershop</label>
                                <?php
                                $selectedShopName = '—';
                                if ($selectedShopId) {
                                    foreach ($shops as $s) {
                                        if ((int)$s['shop_id'] === (int)$selectedShopId) {
                                            $selectedShopName = $s['shop_name'] . ($s['city'] ? ' • ' . $s['city'] : '');
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <input type="text" class="control" value="<?= e($selectedShopName) ?>" readonly aria-readonly="true" />
                                <input type="hidden" name="shop_id" value="<?= (int)$selectedShopId ?>" />
                                <p class="muted" style="margin:.3rem 0 0;">Barbershops will show here.</p>
                            </div>
                            <div>
                                <label class="field-label">Date & Time</label>
                                <input type="datetime-local" name="appointment_date" id="appointment_dt" class="control" value="<?= e($_POST['appointment_date'] ?? '') ?>" />
                                <?php
                                // small formatter to 12-hour display
                                $fmtHr = function ($t) {
                                    if (!$t) return null;
                                    if (preg_match('/^\d{2}:\d{2}$/', $t)) $t .= ':00';
                                    $ts = strtotime($t);
                                    return $ts ? date('g:i A', $ts) : $t;
                                };
                                if (!empty($shopHours)) {
                                    $o = $shopHours['open_time'] ?? null;
                                    $c = $shopHours['close_time'] ?? null;
                                    if (!empty($o) && !empty($c)):
                                ?>
                                        <div class="hours-hint"><i class="bi bi-clock"></i> Open today: <?= e($fmtHr($o)) ?> – <?= e($fmtHr($c)) ?></div>
                                <?php
                                    endif;
                                }
                                ?>
                            </div>
                            <div>
                                <label class="field-label">Payment</label>
                                <select name="payment_option" class="control">
                                    <option value="cash" <?= (($_POST['payment_option'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                                    <option value="online" <?= (($_POST['payment_option'] ?? '') === 'online') ? 'selected' : '' ?>>Online</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="field-label">Notes (Optional)</label>
                            <textarea name="notes" class="control notes-box" placeholder="Any specific instructions?"><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="field-label" style="display:flex;align-items:center;gap:.4rem;">Service <span class="muted" style="font-weight:400;">(select one)</span></label>
                            <?php if (!$selectedShopId): ?>
                                <div class="loading-msg">Select a barbershop to load its services.</div>
                            <?php elseif (!$services): ?>
                                <div class="loading-msg">No services configured for this shop.</div>
                            <?php else: ?>
                                <div class="services-list">
                                    <?php foreach ($services as $svc): $sid = (int)$svc['service_id'];
                                        $active = ((int)($_POST['service_id'] ?? 0) === $sid); ?>
                                        <label class="service-item<?= $active ? ' active' : '' ?>">
                                            <input type="radio" name="service_id" value="<?= $sid ?>" class="service-radio" <?= $active ? 'checked' : '' ?> />
                                            <span style="font-weight:600;letter-spacing:.3px;font-size:1rem;"><?= e($svc['service_name']) ?></span>
                                            <span style="display:flex;justify-content:space-between;align-items:center;font-size:0.9rem;margin-top:4px;">
                                                <span><?= (int)$svc['duration_minutes'] ?> mins</span>
                                                <span class="badge-pill">₱<?= number_format((float)$svc['price'], 2) ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="actions">
                            <button type="submit" class="btn btn-primary" style="font-size:1rem;padding:12px 24px;">Book Appointment</button>
                            <a href="booking.php" class="btn" style="font-size:1rem;padding:12px 24px;">Reset</a>
                        </div>
                    </form>
                </div>
                <div class="summary-col sticky-note" aria-live="polite">
                    <h2 class="live-summary-title">Summary</h2>
                    <div class="live-summary" id="live-summary">
                        <div><strong>Shop</strong></div>
                        <div id="ls-shop">—</div>
                        <div><strong>Service</strong></div>
                        <div id="ls-service">—</div>
                        <div><strong>Date & Time</strong></div>
                        <div id="ls-datetime">—</div>
                        <div><strong>Payment</strong></div>
                        <div id="ls-payment">Cash</div>
                        <div><strong>Notes</strong></div>
                        <div id="ls-notes">—</div>
                    </div>
                    <p class="summary-hint">This summary updates automatically as you fill out the form. You can still review everything in the confirmation step before submitting.</p>
                </div>
            </div>
        </div> <!-- /.page-container -->
    </main>
    <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • Book with confidence.</footer>
    <!-- Confirmation Modal -->
    <div id="confirm-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="confirm-title">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="confirm-title" class="modal-title">Confirm Your Booking</h3>
                <span class="badge-pill">Review</span>
            </div>
            <div class="modal-body">
                <div class="modal-summary">
                    <div><strong>Shop</strong></div>
                    <div id="sum-shop">—</div>
                    <div><strong>Service</strong></div>
                    <div id="sum-service">—</div>
                    <div><strong>Date & Time</strong></div>
                    <div id="sum-datetime">—</div>
                    <div><strong>Payment</strong></div>
                    <div id="sum-payment">—</div>
                    <div><strong>Notes</strong></div>
                    <div id="sum-notes">—</div>
                </div>
                <div class="muted">Please verify the details are correct. You can still go back to make changes.</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-ghost" id="confirm-cancel">Cancel</button>
                <button type="button" class="btn-accent" id="confirm-submit" disabled>Confirm (3)</button>
            </div>
        </div>
    </div>

    <script>
        // Client-side validation: enforce future date and shop hours (runs after DOM built)
        (function() {
            const dtInput = document.getElementById('appointment_dt');
            if (!dtInput) return;
            const pad = n => String(n).padStart(2, '0');
            const now = new Date();
            now.setMinutes(now.getMinutes() + (5 - (now.getMinutes() % 5)) % 5, 0, 0);
            const minVal = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
            if (!dtInput.min) dtInput.min = minVal;
            const shopOpen = <?= isset($shopHours['open_time']) ? ('"' . addslashes($shopHours['open_time']) . '"') : 'null' ?>;
            const shopClose = <?= isset($shopHours['close_time']) ? ('"' . addslashes($shopHours['close_time']) . '"') : 'null' ?>;

            function norm(t) {
                if (!t) return null;
                return /^\d{2}:\d{2}$/.test(t) ? t + ':00' : t;
            }

            function inHours(d) {
                if (!shopOpen || !shopClose) return true;
                const o = norm(shopOpen),
                    c = norm(shopClose);
                if (!o || !c) return true;
                const day = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
                const oD = new Date(`${day}T${o}`);
                const cD = new Date(`${day}T${c}`);
                return d >= oD && d < cD;
            }

            function validate() {
                if (!dtInput.value) {
                    dtInput.setCustomValidity('');
                    return;
                }
                const d = new Date(dtInput.value);
                if (isNaN(d.getTime())) {
                    dtInput.setCustomValidity('Invalid date');
                    return;
                }
                const nowLocal = new Date();
                if (d < nowLocal) dtInput.setCustomValidity('Time already passed');
                else if (!inHours(d)) dtInput.setCustomValidity('Outside shop hours');
                else dtInput.setCustomValidity('');
            }
            dtInput.addEventListener('change', validate);
            dtInput.addEventListener('input', validate);
            validate();
        })();
    </script>
    <script>
        // Intercept submit to show confirmation with 3s delay before enabling confirm
        (function() {
            const form = document.querySelector('form.booking-form');
            if (!form) return;
            let bypass = false;

            const overlay = document.getElementById('confirm-overlay');
            const btnCancel = document.getElementById('confirm-cancel');
            const btnConfirm = document.getElementById('confirm-submit');
            const elShop = document.getElementById('sum-shop');
            const elSvc = document.getElementById('sum-service');
            const elDt = document.getElementById('sum-datetime');
            const elPay = document.getElementById('sum-payment');
            const elNotes = document.getElementById('sum-notes');

            function getShopName() {
                // The read-only shop input is the first readonly .control in the form
                const ro = form.querySelector('input.control[readonly]');
                return ro ? ro.value.trim() || '—' : '—';
            }

            function getServiceText() {
                const checked = form.querySelector('input[name="service_id"]:checked');
                if (!checked) return '—';
                const wrap = checked.closest('.service-item');
                if (!wrap) return '—';
                const spans = wrap.querySelectorAll('span');
                // First span is name; second line has price badge later
                const name = spans[0] ? spans[0].textContent.trim() : '';
                const price = wrap.querySelector('.badge-pill')?.textContent.trim() || '';
                return price ? name + ' • ' + price : name || '—';
            }

            function formatDateTime(v) {
                if (!v) return '—';
                try {
                    // v is like '2025-09-24T14:30'
                    const dt = new Date(v);
                    if (isNaN(dt.getTime())) return v;
                    const opts = {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    };
                    return dt.toLocaleString(undefined, opts);
                } catch (e) {
                    return v;
                }
            }

            function populateSummary() {
                elShop.textContent = getShopName();
                elSvc.textContent = getServiceText();
                elDt.textContent = formatDateTime(form.elements['appointment_date']?.value || '');
                const paySel = form.elements['payment_option'];
                const payText = paySel && paySel.options[paySel.selectedIndex] ? paySel.options[paySel.selectedIndex].text : '—';
                elPay.textContent = payText;
                const notes = form.elements['notes']?.value.trim() || '';
                elNotes.textContent = notes !== '' ? notes : '—';

                // Mirror to live summary panel
                const lsShop = document.getElementById('ls-shop');
                const lsSvc = document.getElementById('ls-service');
                const lsDt = document.getElementById('ls-datetime');
                const lsPay = document.getElementById('ls-payment');
                const lsNotes = document.getElementById('ls-notes');
                if (lsShop) lsShop.textContent = elShop.textContent;
                if (lsSvc) lsSvc.textContent = elSvc.textContent;
                if (lsDt) lsDt.textContent = elDt.textContent;
                if (lsPay) lsPay.textContent = elPay.textContent;
                if (lsNotes) lsNotes.textContent = elNotes.textContent;
            }

            function openModal() {
                populateSummary();
                overlay.setAttribute('aria-hidden', 'false');
                // Countdown
                let left = 3;
                btnConfirm.disabled = true;
                btnConfirm.textContent = `Confirm (${left})`;
                const t = setInterval(() => {
                    left -= 1;
                    if (left <= 0) {
                        clearInterval(t);
                        btnConfirm.disabled = false;
                        btnConfirm.textContent = 'Confirm Booking';
                    } else {
                        btnConfirm.textContent = `Confirm (${left})`;
                    }
                }, 1000);
            }

            function closeModal() {
                overlay.setAttribute('aria-hidden', 'true');
            }

            form.addEventListener('submit', function(e) {
                if (bypass) return; // allow actual submit after confirm

                // Basic client-side check: require date and service to show modal; otherwise let server validate
                const hasDate = !!(form.elements['appointment_date']?.value);
                const hasService = !!form.querySelector('input[name="service_id"]:checked');
                if (!hasDate || !hasService) {
                    return; // allow normal submit to get server-side errors
                }

                e.preventDefault();
                openModal();
            });

            btnCancel?.addEventListener('click', () => closeModal());
            overlay?.addEventListener('click', (ev) => {
                if (ev.target === overlay) closeModal();
            });
            btnConfirm?.addEventListener('click', () => {
                if (btnConfirm.disabled) return;
                bypass = true;
                closeModal();
                form.submit();
            });

            // Live listeners for instant summary updates
            ['change', 'input'].forEach(evt => {
                form.addEventListener(evt, (e) => {
                    if (e.target.matches('input[name="service_id"], select[name="payment_option"], textarea[name="notes"], input[name="appointment_date"]')) {
                        populateSummary();
                    }
                });
            });

            // Initial population
            populateSummary();
        })();
    </script>
    <script>
        // Make clicking service label toggle active state instantly when choosing
        document.querySelectorAll('.service-radio').forEach(r => {
            r.addEventListener('change', () => {
                document.querySelectorAll('.service-item').forEach(i => i.classList.remove('active'));
                const wrap = r.closest('.service-item');
                if (wrap) wrap.classList.add('active');
            });
        });
    </script>
    <script>
        // Lightweight toast behavior (reuses CSS in assets/css/toast.css)
        (function() {
            const cont = document.querySelector('.toast-container');
            if (!cont) return;
            cont.querySelectorAll('.toast').forEach(t => {
                const btn = t.querySelector('.toast-close');
                const dur = parseInt(t.getAttribute('data-duration') || '5000', 10);
                let timer;

                function close() {
                    t.style.display = 'none';
                }
                if (btn) btn.addEventListener('click', close);
                if (dur > 0) timer = setTimeout(close, dur);
            });
        })();
    </script>
    <?php if ($needsPhone): ?>
        <script>
            // After ~7 seconds, send the user to profile to complete phone, preserving return
            (function() {
                const to = 'profile.php?require=phone&from=<?= e(rawurlencode('customer/booking.php' . ($selectedShopId ? ('?shop=' . (int)$selectedShopId) : ''))) ?>';
                setTimeout(() => {
                    window.location.href = to;
                }, 7000);
            })();
        </script>
    <?php endif; ?>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>