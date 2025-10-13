<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

// Refresh user data to ensure we have all columns
$refreshUserStmt = $pdo->prepare("SELECT * FROM Users WHERE user_id = ?");
$refreshUserStmt->execute([$ownerId]);
$user = $refreshUserStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

// Status messages
$statusMessage = null;
$statusError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $statusError = 'Invalid session token.';
    } else {
        $action = $_POST['action'] ?? '';

        // Update profile information
        if ($action === 'update_profile') {
            try {
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                if ($phone === '' && isset($_POST['phone_local'])) {
                    $pl = trim((string)$_POST['phone_local']);
                    $phone = $pl !== '' ? ('+63 ' . $pl) : $phone;
                }
                $username = trim($_POST['username'] ?? '');

                // Validation
                if (empty($fullName)) throw new Exception('Full name is required.');
                if (empty($email)) throw new Exception('Email is required.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format.');

                // Check if phone number follows Philippines format (if provided)
                if (!empty($phone)) {
                    // Extract digits only from full phone (+63 9xxxxxxxxx)
                    $phoneDigits = preg_replace('/\D/', '', $phone);
                    if (!preg_match('/^639\d{9}$/', $phoneDigits)) {
                        throw new Exception('Phone number must be exactly 10 digits after +63 (starting with 9).');
                    }
                }

                // Check if username is unique (if provided and different from current)
                if (!empty($username) && $username !== $user['username']) {
                    $checkUsername = $pdo->prepare("SELECT user_id FROM Users WHERE username = ? AND user_id != ?");
                    $checkUsername->execute([$username, $ownerId]);
                    if ($checkUsername->fetch()) {
                        throw new Exception('Username is already taken.');
                    }
                }

                // Check if email is unique (if different from current)
                if ($email !== $user['email']) {
                    $checkEmail = $pdo->prepare("SELECT user_id FROM Users WHERE email = ? AND user_id != ?");
                    $checkEmail->execute([$email, $ownerId]);
                    if ($checkEmail->fetch()) {
                        throw new Exception('Email is already registered to another account.');
                    }
                }

                // Update user information
                $updateStmt = $pdo->prepare("
                    UPDATE Users 
                    SET full_name = ?, email = ?, phone = ?, username = ?
                    WHERE user_id = ?
                ");
                $updateStmt->execute([
                    $fullName,
                    $email,
                    $phone ?: null,
                    $username ?: null,
                    $ownerId
                ]);

                $statusMessage = 'Profile updated successfully.';

                // Refresh user data
                $refreshStmt = $pdo->prepare("SELECT * FROM Users WHERE user_id = ?");
                $refreshStmt->execute([$ownerId]);
                $user = $refreshStmt->fetch(PDO::FETCH_ASSOC);
                // Update session snapshot too for consistency
                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['full_name'] = $user['full_name'] ?? $_SESSION['user']['full_name'];
                    $_SESSION['user']['email'] = $user['email'] ?? $_SESSION['user']['email'];
                    $_SESSION['user']['phone'] = $user['phone'] ?? $_SESSION['user']['phone'] ?? null;
                    $_SESSION['user']['username'] = $user['username'] ?? $_SESSION['user']['username'] ?? null;
                }
            } catch (Exception $e) {
                $statusError = $e->getMessage();
            }
        }

        // Change password
        elseif ($action === 'change_password') {
            try {
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                // Validation
                if (empty($currentPassword)) throw new Exception('Current password is required.');
                if (empty($newPassword)) throw new Exception('New password is required.');
                if (strlen($newPassword) < 8) throw new Exception('New password must be at least 8 characters long.');
                if ($newPassword !== $confirmPassword) throw new Exception('Password confirmation does not match.');

                // Verify current password
                if (!password_verify($currentPassword, $user['password_hash'])) {
                    throw new Exception('Current password is incorrect.');
                }

                // Update password
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updatePassword = $pdo->prepare("UPDATE Users SET password_hash = ? WHERE user_id = ?");
                $updatePassword->execute([$newPasswordHash, $ownerId]);

                $statusMessage = 'Password changed successfully.';
            } catch (Exception $e) {
                $statusError = $e->getMessage();
            }
        }

        // Toggle email notifications (if we implement this feature)
        elseif ($action === 'toggle_notifications') {
            try {
                // This would be for future notification preferences
                $statusMessage = 'Notification preferences updated.';
            } catch (Exception $e) {
                $statusError = $e->getMessage();
            }
        }
    }
}

// Get user's shops and their status
$shopsStmt = $pdo->prepare("
    SELECT 
        shop_id,
        shop_name,
        status,
        city,
        registered_at,
        (SELECT COUNT(*) FROM Services WHERE shop_id = Barbershops.shop_id) as service_count
    FROM Barbershops 
    WHERE owner_id = ?
    ORDER BY registered_at ASC
");
$shopsStmt->execute([$ownerId]);
$userShops = $shopsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Get account statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT b.shop_id) as total_shops,
        COUNT(DISTINCT CASE WHEN b.status = 'approved' THEN b.shop_id END) as approved_shops,
        COUNT(DISTINCT a.appointment_id) as total_appointments,
        COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN a.appointment_id END) as completed_appointments,
        COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount END), 0) as total_earnings
    FROM Barbershops b
    LEFT JOIN Appointments a ON b.shop_id = a.shop_id
    LEFT JOIN Payments p ON a.appointment_id = p.appointment_id
    WHERE b.owner_id = ?
");
$statsStmt->execute([$ownerId]);
$userStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_shops' => 0,
    'approved_shops' => 0,
    'total_appointments' => 0,
    'completed_appointments' => 0,
    'total_earnings' => 0
];

// Helper functions
function format_join_date($datetime)
{
    return date('M j, Y', strtotime($datetime));
}

function format_currency($amount)
{
    return '₱' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Profile • Owner • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin: .5rem 0 1.2rem;
        }

        .form-grid-full {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin: .5rem 0 1.2rem;
        }

        form label {
            display: flex;
            flex-direction: column;
            font-size: .65rem;
            font-weight: 600;
            letter-spacing: .5px;
            gap: .35rem;
            color: var(--o-text-soft);
        }

        form input,
        form textarea,
        form select {
            background: #111c27;
            border: 1px solid #253344;
            border-radius: 8px;
            padding: .55rem .65rem;
            color: #e9eef3;
            font-size: .72rem;
            font-family: inherit;
        }

        form input:focus,
        form select:focus,
        form textarea:focus {
            outline: none;
            border-color: var(--o-accent);
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1);
        }

        .toast-stack {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin: 0 0 1rem;
        }

        .toast {
            background: #102231;
            border: 1px solid #253748;
            padding: .55rem .7rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: .7rem;
            font-size: .63rem;
            box-shadow: 0 4px 18px -6px #0008;
        }

        .toast.t-error {
            border-color: #5c1f2c;
            background: #2a1218;
            color: #fda4af;
        }

        .toast.t-success {
            border-color: #1c5030;
            background: #0d2a17;
            color: #6ee7b7;
        }

        .toast .t-close {
            background: none;
            border: 0;
            color: inherit;
            font-size: .9rem;
            cursor: pointer;
            line-height: 1;
            padding: .25rem .4rem;
            border-radius: 6px;
        }

        .toast .t-close:hover {
            background: #ffffff12;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--o-surface);
            border-radius: var(--o-radius);
            border: 1px solid var(--o-border-soft);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--o-grad-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }

        .profile-info h1 {
            font-size: 1.5rem;
            margin: 0 0 .3rem;
            color: var(--o-text);
        }

        .profile-meta {
            font-size: .75rem;
            color: var(--o-text-soft);
            display: flex;
            flex-direction: column;
            gap: .2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--o-surface);
            border: 1px solid var(--o-border-soft);
            border-radius: var(--o-radius-sm);
            padding: 1rem;
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--o-accent);
            margin-bottom: .3rem;
        }

        .stat-label {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--o-text-soft);
        }

        .section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .shops-list {
            display: flex;
            flex-direction: column;
            gap: .75rem;
        }

        .shop-item {
            background: var(--o-surface);
            border: 1px solid var(--o-border-soft);
            border-radius: var(--o-radius-sm);
            padding: .8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .shop-info h4 {
            margin: 0 0 .2rem;
            font-size: .8rem;
            color: var(--o-text);
        }

        .shop-meta {
            font-size: .6rem;
            color: var(--o-text-soft);
        }

        .verification-status {
            padding: .3rem 0;
            margin: 1rem 0;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .4rem 0;
            font-size: .7rem;
        }

        .status-icon {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .status-verified {
            background: #10b981;
        }

        .status-pending {
            background: #f59e0b;
        }

        .status-failed {
            background: #ef4444;
        }

        .password-section {
            border-top: 1px solid var(--o-border-soft);
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }

        .mini-note {
            font-size: .55rem;
            color: #69839b;
            margin: .2rem 0 .4rem;
        }

        @media (max-width: 768px) {
            .section-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body class="owner-shell owner-wrapper">
    <header class="owner-header">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="ownerNav">☰</button>
        <?php $__first = $user ? e(explode(' ', trim($user['full_name']))[0]) : 'Owner'; ?>
        <div class="owner-brand">BarberSure <span style="opacity:.55;font-weight:500;">Owner</span><span class="owner-badge">Welcome <?= $__first ?></span></div>
        <nav id="ownerNav" class="owner-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="manage_shop.php">Manage Shop</a>
            <a href="bookings.php">Bookings</a>
            <a href="messages.php">Messages</a>
            <a href="payments.php">Payments</a>
            <a class="active" href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="owner-main" style="padding-top:1.25rem;">
        <div class="toast-stack" aria-live="polite" aria-atomic="true" style="position:relative;min-height:0;">
            <?php if ($statusError): ?>
                <div class="toast t-error" role="alert">
                    <div class="t-body"><?= e($statusError) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endif; ?>
            <?php if ($statusMessage): ?>
                <div class="toast t-success" role="status">
                    <div class="t-body"><?= e($statusMessage) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?= e(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div class="profile-info">
                <h1><i class="bi bi-person" aria-hidden="true"></i> <?= e($user['full_name']) ?></h1>
                <div class="profile-meta">
                    <span>📧 <?= e($user['email']) ?></span>
                    <?php if (!empty($user['phone'])): ?>
                        <span>📱 <?= e($user['phone']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($user['username'])): ?>
                        <span>👤 @<?= e($user['username']) ?></span>
                    <?php endif; ?>
                    <span>📅 Joined <?= format_join_date($user['created_at']) ?></span>
                </div>
            </div>
            <div class="verification-status">
                <div class="status-item">
                    <span class="status-icon <?= ($user['is_verified'] ?? 0) ? 'status-verified' : 'status-pending' ?>"></span>
                    <span><?= ($user['is_verified'] ?? 0) ? 'Account Verified' : 'Verification Pending' ?></span>
                </div>
                <?php if ($user['is_suspended'] ?? 0): ?>
                    <div class="status-item">
                        <span class="status-icon status-failed"></span>
                        <span>Account Suspended</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$userStats['total_shops'] ?></div>
                <div class="stat-label">Total Shops</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int)$userStats['approved_shops'] ?></div>
                <div class="stat-label">Approved Shops</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int)$userStats['total_appointments'] ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int)$userStats['completed_appointments'] ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= format_currency($userStats['total_earnings']) ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="section-grid">
            <!-- Personal Information -->
            <section class="card">
                <h2 style="margin:0 0 1rem;font-size:1rem;"><i class="bi bi-id-card" aria-hidden="true"></i> Personal Information</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                    <input type="hidden" name="action" value="update_profile" />

                    <div class="form-grid">
                        <label>Full Name *
                            <input name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="150" />
                        </label>

                        <label>Username
                            <input name="username" value="<?= e($user['username'] ?? '') ?>" maxlength="50" placeholder="Optional" />
                        </label>
                    </div>

                    <div class="form-grid">
                        <label>Email Address *
                            <input type="email" name="email" value="<?= e($user['email']) ?>" required maxlength="150" />
                        </label>

                        <label>Phone Number
                            <?php
                            $fullPhone = trim((string)($user['phone'] ?? ''));
                            $localPart = '';
                            if ($fullPhone !== '' && strpos($fullPhone, '+63') === 0) {
                                $localPart = ltrim(substr($fullPhone, 3));
                            } elseif ($fullPhone !== '') {
                                $localPart = $fullPhone; // legacy fallback if stored without +63
                            }
                            ?>
                            <div class="phone-group" style="display:flex;align-items:center;gap:.4rem;">
                                <span class="phone-prefix" style="background:#111c27;border:1px solid #253344;color:#93adc7;padding:.5rem .55rem;border-radius:8px;font-weight:600;letter-spacing:.4px;">+63</span>
                                <input type="tel" id="owner_phone_local" name="phone_local" value="<?= e($localPart) ?>" placeholder="9xx xxx xxxx" pattern="^9\d{9}$" inputmode="tel" maxlength="10" style="flex:1;" />
                                <input type="hidden" name="phone" id="owner_phone_full" value="<?= e($fullPhone !== '' ? $fullPhone : '+63 ') ?>" />
                            </div>
                        </label>
                    </div>

                    <p class="mini-note">Phone number must be exactly 10 digits after +63 (starting with 9).</p>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle" aria-hidden="true"></i>Update Profile</button>
                </form>

                <!-- Password Change Section -->
                <div class="password-section">
                    <h3 style="margin:0 0 1rem;font-size:.9rem;"><i class="bi bi-shield-lock" aria-hidden="true"></i> Change Password</h3>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                        <input type="hidden" name="action" value="change_password" />

                        <div class="form-grid-full">
                            <label>Current Password *
                                <input type="password" name="current_password" required />
                            </label>

                            <label>New Password *
                                <input type="password" name="new_password" required minlength="8" />
                            </label>

                            <label>Confirm New Password *
                                <input type="password" name="confirm_password" required minlength="8" />
                            </label>
                        </div>

                        <p class="mini-note">Password must be at least 8 characters long.</p>

                        <button type="submit" class="btn btn-primary"><i class="bi bi-key" aria-hidden="true"></i>Change Password</button>
                    </form>
                </div>
            </section>

            <!-- Shops & Activity -->
            <section class="card">
                <h2 style="margin:0 0 1rem;font-size:1rem;"><i class="bi bi-shop" aria-hidden="true"></i> Your Shops <span style="font-size:.6rem;color:#6b8299;">(<?= count($userShops) ?>)</span></h2>

                <?php if (empty($userShops)): ?>
                    <div style="text-align:center;padding:2rem;color:var(--o-text-soft);">
                        <p>No shops registered yet.</p>
                        <a href="manage_shop.php" class="btn btn-primary"><i class="bi bi-plus-circle" aria-hidden="true"></i>Register Your First Shop</a>
                    </div>
                <?php else: ?>
                    <div class="shops-list">
                        <?php foreach ($userShops as $shop): ?>
                            <div class="shop-item">
                                <div class="shop-info">
                                    <h4><?= e($shop['shop_name']) ?></h4>
                                    <div class="shop-meta">
                                        <?= e($shop['city']) ?> •
                                        <?= (int)$shop['service_count'] ?> services •
                                        Registered <?= format_join_date($shop['registered_at']) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-status-<?= $shop['status'] ?>">
                                        <?= strtoupper($shop['status']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:1rem;text-align:center;">
                        <a href="manage_shop.php" class="btn btn-outline">Manage Shops</a>
                    </div>
                <?php endif; ?>

                <!-- Account Status -->
                <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--o-border-soft);">
                    <h3 style="margin:0 0 .75rem;font-size:.9rem;">Account Status</h3>

                    <?php if (!($user['is_verified'] ?? 0)): ?>
                        <div style="background:#3b2f12;border:1px solid #92400e;color:#fcd34d;padding:.8rem;border-radius:8px;font-size:.65rem;margin:.5rem 0;">
                            <strong>⚠️ Account Not Verified</strong><br>
                            Your account is pending verification. Some features may be limited until verification is complete.
                        </div>
                    <?php else: ?>
                        <div style="background:#0d2a17;border:1px solid #1c5030;color:#6ee7b7;padding:.8rem;border-radius:8px;font-size:.65rem;margin:.5rem 0;">
                            <strong>✅ Account Verified</strong><br>
                            Your account has been successfully verified. You have full access to all features.
                        </div>
                    <?php endif; ?>

                    <?php if ($user['is_suspended'] ?? 0): ?>
                        <div style="background:#3b1212;border:1px solid #5c1f2c;color:#fda4af;padding:.8rem;border-radius:8px;font-size:.65rem;margin:.5rem 0;">
                            <strong>🚫 Account Suspended</strong><br>
                            Your account has been suspended. Please contact support for assistance.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <footer class="footer" style="margin-top:2rem;">&copy; <?= date('Y') ?> BarberSure</footer>
    </main>

    <script>
        (function initToasts() {
            const stack = document.querySelector('.toast-stack');
            if (!stack) return;
            stack.querySelectorAll('.toast').forEach(t => {
                const close = t.querySelector('.t-close');
                let timer = setTimeout(() => dismiss(t), 4000);
                close?.addEventListener('click', () => {
                    clearTimeout(timer);
                    dismiss(t);
                });
            });

            function dismiss(t) {
                if (!t) return;
                t.style.transition = 'opacity .4s,transform .4s';
                t.style.opacity = '0';
                t.style.transform = 'translateY(-6px)';
                setTimeout(() => t.remove(), 410);
            }
        })();

        // Simple form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const passwords = form.querySelectorAll('input[type="password"]');
                if (passwords.length >= 2) {
                    const newPass = form.querySelector('input[name="new_password"]');
                    const confirmPass = form.querySelector('input[name="confirm_password"]');
                    if (newPass && confirmPass && newPass.value !== confirmPass.value) {
                        e.preventDefault();
                        alert('Password confirmation does not match.');
                        return false;
                    }
                }
            });
        });
    </script>
    <script>
        // Sync owner personal phone with fixed +63 prefix
        (function syncOwnerPersonalPhone() {
            const local = document.getElementById('owner_phone_local');
            const full = document.getElementById('owner_phone_full');
            if (!local || !full) return;

            function update() {
                const v = (local.value || '').replace(/\D+/g, '');
                // Allow typing spaces/dashes visually but keep digits-only in hidden when combined
                full.value = '+63' + (v ? ' ' + v : ' ');
                // Enforce pattern gently: only digits, max 10, starting with 9
                if (/^\d{1,10}$/.test(v)) {
                    if (v.length > 0 && v[0] !== '9') {
                        // no intrusive correction; pattern attribute will block submit
                    }
                }
            }
            ['input', 'change', 'blur'].forEach(evt => local.addEventListener(evt, update));
            update();
        })();
    </script>
</body>

</html>
<script src="../assets/js/menu-toggle.js"></script>