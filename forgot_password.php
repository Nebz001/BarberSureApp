<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mailer.php';

$errors = [];
$sent = false;
$rateLimited = false;

function recent_reset_count($email)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Password_Resets pr JOIN Users u ON pr.user_id=u.user_id WHERE TRIM(LOWER(u.email)) = ? AND pr.requested_at > (NOW() - INTERVAL 15 MINUTE)");
    $stmt->execute([strtolower(trim($email))]);
    return (int)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid security token. Refresh and try again.';
    }
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!$errors) {
        // Silent success if user not found (avoid enumeration)
        $userStmt = $pdo->prepare('SELECT user_id, email FROM Users WHERE TRIM(LOWER(email)) = ? LIMIT 1');
        $userStmt->execute([strtolower(trim($email))]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            if (recent_reset_count($email) >= 3) {
                $rateLimited = true; // still show generic message
            } else {
                $selector = bin2hex(random_bytes(8));
                $verifier = bin2hex(random_bytes(16));
                $verifierHash = hash('sha256', $verifier);
                $expiresAt = (new DateTime('+45 minutes'))->format('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ins = $pdo->prepare('INSERT INTO Password_Resets (user_id, selector, verifier_hash, expires_at, request_ip) VALUES (?,?,?,?,?)');
                $ins->execute([$user['user_id'], $selector, $verifierHash, $expiresAt, $ip]);
                $resetUrl = base_url('reset_password.php?token=' . urlencode($selector . ':' . $verifier));
                $subject = 'Password Reset Request';
                $htmlBody = '<p>Hello,</p><p>You (or someone using your email) requested a password reset for your BarberSure account.</p>' .
                    '<p>Click the button or copy the link below (valid 45 minutes):</p>' .
                    '<p><a href="' . e($resetUrl) . '" style="background:#2563eb;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">Reset Password</a></p>' .
                    '<p style="font-size:12px;opacity:.7;">If you did not request this, you can ignore this email. For security the link can only be used once.</p>';
                $plainBody = "Visit this link to reset your password (valid 45 minutes):\n$resetUrl\nIf you did not request this, ignore this email.";
                $mailResult = send_app_email($user['email'], $subject, $htmlBody, $plainBody);
                // Build attempts string
                $attemptsStr = '';
                if (!empty($mailResult['attempts']) && is_array($mailResult['attempts'])) {
                    $parts = [];
                    foreach ($mailResult['attempts'] as $a) {
                        $parts[] = ($a['driver'] ?? '?') . ':' . (($a['sent'] ?? false) ? 'ok' : 'fail');
                    }
                    $attemptsStr = implode(',', $parts);
                }
                $logLine = sprintf(
                    "[%s] PASSWORD RESET: user=%d email=%s link=%s final_driver=%s sent=%s attempts=%s final_error=%s\n",
                    date('c'),
                    $user['user_id'],
                    $user['email'],
                    $resetUrl,
                    $mailResult['driver'] ?? 'n/a',
                    $mailResult['sent'] ? 'yes' : 'no',
                    $attemptsStr,
                    $mailResult['error'] ?? ''
                );
                file_put_contents(__DIR__ . '/logs/reset_emails.log', $logLine, FILE_APPEND);
            }
        }
        $sent = true; // always claim success
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Forgot Password • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="assets/css/login.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        /* Page-specific minor adjustments reusing auth-card */
        .auth-card.forgot {
            max-width: 480px;
        }

        .auth-card.forgot h1 {
            margin: 0 0 .7rem;
            font-size: 1.45rem;
        }

        .auth-card.forgot p.lead {
            margin: .15rem 0 1.15rem;
            font-size: .72rem;
            line-height: 1.55;
            color: var(--auth-text-muted);
        }

        .auth-card.forgot .primary-btn {
            width: 100%;
            margin-top: .4rem;
        }

        .auth-card.forgot .rate-msg {
            font-size: .6rem;
            margin-top: .85rem;
            color: #f59e0b;
            text-align: center;
        }

        .auth-card.forgot .back-link {
            display: block;
            margin-top: 1.2rem;
            font-size: .62rem;
            text-align: center;
            color: var(--login-accent);
        }

        .auth-card.forgot .back-link:hover {
            text-decoration: underline;
        }

        .auth-card.forgot .notice,
        .auth-card.forgot .error-list {
            font-size: .62rem;
        }

        .auth-card.forgot .error-list {
            list-style: disc;
            margin: .2rem 0 1rem 1.1rem;
            padding: 0;
        }
    </style>
</head>

<body class="auth">
    <main class="auth-card forgot" role="main">
        <header class="auth-header">
            <h1>Forgot Password</h1>
            <p class="lead">Enter your account email. If it exists, we'll send a reset link (or log it for development). The link will expire in 45 minutes.</p>
        </header>
        <?php if ($errors): ?>
            <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($sent && !$errors): ?>
            <div class="notice" role="status">If that email is registered, a reset link has been issued.
                <?php if ($rateLimited): ?>You have reached the request limit. Try again later.<?php else: ?>
                If you do not see it within a minute:
                <ul style="margin:.4rem 0 0 1rem; padding:0; list-style:disc;">
                    <li>Check spam/junk folder</li>
                    <li>Ensure you entered the correct email</li>
                    <li>If mail driver is set to "log", ask admin to enable SMTP</li>
                </ul>
            <?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
            <label>Email
                <input id="email" name="email" type="email" required autofocus value="<?= isset($email) ? e($email) : '' ?>" autocomplete="email" />
            </label>
            <button type="submit" class="primary-btn">Send Reset Link</button>
        </form>
        <div class="rate-msg">Limit: 3 reset requests per 15 minutes.</div>
        <a class="back-link" href="login.php">&larr; Back to login</a>
    </main>
</body>

</html>