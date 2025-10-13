<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_login();
if (!has_role('customer')) redirect('../login.php');
$user = current_user();
$uid = (int)($user['user_id'] ?? 0);

// Cancellation handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $cancel_error = 'Invalid session token.';
    } else {
        $cid = (int)$_POST['cancel_id'];
        $chk = $pdo->prepare("SELECT appointment_id,status,appointment_date FROM Appointments WHERE appointment_id=? AND customer_id=? LIMIT 1");
        $chk->execute([$cid, $uid]);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            $apptTime = strtotime($row['appointment_date']);
            if (!in_array($row['status'], ['pending', 'confirmed'])) {
                $cancel_error = 'This appointment cannot be cancelled.';
            } elseif ($apptTime < time() - 300) {
                $cancel_error = 'Past appointments cannot be cancelled.';
            } else {
                $upd = $pdo->prepare("UPDATE Appointments SET status='cancelled' WHERE appointment_id=?");
                if ($upd->execute([$cid])) $cancel_success = 'Appointment cancelled.';
                else $cancel_error = 'Unable to cancel appointment.';
            }
        } else $cancel_error = 'Appointment not found.';
    }
}

function in_get($k, $d = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}
$status = in_get('status');
$range = in_get('range', 'all');
$q = in_get('q');
$page = max(1, (int)in_get('page', 1));
$perPage = 10;
$sort = in_get('sort', 'date_desc');

$where = ['a.customer_id = :uid'];
$params = [':uid' => $uid];
if ($status !== '' && in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'])) {
    $where[] = 'a.status = :status';
    $params[':status'] = $status;
}
$now = date('Y-m-d H:i:s');
if ($range === 'upcoming') {
    $where[] = 'a.appointment_date >= :now';
    $params[':now'] = $now;
} elseif ($range === 'past') {
    $where[] = 'a.appointment_date < :now';
    $params[':now'] = $now;
} elseif ($range === 'last30') {
    $where[] = 'a.appointment_date >= :d30';
    $params[':d30'] = date('Y-m-d H:i:s', time() - 86400 * 30);
}
if ($q !== '') {
    $where[] = '(b.shop_name LIKE :kw OR s.service_name LIKE :kw)';
    $params[':kw'] = "%$q%";
}
$whereSql = implode(' AND ', $where);
switch ($sort) {
    case 'date_asc':
        $order = 'a.appointment_date ASC';
        break;
    case 'shop':
        $order = 'b.shop_name ASC, a.appointment_date DESC';
        break;
    case 'status':
        $order = 'a.status ASC, a.appointment_date DESC';
        break;
    default:
        $order = 'a.appointment_date DESC';
        $sort = 'date_desc';
}

// export before pagination
if (isset($_GET['export']) && $_GET['export'] == '1') {
    $csvSql = "SELECT a.appointment_id,a.appointment_date,a.status,a.payment_option,a.is_paid,b.shop_name,b.city,s.service_name,s.duration_minutes,s.price,a.notes FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id JOIN Services s ON a.service_id=s.service_id WHERE $whereSql ORDER BY $order";
    $csvStmt = $pdo->prepare($csvSql);
    foreach ($params as $k => $v) $csvStmt->bindValue($k, $v);
    $csvStmt->execute();
    $all = $csvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="booking_history_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Status', 'Payment', 'Paid', 'Shop', 'City', 'Service', 'Duration', 'Price', 'Notes']);
    foreach ($all as $r) {
        fputcsv($out, [$r['appointment_id'], $r['appointment_date'], $r['status'], $r['payment_option'], $r['is_paid'] ? 'yes' : 'no', $r['shop_name'], $r['city'], $r['service_name'], $r['duration_minutes'], number_format((float)$r['price'], 2), preg_replace('/\s+/', ' ', trim((string)$r['notes']))]);
    }
    fclose($out);
    exit;
}

$cSql = "SELECT COUNT(*) FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id JOIN Services s ON a.service_id=s.service_id WHERE $whereSql";
$cStmt = $pdo->prepare($cSql);
foreach ($params as $k => $v) $cStmt->bindValue($k, $v);
$cStmt->execute();
$total = (int)$cStmt->fetchColumn();
$maxPage = $total ? (int)ceil($total / $perPage) : 1;
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

$sql = "SELECT a.appointment_id,a.appointment_date,a.status,a.payment_option,a.notes,a.is_paid,b.shop_name,b.city,s.service_name,s.duration_minutes,s.price,r.rating AS review_rating,r.comment AS review_comment FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id JOIN Services s ON a.service_id=s.service_id LEFT JOIN Reviews r ON r.appointment_id=a.appointment_id AND r.customer_id=:uid WHERE $whereSql ORDER BY $order LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
function page_link_hist($p)
{
    $qs = $_GET;
    $qs['page'] = $p;
    return 'bookings_history.php?' . http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Booking History • BarberSure</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/customer.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        .history-header {
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin-bottom: 1.1rem;
        }

        .history-header h1 {
            font-size: 1.55rem;
            margin: 0;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .empty {
            padding: 2.3rem 1rem;
            text-align: center;
            font-size: .8rem;
            color: var(--c-text-soft);
        }

        .hist-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin-top: .2rem;
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
            <a href="search.php">Find Shops</a>
            <a class="active" href="bookings_history.php">History</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="dashboard-main">
        <section class="card" style="padding:1.25rem 1.35rem 1.5rem;margin-bottom:1.55rem;">
            <div class="history-header" style="margin-bottom:.95rem;">
                <h1><i class="bi bi-journal-text" aria-hidden="true"></i> <span>Your Booking History</span></h1>
                <p style="font-size:.78rem;color:var(--c-text-soft);max-width:760px;line-height:1.55;margin:.35rem 0 0;">Review past and upcoming appointments. Filter by status, timeframe, or search by shop and service name.</p>
            </div>
            <form method="get" class="filters" action="bookings_history.php" autocomplete="off">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search shop or service" />
                <select name="status">
                    <option value="">Any Status</option>
                    <?php foreach (['pending', 'confirmed', 'cancelled', 'completed'] as $st): ?>
                        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="range">
                    <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>All Dates</option>
                    <option value="upcoming" <?= $range === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="past" <?= $range === 'past' ? 'selected' : '' ?>>Past</option>
                    <option value="last30" <?= $range === 'last30' ? 'selected' : '' ?>>Last 30 Days</option>
                </select>
                <select name="sort">
                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Newest First</option>
                    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="shop" <?= $sort === 'shop' ? 'selected' : '' ?>>Shop Name</option>
                    <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                </select>
                <div style="display:flex;gap:.5rem;">
                    <button class="btn btn-primary btn-small" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> <span>Apply</span></button>
                    <a href="bookings_history.php" class="btn btn-small" style="background:var(--c-surface);"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> <span>Reset</span></a>
                    <a href="<?= e(page_link_hist(1) . (strpos(page_link_hist(1), '?') !== false ? '&' : '?')) ?>export=1" class="btn btn-small" style="background:var(--c-surface);"><i class="bi bi-filetype-csv" aria-hidden="true"></i> <span>Export CSV</span></a>
                </div>
            </form>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.8rem;align-items:center;font-size:.55rem;letter-spacing:.5px;color:var(--c-text-soft);">
                <span style="font-weight:600;opacity:.75;">Legend:</span>
                <span class="badge-status st-pending">PENDING</span>
                <span class="badge-status st-confirmed">CONFIRMED</span>
                <span class="badge-status st-cancelled">CANCELLED</span>
                <span class="badge-status st-completed">COMPLETED</span>
            </div>
            <?php if (isset($cancel_error)): ?><div class="alert-inline error"><?= e($cancel_error) ?></div><?php endif; ?>
            <?php if (isset($cancel_success)): ?><div class="alert-inline success"><?= e($cancel_success) ?></div><?php endif; ?>
            <?php if (!$rows): ?>
                <div class="empty" style="margin-top:1.2rem;">
                    <?php if ($q !== '' || $status !== '' || $range !== 'all'): ?>No appointments match your filters.<?php else: ?>No appointments yet. <a href="booking.php" class="link" style="color:var(--c-accent-alt);text-decoration:none;">Book your first one</a>.<?php endif; ?>
                </div>
            <?php else: ?>
                <div class="hist-list" aria-label="Appointment list">
                    <?php foreach ($rows as $r): $dt = strtotime($r['appointment_date']);
                        $dateFmt = date('M d, Y g:i A', $dt);
                        $statusClass = 'st-' . $r['status']; ?>
                        <div class="hist-item" data-appt="<?= (int)$r['appointment_id'] ?>">
                            <div class="hist-top">
                                <h3 class="hist-shop" style="margin:0;"><?= e($r['shop_name']) ?></h3>
                                <span class="badge-status <?= e($statusClass) ?>"><?= strtoupper(e($r['status'])) ?></span>
                                <span class="badge-status" style="background:var(--c-accent-alt);color:#fff;"><?= e($r['payment_option'] === 'online' ? 'ONLINE' : 'CASH') ?></span>
                                <?php if ($r['is_paid']): ?><span class="badge-status" style="background:#062e2b;color:#6ee7b7;border:1px solid #0d4b45;">PAID</span><?php endif; ?>
                            </div>
                            <div class="meta">
                                <span><?= e($dateFmt) ?></span>
                                <span><?= e($r['city'] ?: '—') ?></span>
                                <span><?= (int)$r['duration_minutes'] ?> mins</span>
                                <span><?= e($r['service_name']) ?></span>
                            </div>
                            <div class="svc-line"><span>Service Price</span><span class="price-pill">₱<?= number_format((float)$r['price'], 2) ?></span></div>
                            <?php $hasNotes = (bool)$r['notes'];
                            if ($hasNotes): ?><div class="notes"><?= e(strlen($r['notes']) > 160 ? substr($r['notes'], 0, 158) . '…' : $r['notes']) ?></div><?php endif; ?>
                            <div class="hist-actions" style="margin-top:.25rem;">
                                <button type="button" class="details-toggle" data-target="det-<?= (int)$r['appointment_id'] ?>">Details</button>
                                <?php
                                // Per-booking chat channel (appointment context) — allows discussion tied to this appointment
                                $bookingChannel = 'bk_' . (int)$r['appointment_id'] . '_' . substr(hash('sha256', session_id() . '|appt|' . (int)$r['appointment_id']), 0, 16);
                                ?>
                                <button type="button" class="booking-chat-open" data-channel="<?= e($bookingChannel) ?>" data-appt="<?= (int)$r['appointment_id'] ?>" style="background:var(--c-surface);border:1px solid var(--c-border);color:var(--c-text-soft);font-size:.55rem;padding:.35rem .6rem;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;letter-spacing:.45px;">Chat</button>
                                <?php $isFuture = $dt >= time() - 300;
                                $canCancel = $isFuture && in_array($r['status'], ['pending', 'confirmed']);
                                if ($canCancel): ?>
                                    <form method="post" onsubmit="return confirm('Cancel this appointment?');" style="margin:0;">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                        <input type="hidden" name="cancel_id" value="<?= (int)$r['appointment_id'] ?>" />
                                        <button type="submit" class="btn-cancel">Cancel</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'completed' && (int)($r['review_rating'] ?? 0) === 0): ?>
                                    <button type="button" class="btn rate-btn" data-appt="<?= (int)$r['appointment_id'] ?>" data-shop="<?= e($r['shop_name']) ?>" style="font-size:.55rem;padding:.45rem .6rem;background:#0ea5e9;color:#fff;border-color:#0ea5e9;">
                                        <i class="bi bi-star"></i> Rate
                                    </button>
                                <?php elseif ((int)($r['review_rating'] ?? 0) > 0): ?>
                                    <span class="badge-status" title="Your rating" style="background:#fbbf24;color:#111;">★ <?= (int)$r['review_rating'] ?>/5</span>
                                <?php endif; ?>
                            </div>
                            <div id="det-<?= (int)$r['appointment_id'] ?>" class="hist-details">
                                <table>
                                    <tr>
                                        <td>Appointment ID</td>
                                        <td><?= (int)$r['appointment_id'] ?></td>
                                    </tr>
                                    <tr>
                                        <td>Date Raw</td>
                                        <td><?= e($r['appointment_date']) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Status</td>
                                        <td><?= e($r['status']) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Payment</td>
                                        <td><?= e($r['payment_option']) ?><?= $r['is_paid'] ? ' (paid)' : '' ?></td>
                                    </tr>
                                    <tr>
                                        <td>Shop</td>
                                        <td><?= e($r['shop_name']) ?><?= $r['city'] ? ' • ' . e($r['city']) : '' ?></td>
                                    </tr>
                                    <tr>
                                        <td>Service</td>
                                        <td><?= e($r['service_name']) ?> (<?= (int)$r['duration_minutes'] ?> mins)</td>
                                    </tr>
                                    <tr>
                                        <td>Price</td>
                                        <td>₱<?= number_format((float)$r['price'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Notes</td>
                                        <td><?= $hasNotes ? e($r['notes']) : '—' ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="booking-chat-box" id="chat-box-<?= (int)$r['appointment_id'] ?>" data-loaded="0" style="display:none;margin-top:.5rem;border:1px solid var(--c-border);background:var(--c-surface);border-radius:var(--radius-sm);padding:.55rem .9rem;">
                                <div class="chat-lines" style="display:flex;flex-direction:column;gap:.45rem;max-height:200px;overflow-y:auto;font-size:.58rem;line-height:1.4;"></div>
                                <form class="chat-send" style="margin-top:.45rem;display:flex;gap:.4rem;align-items:flex-start;">
                                    <textarea rows="2" placeholder="Message..." style="flex:1;background:var(--c-bg-alt);border:1px solid var(--c-border-soft);color:var(--c-text);border-radius:var(--radius-sm);padding:.4rem .5rem;font-size:.6rem;resize:vertical;min-height:54px;max-height:120px;"></textarea>
                                    <button type="submit" class="btn" style="font-size:.55rem;padding:.5rem .7rem;">Send</button>
                                </form>
                                <div style="font-size:.5rem;color:var(--c-text-soft);margin-top:.3rem;">Contextual chat • Not stored in database • Auto-clears</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($total > $perPage): $rangePages = 2;
                $start = max(1, $page - $rangePages);
                $end = min($maxPage, $page + $rangePages); ?>
                <div class="pagination" aria-label="Pagination">
                    <?php if ($start > 1): ?><a href="<?= e(page_link_hist(1)) ?>">1</a><?php if ($start > 2): ?><span>…</span><?php endif; ?><?php endif; ?>
                            <?php for ($p = $start; $p <= $end; $p++): if ($p === $page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="<?= e(page_link_hist($p)) ?>"><?= $p ?></a><?php endif;
                                                                                                                                                                                            endfor; ?>
                            <?php if ($end < $maxPage): if ($end < $maxPage - 1): ?><span>…</span><?php endif; ?><a href="<?= e(page_link_hist($maxPage)) ?>"><?= $maxPage ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • Track your grooming.</footer>
    <!-- Rate Modal -->
    <div id="rateModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:center;justify-content:center;z-index:1000;">
        <div class="modal-card" role="dialog" aria-labelledby="rateTitle" style="background:var(--c-bg-alt);border:1px solid var(--c-border);border-radius:10px;min-width:320px;max-width:92vw;padding:1rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
                <h3 id="rateTitle" style="margin:0;font-size:1rem;font-weight:700;">Rate your visit</h3>
                <button type="button" id="rateClose" class="btn" style="background:var(--c-surface);">Close</button>
            </div>
            <div id="rateShop" style="font-size:.8rem;color:var(--c-text-soft);margin-bottom:.6rem;"></div>
            <form id="rateForm" style="display:flex;flex-direction:column;gap:.6rem;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <input type="hidden" name="appointment_id" value="" />
                <div>
                    <label style="display:block;font-size:.75rem;margin-bottom:.3rem;">Rating</label>
                    <div id="stars" style="display:flex;gap:.35rem;font-size:1.2rem;cursor:pointer;">
                        <span data-val="1">☆</span><span data-val="2">☆</span><span data-val="3">☆</span><span data-val="4">☆</span><span data-val="5">☆</span>
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;margin-bottom:.3rem;">Comment (optional)</label>
                    <textarea name="comment" rows="4" placeholder="Share your experience" style="width:100%;box-sizing:border-box;background:var(--c-surface);border:1px solid var(--c-border);color:var(--c-text);border-radius:8px;padding:.55rem;font-size:.78rem;"></textarea>
                </div>
                <div id="rateError" style="display:none;color:#ef4444;font-size:.75rem;"></div>
                <button type="submit" class="btn btn-primary">Submit Review</button>
            </form>
        </div>
    </div>
    <script>
        document.querySelectorAll('.details-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-target');
                const panel = document.getElementById(id);
                if (!panel) return;
                const open = panel.style.display === 'block';
                panel.style.display = open ? 'none' : 'block';
                btn.textContent = open ? 'Details' : 'Hide';
            });
        });
        // Rating modal logic
        (function() {
            const modal = document.getElementById('rateModal');
            const closeBtn = document.getElementById('rateClose');
            const form = document.getElementById('rateForm');
            const stars = document.getElementById('stars');
            const shopEl = document.getElementById('rateShop');
            const errEl = document.getElementById('rateError');
            let rating = 0;

            function openModal(apptId, shopName) {
                form.appointment_id.value = String(apptId);
                form.comment.value = '';
                rating = 0;
                paintStars(0);
                shopEl.textContent = shopName || '';
                errEl.style.display = 'none';
                errEl.textContent = '';
                modal.style.display = 'flex';
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            function paintStars(n) {
                Array.from(stars.children).forEach((el, i) => {
                    el.textContent = (i < n) ? '★' : '☆';
                });
            }
            stars.addEventListener('click', (e) => {
                const t = e.target.closest('span');
                if (!t) return;
                rating = parseInt(t.getAttribute('data-val') || '0', 10) || 0;
                paintStars(rating);
            });
            closeBtn.addEventListener('click', closeModal);
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeModal();
            });
            document.body.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            document.querySelectorAll('.rate-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const appt = btn.getAttribute('data-appt');
                    const shop = btn.getAttribute('data-shop');
                    openModal(appt, `Rating for ${shop}`);
                });
            });

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                errEl.style.display = 'none';
                errEl.textContent = '';
                if (!rating || rating < 1 || rating > 5) {
                    errEl.textContent = 'Please select a rating from 1 to 5.';
                    errEl.style.display = 'block';
                    return;
                }
                const fd = new FormData(form);
                fd.append('rating', String(rating));
                fetch('../api/review_submit.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json()).then(j => {
                        if (!j || !j.ok) {
                            throw new Error(j && j.error ? j.error : 'Failed to submit review');
                        }
                        // Update the row badges/UI without full reload
                        const apptId = form.appointment_id.value;
                        const itemTop = document.querySelector(`.hist-item[data-appt="${CSS.escape(apptId)}"] .hist-top`);
                        if (itemTop) {
                            const badge = document.createElement('span');
                            badge.className = 'badge-status';
                            badge.style.background = '#fbbf24';
                            badge.style.color = '#111';
                            badge.textContent = `★ ${rating}/5`;
                            itemTop.appendChild(badge);
                        }
                        const rateBtn = document.querySelector(`.hist-item[data-appt="${CSS.escape(apptId)}"] .rate-btn`);
                        if (rateBtn) rateBtn.remove();
                        closeModal();
                    })
                    .catch(err => {
                        errEl.textContent = err.message || 'Something went wrong.';
                        errEl.style.display = 'block';
                    });
            });
        })();
    </script>
    <script src="../assets/js/booking_thread_chat.js"></script>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>