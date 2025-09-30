<?php
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';

$errors = [];
$account_created = false;
$awaiting_verify = false;
$created_user = null;

// Capture posted fields (keep old values on error)
$full_name = $_POST['full_name'] ?? '';
$email     = $_POST['email'] ?? '';
$role      = $_POST['role'] ?? 'customer';
$phone     = $_POST['phone'] ?? '';
// Owner business fields
$shop_name    = $_POST['shop_name'] ?? '';
$shop_address = $_POST['shop_address'] ?? '';
$shop_city    = $_POST['shop_city'] ?? '';
$shop_phone   = $_POST['shop_phone'] ?? '';
$services_raw = $_POST['services'] ?? '';
$open_time    = $_POST['open_time'] ?? '';
$close_time   = $_POST['close_time'] ?? '';
// Removed latitude/longitude feature (maps integration rolled back)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    }

    $full_name = trim($full_name);
    $email     = trim($email);
    $role      = normalize_role($role);
    $password  = $_POST['password'] ?? '';
    $phone     = trim($phone);
    $shop_name = trim($shop_name);
    $shop_address = trim($shop_address);
    $shop_city = trim($shop_city);
    $shop_phone = trim($shop_phone);
    $services_raw = trim($services_raw);
    $open_time = trim($open_time);
    $close_time = trim($close_time);

    if ($full_name === '' || $email === '' || $password === '' || $phone === '') {
        $errors[] = "Full name, email, phone and password are required.";
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if ($password && strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }
    if ($phone !== '') {
        // Extract digits only from full phone (+63 9xxxxxxxxx)
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^639\d{9}$/', $phoneDigits)) {
            $errors[] = "Phone number must be exactly 10 digits after +63 (starting with 9).";
        }
    }
    if ($role === 'owner') {
        if ($shop_name === '' || $shop_address === '' || $shop_city === '') {
            $errors[] = "Shop name, address and city are required for owners.";
        }
        if (($open_time && !$close_time) || (!$open_time && $close_time)) {
            $errors[] = "Provide both opening and closing time or leave both empty.";
        }
        if ($open_time && $close_time) {
            if (!preg_match('/^\d{2}:\d{2}$/', $open_time) || !preg_match('/^\d{2}:\d{2}$/', $close_time)) {
                $errors[] = "Business hours must be in HH:MM format.";
            } elseif ($open_time >= $close_time) {
                $errors[] = "Opening time must be before closing time.";
            }
        }
        // Optional shop contact number validation (same format policy as customer phone)
        if ($shop_phone !== '') {
            $shopPhoneDigits = preg_replace('/\D/', '', $shop_phone);
            if (!preg_match('/^639\d{9}$/', $shopPhoneDigits)) {
                $errors[] = "Shop contact number must be exactly 10 digits after +63 (starting with 9).";
            }
        }
    }
    if (!$errors && find_user_by_email($email)) {
        $errors[] = "Email already in use.";
    }

    if (!$errors) {
        // Generate and send verification code via SMS.
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_registration'] = [
            'full_name'   => $full_name,
            'email'       => $email,
            'password'    => $password,
            'role'        => $role,
            'phone'       => $phone,
            'shop_name'   => $shop_name ?? '',
            'shop_address' => $shop_address ?? '',
            'shop_city'   => $shop_city ?? '',
            'services'    => $services_raw ?? '',
            'open_time'   => $open_time ?? '',
            'close_time'  => $close_time ?? '',
            'code'        => $code,
            'created_at'  => time()
        ];

        // Send the SMS right after registration
        send_sms($phone, "Your BarberSure verification code is: $code");
        // Redirect to verification step
        redirect('verify_phone.php');
    }
}

$selectedRole = $role ?? 'customer';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Create Account • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0f1216">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="auth">
    <div class="toast-container" aria-live="polite" aria-atomic="true">
        <?php if ($account_created): ?>
            <div class="toast toast-success" role="status" data-duration="1800" data-auto="redirect-login">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Account Created</strong>
                    Redirecting you to login…
                </div>
                <button class="toast-close" aria-label="Close notification">&times;</button>
                <div class="toast-progress"></div>
            </div>
        <?php endif; ?>

        <?php if (!$account_created && $errors): ?>
            <div class="toast toast-error" role="alert">
                <div class="toast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v4m0 4h.01M12 5a7 7 0 1 1 0 14 7 7 0 0 1 0-14Z" />
                    </svg>
                </div>
                <div class="toast-body">
                    <strong>Registration Error</strong>
                    <ul style="margin:.25rem 0 0 .9rem; padding:0; list-style:disc;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button class="toast-close" aria-label="Close notification">&times;</button>
            </div>
        <?php endif; ?>
    </div>

    <main class="auth-card register" role="main" <?= $account_created ? 'aria-hidden="true"' : '' ?>>
        <header class="auth-header">
            <h1>Create Account <span>Register</span></h1>
            <p>Join BarberSure and start booking or managing your barbershop today.</p>
        </header>

        <form method="post" class="auth-form" novalidate <?= $account_created ? 'style="pointer-events:none;opacity:.5;"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>
                Full Name
                <input type="text" name="full_name" required autocomplete="name"
                    value="<?= e($full_name) ?>">
            </label>
            <label>
                Email
                <input type="email" name="email" required autocomplete="email" value="<?= e($email) ?>">
            </label>
            <label>
                Phone Number
                <?php
                $regLocal = '';
                if (trim((string)$phone) !== '') {
                    if (strpos($phone, '+63') === 0) {
                        $regLocal = ltrim(substr($phone, 3));
                    } else {
                        $regLocal = $phone;
                    }
                }
                ?>
                <div class="phone-group" style="display:flex;align-items:center;gap:.4rem;">
                    <span class="phone-prefix" style="background:#121820;border:1px solid #273241;color:#c9d3dd;padding:.58rem .6rem;border-radius:10px;font-weight:600;letter-spacing:.3px;">+63</span>
                    <input type="tel" id="reg_phone_local" name="phone_local" required autocomplete="tel" value="<?= e($regLocal) ?>" placeholder="9xx xxx xxxx" pattern="^9\d{9}$" inputmode="tel" maxlength="10" style="flex:1;">
                    <input type="hidden" name="phone" id="reg_phone_full" value="<?= e(trim((string)$phone) !== '' ? $phone : '+63 ') ?>">
                </div>
            </label>
            <div class="phone-hint" style="margin-top:-0.45rem;font-size:.55rem;color:#79818c;letter-spacing:.05em;">
                Required. Exactly 10 digits starting with 9 (e.g., 9171234567).
            </div>
            <label>
                Password
                <div class="password-wrapper">
                    <input type="password" name="password" required autocomplete="new-password" id="regPassword">
                    <button type="button" class="toggle-password" data-target="regPassword">Show</button>
                </div>
            </label>
            <div class="password-hint">Use at least 8 characters.</div>

            <div class="role-switch-wrapper">
                <div class="role-switch-heading">Choose Role</div>
                <div class="role-switch">
                    <input type="radio" name="role" value="customer" id="roleCustomer" <?= $selectedRole === 'customer' ? 'checked' : '' ?>>
                    <label for="roleCustomer">Customer</label>
                    <input type="radio" name="role" value="owner" id="roleOwner" <?= $selectedRole === 'owner' ? 'checked' : '' ?>>
                    <label for="roleOwner">Owner</label>
                </div>
            </div>

            <fieldset id="ownerBizFields" class="owner-extra" style="display:<?= $selectedRole === 'owner' ? 'block' : 'none' ?>;margin:.5rem 0 1rem;padding:.85rem 1rem 1rem;border:1px solid #273241;border-radius:10px;">
                <legend style="padding:0 .5rem;font-size:.8rem;font-weight:600;letter-spacing:.5px;color:#93adc7;">Owner Business Details</legend>
                <div class="two-col" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.75rem;">
                    <label>Barbershop Name
                        <input type="text" name="shop_name" value="<?= e($shop_name) ?>" <?= $selectedRole === 'owner' ? 'required' : '' ?>>
                    </label>
                    <label>City (Batangas)
                        <?php
                        $batangasCities = [
                            'Batangas City',
                            'Lipa',
                            'Tanauan',
                            'Sto. Tomas',
                            'Malvar',
                            'Balete',
                            'Agoncillo',
                            'Alitagtag',
                            'Balayan',
                            'Cuenca',
                            'Ibaan',
                            'Laurel',
                            'Lemery',
                            'Lian',
                            'Mabini',
                            'Nasugbu',
                            'Padre Garcia',
                            'Rosario',
                            'San Jose',
                            'San Juan',
                            'San Luis',
                            'San Nicolas',
                            'San Pascual',
                            'Sta. Teresita',
                            'Taal',
                            'Taysan',
                            'Tingloy',
                            'Calaca',
                            'Calatagan'
                        ];
                        $hasCity = in_array($shop_city, $batangasCities, true);
                        ?>
                        <select name="shop_city" <?= $selectedRole === 'owner' ? 'required' : '' ?>>
                            <option value="" disabled <?= $shop_city === '' ? 'selected' : '' ?>>Select City</option>
                            <?php foreach ($batangasCities as $c): ?>
                                <option value="<?= e($c) ?>" <?= $shop_city === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                            <?php endforeach; ?>
                            <?php if ($shop_city && !$hasCity): ?>
                                <option value="<?= e($shop_city) ?>" selected><?= e($shop_city) ?> (Unlisted)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label>Shop Contact Number (Optional)
                        <?php
                        $shopLocal = '';
                        if (trim((string)$shop_phone) !== '') {
                            if (strpos($shop_phone, '+63') === 0) {
                                $shopLocal = ltrim(substr($shop_phone, 3));
                            } else {
                                $shopLocal = $shop_phone; // fallback if stored w/o prefix
                            }
                        }
                        ?>
                        <div class="phone-group" style="display:flex;align-items:center;gap:.4rem;">
                            <span class="phone-prefix" style="background:#121820;border:1px solid #273241;color:#c9d3dd;padding:.58rem .6rem;border-radius:10px;font-weight:600;letter-spacing:.3px;">+63</span>
                            <input type="tel" id="shop_phone_local" name="shop_phone_local" autocomplete="tel" value="<?= e($shopLocal) ?>" placeholder="9xx xxx xxxx" pattern="^9\d{9}$" inputmode="tel" maxlength="10" style="flex:1;">
                            <input type="hidden" name="shop_phone" id="shop_phone_full" value="<?= e(trim((string)$shop_phone) !== '' ? $shop_phone : '+63 ') ?>">
                        </div>
                        <div class="shop-phone-hint" style="margin:.25rem 0 0;font-size:.52rem;color:#77808a;letter-spacing:.05em;">If provided, must be 10 digits starting with 9 (same as customer phone format).</div>
                    </label>
                    <label>Open Time
                        <input type="time" name="open_time" value="<?= e($open_time) ?>">
                    </label>
                    <label>Close Time
                        <input type="time" name="close_time" value="<?= e($close_time) ?>">
                    </label>
                </div>
                <div class="time-hint" style="margin:.1rem 0 .2rem;font-size:.52rem;color:#77808a;letter-spacing:.05em;">Both optional. If one is set, set the other. Opening must be earlier than closing.</div>
                <label class="address-field" style="margin-top:.6rem;display:flex;flex-direction:column;gap:.45rem;">Barbershop Address
                    <textarea name="shop_address" rows="3" placeholder="Street / Brgy / Landmark" <?= $selectedRole === 'owner' ? 'required' : '' ?>><?= e($shop_address) ?></textarea>
                </label>
                <div class="address-hint" style="margin:.15rem 0 .2rem;font-size:.52rem;color:#77808a;letter-spacing:.05em;">Example: Purok 2, Brgy. San Isidro, near public market</div>
                <!-- Map & coordinate inputs removed: relying on textual address and city only -->
                <label class="services-field" style="margin-top:.6rem;display:flex;flex-direction:column;gap:.45rem;">Services Offered (comma or newline separated)
                    <textarea name="services" rows="5" placeholder="Haircut, Beard Trim, Shave, ..." style="width:100%;resize:vertical;"><?= e($services_raw) ?></textarea>
                </label>
                <p style="margin:.4rem 0 0;font-size:.6rem;line-height:1.4;color:#6e859c;">Your shop will be set to <strong>pending</strong> status until reviewed.</p>
            </fieldset>

            <div class="policy">
                By creating an account you agree to our
                <a href="#" onclick="alert('Policy placeholder'); return false;">Terms</a>
                &amp;
                <a href="#" onclick="alert('Privacy placeholder'); return false;">Privacy Policy</a>.
            </div>

            <button type="submit" class="primary-btn">Create Account</button>

            <div class="secondary-link">
                Already have an account? <a href="login.php">Login</a>
            </div>
        </form>

        <p class="footer-note">&copy; <?= date('Y') ?> BarberSure &mdash; All rights reserved.</p>
    </main>

    <script src="assets/js/auth.js"></script>
    <script>
        // Sync registration phone with fixed +63 prefix
        (function syncRegPhone() {
            const local = document.getElementById('reg_phone_local');
            const full = document.getElementById('reg_phone_full');
            if (!local || !full) return;

            function update() {
                // Allow only digits, remove everything else
                let v = local.value.replace(/\D/g, '');
                // Limit to 10 digits max
                if (v.length > 10) v = v.slice(0, 10);
                local.value = v;
                full.value = '+63' + (v ? ' ' + v : ' ');
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
        (function initToasts() {
            const container = document.querySelector('.toast-container');
            if (!container) return;
            container.querySelectorAll('.toast').forEach(t => {
                const closeBtn = t.querySelector('.toast-close');
                const duration = parseInt(t.getAttribute('data-duration') || '0', 10);
                let autoTimer;
                if (duration > 0) {
                    autoTimer = setTimeout(() => dismiss(t), duration);
                }
                closeBtn?.addEventListener('click', () => {
                    if (autoTimer) clearTimeout(autoTimer);
                    dismiss(t);
                });

                if (t.dataset.auto === 'redirect-login') {
                    setTimeout(() => {
                        window.location.href = 'login.php?registered=1';
                    }, duration || 1800);
                }
            });

            function dismiss(t) {
                t.style.transition = 'opacity .35s,transform .35s';
                t.style.opacity = '0';
                t.style.transform = 'translateX(14px)';
                setTimeout(() => t.remove(), 380);
            }
        })();

        (function ownerToggle() {
            const roleRadios = document.querySelectorAll('input[name="role"]');
            const biz = document.getElementById('ownerBizFields');

            function sync() {
                const val = document.querySelector('input[name="role"]:checked')?.value;
                if (!biz) return;
                if (val === 'owner') {
                    biz.style.display = 'block';
                    biz.querySelectorAll('input,textarea').forEach(el => {
                        if (el.name === 'shop_name' || el.name === 'shop_address' || el.name === 'shop_city') {
                            el.required = true;
                        }
                    });
                } else {
                    biz.style.display = 'none';
                    biz.querySelectorAll('input,textarea').forEach(el => {
                        el.required = false;
                    });
                }
            }
            roleRadios.forEach(r => r.addEventListener('change', sync));
            sync();
        })();

        // Sync optional shop contact number with +63 prefix (mirrors customer phone logic)
        (function syncShopPhone() {
            const local = document.getElementById('shop_phone_local');
            const full = document.getElementById('shop_phone_full');
            if (!local || !full) return;

            function update() {
                let v = local.value.replace(/\D/g, '');
                if (v.length > 10) v = v.slice(0, 10);
                local.value = v;
                full.value = '+63' + (v ? ' ' + v : ' ');
            }
            local.addEventListener('input', update);
            local.addEventListener('keypress', function(e) {
                const ch = String.fromCharCode(e.which);
                if (!/[0-9]/.test(ch) && e.which !== 8 && e.which !== 0) e.preventDefault();
            });
            local.addEventListener('blur', update);
            update();
        })();

        // Maps integration removed.
        (function timeValidation() {
            const openEl = document.querySelector('input[name="open_time"]');
            const closeEl = document.querySelector('input[name="close_time"]');
            if (!openEl || !closeEl) return;
            const hint = document.querySelector('.time-hint');

            function check() {
                const o = openEl.value.trim();
                const c = closeEl.value.trim();
                let msg = '';
                if ((o && !c) || (!o && c)) msg = 'Set both opening and closing time or clear both.';
                else if (o && c && o >= c) msg = 'Opening time must be earlier than closing time.';
                if (msg) {
                    if (hint) {
                        hint.textContent = msg;
                        hint.style.color = '#f5c2ce';
                    }
                } else {
                    if (hint) {
                        hint.textContent = 'Both optional. If one is set, set the other. Opening must be earlier than closing.';
                        hint.style.color = '#77808a';
                    }
                }
            }
            openEl.addEventListener('change', check);
            closeEl.addEventListener('change', check);
        })();
    </script>
</body>

</html>