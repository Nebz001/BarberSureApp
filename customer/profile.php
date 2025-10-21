<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
if (!is_logged_in() || !has_role('customer')) redirect('../login.php');

$sessionUser = current_user();
$userId = (int)($sessionUser['user_id'] ?? 0);

// Pull fresh user data
$stmt = $pdo->prepare("SELECT user_id, full_name, username, email, phone, created_at FROM Users WHERE user_id=? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $sessionUser;

$errors = [];
$success = null;

// Optional return path if redirected from booking (compute early for POST redirects)
$returnTo = null;
if (!empty($_GET['from'])) {
    $candidate = $_GET['from'];
    // allow only relative, simple characters to avoid open redirects
    if (!preg_match('#^https?://#i', $candidate) && preg_match('#^[A-Za-z0-9_\-/?.=&%]+$#', $candidate)) {
        $returnTo = $candidate;
    }
}

// Optional return path if redirected from booking
$returnTo = null;
if (!empty($_GET['from'])) {
    $candidate = $_GET['from'];
    // allow only relative, simple characters to avoid open redirects
    if (!preg_match('#^https?://#i', $candidate) && preg_match('#^[A-Za-z0-9_\-/?.=&%]+$#', $candidate)) {
        $returnTo = $candidate;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid session token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_profile') {
            $full_name = trim($_POST['full_name'] ?? '');
            $username  = trim($_POST['username'] ?? '');
            $email     = strtolower(trim($_POST['email'] ?? ''));
            $phone     = trim($_POST['phone'] ?? '');
            if ($phone === '' && isset($_POST['phone_local'])) {
                $pl = trim((string)$_POST['phone_local']);
                $phone = $pl !== '' ? ('+63 ' . $pl) : $phone;
            }

            if ($full_name === '') $errors[] = 'Full name is required.';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
            if ($username !== '') {
                if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
                    $errors[] = 'Username must be 3–20 chars (letters, numbers, underscore).';
                }
            } else {
                $username = null; // allow null
            }
            if ($phone === '') {
                $errors[] = 'Phone number is required.';
            } else {
                // Extract digits only from full phone (+63 9xxxxxxxxx)
                $phoneDigits = preg_replace('/\D/', '', $phone);
                if (!preg_match('/^639\d{9}$/', $phoneDigits)) {
                    $errors[] = 'Phone number must be exactly 10 digits after +63 (starting with 9).';
                }
            }

            // Uniqueness checks
            if (!$errors) {
                $chk = $pdo->prepare('SELECT user_id FROM Users WHERE LOWER(email)=LOWER(?) AND user_id<>? LIMIT 1');
                $chk->execute([$email, $userId]);
                if ($chk->fetch(PDO::FETCH_ASSOC)) $errors[] = 'That email is already in use.';
            }
            if (!$errors && $username !== null) {
                $chk = $pdo->prepare('SELECT user_id FROM Users WHERE LOWER(username)=LOWER(?) AND user_id<>? LIMIT 1');
                $chk->execute([$username, $userId]);
                if ($chk->fetch(PDO::FETCH_ASSOC)) $errors[] = 'That username is taken.';
            }

            if (!$errors) {
                $upd = $pdo->prepare('UPDATE Users SET full_name=:full, username=:user, email=:email, phone=:phone WHERE user_id=:id');
                $ok = $upd->execute([
                    ':full' => $full_name,
                    ':user' => $username,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':id'   => $userId
                ]);
                if ($ok) {
                    // refresh session snapshot
                    $_SESSION['user']['full_name'] = $full_name;
                    $_SESSION['user']['username']  = $username;
                    $_SESSION['user']['email']     = $email;
                    $_SESSION['user']['phone']     = $phone;
                    $success = 'Profile updated successfully.';
                    // refresh $user for view
                    $user['full_name'] = $full_name;
                    $user['username']  = $username;
                    $user['email']     = $email;
                    $user['phone']     = $phone;
                    // If this update came from a booking requirement and phone is present, go back
                    if (!empty($_GET['require']) && $_GET['require'] === 'phone' && $returnTo && !empty($phone)) {
                        redirect($returnTo);
                    }
                } else {
                    $errors[] = 'Failed to update profile.';
                }
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $newpass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $newpass === '' || $confirm === '') {
                $errors[] = 'Please fill in all password fields.';
            } elseif (strlen($newpass) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($newpass !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $st = $pdo->prepare('SELECT password_hash FROM Users WHERE user_id=?');
                $st->execute([$userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row || empty($row['password_hash']) || !password_verify($current, $row['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $hash = password_hash($newpass, PASSWORD_DEFAULT);
                    $up = $pdo->prepare('UPDATE Users SET password_hash=? WHERE user_id=?');
                    if ($up->execute([$hash, $userId])) {
                        $success = 'Password changed successfully.';
                    } else {
                        $errors[] = 'Failed to change password.';
                    }
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
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Profile • BarberSure</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/customer.css" />
    <link rel="stylesheet" href="../assets/css/toast.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .9rem;
        }

        @media (max-width: 720px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: .3rem;
        }

        .field label {
            font-size: .7rem;
            letter-spacing: .4px;
            color: var(--c-text-soft);
        }

        .field input {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            border-radius: var(--radius-sm);
            padding: .7rem .75rem;
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 600;
            margin: 0 0 .8rem;
        }

        .hr {
            border-top: 1px solid var(--c-border-soft);
            margin: 1.1rem 0;
        }

        /* Phone with fixed +63 prefix */
        .phone-group {
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .phone-prefix {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text-soft);
            padding: .7rem .7rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            letter-spacing: .3px;
        }

        .phone-group input[type="text"],
        .phone-group input[type="tel"] {
            flex: 1;
        }
    </style>
</head>

<body class="dashboard-wrapper">
    <header class="header-bar">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg" />
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
        <div class="header-brand">
            <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
            <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim(($user['full_name'] ?? $sessionUser['full_name']) ?? ''))[0]) : '' ?></span>
        </div>
        <nav id="customerNav" class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="search.php">Find Shops</a>
            <a href="bookings_history.php">History</a>
            <a class="active" href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="dashboard-main">
        <section class="card" style="padding:1.25rem 1.3rem 1.4rem;">
            <div class="card-header" style="margin-bottom:.8rem;display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;">
                <h1 style="font-size:1.3rem;margin:0;"><i class="bi bi-person-circle" aria-hidden="true"></i> <span>Your Profile</span></h1>
                <div class="muted" style="font-size:.7rem;">Member since <?= e(date('M Y', strtotime($user['created_at'] ?? $sessionUser['created_at'] ?? 'now'))) ?></div>
            </div>

            <?php if ((!empty($_GET['require']) && $_GET['require'] === 'phone') || $errors || $success): ?>
                <div class="toast-container" aria-live="polite" aria-atomic="true" style="margin-bottom:.8rem;">
                    <?php if (!empty($_GET['require']) && $_GET['require'] === 'phone'): ?>
                        <div class="toast toast-error" role="alert" data-duration="9000">
                            <div class="toast-icon" aria-hidden="true">⚠️</div>
                            <div class="toast-body">
                                Please add your phone number to continue booking.
                                <?php if ($returnTo): ?>
                                    <div style="margin-top:.45rem;"><a class="btn" href="<?= e($returnTo) ?>" style="font-size:.65rem;">Continue to Booking</a></div>
                                <?php endif; ?>
                            </div>
                            <button class="toast-close" aria-label="Close notification">&times;</button>
                            <div class="toast-progress"></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="toast" data-duration="6000" role="status">
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

            <h2 class="section-title"><i class="bi bi-card-text" aria-hidden="true"></i> <span>Profile Information</span></h2>
            <form method="post" class="grid-2" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <input type="hidden" name="action" value="update_profile" />
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" type="text" required value="<?= e($user['full_name'] ?? '') ?>" />
                </div>
                <div class="field">
                    <label for="username">Username <span class="muted">(optional)</span></label>
                    <input id="username" name="username" type="text" placeholder="yourname" value="<?= e($user['username'] ?? '') ?>" />
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required value="<?= e($user['email'] ?? '') ?>" />
                </div>
                <div class="field">
                    <label for="phone_local">Phone</label>
                    <?php
                    $fullPhone = trim((string)($user['phone'] ?? ''));
                    $localPart = '';
                    if ($fullPhone !== '' && strpos($fullPhone, '+63') === 0) {
                        $localPart = ltrim(substr($fullPhone, 3));
                    } elseif ($fullPhone !== '') {
                        $localPart = $fullPhone; // fallback if stored without +63
                    }
                    ?>
                    <div class="phone-group">
                        <span class="phone-prefix" aria-hidden="true">+63</span>
                        <input id="phone_local" name="phone_local" type="text" inputmode="tel" required placeholder="9171234567" pattern="^9\d{9}$" maxlength="10" value="<?= e($localPart) ?>" />
                        <input type="hidden" name="phone" id="phone_full" value="<?= e($fullPhone !== '' ? $fullPhone : '+63 ') ?>" />
                    </div>
                </div>
                <div style="grid-column:1/-1; display:flex; gap:.6rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" style="font-size:.72rem;"><i class="bi bi-check2-circle" aria-hidden="true"></i> <span>Save Changes</span></button>
                    <a class="btn" href="profile.php" style="font-size:.72rem;"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> <span>Reset</span></a>
                    <?php if ($returnTo): ?>
                        <a class="btn" href="<?= e($returnTo) ?>" style="font-size:.72rem;">Continue to Booking</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="hr"></div>
            <h2 class="section-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> <span>Change Password</span></h2>
            <form method="post" class="grid-2" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <input type="hidden" name="action" value="change_password" />
                <div class="field">
                    <label for="current_password">Current Password</label>
                    <input id="current_password" name="current_password" type="password" required />
                </div>
                <div class="field">
                    <label for="new_password">New Password</label>
                    <input id="new_password" name="new_password" type="password" required minlength="8" />
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" name="confirm_password" type="password" required minlength="8" />
                </div>
                <div style="grid-column:1/-1; display:flex; gap:.6rem;">
                    <button type="submit" class="btn btn-primary" style="font-size:.72rem;"><i class="bi bi-key" aria-hidden="true"></i> <span>Change Password</span></button>
                </div>
            </form>
        </section>
        <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • View Your Profile.</footer>
    </main>
    <script>
        // Keep hidden full phone in sync with local part; prefix is fixed '+63'
        (function syncPhoneProfile() {
            const local = document.getElementById('phone_local');
            const full = document.getElementById('phone_full');
            if (!local || !full) return;

            function update() {
                // Allow only digits, remove everything else
                let lp = local.value.replace(/\D/g, '');
                // Limit to 10 digits max
                if (lp.length > 10) lp = lp.slice(0, 10);
                local.value = lp;
                full.value = '+63' + (lp ? ' ' + lp : ' ');
            }

            local.addEventListener('input', update);
            local.addEventListener('keypress', function(e) {
                // Allow only digits (0-9), backspace, delete, arrow keys
                const char = String.fromCharCode(e.which);
                if (!/[0-9]/.test(char) && e.which !== 8 && e.which !== 0) {
                    e.preventDefault();
                }
            });
            local.addEventListener('blur', update);
            update();
        })();
    </script>
    <script>
        // Lightweight toast behavior (shared pattern with booking page)
        (function() {
            const cont = document.querySelector('.toast-container');
            if (!cont) return;
            cont.querySelectorAll('.toast').forEach(t => {
                const btn = t.querySelector('.toast-close');
                const dur = parseInt(t.getAttribute('data-duration') || '5000', 10);

                function close() {
                    t.style.display = 'none';
                }
                btn && btn.addEventListener('click', close);
                if (dur > 0) setTimeout(close, dur);
            });
        })();
    </script>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>