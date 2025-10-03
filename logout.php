<?php
// Secure logout (POST + CSRF)
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';

if (!is_logged_in()) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Disallow GET logout to prevent CSRF via image/request
    header('HTTP/1.1 405 Method Not Allowed');
    echo '<!DOCTYPE html><html><body style="font-family:Arial;background:#111;color:#eee;padding:40px;">'
        . '<h2>Method Not Allowed</h2><p>Use the logout button inside the application.</p>'
        . '</body></html>';
    exit;
}

$token = $_POST['csrf'] ?? '';
if (!verify_csrf($token)) {
    header('HTTP/1.1 400 Bad Request');
    echo '<!DOCTYPE html><html><body style="font-family:Arial;background:#111;color:#eee;padding:40px;">'
        . '<h2>Invalid Request</h2><p>CSRF token mismatch. Please go back and try again.</p>'
        . '</body></html>';
    exit;
}

// Perform logout
logout_user();
// Fully destroy session cookie for cleanliness
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
// Redirect to public landing page after logout
redirect('index.php?logged_out=1');
