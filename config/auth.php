<?php

/**
 * Authentication & user helper functions.
 * Clean rewrite after previous file corruption (duplicate blocks / stray braces).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Ensure session started once.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Toggle to true temporarily for diagnosing login issues.
if (!defined('AUTH_DEBUG')) {
    define('AUTH_DEBUG', false);
}
if (!function_exists('_auth_dbg')) {
    function _auth_dbg(string $msg): void
    {
        if (AUTH_DEBUG) {
            error_log('[AUTH_DEBUG] ' . $msg);
        }
    }
}

// -------------------------------------------------------------------------
// Role helpers
// -------------------------------------------------------------------------
function valid_roles(): array
{
    return ['customer', 'owner', 'admin'];
}
function normalize_role(?string $role): string
{
    $role = strtolower(trim($role ?? ''));
    return in_array($role, valid_roles(), true) ? $role : 'customer';
}

// -------------------------------------------------------------------------
// User lookup helpers
// -------------------------------------------------------------------------
function find_user_by_email(string $email)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, role, is_verified, is_suspended, created_at FROM Users WHERE LOWER(email)=LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function find_user_by_id(int $user_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, role, is_verified, is_suspended, created_at FROM Users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------------------------------------------------------------------
// Create user (basic registration utility)
// -------------------------------------------------------------------------
function create_user(string $full_name, string $email, string $password, string $role = 'customer', ?string $phone = null, ?string $username = null)
{
    global $pdo;
    $role       = normalize_role($role);
    $full_name  = trim($full_name);
    $email      = strtolower(trim($email));
    $username   = $username !== null ? trim($username) : null;

    if ($full_name === '' || $email === '' || $password === '') return false;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (strlen($password) < 8) return false;
    if (find_user_by_email($email)) return false; // duplicate email

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        // New users should never start verified; set is_verified=0 by default
        $stmt = $pdo->prepare("INSERT INTO Users (full_name, username, email, phone, role, password_hash, is_verified, created_at, is_suspended) VALUES (:full,:user,:email,:phone,:role,:hash,0,NOW(),0)");
        $stmt->execute([
            ':full'  => $full_name,
            ':user'  => $username,
            ':email' => $email,
            ':phone' => $phone,
            ':role'  => $role,
            ':hash'  => $hash
        ]);
        return find_user_by_id((int)$pdo->lastInsertId());
    } catch (PDOException $e) {
        error_log('CREATE_USER failed: ' . $e->getMessage());
        return false;
    }
}

// -------------------------------------------------------------------------
// Authenticate user by email & password
// -------------------------------------------------------------------------
function authenticate(string $email, string $password): bool
{
    global $pdo;
    $rawEmail = $email;
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        _auth_dbg("Empty credentials raw='{$rawEmail}'");
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, role, password_hash, is_verified, is_suspended, created_at FROM Users WHERE LOWER(email)=? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        _auth_dbg('Query error: ' . $e->getMessage());
        return false;
    }

    if (!$row) {
        _auth_dbg("No user for email='{$email}'");
        return false;
    }
    if (empty($row['password_hash'])) {
        _auth_dbg('Empty password hash');
        return false;
    }
    $hash = $row['password_hash'];
    if (!password_verify($password, $hash)) {
        _auth_dbg('Password verify failed');
        return false;
    }
    if (!empty($row['is_suspended'])) {
        _auth_dbg('User suspended');
        return false;
    }

    // Opportunistic rehash
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        try {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE Users SET password_hash=? WHERE user_id=?');
            $upd->execute([$newHash, $row['user_id']]);
            _auth_dbg('Password hash rehashed for user ' . $row['user_id']);
        } catch (PDOException $e) {
            _auth_dbg('Rehash failed: ' . $e->getMessage());
        }
    }

    // Store minimal safe session snapshot
    $_SESSION['user'] = [
        'user_id'     => (int)$row['user_id'],
        'full_name'   => $row['full_name'],
        'username'    => $row['username'],
        'email'       => $row['email'],
        'phone'       => $row['phone'],
        'role'        => $row['role'],
        'is_verified' => isset($row['is_verified']) ? (int)$row['is_verified'] : 0,
        'created_at'  => $row['created_at']
    ];
    _auth_dbg('Login success user_id=' . (int)$row['user_id']);
    return true;
}

// -------------------------------------------------------------------------
// Session helpers
// -------------------------------------------------------------------------
if (!function_exists('current_user')) {
    function current_user()
    {
        return $_SESSION['user'] ?? null;
    }
}
function logout_user(): void
{
    unset($_SESSION['user']);
}
