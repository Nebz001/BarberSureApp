<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';

$user = current_user();
if ($user) {
    // Already logged in: send them to their role destination immediately.
    $dest = 'index.php';
    if ($user['role'] === 'customer') {
        $dest = 'customer/dashboard.php'; // normalized
    } elseif ($user['role'] === 'owner') {
        $dest = 'owner/dashboard.php';
    } elseif ($user['role'] === 'admin') {
        $dest = 'admin/dashboard.php';
    }
    redirect($dest);
    exit;
}

$errors = [];
$just_registered = isset($_GET['registered']);
$login_success = false;
$redirectTo = 'index.php';

// Capture optional next param
$nextParam = $_GET['next'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nextParam = $_POST['next'] ?? $nextParam;

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = "Email and password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (!$errors && !authenticate($email, $password)) {
        $errors[] = "Invalid credentials.";
    }

    if (!$errors) {
        $user = current_user();
        // Base destination by role
        $redirectTo = 'index.php';
        if ($user && isset($user['role'])) {
            if ($user['role'] === 'customer') {
                $redirectTo = 'customer/dashboard.php';
            } elseif ($user['role'] === 'owner') {
                $redirectTo = 'owner/dashboard.php';
            } elseif ($user['role'] === 'admin') {
                $redirectTo = 'admin/dashboard.php';
            }
        }

        // Validate next param (internal only). Reject absolute URLs, schemes, traversal.
        if ($nextParam) {
            $candidate = trim($nextParam);
            $isExternal   = preg_match('#^(?:https?:)?//#i', $candidate);
            $hasScheme    = preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $candidate);
            $hasTraversal = strpos($candidate, '..') !== false;
            if (!$isExternal && !$hasScheme && !$hasTraversal && $candidate !== '') {
                $redirectTo = ltrim($candidate, '/');
            }
        }

        $login_success = true;

        // Clear credential echoes for safety
        $email = '';
    }
}

$firstName = '';
if ($login_success && $user && !empty($user['full_name'])) {
    $firstName = explode(' ', trim($user['full_name']))[0];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0f1216">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
</head>

<body class="auth">
    <div class="toast-container" aria-live="polite" aria-atomic="true">
        <?php if ($just_registered && !$login_success): ?>
            <div class="toast toast-success" role="status" data-duration="5000">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Registration Complete</strong>
                    You can now log in with your credentials.
                </div>
                <button class="toast-close" aria-label="Close notification">&times;</button>
                <div class="toast-progress"></div>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="toast toast-error" role="alert">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v4m0 4h.01M12 5a7 7 0 1 1 0 14 7 7 0 0 1 0-14Z" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Login Error</strong>
                    <ul style="margin:.25rem 0 0 .9rem; padding:0; list-style:disc;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button class="toast-close" aria-label="Close notification">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($login_success): ?>
            <div class="toast toast-success" role="status" id="loginSuccessToast"
                data-redirect="<?= e($redirectTo) ?>" data-delay="1800">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Logged In Successfully</strong>
                    <?= $firstName ? "Welcome back, " . e($firstName) . "!" : "Redirecting…" ?>
                </div>
                <button class="toast-close" aria-label="Close notification" disabled>&times;</button>
                <div class="toast-progress"></div>
            </div>
        <?php endif; ?>
    </div>

    <main class="auth-card login" role="main" <?= $login_success ? 'aria-hidden="true"' : '' ?>>
        <header class="auth-header">
            <h1>Welcome Back <span>Login</span></h1>
            <p>Access your account to continue booking or managing your shop.</p>
        </header>

        <form method="post" class="auth-form" novalidate <?= $login_success ? 'style="pointer-events:none;opacity:.55;"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <?php if ($nextParam && !$login_success): ?>
                <input type="hidden" name="next" value="<?= e($nextParam) ?>">
            <?php endif; ?>

            <label>
                Email
                <input name="email" type="email" required autocomplete="email"
                    value="<?= isset($email) ? e($email) : '' ?>" <?= $login_success ? 'disabled' : '' ?>>
            </label>
            <label>
                Password
                <div class="password-wrapper">
                    <input name="password" type="password" required autocomplete="current-password" id="passwordField" <?= $login_success ? 'disabled' : '' ?>>
                    <button type="button" class="toggle-password" data-target="passwordField" <?= $login_success ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>Show</button>
                </div>
            </label>

            <div class="actions-inline">
                <div class="remember-wrapper">
                    <input type="checkbox" id="remember" disabled>
                    <label for="remember" style="margin:0; cursor:not-allowed; opacity:.55;">Remember Me (soon)</label>
                </div>
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit" class="primary-btn" <?= $login_success ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '' ?>>Login</button>

            <div class="secondary-link">
                New here? <a href="register.php">Create an account</a>
            </div>
        </form>

        <p class="footer-note">&copy; <?= date('Y') ?> BarberSure &mdash; All rights reserved.</p>
    </main>

    <script src="assets/js/auth.js"></script>
    <script>
        (function initToasts() {
            const toasts = document.querySelectorAll('.toast-container .toast:not(#loginSuccessToast)');
            toasts.forEach(t => {
                const closeBtn = t.querySelector('.toast-close');
                const duration = parseInt(t.getAttribute('data-duration') || '0', 10);
                let timer;
                if (duration > 0) {
                    timer = setTimeout(() => dismiss(t), duration);
                } else {
                    setTimeout(() => {
                        if (!document.body.contains(t)) return;
                        t.style.boxShadow = '0 0 0 2px rgba(255,90,90,0.25),0 3px 10px -2px rgba(0,0,0,.25)';
                    }, 8000);
                }
                closeBtn?.addEventListener('click', () => {
                    if (timer) clearTimeout(timer);
                    dismiss(t);
                });
            });

            function dismiss(t) {
                t.style.transition = 'opacity .35s,transform .35s';
                t.style.opacity = '0';
                t.style.transform = 'translateX(14px)';
                setTimeout(() => t.remove(), 380);
            }
        })();

        (function handleSuccessRedirect() {
            const toast = document.getElementById('loginSuccessToast');
            if (!toast) return;
            const delay = parseInt(toast.getAttribute('data-delay') || '1800', 10);
            const target = toast.getAttribute('data-redirect') || 'index.php';
            setTimeout(() => {
                window.location.href = target;
            }, delay);
        })();
    </script>
</body>

</html>