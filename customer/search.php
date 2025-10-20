<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Allow guests & customers only
if (is_logged_in() && !has_role('customer')) redirect('../login.php');

function in_get($k, $d = '')
{
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}
$q = in_get('q');
$city = in_get('city');
$verified = in_get('verified');
$sort = in_get('sort', 'rating'); // rating | name | reviews
$page = max(1, (int)in_get('page', 1));
$perPage = 12;

$params = [];
$w = [
  "b.status='approved'",
  "EXISTS (SELECT 1 FROM Shop_Subscriptions s WHERE s.shop_id=b.shop_id AND s.payment_status='paid' AND CURDATE() BETWEEN s.valid_from AND s.valid_to)"
];
if ($q !== '') {
  $w[] = '(b.shop_name LIKE :kw OR b.city LIKE :kw)';
  $params[':kw'] = "%$q%";
}
if ($city !== '') {
  $w[] = 'b.city=:city';
  $params[':city'] = $city;
}
if ($verified === '1') {
  $w[] = 'u.is_verified=1';
}
$whereSql = implode(' AND ', $w);

$cStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.shop_id) FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE $whereSql");
foreach ($params as $k => $v) $cStmt->bindValue($k, $v);
$cStmt->execute();
$total = (int)$cStmt->fetchColumn();
$maxPage = $total ? (int)ceil($total / $perPage) : 1;
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

// Sorting
switch ($sort) {
  case 'name':
    $order = 'b.shop_name ASC';
    break;
  case 'reviews':
    $order = 'reviews_count DESC, avg_rating DESC';
    break;
  default:
    $order = 'avg_rating DESC, reviews_count DESC, b.shop_name ASC';
    $sort = 'rating';
}

$sql = "SELECT b.shop_id,b.shop_name,b.city,b.address,LEFT(IFNULL(b.description,''),160) description,u.is_verified,
	  COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.review_id) reviews_count
	  FROM Barbershops b
	  JOIN Users u ON b.owner_id=u.user_id
	  LEFT JOIN Reviews r ON r.shop_id=b.shop_id
	  WHERE $whereSql
	  GROUP BY b.shop_id
	  ORDER BY $order
	  LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$shops = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cityRows = $pdo->query("SELECT DISTINCT city FROM Barbershops WHERE city IS NOT NULL AND city<>'' ORDER BY city ASC LIMIT 80")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$user = current_user();

// Pagination link helper must be defined before first usage to avoid runtime undefined function errors
if (!function_exists('page_link')) {
  function page_link(int $p): string
  {
    $qs = $_GET;
    $qs['page'] = $p;
    return 'search.php?' . http_build_query($qs);
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Search Shops • BarberSure</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/customer.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <style>
    .search-header {
      display: flex;
      flex-direction: column;
      gap: .55rem;
      margin-bottom: 1.2rem;
    }

    .search-header h1 {
      font-size: 1.65rem;
      margin: 0;
      font-weight: 600;
      letter-spacing: .4px;
    }

    .filters-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: .7rem;
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      padding: .9rem .95rem 1rem;
      border-radius: var(--radius);
    }

    .filters-wrap input,
    .filters-wrap select {
      background: var(--c-surface);
      border: 1px solid var(--c-border);
      color: var(--c-text);
      font-size: .78rem;
      padding: .6rem .7rem;
      border-radius: var(--radius-sm);
      min-width: 150px;
    }

    .filters-wrap input:focus,
    .filters-wrap select:focus {
      outline: none;
      border-color: var(--c-accent-alt);
      box-shadow: 0 0 0 .12rem rgba(14, 165, 233, .25);
    }

    .filters-actions {
      display: flex;
      gap: .5rem;
    }

    .btn-small {
      font-size: .7rem;
      padding: .6rem .9rem;
    }

    .shops-grid {
      display: grid;
      gap: 1rem;
      margin-top: 1.4rem;
      grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    }

    .tile {
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      padding: .95rem .95rem 1.05rem;
      border-radius: var(--radius-sm);
      display: flex;
      flex-direction: column;
      gap: .45rem;
      position: relative;
      transition: border-color .35s, transform .35s;
    }

    .tile:hover {
      border-color: var(--c-accent-alt);
    }

    .tile-title {
      font-size: 1rem;
      font-weight: 600;
      margin: 0;
      display: flex;
      align-items: center;
      gap: .4rem;
    }

    .verify-badge {
      background: var(--c-accent-alt);
      color: #fff;
      font-size: .5rem;
      padding: .22rem .45rem;
      border-radius: 40px;
      letter-spacing: .55px;
      font-weight: 600;
    }

    .rating-line {
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .75rem;
      font-weight: 600;
      color: #fbbf24;
    }

    .rating-line span.count {
      color: var(--c-text-soft);
      font-weight: 500;
    }

    .address {
      font-size: .62rem;
      letter-spacing: .45px;
      text-transform: uppercase;
      color: var(--c-text-soft);
      display: flex;
      flex-wrap: wrap;
      gap: .35rem;
    }

    .desc {
      font-size: .68rem;
      line-height: 1.5;
      color: var(--c-text-soft);
      flex: 1;
    }

    .pagination {
      margin: 2rem 0 0;
      display: flex;
      justify-content: center;
      gap: .4rem;
      flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
      min-width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .63rem;
      font-weight: 600;
      letter-spacing: .5px;
      text-decoration: none;
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border);
      color: var(--c-text-soft);
    }

    .pagination a:hover {
      border-color: var(--c-accent-alt);
      color: var(--c-text);
    }

    .pagination .active {
      background: var(--c-accent-alt);
      border-color: var(--c-accent-alt);
      color: #fff;
    }

    .empty {
      padding: 3rem 1rem;
      text-align: center;
      font-size: .8rem;
      color: var(--c-text-soft);
    }

    .tile a {
      color: inherit;
      text-decoration: none;
    }

    .tile-link {
      position: absolute;
      inset: 0;
    }

    .sort-select {
      min-width: 130px;
    }

    .nearby-status {
      display: none;
      margin: .6rem 0 -.4rem;
      background: var(--c-bg-alt);
      border: 1px dashed var(--c-border);
      color: var(--c-text);
      padding: .55rem .7rem;
      border-radius: var(--radius-sm);
      font-size: .75rem;
      align-items: center;
      justify-content: space-between;
      gap: .6rem;
    }

    .distance-badge {
      position: absolute;
      top: .6rem;
      right: .6rem;
      background: #0ea5e9;
      color: #fff;
      font-size: .6rem;
      padding: .2rem .4rem;
      border-radius: 6px;
      letter-spacing: .3px;
    }

    @keyframes spin {
      from {
        transform: rotate(0deg);
      }

      to {
        transform: rotate(360deg);
      }
    }

    @media (max-width:680px) {
      .filters-wrap {
        flex-direction: column;
        align-items: stretch;
      }

      .filters-actions {
        width: 100%;
      }

      .filters-actions .btn {
        flex: 1;
      }
    }
  </style>
</head>

<body class="dashboard-wrapper">
  <header class="header-bar">
    <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
    <div class="header-brand">
      <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
      <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
    </div>
    <nav id="customerNav" class="nav-links">
      <a href="dashboard.php">Dashboard</a>
      <a class="active" href="search.php">Find Shops</a>
      <a href="bookings_history.php">History</a>
      <a href="profile.php">Profile</a>
      <?php if (is_logged_in()): ?>
        <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
          <button type="submit" class="logout-btn">Logout</button>
        </form>
      <?php else: ?>
        <a href="../login.php" class="btn btn-primary">Sign In</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="dashboard-main">
    <section class="card" style="padding:1.3rem 1.4rem 1.55rem;margin-bottom:1.6rem;">
      <div class="search-header" style="margin-bottom:1rem;">
        <h1><i class="bi bi-search" aria-hidden="true"></i> <span>Find a Barbershop</span></h1>
        <p style="font-size:.8rem;color:var(--c-text-soft);max-width:720px;line-height:1.55;">Search verified shops, compare ratings and reviews, and discover new places to book your next appointment.</p>
      </div>
      <form method="get" class="filters-wrap" action="search.php">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or city" />
        <select name="city">
          <option value="">All Cities</option>
          <?php foreach ($cityRows as $c): ?>
            <option value="<?= e($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="verified">
          <option value="">Any</option>
          <option value="1" <?= $verified === '1' ? 'selected' : '' ?>>Verified</option>
        </select>
        <select name="sort" class="sort-select">
          <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top Rated</option>
          <option value="reviews" <?= $sort === 'reviews' ? 'selected' : '' ?>>Most Reviewed</option>
          <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
        </select>
        <div class="filters-actions">
          <button class="btn btn-primary btn-small" type="submit">Apply</button>
          <a href="search.php" class="btn btn-small" style="background:var(--c-surface);">Reset</a>
          <button id="useLocationBtn" class="btn btn-small" type="button" title="Show nearest shops" style="background:var(--c-surface);display:flex;align-items:center;gap:.35rem;">
            <i class="bi bi-geo-alt"></i>
            <span>Use My Location</span>
          </button>
        </div>
      </form>
    </section>
    <div id="nearbyStatus" class="nearby-status" role="status" aria-live="polite">
      <span id="nearbyMessage">Showing top 3 nearest to your location</span>
      <button id="clearNearbyBtn" class="btn btn-small" type="button" style="background:var(--c-surface);">Clear</button>
    </div>
    <div class="shops-grid">
      <?php if (!$shops): ?>
        <div class="empty" style="grid-column:1/-1;">No shops found. Try adjusting your filters.</div>
        <?php else: foreach ($shops as $shop): $rating = round((float)$shop['avg_rating'], 1);
          $reviews = (int)$shop['reviews_count']; ?>
          <div class="tile">
            <a class="tile-link" href="shop_details.php?id=<?= (int)$shop['shop_id'] ?>" aria-label="View <?= e($shop['shop_name']) ?> details"></a>
            <h3 class="tile-title"><?= e($shop['shop_name']) ?> <?php if ($shop['is_verified']): ?><span class="verify-badge">VERIFIED</span><?php endif; ?></h3>
            <div class="rating-line"><i class="bi bi-star-fill" style="color:#fbbf24;"></i><span><?= number_format($rating, 1) ?></span><span class="count">(<?= $reviews ?>)</span></div>
            <div class="address"><span><?= e($shop['city'] ?: '—') ?></span><span><?= e($shop['address'] ? (strlen($shop['address']) > 30 ? substr($shop['address'], 0, 28) . '…' : $shop['address']) : 'No address') ?></span></div>
            <p class="desc mb-none"><?= e($shop['description']) ?></p>
          </div>
      <?php endforeach;
      endif; ?>
    </div>
    <?php if ($total > $perPage): ?>
      <div id="pagination" class="pagination" aria-label="Pagination">
        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end = min($maxPage, $page + $range);
        if ($start > 1) {
          echo '<a href="' . e(page_link(1)) . '">1</a>';
          if ($start > 2) echo '<span>…</span>';
        }
        for ($p = $start; $p <= $end; $p++) {
          if ($p === $page) echo '<span class="active">' . $p . '</span>';
          else echo '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
        }
        if ($end < $maxPage) {
          if ($end < $maxPage - 1) echo '<span>…</span>';
          echo '<a href="' . e(page_link($maxPage)) . '">' . $maxPage . '</a>';
        }
        // page_link() defined earlier
        ?>
      </div>
    <?php endif; ?>
  </main>
  <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • Find your style.</footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.js"></script>
  <script src="../assets/js/menu-toggle.js"></script>
  <script>
    (function() {
      const btn = document.getElementById('useLocationBtn');
      if (!btn) return;
      const grid = document.querySelector('.shops-grid');
      const pag = document.getElementById('pagination');
      const statusBar = document.getElementById('nearbyStatus');
      const statusMsg = document.getElementById('nearbyMessage');
      const clearBtn = document.getElementById('clearNearbyBtn');

      function escapeHtml(s) {
        return String(s)
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#039;');
      }

      function setLoading(loading) {
        btn.disabled = !!loading;
        if (loading) {
          btn.dataset.prevText = btn.innerHTML;
          btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;display:inline-block;"></i> Locating…';
        } else if (btn.dataset.prevText) {
          btn.innerHTML = btn.dataset.prevText;
          delete btn.dataset.prevText;
        }
      }

      function showStatus(msg) {
        statusMsg.textContent = msg;
        statusBar.style.display = 'flex';
      }

      function hideStatus() {
        statusBar.style.display = 'none';
      }

      function renderShops(shops) {
        if (!Array.isArray(shops) || shops.length === 0) {
          grid.innerHTML = '<div class="empty" style="grid-column:1/-1;">No nearby shops found.</div>';
          return;
        }
        const tiles = shops.map(s => {
          const rating = Number(s.avg_rating ?? 0).toFixed(1);
          const reviews = Number(s.reviews_count ?? 0);
          const name = escapeHtml(s.shop_name ?? '');
          const city = escapeHtml(s.city ?? '—');
          const addressRaw = s.address ? String(s.address) : '';
          const addressTrim = addressRaw.length > 30 ? addressRaw.slice(0, 28) + '…' : addressRaw;
          const address = escapeHtml(addressTrim || 'No address');
          const desc = escapeHtml(s.description ?? '');
          const verified = Number(s.is_verified ?? 0) === 1;
          const id = Number(s.shop_id);
          const dist = (typeof s.distance_km !== 'undefined' && s.distance_km !== null) ?
            `${(Math.round(Number(s.distance_km) * 10) / 10).toFixed(1)} km` :
            '';
          return `
                        <div class="tile">
                            <a class="tile-link" href="shop_details.php?id=${id}" aria-label="View ${name} details"></a>
                            ${dist ? `<span class="distance-badge" title="Distance from you">${dist}</span>` : ''}
                            <h3 class="tile-title">${name} ${verified ? '<span class="verify-badge">VERIFIED</span>' : ''}</h3>
                            <div class="rating-line"><i class="bi bi-star-fill" style="color:#fbbf24;"></i><span>${rating}</span><span class="count">(${reviews})</span></div>
                            <div class="address"><span>${city}</span><span>${address}</span></div>
                            <p class="desc mb-none">${desc}</p>
                        </div>`;
        }).join('');
        grid.innerHTML = tiles;
      }

      function doNearby(lat, lng, fallbackCity) {
        const q = document.querySelector('input[name="q"]').value || '';
        const city = document.querySelector('select[name="city"]').value || '';
        const verified = document.querySelector('select[name="verified"]').value || '';
        fetch('../api/shops_nearby.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            lat,
            lng,
            q,
            city,
            verified,
            fallbackCity: (fallbackCity || '')
          })
        }).then(r => r.json()).then(data => {
          if (data && data.ok) {
            renderShops(data.shops || []);
            if (pag) pag.style.display = 'none';
            showStatus('Showing top 3 nearest to your location' + (fallbackCity ? ` (around ${escapeHtml(fallbackCity)})` : ''));
          } else {
            renderShops([]);
            if (pag) pag.style.display = 'none';
            showStatus('Could not fetch nearby shops.');
          }
        }).catch(() => {
          renderShops([]);
          if (pag) pag.style.display = 'none';
          showStatus('Network error fetching nearby shops.');
        });
      }

      btn.addEventListener('click', function() {
        if (!('geolocation' in navigator)) {
          showStatus('Geolocation is not supported by your browser.');
          return;
        }
        setLoading(true);
        const opts = {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0
        };
        navigator.geolocation.getCurrentPosition(function(pos) {
          setLoading(false);
          const {
            latitude,
            longitude
          } = pos.coords || {};
          if (typeof latitude !== 'number' || typeof longitude !== 'number') {
            showStatus('Could not read your location.');
            return;
          }
          // Try reverse geocoding city name for better fallback matching
          let cityHint = '';
          const url = `../api/reverse_geocode.php?lat=${encodeURIComponent(latitude)}&lng=${encodeURIComponent(longitude)}`;
          fetch(url).then(r => r.ok ? r.json() : null).then(j => {
            if (j && j.address) {
              cityHint = j.address.city || j.address.town || j.address.village || j.address.municipality || '';
            }
          }).catch(() => {}).finally(() => {
            doNearby(latitude, longitude, cityHint);
          });
        }, function(err) {
          setLoading(false);
          if (err && (err.code === 1)) {
            showStatus('Permission denied to access location.');
          } else if (err && (err.code === 2)) {
            showStatus('Location unavailable.');
          } else if (err && (err.code === 3)) {
            showStatus('Location request timed out.');
          } else {
            showStatus('Unable to get your location.');
          }
        }, opts);
      });

      if (clearBtn) {
        clearBtn.addEventListener('click', function() {
          // simplest: restore original server-rendered list
          hideStatus();
          location.reload();
        });
      }
    })();
  </script>
</body>

</html>