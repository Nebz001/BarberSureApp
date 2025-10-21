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
  <!-- Leaflet CSS for map -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
  <style>
    .card {
      background: linear-gradient(135deg, #0b1624, #0a1120);
      border: 1px solid #1d3557;
      border-radius: 14px;
    }

    /* Match field styling used in Manage Shop */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
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

    form textarea {
      resize: vertical;
      min-height: 70px;
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

    #shopMapCreate {
      width: 100%;
      height: 280px;
      border-radius: 8px;
      overflow: hidden;
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
        <div class="form-grid">
          <label>Shop Name
            <input type="text" name="shop_name" required placeholder="e.g., Fade Masters Barbershop" />
          </label>
          <label>City (Batangas)
            <select name="city" required>
              <option value="" disabled selected>Select City</option>
              <?php foreach ($batangasCities as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="small-note">Choose the city where your shop operates.</span>
          </label>
        </div>
        <label>Address
          <textarea name="address" rows="3" required placeholder="Street / Brgy / Landmark"></textarea>
        </label>
        <div class="map-wrap" style="margin-top:.6rem;">
          <div class="map-toolbar">
            <strong style="letter-spacing:.4px;">Pin Location (optional)</strong>
            <div style="display:flex;gap:.5rem;align-items:center;">
              <button type="button" class="btn" id="geoBtnCreate" style="font-size:.65rem;">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <span class="label">Use my location</span>
              </button>
              <span id="coordsCreate" class="small-note" style="font-size:.6rem;">Lat/Lng: —</span>
            </div>
          </div>
          <div id="shopMapCreate" role="region" aria-label="Shop location map"></div>
          <input type="hidden" name="latitude" id="latitudeCreate" />
          <input type="hidden" name="longitude" id="longitudeCreate" />
          <div class="small-note" style="font-size:.6rem;">Drag the pin or click the map to set your shop location. This helps customers find you.</div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-circle" aria-hidden="true"></i>
            <span>Create Shop</span>
          </button>
          <a href="manage_shop.php" class="btn btn-outline">
            <i class="bi bi-shop" aria-hidden="true"></i>
            <span>Manage Shops</span>
          </a>
          <a href="dashboard.php" class="btn btn-outline">
            <i class="bi bi-speedometer2" aria-hidden="true"></i>
            <span>Back to Dashboard</span>
          </a>
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
  <!-- Leaflet JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <script>
    // Map initializer copied from Manage Shop for consistency
    function initMap(containerId, latInputId, lngInputId, coordsLabelId, geoBtnId, initialLat, initialLng) {
      const mapEl = document.getElementById(containerId);
      if (!mapEl) return null;
      const latEl = document.getElementById(latInputId);
      const lngEl = document.getElementById(lngInputId);
      const labelEl = document.getElementById(coordsLabelId);
      const geoBtn = document.getElementById(geoBtnId);
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

      if ((latEl && latEl.value) && (lngEl && lngEl.value)) {
        if (labelEl) labelEl.textContent = 'Lat/Lng: ' + parseFloat(latEl.value).toFixed(6) + ', ' + parseFloat(lngEl.value).toFixed(6);
      }

      marker.on('dragend', () => updateInputs(marker.getLatLng()));
      map.on('click', (e) => {
        marker.setLatLng(e.latlng);
        updateInputs(e.latlng);
      });

      if (geoBtn) {
        const geoLabel = geoBtn.querySelector('.label');
        geoBtn.addEventListener('click', () => {
          if (!navigator.geolocation) return alert('Geolocation not supported by your browser.');
          geoBtn.disabled = true;
          if (geoLabel) geoLabel.textContent = 'Locating…';
          else geoBtn.textContent = 'Locating…';
          navigator.geolocation.getCurrentPosition((pos) => {
            const ll = {
              lat: pos.coords.latitude,
              lng: pos.coords.longitude
            };
            map.setView(ll, 16);
            marker.setLatLng(ll);
            updateInputs(ll);
            geoBtn.disabled = false;
            if (geoLabel) geoLabel.textContent = 'Use my location';
            else geoBtn.textContent = 'Use my location';
          }, (err) => {
            alert('Unable to retrieve location: ' + (err && err.message ? err.message : 'Unknown error'));
            geoBtn.disabled = false;
            if (geoLabel) geoLabel.textContent = 'Use my location';
            else geoBtn.textContent = 'Use my location';
          }, {
            enableHighAccuracy: true,
            timeout: 8000,
            maximumAge: 0
          });
        });
      }

      setTimeout(() => map.invalidateSize(), 150);

      function reverseGeocode(lat, lng) {
        if (!addrTextarea) return;
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
              if (!data || !data.address) throw new Error('No address found');
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
            .catch(() => {
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

    // Initialize the create map on this page
    (function() {
      initMap('shopMapCreate', 'latitudeCreate', 'longitudeCreate', 'coordsCreate', 'geoBtnCreate', null, null);
    })();
  </script>
</body>

</html>