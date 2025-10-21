<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
if (!has_role('owner')) redirect('../login.php');
global $pdo;

$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

$errors = [];
$notice = null;

// Helper: sanitize POST
function in_post($k, $d = '')
{
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_shop') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid session token. Please refresh and try again.';
  } else {
    $shop_name = in_post('shop_name');
    $address   = in_post('address');
    $city      = in_post('city');
    $latRaw    = in_post('latitude');
    $lngRaw    = in_post('longitude');
    $lat = ($latRaw !== '') ? (float)$latRaw : null;
    $lng = ($lngRaw !== '') ? (float)$lngRaw : null;

    if ($shop_name === '' || $address === '' || $city === '') {
      $errors[] = 'Shop name, address, and city are required.';
    }
    if ($lat !== null && ($lat < -90 || $lat > 90)) $errors[] = 'Latitude out of range.';
    if ($lng !== null && ($lng < -180 || $lng > 180)) $errors[] = 'Longitude out of range.';
    if ($lat !== null) $lat = round($lat, 6);
    if ($lng !== null) $lng = round($lng, 6);

    if (!$errors) {
      try {
        if ($lat !== null && $lng !== null) {
          $ins = $pdo->prepare("INSERT INTO Barbershops (owner_id, shop_name, address, city, latitude, longitude, status, registered_at) VALUES (?,?,?,?,?,?, 'pending', NOW())");
          $ins->execute([$ownerId, $shop_name, $address, $city, $lat, $lng]);
        } else {
          $ins = $pdo->prepare("INSERT INTO Barbershops (owner_id, shop_name, address, city, status, registered_at) VALUES (?,?,?,?, 'pending', NOW())");
          $ins->execute([$ownerId, $shop_name, $address, $city]);
        }
        // Redirect to manage shops after successful creation
        header('Location: manage_shop.php');
        exit;
      } catch (Throwable $e) {
        $errors[] = 'Failed to create shop. Please try again.';
      }
    }
  }
}

// Simple list of Batangas cities (same as used in public register form)
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

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Register Shop • Owner • BarberSure</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/owner.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    .card {
      background: linear-gradient(135deg, #0b1624, #0a1120);
      border: 1px solid #1d3557;
      border-radius: 14px;
    }

    .form-row {
      display: grid;
      gap: .75rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    .small-note {
      font-size: .65rem;
      color: #93c5fd;
      margin-top: .25rem;
    }

    .alert {
      background: #7f1d1d;
      color: #fecaca;
      border: 1px solid #991b1b;
      padding: .6rem .8rem;
      border-radius: 8px;
      font-size: .7rem;
    }
  </style>
  <script>
    function confirmSubmit(e) {
      if (!confirm('Create this shop now?')) {
        e.preventDefault();
        return false;
      }
      return true;
    }
  </script>
  <meta name="robots" content="noindex" />
  <meta name="theme-color" content="#0b1624" />
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
      <a href="profile.php">Profile</a>
      <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
        <button type="submit" class="logout-btn">Logout</button>
      </form>
    </nav>
  </header>
  <main class="owner-main">
    <section class="card" style="padding:1.1rem 1.2rem 1.3rem;margin-bottom:1.2rem;">
      <h1 style="margin:0;font-weight:600;letter-spacing:.4px;font-size:1.3rem;">Register a New Shop</h1>
      <p style="font-size:.75rem;color:var(--o-text-soft);margin:.5rem 0 0;max-width:760px;">Provide your shop’s basic details. Your shop will be set to <strong>pending</strong> status until reviewed. After verification and subscription activation, it will be visible to customers.</p>
    </section>

    <?php if ($errors): ?>
      <div class="alert" role="alert">
        <strong>Could not create shop:</strong>
        <ul style="margin:.35rem 0 0 .9rem;">
          <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="card" style="padding:1rem 1.1rem 1.2rem;">
      <form method="post" onsubmit="return confirmSubmit(event)" style="display:flex;flex-direction:column;gap:.9rem;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
        <input type="hidden" name="action" value="register_shop" />
        <div class="form-row">
          <label style="display:flex;flex-direction:column;gap:.35rem;">
            <span class="label" style="font-size:.7rem;color:#cfe3ff;font-weight:600;">Shop Name</span>
            <input type="text" name="shop_name" required placeholder="e.g., Fade Masters Barbershop" />
          </label>
          <label style="display:flex;flex-direction:column;gap:.35rem;">
            <span class="label" style="font-size:.7rem;color:#cfe3ff;font-weight:600;">City (Batangas)</span>
            <select name="city" required>
              <option value="" disabled selected>Select City</option>
              <?php foreach ($batangasCities as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="small-note">Choose the city where your shop operates.</span>
          </label>
        </div>
        <label style="display:flex;flex-direction:column;gap:.35rem;">
          <span class="label" style="font-size:.7rem;color:#cfe3ff;font-weight:600;">Address</span>
          <textarea name="address" rows="3" required placeholder="Street / Brgy / Landmark"></textarea>
        </label>
        <div class="form-row">
          <label style="display:flex;flex-direction:column;gap:.35rem;">
            <span class="label" style="font-size:.7rem;color:#cfe3ff;font-weight:600;">Latitude (optional)</span>
            <input type="text" name="latitude" inputmode="decimal" placeholder="e.g., 13.7563" />
          </label>
          <label style="display:flex;flex-direction:column;gap:.35rem;">
            <span class="label" style="font-size:.7rem;color:#cfe3ff;font-weight:600;">Longitude (optional)</span>
            <input type="text" name="longitude" inputmode="decimal" placeholder="e.g., 121.0583" />
          </label>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
          <button type="submit" class="btn-accent"><i class="bi bi-plus-circle" aria-hidden="true"></i> Create Shop</button>
          <a href="manage_shop.php" class="btn-outline">Manage Shops</a>
          <a href="dashboard.php" class="btn-outline">Back to Dashboard</a>
        </div>
      </form>
    </section>

    <footer class="footer" style="margin-top:2rem;">&copy; <?= date('Y') ?> BarberSure • Empowering barbershop owners.</footer>
  </main>
  <script src="../assets/js/menu-toggle.js"></script>
  <script>
    // Focus first input on load for faster entry
    window.addEventListener('DOMContentLoaded', () => {
      const first = document.querySelector('input[name="shop_name"]');
      if (first) first.focus();
    });
  </script>
</body>

</html>