<?php
// Prevent double session_start warnings
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function base_url($path = '')
{
    static $cfg;
    if (!$cfg) $cfg = require __DIR__ . '/config.php';
    return rtrim($cfg['app']['base_url'], '/') . '/' . ltrim($path, '/');
}

function redirect($path)
{
    // Accept absolute (http...) or relative
    if (preg_match('#^https?://#i', $path)) {
        header("Location: $path");
    } else {
        header("Location: " . base_url($path));
    }
    exit;
}

function is_logged_in()
{
    return isset($_SESSION['user']);
}

function current_user()
{
    return $_SESSION['user'] ?? null;
}

function require_login()
{
    if (!is_logged_in()) redirect('login.php');
}

function has_role($role)
{
    return is_logged_in() && ($_SESSION['user']['role'] ?? null) === $role;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf($token)
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function e($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
