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

// Track last authentication error for UI messaging
if (!isset($GLOBALS['AUTH_LAST_ERROR'])) {
    $GLOBALS['AUTH_LAST_ERROR'] = null; // values: not_found|empty_hash|bad_password|suspended|error
}
function auth_last_error(): ?string
{
    return $GLOBALS['AUTH_LAST_ERROR'] ?? null;
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
    $stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, role, is_verified, is_suspended, created_at FROM Users WHERE TRIM(LOWER(email)) = ? LIMIT 1");
    $stmt->execute([strtolower(trim($email))]);
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
        $GLOBALS['AUTH_LAST_ERROR'] = 'error';
        return false;
    }

    // Optional: detect duplicate emails after normalization for diagnostics
    try {
        $dupStmt = $pdo->prepare("SELECT user_id FROM Users WHERE TRIM(LOWER(email)) = ?");
        $dupStmt->execute([$email]);
        $dupRows = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($dupRows) && count($dupRows) > 1) {
            $root = dirname(__DIR__);
            @file_put_contents($root . '/logs/auth_failures.log', date('c') . ' warning duplicate_email email=' . $email . ' user_ids=' . implode(',', array_map('intval', $dupRows)) . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
        // ignore dup check issues
    }

    try {
        $stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, role, password_hash, is_verified, is_suspended, created_at FROM Users WHERE TRIM(LOWER(email)) = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        _auth_dbg('Query error: ' . $e->getMessage());
        $GLOBALS['AUTH_LAST_ERROR'] = 'error';
        return false;
    }

    if (!$row) {
        _auth_dbg("No user for email='{$email}'");
        $GLOBALS['AUTH_LAST_ERROR'] = 'not_found';
        // Log not_found for diagnostics
        try {
            $root = dirname(__DIR__);
            @file_put_contents($root . '/logs/auth_failures.log', date('c') . " login_fail email={$email} reason=not_found\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* ignore */
        }
        return false;
    }
    if (empty($row['password_hash'])) {
        _auth_dbg('Empty password hash');
        $GLOBALS['AUTH_LAST_ERROR'] = 'empty_hash';
        // Log empty_hash for diagnostics
        try {
            $root = dirname(__DIR__);
            @file_put_contents($root . '/logs/auth_failures.log', date('c') . " login_fail email={$email} reason=empty_hash user_id=" . (int)$row['user_id'] . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* ignore */
        }
        return false;
    }
    $hash = $row['password_hash'];
    if (!password_verify($password, $hash)) {
        _auth_dbg('Password verify failed');
        $GLOBALS['AUTH_LAST_ERROR'] = 'bad_password';
        // Safe failure log (no plaintext password)
        try {
            $root = dirname(__DIR__);
            @file_put_contents($root . '/logs/auth_failures.log', date('c') . " login_fail email={$email} reason=bad_password user_id=" . (int)$row['user_id'] . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* ignore */
        }
        return false;
    }
    if (!empty($row['is_suspended'])) {
        _auth_dbg('User suspended');
        $GLOBALS['AUTH_LAST_ERROR'] = 'suspended';
        try {
            $root = dirname(__DIR__);
            @file_put_contents($root . '/logs/auth_failures.log', date('c') . " login_fail email={$email} reason=suspended user_id=" . (int)$row['user_id'] . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) { /* ignore */
        }
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

    // Normalize and persist email (trim + lowercase) to avoid future mismatches
    $normalizedEmail = strtolower(trim($row['email']));
    if ($normalizedEmail !== $row['email']) {
        try {
            $updE = $pdo->prepare('UPDATE Users SET email=? WHERE user_id=?');
            $updE->execute([$normalizedEmail, $row['user_id']]);
            $row['email'] = $normalizedEmail;
            _auth_dbg('Normalized email for user ' . $row['user_id']);
        } catch (PDOException $e) {
            _auth_dbg('Email normalize failed: ' . $e->getMessage());
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
    $GLOBALS['AUTH_LAST_ERROR'] = null;
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
