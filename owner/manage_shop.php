<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)$user['user_id'];

// CSRF helper inline
function ensure_csrf()
{
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        throw new Exception('Invalid CSRF token.');
    }
}

$errors = [];
$notices = [];

// Fetch all shops for this owner (multi-shop management)
$stmtAll = $pdo->prepare("SELECT * FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC");
$stmtAll->execute([$ownerId]);
$shops = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Determine selected shop via ?shop=ID (fallback to first if invalid)
$selectedShopId = isset($_GET['shop']) ? (int)$_GET['shop'] : 0;
$shop = null;
if ($shops) {
    if ($selectedShopId) {
        foreach ($shops as $s) {
            if ((int)$s['shop_id'] === $selectedShopId) {
                $shop = $s;
                break;
            }
        }
    }
    if (!$shop) { // fallback
        $shop = $shops[0];
        $selectedShopId = (int)$shop['shop_id'];
    }
}

// Handle create shop if none exists yet
if (!$shop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_shop') {
    try {
        ensure_csrf();
        $shop_name = trim($_POST['shop_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        // Optional coordinates
        $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
        $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) throw new Exception('Latitude out of range.');
        if ($lng !== null && ($lng < -180 || $lng > 180)) throw new Exception('Longitude out of range.');
        if ($lat !== null) $lat = round($lat, 6);
        if ($lng !== null) $lng = round($lng, 6);
        if ($shop_name === '' || $address === '' || $city === '') throw new Exception('All fields required.');
        // Insert with optional coordinates if provided
        if ($lat !== null && $lng !== null) {
            $ins = $pdo->prepare("INSERT INTO Barbershops (owner_id, shop_name, address, city, latitude, longitude, status, registered_at) VALUES (?,?,?,?,?,?, 'pending', NOW())");
            $ins->execute([$ownerId, $shop_name, $address, $city, $lat, $lng]);
        } else {
            $ins = $pdo->prepare("INSERT INTO Barbershops (owner_id, shop_name, address, city, status, registered_at) VALUES (?,?,?,?, 'pending', NOW())");
            $ins->execute([$ownerId, $shop_name, $address, $city]);
        }
        $notices[] = 'Shop created successfully (pending approval).';
        // Refresh shop lists after creation
        $stmtAll->execute([$ownerId]);
        $shops = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($shops) {
            $shop = $shops[0];
            $selectedShopId = (int)$shop['shop_id'];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Update details (extended fields)
if ($shop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_shop') {
    try {
        ensure_csrf();
        $shop_name = trim($_POST['shop_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        // Optional shop phone using +63 prefix UX
        $shop_phone_local = trim($_POST['shop_phone_local'] ?? '');
        if ($shop_phone_local !== '') {
            $digitsLocal = preg_replace('/\D/', '', $shop_phone_local);
            if (strlen($digitsLocal) > 10) $digitsLocal = substr($digitsLocal, 0, 10);
            $shop_phone = '+63 ' . $digitsLocal;
        } else {
            $shop_phone = trim($_POST['shop_phone'] ?? '');
            if ($shop_phone === '+63' || $shop_phone === '+63 ') $shop_phone = '';
        }
        $open_time = trim($_POST['open_time'] ?? '');
        $close_time = trim($_POST['close_time'] ?? '');
        $description = trim($_POST['description'] ?? '');
        // Optional coordinates
        $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
        $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) throw new Exception('Latitude out of range.');
        if ($lng !== null && ($lng < -180 || $lng > 180)) throw new Exception('Longitude out of range.');
        if ($lat !== null) $lat = round($lat, 6);
        if ($lng !== null) $lng = round($lng, 6);
        if ($shop_name === '' || $address === '' || $city === '') throw new Exception('Required fields missing.');
        // Optional shop phone validation: must follow +63 9XXXXXXXXX pattern if provided
        if ($shop_phone !== '') {
            $digits = preg_replace('/\D/', '', $shop_phone);
            if ($digits === '') {
                $shop_phone = '';
            } else if (!preg_match('/^639\d{9}$/', $digits)) {
                throw new Exception('Shop contact number must be exactly 10 digits after +63 (starting with 9).');
            }
        }
        if (($open_time && !$close_time) || (!$open_time && $close_time)) {
            throw new Exception('Provide both opening and closing time or neither.');
        }
        if ($open_time && $close_time) {
            if (!preg_match('/^\d{2}:\d{2}$/', $open_time) || !preg_match('/^\d{2}:\d{2}$/', $close_time)) {
                throw new Exception('Times must be HH:MM format.');
            }
            if ($open_time >= $close_time) throw new Exception('Opening time must be before closing time.');
        }
        $upd = $pdo->prepare("UPDATE Barbershops SET shop_name=?, address=?, city=?, description=?, shop_phone=?, open_time=?, close_time=?, latitude=?, longitude=? WHERE shop_id=? AND owner_id=?");
        $upd->execute([
            $shop_name,
            $address,
            $city,
            $description ?: null,
            $shop_phone ?: null,
            $open_time ?: null,
            $close_time ?: null,
            $lat,
            $lng,
            $shop['shop_id'],
            $ownerId
        ]);
        $notices[] = 'Shop details updated.';
        // Reload selected shop to reflect changes
        $stmtAll->execute([$ownerId]);
        $shops = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($shops) {
            foreach ($shops as $s) {
                if ((int)$s['shop_id'] === $selectedShopId) {
                    $shop = $s;
                    break;
                }
            }
            if (!$shop) {
                $shop = $shops[0];
                $selectedShopId = (int)$shop['shop_id'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Add service
if ($shop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    try {
        ensure_csrf();
        $service_name = trim($_POST['service_name'] ?? '');
        $duration = (int)($_POST['duration'] ?? 30);
        $price = (float)($_POST['price'] ?? 0);
        if ($service_name === '') throw new Exception('Service name required.');
        if ($duration < 5 || $duration > 480) throw new Exception('Duration out of range.');
        if ($price < 0 || $price > 10000) throw new Exception('Price out of range.');
        // limit total services
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM Services WHERE shop_id=?");
        $cnt->execute([$shop['shop_id']]);
        if ((int)$cnt->fetchColumn() >= 100) throw new Exception('Service limit reached.');
        $ins = $pdo->prepare("INSERT INTO Services (shop_id, service_name, duration_minutes, price) VALUES (?,?,?,?)");
        $ins->execute([$shop['shop_id'], $service_name, $duration, $price]);
        $notices[] = 'Service added.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Delete service
if ($shop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_service') {
    try {
        ensure_csrf();
        $sid = (int)($_POST['service_id'] ?? 0);
        if ($sid) {
            $del = $pdo->prepare("DELETE FROM Services WHERE service_id=? AND shop_id=?");
            $del->execute([$sid, $shop['shop_id']]);
            if ($del->rowCount()) $notices[] = 'Service removed.';
            else $errors[] = 'Service not found.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Update existing service
if ($shop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_service') {
    try {
        ensure_csrf();
        $sid = (int)($_POST['service_id'] ?? 0);
        $service_name = trim($_POST['service_name'] ?? '');
        $duration = (int)($_POST['duration'] ?? 30);
        $price = (float)($_POST['price'] ?? 0);
        if ($sid <= 0) throw new Exception('Invalid service id.');
        if ($service_name === '') throw new Exception('Service name required.');
        if ($duration < 5 || $duration > 480) throw new Exception('Duration out of range.');
        if ($price < 0 || $price > 10000) throw new Exception('Price out of range.');
        $upd = $pdo->prepare("UPDATE Services SET service_name=?, duration_minutes=?, price=? WHERE service_id=? AND shop_id=?");
        $upd->execute([$service_name, $duration, $price, $sid, $shop['shop_id']]);
        if ($upd->rowCount()) $notices[] = 'Service updated.';
        else $notices[] = 'No changes applied.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Fetch services
$services = [];
if ($shop) {
    $svcStmt = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC");
    $svcStmt->execute([$shop['shop_id']]);
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Manage Shops • Owner • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <!-- Leaflet CSS for map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin: .5rem 0 1.2rem;
        }

        /* Multi-shop selector pills (kept consistent with Messages page) */
        .shop-switch .shop-tab {
            background: #132230;
            border: 1px solid #1f2f3a;
            padding: .38rem .7rem;
            border-radius: 20px;
            font-size: .6rem;
            color: #b7c6d3;
            font-weight: 600;
            letter-spacing: .4px;
            transition: background .25s, border-color .25s, color .25s;
        }

        .shop-switch .shop-tab:hover {
            border-color: #0ea5e9;
            color: #fff;
        }

        .shop-switch .shop-tab.active {
            background: #0f2733;
            border-color: #0ea5e9;
            color: #fff;
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

        form textarea {
            resize: vertical;
            min-height: 70px;
        }

        td {
            color: #ffffff;
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

        .svc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .62rem;
        }

        .svc-table th,
        .svc-table td {
            padding: .5rem .55rem;
            text-align: left;
            border-bottom: 1px solid #1f2b36;
        }

        .svc-table th {
            font-weight: 600;
            font-size: .58rem;
            letter-spacing: .5px;
            color: #93adc7;
            text-transform: uppercase;
        }

        .badge-status-pending {
            background: #1e3a8a1a;
            color: #bfdbfe;
            border: 1px solid #1e3a8a40;
        }

        .notice-list,
        .error-list {
            margin: 0 0 1rem;
            padding: 0;
            list-style: none;
            font-size: .62rem;
        }

        .notice-list li {
            background: #0d2a17;
            border: 1px solid #1c5030;
            color: #6ee7b7;
            padding: .45rem .6rem;
            border-radius: 8px;
            margin-bottom: .4rem;
        }

        .error-list li {
            background: #36111a;
            border: 1px solid #5c1f2c;
            color: #fda4af;
            padding: .45rem .6rem;
            border-radius: 8px;
            margin-bottom: .4rem;
        }

        .danger-btn {
            background: #6b1d28;
            border: 1px solid #a33040;
            color: #fda4af;
            padding: .4rem .7rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: .6rem;
        }

        .danger-btn:hover {
            background: #811f2e;
        }

        .inline-form {
            display: inline;
        }

        .mini-note {
            font-size: .55rem;
            color: #69839b;
            margin: .2rem 0 .4rem;
        }

        /* Map container styling */
        .map-wrap {
            background: #0f1a24;
            border: 1px solid #223142;
            border-radius: 10px;
            padding: .5rem;
        }

        .map-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .4rem;
            gap: .5rem;
            font-size: .6rem;
            color: #7fa6c7;
        }

        #shopMapUpdate,
        #shopMapCreate {
            width: 100%;
            height: 280px;
            border-radius: 8px;
            overflow: hidden;
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
            <a class="active" href="manage_shop.php">Manage Shop</a>
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
    <main class="owner-main" style="padding-top:1.25rem;">
        <h1 style="margin:0 0 .9rem;font-size:1.3rem;">Manage Your Shops<?= $shop ? ' • ' . e($shop['shop_name']) : '' ?></h1>
        <?php if (!empty($shops) && count($shops) > 1): ?>
            <div class="shop-switch" style="display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1rem;align-items:center;">
                <span style="font-size:.55rem;letter-spacing:.5px;font-weight:600;color:#7e95aa;">SELECT SHOP:</span>
                <?php foreach ($shops as $s): $active = ((int)$s['shop_id'] === (int)$selectedShopId); ?>
                    <a href="?shop=<?= (int)$s['shop_id'] ?>" class="shop-tab<?= $active ? ' active' : '' ?>" style="text-decoration:none;<?= $active ? 'background:#1d3142;border-color:#0ea5e9;color:#fff;' : '' ?>">
                        <?= e($s['shop_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($shop && $shop['status'] === 'pending'): ?>
            <div style="background:#0b224b;border:1px solid #1e3a8a;color:#dbeafe;padding:.65rem .8rem;border-radius:10px;font-size:.63rem;margin:0 0 1rem;display:flex;gap:.6rem;align-items:flex-start;box-shadow:0 0 0 1px #1e3a8a inset;">
                <strong style="font-weight:600;letter-spacing:.5px;font-size:.6rem;">PENDING REVIEW</strong>
                <span style="flex:1;line-height:1.4;">Your shop is awaiting admin approval. You can already prepare services, but customers may not see it until approved.</span>
            </div>
        <?php endif; ?>
        <div class="toast-stack" aria-live="polite" aria-atomic="true" style="position:relative;min-height:0;">
            <?php foreach ($errors as $er): ?>
                <div class="toast t-error" role="alert">
                    <div class="t-body"><?= e($er) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endforeach; ?>
            <?php foreach ($notices as $n): ?>
                <div class="toast t-success" role="status">
                    <div class="t-body"><?= e($n) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$shop): ?>
            <section class="card" style="margin-bottom:1.5rem;">
                <h2 style="margin:0 0 .75rem;font-size:1rem;">Register Your First Shop</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                    <input type="hidden" name="action" value="create_shop" />
                    <div class="form-grid">
                        <label>Shop Name<input name="shop_name" required></label>
                        <label>City<input name="city" required></label>
                    </div>
                    <label>Address<textarea name="address" required></textarea></label>
                    <div class="map-wrap" style="margin-top:.6rem;">
                        <div class="map-toolbar">
                            <strong style="letter-spacing:.4px;">Pin Location (optional)</strong>
                            <div style="display:flex;gap:.5rem;align-items:center;">
                                <button type="button" class="btn btn-small" id="geoBtnCreate" style="font-size:.6rem;">Use my location</button>
                                <span id="coordsCreate" class="mini-note">Lat/Lng: —</span>
                            </div>
                        </div>
                        <div id="shopMapCreate" role="region" aria-label="Shop location map"></div>
                        <input type="hidden" name="latitude" id="latitudeCreate" />
                        <input type="hidden" name="longitude" id="longitudeCreate" />
                        <div class="mini-note">Drag the pin or click the map to set your shop location. This helps customers find you.</div>
                    </div>
                    <button class="btn btn-primary" style="margin-top:.8rem;">Create Shop</button>
                </form>
            </section>
        <?php else: ?>
            <section class="card" style="margin-bottom:1.4rem;display:flex;flex-direction:column;gap:.85rem;">
                <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;justify-content:space-between;">
                    <h2 style="margin:0;font-size:1rem;">Shop Details <span class="badge badge-status-<?= e($shop['status']) ?>" style="vertical-align:middle;"><?= strtoupper(e($shop['status'])) ?></span></h2>
                </div>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                    <input type="hidden" name="action" value="update_shop" />
                    <div class="form-grid">
                        <label>Shop Name<input name="shop_name" value="<?= e($shop['shop_name']) ?>" required></label>
                        <label>City<input name="city" value="<?= e($shop['city']) ?>" required></label>
                        <label>Phone (Optional)
                            <?php
                            $shopLocal = '';
                            $rawPhone = trim((string)($shop['shop_phone'] ?? ''));
                            if ($rawPhone !== '') {
                                if (strpos($rawPhone, '+63') === 0) {
                                    $shopLocal = ltrim(substr($rawPhone, 3));
                                } else {
                                    $shopLocal = $rawPhone; // fallback legacy format
                                }
                            }
                            ?>
                            <div class="phone-group" style="display:flex;align-items:center;gap:.4rem;">
                                <span class="phone-prefix" style="background:#111c27;border:1px solid #253344;color:#93adc7;padding:.5rem .55rem;border-radius:8px;font-weight:600;letter-spacing:.4px;">+63</span>
                                <input type="tel" id="shop_phone_local" name="shop_phone_local" value="<?= e($shopLocal) ?>" placeholder="9xx xxx xxxx" pattern="^9\d{9}$" inputmode="tel" maxlength="10" style="flex:1;" />
                                <input type="hidden" name="shop_phone" id="shop_phone_full" value="<?= e($rawPhone !== '' ? $rawPhone : '+63 ') ?>" />
                            </div>
                            <div class="mini-note" style="margin:.2rem 0 0;">If entered, must be 10 digits starting with 9 (e.g. 9171234567).</div>
                        </label>
                        <label>Open Time<input type="time" name="open_time" value="<?= e($shop['open_time'] ?? '') ?>"></label>
                        <label>Close Time<input type="time" name="close_time" value="<?= e($shop['close_time'] ?? '') ?>"></label>
                    </div>
                    <label>Address<textarea name="address" required><?= e($shop['address']) ?></textarea></label>
                    <label>Description<textarea name="description" placeholder="Describe your shop, specialties, parking, etc."><?= e($shop['description'] ?? '') ?></textarea></label>
                    <div class="map-wrap">
                        <div class="map-toolbar">
                            <strong style="letter-spacing:.4px;">Pin Location (optional)</strong>
                            <div style="display:flex;gap:.5rem;align-items:center;">
                                <button type="button" class="btn btn-small" id="geoBtnUpdate" style="font-size:.6rem;">Use my location</button>
                                <?php
                                $latVal = isset($shop['latitude']) && $shop['latitude'] !== null ? (float)$shop['latitude'] : null;
                                $lngVal = isset($shop['longitude']) && $shop['longitude'] !== null ? (float)$shop['longitude'] : null;
                                ?>
                                <span id="coordsUpdate" class="mini-note">Lat/Lng: <?= ($latVal !== null && $lngVal !== null) ? e(number_format($latVal, 6) . ', ' . number_format($lngVal, 6)) : '—' ?></span>
                            </div>
                        </div>
                        <div id="shopMapUpdate" role="region" aria-label="Shop location map"></div>
                        <input type="hidden" name="latitude" id="latitudeUpdate" value="<?= $latVal !== null ? e($latVal) : '' ?>" />
                        <input type="hidden" name="longitude" id="longitudeUpdate" value="<?= $lngVal !== null ? e($lngVal) : '' ?>" />
                        <div class="mini-note">Drag the pin or click the map to set your shop location. This helps customers find you.</div>
                    </div>
                    <p class="mini-note">Status is managed by administrators. Pending shops may have limited visibility.</p>
                    <button class="btn btn-primary">Save Changes</button>
                </form>
            </section>
            <section class="card" style="display:flex;flex-direction:column;gap:.95rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                    <h2 style="margin:0;font-size:1rem;">Services <span style="font-size:.6rem;color:#6b8299;">(<?= count($services) ?>)</span></h2>
                    <form method="post" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                        <input type="hidden" name="action" value="add_service" />
                        <label style="width:160px;">Name<input name="service_name" required maxlength="100" placeholder="e.g. Haircut"></label>
                        <label style="width:90px;">Duration<input type="number" name="duration" value="30" min="5" max="480" required></label>
                        <label style="width:110px;">Price<input type="number" step="0.01" name="price" value="0" min="0" max="10000" required></label>
                        <button class="btn btn-primary" style="align-self:flex-end;">Add</button>
                    </form>
                </div>
                <div style="overflow:auto;">
                    <table class="svc-table" aria-label="Services table">
                        <thead>
                            <tr>
                                <th style="min-width:160px;">Name</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$services): ?>
                                <tr>
                                    <td colspan="4" style="padding:.9rem .6rem;color:#6e859c;font-size:.6rem;">No services yet. Add your first above.</td>
                                </tr>
                                <?php else: foreach ($services as $svc): ?>
                                    <tr data-sid="<?= (int)$svc['service_id'] ?>">
                                        <td>
                                            <span class="svc-view svc-name"><?= e($svc['service_name']) ?></span>
                                            <form method="post" class="svc-edit" style="display:none;gap:.4rem;flex-wrap:wrap;align-items:flex-end;">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                <input type="hidden" name="action" value="update_service" />
                                                <input type="hidden" name="service_id" value="<?= (int)$svc['service_id'] ?>" />
                                                <input name="service_name" value="<?= e($svc['service_name']) ?>" maxlength="100" style="width:150px;">
                                            </form>
                                        </td>
                                        <td>
                                            <span class="svc-view svc-duration"><?= (int)$svc['duration_minutes'] ?> min</span>
                                            <form class="svc-edit" method="post" style="display:none;">
                                                <!-- merged in first form via JS if needed -->
                                            </form>
                                        </td>
                                        <td>
                                            <span class="svc-view svc-price">₱<?= number_format($svc['price'], 2) ?></span>
                                        </td>
                                        <td style="text-align:right;white-space:nowrap;">
                                            <div class="svc-view">
                                                <button type="button" class="btn btn-small" onclick="editService(this)" data-mode="view">Edit</button>
                                                <form method="post" class="inline-form" onsubmit="return confirm('Delete service?');" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                    <input type="hidden" name="action" value="delete_service" />
                                                    <input type="hidden" name="service_id" value="<?= (int)$svc['service_id'] ?>" />
                                                    <button class="danger-btn" aria-label="Delete service">Del</button>
                                                </form>
                                            </div>
                                            <form method="post" class="svc-edit" style="display:none;gap:.4rem;align-items:flex-end;">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                <input type="hidden" name="action" value="update_service" />
                                                <input type="hidden" name="service_id" value="<?= (int)$svc['service_id'] ?>" />
                                                <input type="number" name="duration" value="<?= (int)$svc['duration_minutes'] ?>" min="5" max="480" style="width:70px;" />
                                                <input type="number" name="price" step="0.01" value="<?= number_format($svc['price'], 2, '.', '') ?>" min="0" max="10000" style="width:90px;" />
                                                <input name="service_name" value="<?= e($svc['service_name']) ?>" maxlength="100" style="width:140px;display:none;" />
                                                <button class="btn btn-primary btn-small">Save</button>
                                                <button type="button" class="danger-btn" onclick="cancelEdit(this)" style="font-size:.55rem;">Cancel</button>
                                            </form>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php // Customer Conversations removed; moved to dedicated Messages page 
        ?>
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

        function editService(btn) {
            const row = btn.closest('tr');
            row.querySelectorAll('.svc-view').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.svc-edit').forEach(el => el.style.display = 'flex');
        }

        function cancelEdit(btn) {
            const row = btn.closest('tr');
            row.querySelectorAll('.svc-edit').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.svc-view').forEach(el => el.style.display = '');
        }

        // Sync optional shop phone with fixed +63 prefix
        (function syncOwnerShopPhone() {
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
            local.addEventListener('keypress', e => {
                const ch = String.fromCharCode(e.which);
                if (!/[0-9]/.test(ch) && e.which !== 8 && e.which !== 0) e.preventDefault();
            });
            local.addEventListener('blur', update);
            update();
        })();

        // Leaflet map initializer (only runs if containers exist)
        function initMap(containerId, latInputId, lngInputId, coordsLabelId, geoBtnId, initialLat, initialLng) {
            const mapEl = document.getElementById(containerId);
            if (!mapEl) return null;
            const latEl = document.getElementById(latInputId);
            const lngEl = document.getElementById(lngInputId);
            const labelEl = document.getElementById(coordsLabelId);
            const geoBtn = document.getElementById(geoBtnId);
            // Try to find an address textarea within the same form
            const formEl = mapEl.closest('form');
            const addrTextarea = formEl ? formEl.querySelector('textarea[name="address"]') : null;

            const DEFAULT_CENTER = [13.9400, 121.1600]; // Lipa, Batangas approx
            const startLat = (initialLat != null && !isNaN(initialLat)) ? parseFloat(initialLat) : (latEl && latEl.value ? parseFloat(latEl.value) : null);
            const startLng = (initialLng != null && !isNaN(initialLng)) ? parseFloat(initialLng) : (lngEl && lngEl.value ? parseFloat(lngEl.value) : null);
            const start = (startLat != null && startLng != null) ? [startLat, startLng] : DEFAULT_CENTER;

            const map = L.map(mapEl).setView(start, (startLat != null ? 15 : 11));
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            const marker = L.marker(start, {
                draggable: true
            }).addTo(map);

            function updateInputs(latlng) {
                if (latEl) latEl.value = latlng.lat.toFixed(6);
                if (lngEl) lngEl.value = latlng.lng.toFixed(6);
                if (labelEl) labelEl.textContent = 'Lat/Lng: ' + latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6);
                reverseGeocode(latlng.lat, latlng.lng);
            }

            // If inputs already have values (e.g., update form), reflect them in the label.
            if ((latEl && latEl.value) && (lngEl && lngEl.value)) {
                labelEl.textContent = 'Lat/Lng: ' + parseFloat(latEl.value).toFixed(6) + ', ' + parseFloat(lngEl.value).toFixed(6);
            }

            marker.on('dragend', () => updateInputs(marker.getLatLng()));
            map.on('click', (e) => {
                marker.setLatLng(e.latlng);
                updateInputs(e.latlng);
            });

            if (geoBtn) {
                geoBtn.addEventListener('click', () => {
                    if (!navigator.geolocation) return alert('Geolocation not supported by your browser.');
                    geoBtn.disabled = true;
                    geoBtn.textContent = 'Locating…';
                    navigator.geolocation.getCurrentPosition((pos) => {
                        const ll = {
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude
                        };
                        map.setView(ll, 16);
                        marker.setLatLng(ll);
                        updateInputs(ll);
                        geoBtn.disabled = false;
                        geoBtn.textContent = 'Use my location';
                    }, (err) => {
                        alert('Unable to retrieve location: ' + (err && err.message ? err.message : 'Unknown error'));
                        geoBtn.disabled = false;
                        geoBtn.textContent = 'Use my location';
                    }, {
                        enableHighAccuracy: true,
                        timeout: 8000,
                        maximumAge: 0
                    });
                });
            }

            // Ensure map sizes correctly if inside responsive container
            setTimeout(() => map.invalidateSize(), 150);

            function reverseGeocode(lat, lng) {
                if (!addrTextarea) return;
                // Debounced/retry logic for backend reverse geocoding
                let fetchController = reverseGeocode._fetchController;
                if (fetchController) fetchController.abort();
                fetchController = new AbortController();
                reverseGeocode._fetchController = fetchController;
                addrTextarea.value = 'Fetching address…';
                let attempts = 0;
                const endpoint = new URL('../api/reverse_geocode.php', window.location.href).toString();
                const tryFetch = () => {
                    fetch(`${endpoint}?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`, {
                            signal: fetchController.signal
                        })
                        .then(r => r.ok ? r.json() : Promise.reject(new Error('Reverse geocoding failed')))
                        .then(data => {
                            if (!data || !data.address) {
                                throw new Error('No address found');
                            }
                            const a = data.address;
                            const parts = [];
                            const hn = a.house_number || '';
                            const road = a.road || a.residential || a.path || a.pedestrian || a.footway || '';
                            const street = (hn && road) ? `${hn} ${road}` : (road || hn);
                            if (street) parts.push(street);
                            const brgy = a.suburb || a.neighbourhood || a.neighborhood || a.village || a.quarter || a.hamlet || '';
                            if (brgy) parts.push(`Brgy. ${brgy}`);
                            const landmark = data.name || a.public_building || a.school || a.hospital || a.college || a.university || a.mall || a.shop || '';
                            if (landmark && (!street || !street.toLowerCase().includes(String(landmark).toLowerCase()))) {
                                parts.push(`Near ${landmark}`);
                            }
                            const text = parts.filter(Boolean).join('\n');
                            if (text) {
                                addrTextarea.value = text;
                            } else {
                                addrTextarea.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                            }
                        })
                        .catch((err) => {
                            if (attempts < 2) {
                                attempts++;
                                setTimeout(tryFetch, 800 * attempts);
                            } else {
                                addrTextarea.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                            }
                        });
                };
                tryFetch();
            }
            return map;
        }
    </script>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        // Initialize maps for update or create forms conditionally
        (function() {
            const hasUpdate = document.getElementById('shopMapUpdate');
            if (hasUpdate) {
                // PHP injected values
                const lat = document.getElementById('latitudeUpdate')?.value || null;
                const lng = document.getElementById('longitudeUpdate')?.value || null;
                initMap('shopMapUpdate', 'latitudeUpdate', 'longitudeUpdate', 'coordsUpdate', 'geoBtnUpdate', lat, lng);
            }
            const hasCreate = document.getElementById('shopMapCreate');
            if (hasCreate) {
                initMap('shopMapCreate', 'latitudeCreate', 'longitudeCreate', 'coordsCreate', 'geoBtnCreate', null, null);
            }
        })();
    </script>
    <script src="../assets/js/menu-toggle.js"></script>
    <!-- owner_chat.js removed; messaging now on dedicated Messages page -->
</body>

</html>