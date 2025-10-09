<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';

$pending = $_SESSION['pending_registration'] ?? null;
if (!$pending) {
    // If no pending data, go back to register
    redirect('register.php');
}

$errors = [];
$resent = false;
$masked = function (string $phone) {
    $p = preg_replace('/\D+/', '', $phone);
    if (strlen($p) <= 4) return $phone;
    $last4 = substr($p, -4);
    return '••• •• •• ' . $last4;
};

// Handle actions: verify or resend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'verify') {
            $code = trim($_POST['code'] ?? '');
            if (!preg_match('/^\d{6}$/', $code)) {
                $errors[] = 'Enter the 6-digit code.';
            } else {
                $expected = $pending['code'] ?? '';
                // Optional: expire after 10 minutes
                $tooOld = (time() - (int)($pending['created_at'] ?? time())) > (10 * 60);
                if ($tooOld) {
                    $errors[] = 'Code expired. Please resend a new one.';
                } elseif ($code !== $expected) {
                    $errors[] = 'Incorrect code. Please try again.';
                } else {
                    // Create the account now
                    $full_name = $pending['full_name'];
                    $email     = $pending['email'];
                    $password  = $pending['password'];
                    $role      = $pending['role'];
                    $phone     = $pending['phone'];
                    $shop_name    = $pending['shop_name'] ?? '';
                    $shop_address = $pending['shop_address'] ?? '';
                    $shop_city    = $pending['shop_city'] ?? '';
                    $shop_phone   = $pending['shop_phone'] ?? '';
                    $services_raw = $pending['services'] ?? '';
                    $open_time    = $pending['open_time'] ?? '';
                    $close_time   = $pending['close_time'] ?? '';
                    $latitude     = $pending['latitude'] ?? '';
                    $longitude    = $pending['longitude'] ?? '';

                    global $pdo;
                    if ($role === 'owner') {
                        try {
                            $pdo->beginTransaction();
                            $created_user = create_user($full_name, $email, $password, $role, $phone);
                            if ($created_user === false) throw new Exception('Failed to create user.');
                            $owner_id = (int)$created_user['user_id'];
                            // Insert barbershop with optional contact, hours, and coordinates
                            $latVal = ($latitude !== '') ? (float)$latitude : null;
                            $lngVal = ($longitude !== '') ? (float)$longitude : null;
                            $sql = "INSERT INTO Barbershops (owner_id, shop_name, address, city, shop_phone, open_time, close_time, latitude, longitude, status, registered_at)
                                    VALUES (?,?,?,?,?,?,?,?,?, 'pending', NOW())";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([
                                $owner_id,
                                $shop_name,
                                $shop_address,
                                $shop_city,
                                $shop_phone !== '' ? $shop_phone : null,
                                $open_time !== '' ? $open_time : null,
                                $close_time !== '' ? $close_time : null,
                                $latVal,
                                $lngVal
                            ]);
                            $shop_id = (int)$pdo->lastInsertId();
                            if ($services_raw !== '') {
                                $services = preg_split('/[\r\n,]+/', $services_raw);
                                $ins = $pdo->prepare("INSERT INTO Services (shop_id, service_name, duration_minutes, price) VALUES (?,?,30,0.00)");
                                $count = 0;
                                foreach ($services as $svc) {
                                    $svc = trim($svc);
                                    if ($svc === '' || strlen($svc) > 100) continue;
                                    try {
                                        $ins->execute([$shop_id, $svc]);
                                        $count++;
                                    } catch (PDOException $e) {
                                    }
                                    if ($count >= 25) break;
                                }
                            }
                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = 'Owner registration failed: ' . $e->getMessage();
                        }
                    } else {
                        $created_user = create_user($full_name, $email, $password, $role, $phone);
                        if ($created_user === false) {
                            $errors[] = 'Failed to create account. Please try again.';
                        }
                    }

                    if (!$errors) {
                        unset($_SESSION['pending_registration']);
                        $_SESSION['just_registered'] = 1;
                        redirect('login.php?registered=1');
                    }
                }
            }
        } elseif ($action === 'resend') {
            // generate a new code and send
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['pending_registration']['code'] = $code;
            $_SESSION['pending_registration']['created_at'] = time();
            send_sms($pending['phone'], "Your BarberSure verification code is: $code");
            $resent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Verify Phone • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="assets/css/register.css">
</head>

<body class="auth">
    <div class="toast-container" aria-live="polite" aria-atomic="true">
        <?php if ($errors): ?>
            <div class="toast toast-error" role="alert">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v4m0 4h.01M12 5a7 7 0 1 1 0 14 7 7 0 0 1 0-14Z" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Verification Error</strong>
                    <ul style="margin:.25rem 0 0 .9rem; padding:0; list-style:disc;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button class="toast-close" aria-label="Close">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($resent): ?>
            <div class="toast toast-success" role="status" data-duration="2500">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </div>
                <div class="toast-body"><strong>Code Sent</strong> A new code was sent to your phone.</div>
                <button class="toast-close" aria-label="Close">&times;</button>
                <div class="toast-progress"></div>
            </div>
        <?php endif; ?>
    </div>

    <main class="auth-card register" role="main">
        <header class="auth-header">
            <h1>Verify Your Phone</h1>
            <p>We sent a 6-digit code to <?= e($masked($pending['phone'])) ?>. Enter it below to complete registration.</p>
        </header>

        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label class="verification-label">
                Verification Code
                <input name="code" inputmode="numeric" pattern="^\d{6}$" maxlength="6" placeholder="••••••" required autocomplete="one-time-code">
                <small class="field-hint">Enter the 6-digit code sent to your phone</small>
            </label>
            <div class="actions-inline">
                <button type="submit" name="action" value="verify" class="primary-btn">
                    <span>Verify & Create Account</span>
                </button>
                <button type="submit" name="action" value="resend" class="secondary-btn">
                    <span>Resend Code</span>
                </button>
            </div>
            <div class="secondary-link" style="margin-top:.75rem;">
                Entered the wrong number? <a href="register.php">Go back</a>
            </div>
        </form>

        <p class="footer-note">&copy; <?= date('Y') ?> BarberSure &mdash; All rights reserved.</p>
    </main>

    <script src="assets/js/auth.js"></script>
</body>

</html>