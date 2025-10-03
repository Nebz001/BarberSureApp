<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

$errors = [];
$done = false;
$tokenParam = $_GET['token'] ?? '';
$selector = '';
$verifier = '';
$validRecord = null;

if ($tokenParam) {
    if (strpos($tokenParam, ':') !== false) {
        [$selector, $verifier] = explode(':', $tokenParam, 2);
        $selector = preg_replace('/[^a-f0-9]/i', '', $selector);
        $verifier = preg_replace('/[^a-f0-9]/i', '', $verifier);
    }
}

if ($selector && $verifier) {
    $stmt = $pdo->prepare('SELECT pr.*, u.email FROM Password_Resets pr JOIN Users u ON pr.user_id=u.user_id WHERE pr.selector=? LIMIT 1');
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['used_at'] === null && $row['expires_at'] > date('Y-m-d H:i:s')) {
            $hash = $row['verifier_hash'];
            if (hash_equals($hash, hash('sha256', $verifier))) {
                $validRecord = $row;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid security token.';
    }
    $selector = preg_replace('/[^a-f0-9]/i', '', $_POST['selector'] ?? '');
    $verifier = preg_replace('/[^a-f0-9]/i', '', $_POST['verifier'] ?? '');
    $newPass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!$selector || !$verifier) {
        $errors[] = 'Token missing or malformed.';
    }
    if (strlen($newPass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($newPass !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT pr.*, u.email FROM Password_Resets pr JOIN Users u ON pr.user_id=u.user_id WHERE pr.selector=? LIMIT 1');
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $errors[] = 'Invalid token.';
        } else {
            if ($row['used_at'] !== null || $row['expires_at'] <= date('Y-m-d H:i:s')) {
                $errors[] = 'Token expired or already used.';
            } elseif (!hash_equals($row['verifier_hash'], hash('sha256', $verifier))) {
                $errors[] = 'Invalid token data.';
            } else {
                // Update password
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare('UPDATE Users SET password_hash=? WHERE user_id=?');
                    $upd->execute([$hash, $row['user_id']]);
                    $mark = $pdo->prepare('UPDATE Password_Resets SET used_at=NOW() WHERE reset_id=?');
                    $mark->execute([$row['reset_id']]);
                    $pdo->commit();
                    $done = true;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Failed to update password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Reset Password • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="assets/css/login.css" />
    <style>
        .auth-card.reset {
            max-width: 480px;
        }

        .auth-card.reset h1 {
            margin: 0 0 .7rem;
            font-size: 1.45rem;
        }

        .auth-card.reset p.lead {
            margin: .15rem 0 1.15rem;
            font-size: .72rem;
            line-height: 1.55;
            color: var(--auth-text-muted);
        }

        .auth-card.reset form.auth-form label {
            display: block;
            font-size: .65rem;
            font-weight: 500;
            letter-spacing: .45px;
        }

        .auth-card.reset .primary-btn {
            width: 100%;
            margin-top: .9rem;
        }

        .auth-card.reset .notice,
        .auth-card.reset .error-list {
            font-size: .62rem;
        }

        .auth-card.reset .error-list {
            list-style: disc;
            margin: .2rem 0 1rem 1.1rem;
            padding: 0;
        }

        .auth-card.reset .back-link {
            display: block;
            margin-top: 1.2rem;
            text-align: center;
            font-size: .62rem;
            color: var(--login-accent);
        }

        .auth-card.reset .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body class="auth">
    <main class="auth-card reset" role="main">
        <header class="auth-header">
            <h1>Reset Password</h1>
            <?php if (!$done): ?><p class="lead">Choose a new password for your account. It must be at least 8 characters. This link can only be used once.</p><?php endif; ?>
        </header>
        <?php if ($done): ?>
            <div class="notice" role="status">Your password has been reset successfully. You can now <a href="login.php" style="color:var(--login-accent);">log in</a>.</div>
        <?php else: ?>
            <?php if ($errors): ?>
                <ul class="error-list"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (!$validRecord && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <div class="notice">Invalid or expired reset link. Request a new one from the <a href="forgot_password.php" style="color:var(--login-accent);">forgot password</a> page.</div>
            <?php endif; ?>
            <?php if ($validRecord || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <form method="post" class="auth-form" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                    <input type="hidden" name="selector" value="<?= e($selector) ?>" />
                    <input type="hidden" name="verifier" value="<?= e($verifier) ?>" />
                    <label>New Password
                        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" />
                    </label>
                    <label style="margin-top:.8rem;">Confirm Password
                        <input id="confirm" name="confirm" type="password" required minlength="8" autocomplete="new-password" />
                    </label>
                    <button type="submit" class="primary-btn">Set New Password</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <a class="back-link" href="login.php">&larr; Back to login</a>
    </main>
</body>

</html>