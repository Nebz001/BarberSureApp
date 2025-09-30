<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

// Status update handling
$statusMessage = null;
$statusError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $statusError = 'Invalid session token.';
    } else {
        $action = $_POST['action'] ?? '';
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);

        if ($action && $appointmentId) {
            // Verify this appointment belongs to one of this owner's shops and check verification status
            $checkStmt = $pdo->prepare("
                SELECT a.appointment_id, a.status, b.shop_name, b.status as shop_status, u.is_verified 
                FROM Appointments a 
                JOIN Barbershops b ON a.shop_id = b.shop_id 
                JOIN Users u ON b.owner_id = u.user_id
                WHERE a.appointment_id = ? AND b.owner_id = ? 
                LIMIT 1
            ");
            $checkStmt->execute([$appointmentId, $ownerId]);
            $appointment = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($appointment) {
                // Check if owner is verified and shop is approved
                if (!$appointment['is_verified']) {
                    $statusError = "Account verification required to manage appointments.";
                } elseif ($appointment['shop_status'] !== 'approved') {
                    $statusError = "Shop must be approved to manage appointments.";
                } else {
                    $newStatus = null;
                    if ($action === 'confirm' && $appointment['status'] === 'pending') {
                        $newStatus = 'confirmed';
                    } elseif ($action === 'complete' && $appointment['status'] === 'confirmed') {
                        $newStatus = 'completed';
                    } elseif ($action === 'cancel' && in_array($appointment['status'], ['pending', 'confirmed'])) {
                        $newStatus = 'cancelled';
                    }

                    if ($newStatus) {
                        $updateStmt = $pdo->prepare("UPDATE Appointments SET status = ? WHERE appointment_id = ?");
                        if ($updateStmt->execute([$newStatus, $appointmentId])) {
                            $statusMessage = "Appointment " . ucfirst($newStatus) . " successfully.";
                        } else {
                            $statusError = "Failed to update appointment status.";
                        }
                    } else {
                        $statusError = "Invalid status transition.";
                    }
                }
            } else {
                $statusError = "Appointment not found or not authorized.";
            }
        }
    }
}

// Pagination and filtering
function in_get($k, $d = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$shopFilter = (int)in_get('shop', 0);
$statusFilter = in_get('status');
$rangeFilter = in_get('range', 'all');
$searchQuery = in_get('q');
$page = max(1, (int)in_get('page', 1));
$perPage = 15;

// Get owner's shops for filter dropdown
$ownerShops = $pdo->prepare("SELECT shop_id, shop_name FROM Barbershops WHERE owner_id = ? ORDER BY shop_name ASC");
$ownerShops->execute([$ownerId]);
$shops = $ownerShops->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Build query for appointments
$where = ['b.owner_id = :owner_id'];
$params = [':owner_id' => $ownerId];

if ($shopFilter) {
    $where[] = 'a.shop_id = :shop_id';
    $params[':shop_id'] = $shopFilter;
}

if ($statusFilter && in_array($statusFilter, ['pending', 'confirmed', 'cancelled', 'completed'])) {
    $where[] = 'a.status = :status';
    $params[':status'] = $statusFilter;
}

$now = date('Y-m-d H:i:s');
if ($rangeFilter === 'upcoming') {
    $where[] = 'a.appointment_date >= :now';
    $params[':now'] = $now;
} elseif ($rangeFilter === 'past') {
    $where[] = 'a.appointment_date < :now';
    $params[':now'] = $now;
} elseif ($rangeFilter === 'today') {
    $where[] = 'DATE(a.appointment_date) = CURDATE()';
}

if ($searchQuery) {
    $where[] = '(u.full_name LIKE :search OR s.service_name LIKE :search OR a.notes LIKE :search)';
    $params[':search'] = "%$searchQuery%";
}

$whereClause = implode(' AND ', $where);

// Count total appointments
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM Appointments a
    JOIN Barbershops b ON a.shop_id = b.shop_id
    JOIN Users u ON a.customer_id = u.user_id
    JOIN Services s ON a.service_id = s.service_id
    WHERE $whereClause
");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalAppointments = (int)$countStmt->fetchColumn();

$maxPage = $totalAppointments ? (int)ceil($totalAppointments / $perPage) : 1;
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

// Fetch appointments with all related data including verification status
$appointmentsStmt = $pdo->prepare("
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.status,
        a.payment_option,
        a.notes,
        a.created_at,
        u.full_name as customer_name,
        u.phone as customer_phone,
        u.email as customer_email,
        b.shop_name,
        b.status as shop_status,
        s.service_name,
        s.duration_minutes,
        s.price,
        owner.is_verified as owner_verified
    FROM Appointments a
    JOIN Barbershops b ON a.shop_id = b.shop_id
    JOIN Users u ON a.customer_id = u.user_id
    JOIN Users owner ON b.owner_id = owner.user_id
    JOIN Services s ON a.service_id = s.service_id
    WHERE $whereClause
    ORDER BY a.appointment_date DESC, a.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $appointmentsStmt->bindValue($key, $value);
}
$appointmentsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$appointmentsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$appointmentsStmt->execute();
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Helper functions
function status_badge_class($status)
{
    $classes = [
        'pending' => 'st-pending',
        'confirmed' => 'st-confirmed',
        'cancelled' => 'st-cancelled',
        'completed' => 'st-completed'
    ];
    return $classes[$status] ?? 'st-pending';
}

function format_appointment_time($datetime)
{
    $timestamp = strtotime($datetime);
    if (!$timestamp) return $datetime;
    return date('M j, Y g:i A', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Manage Bookings • Owner • BarberSure</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
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

        .appointments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .62rem;
        }

        .appointments-table th,
        .appointments-table td {
            padding: .6rem .7rem;
            text-align: left;
            border-bottom: 1px solid #1f2b36;
        }

        .appointments-table th {
            font-weight: 600;
            font-size: .58rem;
            letter-spacing: .5px;
            color: #93adc7;
            text-transform: uppercase;
        }

        .badge-status-pending {
            background: #b453091a;
            color: #f59e0b;
            border: 1px solid #f59e0b33;
        }

        .badge-status-confirmed {
            background: #1e3a8a1a;
            color: #3b82f6;
            border: 1px solid #3b82f633;
        }

        .badge-status-completed {
            background: #0599681a;
            color: #10b981;
            border: 1px solid #10b98133;
        }

        .badge-status-cancelled {
            background: #dc26261a;
            color: #ef4444;
            border: 1px solid #ef444433;
        }

        .action-buttons {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            font-size: .55rem;
            padding: .35rem .6rem;
        }

        .btn-confirm {
            background: #059669;
            color: white;
            border: 1px solid #059669;
        }

        .btn-confirm:hover {
            background: #047857;
        }

        .btn-complete {
            background: #f59e0b;
            color: #1f2937;
            border: 1px solid #f59e0b;
        }

        .btn-complete:hover {
            background: #d97706;
        }

        .btn-cancel {
            background: transparent;
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .btn-cancel:hover {
            background: #ef4444;
            color: white;
        }

        .filters-section {
            background: var(--o-bg-alt);
            border: 1px solid var(--o-border-soft);
            border-radius: var(--o-radius);
            padding: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .mini-note {
            font-size: .55rem;
            color: #69839b;
            margin: .2rem 0 .4rem;
        }

        .empty-message {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--o-text-soft);
            font-size: .75rem;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: .5rem;
            margin-top: 2rem;
            padding: 1rem 0;
        }

        .pagination-wrapper a,
        .pagination-wrapper span {
            padding: .4rem .7rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: .7rem;
            font-weight: 600;
            border: 1px solid var(--o-border);
            background: var(--o-surface);
            color: var(--o-text-soft);
        }

        .pagination-wrapper a:hover {
            background: var(--o-accent);
            color: white;
            border-color: var(--o-accent);
        }

        .pagination-wrapper .current {
            background: var(--o-accent);
            color: white;
            border-color: var(--o-accent);
        }
    </style>
    <!-- Removed Bootstrap CSS to prevent overriding dark theme card backgrounds -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Hard override in case any residual global styles linger */
        .card {
            background: var(--o-bg-alt) !important;
            border: 1px solid var(--o-border-soft) !important;
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
            <a class="active" href="bookings.php">Bookings</a>
            <a href="payments.php">Payments</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="owner-main">
        <!-- Hero Card for page title (consistent with other owner pages) -->
        <section class="card" style="padding:1.15rem 1.2rem 1.25rem;margin-bottom:1.4rem;display:flex;flex-direction:column;gap:.55rem;">
            <h1 style="margin:0;font-size:1.55rem;font-weight:600;letter-spacing:.45px;">Manage Bookings</h1>
            <p style="margin:0;font-size:.75rem;line-height:1.55;color:var(--o-text-soft);max-width:820px;">Review, filter and update customer appointments across your shops. Use the filters below to narrow results and quickly act on pending or confirmed bookings.</p>
        </section>

        <div class="toast-stack" aria-live="polite" aria-atomic="true" style="position:relative;min-height:0;">
            <?php if ($statusError): ?>
                <div class="toast t-error" role="alert">
                    <div class="t-body"><?= e($statusError) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss">×</button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <section class="card" style="margin-bottom:1.4rem;display:flex;flex-direction:column;gap:.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                <h2 style="margin:0;font-size:1.05rem;font-weight:600;letter-spacing:.4px;">Filters & Search</h2>
                <span style="font-size:.55rem;letter-spacing:.5px;color:var(--o-text-soft);">Refine the appointments list</span>
            </div>
            <form method="get" action="bookings.php">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
                    <label>Shop
                        <select name="shop">
                            <option value="">All Shops</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?= (int)$shop['shop_id'] ?>" <?= $shopFilter === (int)$shop['shop_id'] ? 'selected' : '' ?>>
                                    <?= e($shop['shop_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Status
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </label>

                    <label>Time Range
                        <select name="range">
                            <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="today" <?= $rangeFilter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="upcoming" <?= $rangeFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="past" <?= $rangeFilter === 'past' ? 'selected' : '' ?>>Past</option>
                        </select>
                    </label>

                    <label>Search
                        <input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Customer, service..." />
                    </label>
                </div>
                <div style="margin-top:.2rem;display:flex;gap:.55rem;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="bookings.php" class="btn">Reset</a>
                </div>
            </form>
        </section>

        <section class="card" style="display:flex;flex-direction:column;gap:.95rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.8rem;">
                <h2 style="margin:0;font-size:1.05rem;font-weight:600;">Appointments <span style="font-size:.6rem;color:var(--o-text-soft);">(<?= $totalAppointments ?>)</span></h2>
                <?php if ($totalAppointments > $perPage): ?>
                    <span style="font-size:.6rem;color:var(--o-text-soft);">Page <?= $page ?> of <?= $maxPage ?></span>
                <?php endif; ?>
            </div>
            <div style="overflow:auto;">
                <table class="appointments-table" aria-label="Appointments table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Shop</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                            <th style="width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$appointments): ?>
                            <tr>
                                <td colspan="6" style="padding:.9rem .6rem;color:#6e859c;font-size:.6rem;text-align:center;">No appointments found with current filters. <a href="bookings.php" style="color:#f59e0b;">View all</a></td>
                            </tr>
                            <?php else: foreach ($appointments as $appt): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;"><?= e($appt['customer_name']) ?></div>
                                        <div style="font-size:.55rem;color:#6b8299;"><?= e($appt['customer_phone'] ?: 'No phone') ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($appt['service_name']) ?></div>
                                        <div style="font-size:.55rem;color:#6b8299;">
                                            <?= (int)$appt['duration_minutes'] ?>min • ₱<?= number_format($appt['price'], 2) ?>
                                        </div>
                                    </td>
                                    <td><?= e($appt['shop_name']) ?></td>
                                    <td>
                                        <div><?= date('M j, Y', strtotime($appt['appointment_date'])) ?></div>
                                        <div style="font-size:.55rem;color:#6b8299;"><?= date('g:i A', strtotime($appt['appointment_date'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?= $appt['status'] ?>"><?= strtoupper($appt['status']) ?></span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php
                                            // Check if owner and shop are verified
                                            $canManageAppointment = $appt['owner_verified'] && $appt['shop_status'] === 'approved';

                                            if (!$canManageAppointment): ?>
                                                <div class="verification-warning" style="color: #d4a148; font-size: 0.8rem;">
                                                    <?php if (!$appt['owner_verified']): ?>
                                                        Owner not verified
                                                    <?php elseif ($appt['shop_status'] !== 'approved'): ?>
                                                        Shop not approved
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($appt['status'] === 'pending'): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$appt['appointment_id'] ?>" />
                                                    <button type="submit" name="action" value="confirm" class="btn btn-confirm">Confirm</button>
                                                </form>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$appt['appointment_id'] ?>" />
                                                    <button type="submit" name="action" value="cancel" class="btn btn-cancel">Cancel</button>
                                                </form>
                                            <?php elseif ($appt['status'] === 'confirmed'): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$appt['appointment_id'] ?>" />
                                                    <button type="submit" name="action" value="complete" class="btn btn-complete">Complete</button>
                                                </form>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
                                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$appt['appointment_id'] ?>" />
                                                    <button type="submit" name="action" value="cancel" class="btn btn-cancel">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Pagination -->
        <?php if ($maxPage > 1): ?>
            <nav class="pagination" style="margin-top:2rem;">
                <?php
                $baseUrl = 'bookings.php?' . http_build_query([
                    'shop' => $shopFilter ?: '',
                    'status' => $statusFilter,
                    'range' => $rangeFilter,
                    'q' => $searchQuery
                ]);

                if ($page > 1): ?>
                    <a href="<?= e($baseUrl . '&page=' . ($page - 1)) ?>" class="page-link">‹ Prev</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($maxPage, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="page-link active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e($baseUrl . '&page=' . $i) ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $maxPage): ?>
                    <a href="<?= e($baseUrl . '&page=' . ($page + 1)) ?>" class="page-link">Next ›</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>

    <script>
        // Toast auto-hide functionality
        document.querySelectorAll('.toast').forEach(toast => {
            const closeBtn = toast.querySelector('.toast-close');
            const duration = parseInt(toast.getAttribute('data-duration') || '0');

            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    toast.style.display = 'none';
                });
            }

            if (duration > 0) {
                setTimeout(() => {
                    toast.style.display = 'none';
                }, duration);
            }
        });
    </script>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>