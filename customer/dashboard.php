<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

require_login();
if (!has_role('customer')) redirect('login.php');

$user = current_user();
$userId = (int)($user['user_id'] ?? 0);

// Helper formatting functions
function fmt_dt($dt)
{
  return date('M d, Y g:ia', strtotime($dt));
}

// Fetch counts (appointments & reviews)
$counts = [
  'total' => 0,
  'upcoming' => 0,
  'completed' => 0,
  'cancelled' => 0,
  'reviews' => 0
];
if ($userId) {
  $sqlCounts = "SELECT 
        COUNT(*) total,
        SUM(CASE WHEN appointment_date >= NOW() AND status IN ('pending','confirmed') THEN 1 ELSE 0 END) upcoming,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled
      FROM Appointments WHERE customer_id=?";
  $stmt = $pdo->prepare($sqlCounts);
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $counts = array_merge($counts, array_map('intval', $row));
  $rv = $pdo->prepare("SELECT COUNT(*) FROM Reviews WHERE customer_id=?");
  $rv->execute([$userId]);
  $counts['reviews'] = (int)$rv->fetchColumn();
}

// Upcoming (soonest) booking
$upcoming = null;
if ($userId) {
  $upStmt = $pdo->prepare("SELECT a.appointment_id, a.appointment_date, a.status, b.shop_name, s.service_name
        FROM Appointments a
        JOIN Barbershops b ON a.shop_id=b.shop_id
        JOIN Services s ON a.service_id=s.service_id
        WHERE a.customer_id=? AND a.appointment_date >= NOW() AND a.status IN ('pending','confirmed')
        ORDER BY a.appointment_date ASC LIMIT 1");
  $upStmt->execute([$userId]);
  $upcoming = $upStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Recent bookings (last 5 by date desc)
$recentBookings = [];
if ($userId) {
  $rb = $pdo->prepare("SELECT a.appointment_id, a.appointment_date, a.status, b.shop_name, s.service_name
        FROM Appointments a
        JOIN Barbershops b ON a.shop_id=b.shop_id
        JOIN Services s ON a.service_id=s.service_id
        WHERE a.customer_id=?
        ORDER BY a.appointment_date DESC LIMIT 5");
  $rb->execute([$userId]);
  $recentBookings = $rb->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Recent reviews (limit 5)
$recentReviews = [];
if ($userId) {
  $rr = $pdo->prepare("SELECT r.rating, r.comment, r.created_at, b.shop_name
        FROM Reviews r JOIN Barbershops b ON r.shop_id=b.shop_id
        WHERE r.customer_id=? ORDER BY r.created_at DESC LIMIT 5");
  $rr->execute([$userId]);
  $recentReviews = $rr->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Suggestions: top rated shops user has not booked yet (avg rating desc, at least 1 review) limit 3
$suggestions = [];
if ($userId) {
  $sg = $pdo->prepare("SELECT b.shop_id, b.shop_name, b.city, COALESCE(AVG(rv.rating),0) avg_rating, COUNT(rv.review_id) reviews
        FROM Barbershops b
        LEFT JOIN Reviews rv ON rv.shop_id=b.shop_id
        WHERE b.status='approved' AND NOT EXISTS(
            SELECT 1 FROM Appointments a2 WHERE a2.customer_id=? AND a2.shop_id=b.shop_id
        )
        GROUP BY b.shop_id
        HAVING avg_rating >= 3
        ORDER BY avg_rating DESC, reviews DESC
        LIMIT 3");
  $sg->execute([$userId]);
  $suggestions = $sg->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Profile completeness heuristic (simple: full_name, email, phone, at least 1 booking)
$profileFields = 0;
$profileTotal = 4;
if (!empty($user['full_name'])) $profileFields++;
if (!empty($user['email'])) $profileFields++;
if (!empty($user['phone'])) $profileFields++;
if ($counts['total'] > 0) $profileFields++;
$profilePercent = (int)round(($profileFields / $profileTotal) * 100);

function status_chip_class($st)
{
  return 'status-' . strtolower($st);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Customer Dashboard • BarberSure</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/customer.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <style>
    /* Minimal modal styles (reused from booking page) */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.6);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .modal-overlay[aria-hidden="false"] {
      display: flex;
    }

    .modal-card {
      background: var(--c-bg-alt);
      border: 1px solid var(--c-border-soft);
      border-radius: var(--radius);
      box-shadow: var(--shadow-elev);
      color: var(--c-text);
      padding: 1rem 1.1rem 1.1rem;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      margin-bottom: .6rem;
    }

    .modal-title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: .4px;
    }

    .modal-body {
      font-size: .8rem;
      color: var(--c-text-soft);
    }

    .modal-summary {
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: .45rem .75rem;
      margin: .6rem 0 .2rem;
    }

    .modal-actions {
      display: flex;
      gap: .6rem;
      justify-content: flex-end;
      margin-top: .9rem;
    }

    .btn-ghost {
      background: var(--c-surface);
      color: var(--c-text-soft);
      border: 1px solid var(--c-border);
      padding: 0.5rem 0.85rem;
      border-radius: var(--radius-sm);
      font-weight: 600;
      letter-spacing: .4px;
      cursor: pointer;
    }

    .btn-ghost:hover {
      color: var(--c-text);
      border-color: var(--c-accent-alt);
    }
  </style>
</head>

<body class="dashboard-wrapper">
  <header class="header-bar">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg" />
    <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
    <div class="header-brand">
      <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
      <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
    </div>
    <nav id="customerNav" class="nav-links">
      <a class="active" href="dashboard.php">Dashboard</a>
      <a href="search.php">Find Shops</a>
      <a href="bookings_history.php">History</a>
      <a href="profile.php">Profile</a>
      <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
        <button type="submit" class="logout-btn">Logout</button>
      </form>
    </nav>
  </header>
  <main class="dashboard-main">
    <section class="card" style="padding:1.3rem 1.4rem 1.55rem;">
      <div class="card-header" style="margin-bottom:1rem;">
        <h1 style="font-size:1.55rem;">Your Dashboard</h1>
      </div>
      <p style="margin:.15rem 0 0;font-size:.9rem;color:var(--c-text-soft);max-width:760px;line-height:1.5;">Track appointments, explore new barbershops, and manage your account in one place.</p>
      <div class="quick-actions">
        <a class="btn btn-primary" href="search.php"><i class="bi bi-search" aria-hidden="true"></i> <span>Search Shops</span></a>
        <a class="btn" href="bookings_history.php"><i class="bi bi-clock-history" aria-hidden="true"></i> <span>History</span></a>
        <a class="btn" href="profile.php"><i class="bi bi-person-gear" aria-hidden="true"></i> <span>Edit Profile</span></a>
      </div>
      <div class="profile-bar">
        <div class="progress-wrap">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span class="metric-label" style="font-size:.6rem;">PROFILE COMPLETENESS</span>
            <span style="font-size:.65rem;color:var(--c-text-soft);font-weight:600;"><?= $profilePercent ?>%</span>
          </div>
          <div class="progress-track">
            <div class="progress-fill" style="width:<?= $profilePercent ?>%"></div>
          </div>
        </div>
      </div>
    </section>

    <div class="grid-stats">
      <div class="card">
        <div class="metric-label"><i class="bi bi-calendar3" aria-hidden="true"></i> <span>Total Appointments</span></div>
        <div class="metric-value"><?= $counts['total'] ?></div>
      </div>
      <div class="card">
        <div class="metric-label"><i class="bi bi-calendar-event" aria-hidden="true"></i> <span>Upcoming</span></div>
        <div class="metric-value"><?= $counts['upcoming'] ?></div>
      </div>
      <div class="card">
        <div class="metric-label"><i class="bi bi-check2-circle" aria-hidden="true"></i> <span>Completed</span></div>
        <div class="metric-value"><?= $counts['completed'] ?></div>
      </div>
      <div class="card">
        <div class="metric-label"><i class="bi bi-x-circle" aria-hidden="true"></i> <span>Cancelled</span></div>
        <div class="metric-value"><?= $counts['cancelled'] ?></div>
      </div>
      <div class="card">
        <div class="metric-label"><i class="bi bi-star-fill" aria-hidden="true"></i> <span>Reviews</span></div>
        <div class="metric-value"><?= $counts['reviews'] ?></div>
      </div>
    </div>

    <section class="flex gap" style="flex-wrap:wrap; align-items:stretch;">
      <div class="card" style="flex:1 1 340px; min-width:300px;">
        <div class="card-header">
          <h2><i class="bi bi-calendar-event" aria-hidden="true"></i> <span>Upcoming Appointment</span></h2>
        </div>
        <?php if ($upcoming): ?>
          <div style="font-size:.85rem; line-height:1.4;">
            <div><strong><?= e($upcoming['shop_name']) ?></strong></div>
            <div class="small-muted" style="margin:.2rem 0 .4rem;">Service: <?= e($upcoming['service_name']) ?></div>
            <div style="font-size:.75rem;">Date: <?= e(fmt_dt($upcoming['appointment_date'])) ?></div>
            <div style="margin-top:.55rem;">
              <span class="status-chip <?= status_chip_class($upcoming['status']) ?>"><?= strtoupper(e($upcoming['status'])) ?></span>
              <button type="button" class="btn js-view-appt" data-appt-id="<?= (int)$upcoming['appointment_id'] ?>" style="margin-left:.5rem;"><i class="bi bi-eye" aria-hidden="true"></i> <span>View</span></button>
            </div>
          </div>
        <?php else: ?>
          <p class="small-muted" style="margin:0;">No upcoming bookings.</p>
        <?php endif; ?>
      </div>
      <div class="card" style="flex:1 1 340px; min-width:300px;">
        <div class="card-header">
          <h2><i class="bi bi-stars" aria-hidden="true"></i> <span>Suggestions</span></h2>
        </div>
        <?php if ($suggestions): ?>
          <div class="suggestions-grid">
            <?php foreach ($suggestions as $sg): ?>
              <div class="shop-suggestion">
                <div style="font-size:.8rem;font-weight:600;letter-spacing:.3px;" title="<?= e($sg['shop_name']) ?>"><?= e($sg['shop_name']) ?></div>
                <div class="small-muted" style="font-size:.65rem;"><?= e($sg['city'] ?: 'Batangas') ?></div>
                <div style="font-size:.65rem;margin-top:.3rem;">⭐ <?= number_format((float)$sg['avg_rating'], 1) ?> <span class="small-muted">(<?= (int)$sg['reviews'] ?>)</span></div>
                <a href="shop_details.php?id=<?= (int)$sg['shop_id'] ?>" class="btn" style="margin-top:.4rem; font-size:.65rem; padding:.45rem .6rem;"><i class="bi bi-eye" aria-hidden="true"></i> <span>View</span></a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="small-muted" style="margin:0;">You’ve explored most top shops. More coming soon.</p>
        <?php endif; ?>
      </div>
    </section>

    <hr class="hr-soft" />
    <section class="flex gap mt" style="flex-wrap:wrap; align-items:flex-start;">
      <div class="card" style="flex:2 1 460px; min-width:320px;">
        <div class="card-header">
          <h2><i class="bi bi-clock-history" aria-hidden="true"></i> <span>Recent Bookings</span></h2>
        </div>
        <?php if ($recentBookings): ?>
          <ul class="list">
            <?php foreach ($recentBookings as $rb): ?>
              <li class="list-item">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;">
                  <span style="font-weight:600;font-size:.8rem;"><?= e($rb['shop_name']) ?></span>
                  <span class="status-chip <?= status_chip_class($rb['status']) ?>" style="flex-shrink:0;"><?= strtoupper(e($rb['status'])) ?></span>
                </div>
                <div class="small-muted">Service: <?= e($rb['service_name']) ?></div>
                <div class="small-muted">Date: <?= e(fmt_dt($rb['appointment_date'])) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="small-muted" style="margin:0;">No bookings yet. Start by <a href="search.php" style="color:var(--c-accent);text-decoration:none;">finding a shop</a>.</p>
        <?php endif; ?>
      </div>
      <div class="card" style="flex:1 1 340px; min-width:300px;">
        <div class="card-header">
          <h2><i class="bi bi-star" aria-hidden="true"></i> <span>Your Reviews</span></h2>
        </div>
        <?php if ($recentReviews): ?>
          <ul class="list">
            <?php foreach ($recentReviews as $rev): ?>
              <li class="list-item">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;">
                  <span style="font-weight:600;font-size:.75rem;"><?= e($rev['shop_name']) ?></span>
                  <span class="badge-gradient">⭐ <?= (int)$rev['rating'] ?></span>
                </div>
                <?php if (trim($rev['comment']) !== ''): ?>
                  <div class="small-muted" style="font-size:.65rem;line-height:1.35;"><?= e(mb_strimwidth($rev['comment'], 0, 110, '…')) ?></div>
                <?php endif; ?>
                <div class="small-muted" style="font-size:.6rem; margin-top:.2rem;"><?= e(date('M d, Y', strtotime($rev['created_at']))) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="small-muted" style="margin:0;">You haven’t left a review yet.</p>
        <?php endif; ?>
      </div>
    </section>

  </main>
  <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • All rights reserved.</footer>
  <script src="../assets/js/menu-toggle.js"></script>
  <div id="appt-modal" class="modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="appt-title">
    <div class="modal-card" style="width:min(560px,92vw);">
      <div class="modal-header">
        <h3 id="appt-title" class="modal-title">Appointment Details</h3>
      </div>
      <div class="modal-body">
        <div class="modal-summary">
          <div><strong>Shop</strong></div>
          <div id="ad-shop">—</div>
          <div><strong>Service</strong></div>
          <div id="ad-service">—</div>
          <div><strong>Date & Time</strong></div>
          <div id="ad-dt">—</div>
          <div><strong>Status</strong></div>
          <div id="ad-status">—</div>
          <div><strong>Payment</strong></div>
          <div id="ad-pay">—</div>
          <div><strong>Notes</strong></div>
          <div id="ad-notes">—</div>
        </div>
        <div id="ad-error" class="small-muted" style="color:#ef4444;display:none;margin-top:.5rem;">Failed to load details.</div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost js-appt-close">Close</button>
      </div>
    </div>
  </div>
  <script>
    (function() {
      const modal = document.getElementById('appt-modal');
      const closeBtns = modal?.querySelectorAll('.js-appt-close');

      function open() {
        modal?.setAttribute('aria-hidden', 'false');
      }

      function close() {
        modal?.setAttribute('aria-hidden', 'true');
      }
      closeBtns?.forEach(b => b.addEventListener('click', close));
      modal?.addEventListener('click', (e) => {
        if (e.target === modal) close();
      });

      function set(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
      }

      function fmt(dt) {
        try {
          const d = new Date(dt);
          if (!isNaN(d)) return d.toLocaleString();
        } catch {}
        return dt;
      }

      async function fetchDetails(id) {
        set('ad-error', '');
        const err = document.getElementById('ad-error');
        if (err) err.style.display = 'none';
        set('ad-shop', '—');
        set('ad-service', '—');
        set('ad-dt', '—');
        set('ad-status', '—');
        set('ad-pay', '—');
        set('ad-notes', '—');
        try {
          const res = await fetch(`../api/appointment_details.php?id=${encodeURIComponent(id)}`, {
            credentials: 'same-origin'
          });
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          const data = await res.json();
          if (!data.ok) {
            throw new Error(data.error || 'Failed');
          }
          const a = data.appointment;
          set('ad-shop', `${a.shop.shop_name}${a.shop.city ? ' • '+a.shop.city : ''}`);
          set('ad-service', `${a.service.service_name} • ₱${Number(a.service.price).toFixed(2)} • ${a.service.duration_minutes} mins`);
          set('ad-dt', fmt(a.appointment_date));
          set('ad-status', String(a.status).toUpperCase());
          set('ad-pay', a.payment_option === 'online' ? 'Online' : 'Cash');
          set('ad-notes', a.notes && a.notes.trim() !== '' ? a.notes : '—');
        } catch (e) {
          const errEl = document.getElementById('ad-error');
          if (errEl) {
            errEl.style.display = '';
            errEl.textContent = 'Failed to load details.';
          }
        }
      }

      document.querySelectorAll('.js-view-appt').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-appt-id');
          if (!id) return;
          open();
          fetchDetails(id);
        });
      });
    })();
  </script>
</body>

</html>