<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;

// CSRF token
$csrf = csrf_token();

// Handle actions (approve, reject, deactivate, reactivate, update)
$actionMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $actionMsg = ['type' => 'danger', 'text' => 'Invalid CSRF token'];
    } else {
        $act = $_POST['action'] ?? '';
        $shopId = (int)($_POST['shop_id'] ?? 0);
        try {
            if ($shopId > 0 && $act) {
                if ($act === 'approve') {
                    $pdo->prepare("UPDATE Barbershops SET status='approved' WHERE shop_id=? AND status='pending'")->execute([$shopId]);
                    $actionMsg = ['type' => 'success', 'text' => 'Shop approved'];
                } elseif ($act === 'reject') {
                    $reason = trim($_POST['reason'] ?? '');
                    $pdo->prepare("UPDATE Barbershops SET status='rejected' WHERE shop_id=? AND status='pending'")->execute([$shopId]);
                    $actionMsg = ['type' => 'warning', 'text' => 'Shop rejected' . ($reason ? ' • ' . e($reason) : '')];
                } elseif ($act === 'deactivate') {
                    // Use status pending->rejected? better add separate flag later; for now mark rejected
                    $pdo->prepare("UPDATE Barbershops SET status='rejected' WHERE shop_id=? AND status='approved'")->execute([$shopId]);
                    $actionMsg = ['type' => 'danger', 'text' => 'Shop deactivated'];
                } elseif ($act === 'reactivate') {
                    $pdo->prepare("UPDATE Barbershops SET status='approved' WHERE shop_id=? AND status='rejected'")->execute([$shopId]);
                    $actionMsg = ['type' => 'success', 'text' => 'Shop reactivated'];
                } elseif ($act === 'update_details') {
                    $name = trim($_POST['shop_name'] ?? '');
                    $city = trim($_POST['city'] ?? '');
                    $address = trim($_POST['address'] ?? '');
                    $desc = trim($_POST['description'] ?? '');
                    if ($name !== '') {
                        $stmt = $pdo->prepare("UPDATE Barbershops SET shop_name=?, city=?, address=?, description=? WHERE shop_id=?");
                        $stmt->execute([$name, $city, $address, $desc, $shopId]);
                        $actionMsg = ['type' => 'info', 'text' => 'Shop details updated'];
                    } else {
                        $actionMsg = ['type' => 'danger', 'text' => 'Name required'];
                    }
                }
            }
        } catch (Throwable $e) {
            $actionMsg = ['type' => 'danger', 'text' => 'Action failed'];
        }
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(b.shop_name LIKE :q OR u.full_name LIKE :q)';
    $params[':q'] = "%$q%";
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $where[] = 'b.status = :status';
    $params[':status'] = $statusFilter;
}

// Latest subscription/payment status (LEFT JOIN subquery with most recent paid/pending subscription)
$sql = "SELECT b.shop_id, b.shop_name, b.owner_id, b.status, b.registered_at, b.city, b.address, b.description,
               u.full_name AS owner_name,
               (SELECT payment_status FROM Shop_Subscriptions s WHERE s.shop_id=b.shop_id ORDER BY s.valid_to DESC LIMIT 1) AS sub_status,
               (SELECT valid_to FROM Shop_Subscriptions s2 WHERE s2.shop_id=b.shop_id AND s2.payment_status='paid' ORDER BY s2.valid_to DESC LIMIT 1) AS sub_valid_to
        FROM Barbershops b
        JOIN Users u ON b.owner_id=u.user_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.registered_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$shops = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = 'Manage Shops • Admin';
include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Barbershop Management</h1>
                <div class="text-muted small">Approve, edit, and moderate shops.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="manage_shops.php">Refresh</a>
            </div>
        </div>
        <?php if ($actionMsg): ?>
            <div class="alert alert-<?= e($actionMsg['type']) ?> py-2 small mb-4"><?= e($actionMsg['text']) ?></div>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end small" method="get">
                    <div class="col-sm-4 col-md-3">
                        <label class="form-label text-muted small mb-1">Search</label>
                        <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Name or owner">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach (['pending', 'approved', 'rejected'] as $s): ?>
                                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5 col-md-3 d-flex gap-2">
                        <button class="btn btn-secondary btn-sm flex-grow-1">Filter</button>
                        <a href="manage_shops.php" class="btn btn-outline-secondary btn-sm flex-grow-1">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mb-4 shop-table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Shops</h5>
                <span class="badge rounded-pill bg-secondary bg-opacity-25 text-light small"><?= count($shops) ?> shown</span>
            </div>
            <div class="table-responsive position-relative" style="max-height:520px;">
                <table class="table table-sm table-dark-mode align-middle mb-0 shop-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th>Name & Location</th>
                            <th style="width:150px;">Owner</th>
                            <th style="width:105px;">Status</th>
                            <th style="width:125px;">Subscription</th>
                            <th style="width:90px;">Registered</th>
                            <th style="width:60px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$shops): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No shops found</td>
                            </tr>
                            <?php else: foreach ($shops as $shop):
                                $statusBadge = $shop['status'] === 'approved' ? 'success' : ($shop['status'] === 'pending' ? 'warning' : 'danger');
                                $subStatus = $shop['sub_status'] ?? null;
                                $subBadge = 'secondary';
                                $subLabel = 'None';
                                if ($subStatus === 'paid') {
                                    $subBadge = 'success';
                                    $subLabel = 'Active';
                                } elseif ($subStatus === 'pending') {
                                    $subBadge = 'warning';
                                    $subLabel = 'Pending';
                                } elseif ($subStatus === 'expired') {
                                    $subBadge = 'danger';
                                    $subLabel = 'Expired';
                                }
                                $validTo = $shop['sub_valid_to'] ? date('y-m-d', strtotime($shop['sub_valid_to'])) : null;
                            ?>
                                <tr>
                                    <td class="text-muted small">#<?= (int)$shop['shop_id'] ?></td>
                                    <td>
                                        <div class="fw-semibold small mb-1"><?= e($shop['shop_name']) ?></div>
                                        <div class="text-muted xsmall" style="font-size:.65rem;letter-spacing:.3px;"><?= e($shop['city'] ?: '—') ?> • <?= e($shop['address'] ? (strlen($shop['address']) > 36 ? substr($shop['address'], 0, 33) . '…' : $shop['address']) : 'No address') ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-light"><?= e($shop['owner_name']) ?></div>
                                    </td>
                                    <td><span class="badge rounded-pill bg-<?= $statusBadge ?> bg-opacity-75 text-uppercase" style="font-size:.6rem;letter-spacing:.5px;"><?= e($shop['status']) ?></span></td>
                                    <td>
                                        <span class="badge bg-<?= $subBadge ?> bg-opacity-75" style="font-size:.6rem;"><?= $subLabel ?><?= $validTo ? '<span class="ms-1">' . $validTo . '</span>' : '' ?></span>
                                    </td>
                                    <td class="text-muted small"><?= e(date('y-m-d', strtotime($shop['registered_at']))) ?></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle px-2 py-1" data-bs-toggle="dropdown" aria-expanded="false"></button>
                                            <div class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-sm small py-1">
                                                <?php if ($shop['status'] === 'pending'): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Approve this shop?');">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="shop_id" value="<?= (int)$shop['shop_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-check2-circle"></i> Approve</button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Reject this shop?');">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="shop_id" value="<?= (int)$shop['shop_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="reason" value="Manual rejection">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-x-circle"></i> Reject</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($shop['status'] === 'approved'): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this shop?');">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="shop_id" value="<?= (int)$shop['shop_id'] ?>">
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                                    </form>
                                                <?php elseif ($shop['status'] === 'rejected'): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Reactivate (approve) this shop?');">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="shop_id" value="<?= (int)$shop['shop_id'] ?>">
                                                        <input type="hidden" name="action" value="reactivate">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                                <a class="dropdown-item d-flex align-items-center gap-2" href="shop_details.php?id=<?= (int)$shop['shop_id'] ?>"><i class="bi bi-eye"></i> View Details</a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<!-- Edit Modal -->
<div class="modal fade" id="editShopModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark text-light">
            <form method="post" class="needs-validation" novalidate>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="shop_id" id="editShopId">
                <input type="hidden" name="action" value="update_details">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Edit Shop Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Name</label>
                            <input type="text" class="form-control form-control-sm bg-transparent text-light" name="shop_name" id="editShopName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">City</label>
                            <input type="text" class="form-control form-control-sm bg-transparent text-light" name="city" id="editShopCity">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">Address</label>
                            <input type="text" class="form-control form-control-sm bg-transparent text-light" name="address" id="editShopAddress">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">Description</label>
                            <textarea class="form-control form-control-sm bg-transparent text-light" name="description" id="editShopDescription" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<style>
    .shop-table-card {
        background: #1f2530;
        border: 1px solid #2c3442;
    }

    .shop-table-card .card-header {
        background: linear-gradient(90deg, #242b36, #1f2530);
        border-bottom: 1px solid #2c3442;
    }

    .shop-table thead th {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #273040;
        font-size: .7rem;
        text-transform: uppercase;
        letter-spacing: .55px;
        font-weight: 600;
        color: #fff;
        border-bottom: 1px solid #323d4c;
        padding-top: .55rem;
        padding-bottom: .55rem;
    }

    .shop-table tbody td {
        border-top: 1px solid #273040;
        background: transparent !important;
        color: #fff !important;
    }

    .shop-table tbody tr:hover {
        background: #2b3442 !important;
    }

    .shop-table .badge {
        font-weight: 500;
    }

    .shop-table .badge.bg-success {
        background: #059669 !important;
    }

    .shop-table .badge.bg-danger {
        background: #dc2626 !important;
    }

    .shop-table .badge.bg-warning {
        background: #d97706 !important;
        /* keep warning amber to convey pending */
    }

    .shop-table .badge.bg-secondary {
        background: #6b7280 !important;
    }

    .xsmall {
        font-size: .6rem;
    }

    .modal-content {
        border: 1px solid #2c3442;
    }

    .modal-content .form-control,
    .modal-content textarea {
        border: 1px solid #374151;
    }

    .modal-content .form-control:focus,
    .modal-content textarea:focus {
        border-color: #475569;
        box-shadow: none;
    }
</style>
<script>
    const editModal = document.getElementById('editShopModal');
    editModal?.addEventListener('show.bs.modal', ev => {
        const btn = ev.relatedTarget;
        if (!btn) return;
        try {
            const data = JSON.parse(btn.getAttribute('data-shop'));
            document.getElementById('editShopId').value = data.shop_id;
            document.getElementById('editShopName').value = data.shop_name || '';
            document.getElementById('editShopCity').value = data.city || '';
            document.getElementById('editShopAddress').value = data.address || '';
            document.getElementById('editShopDescription').value = data.description || '';
        } catch (e) {
            console.warn('Parse shop data failed');
        }
    });
    // Floating action dropdown (portal) so menu hovers over scroll area without shifting table
    (function() {
        const active = {
            menu: null,
            originalParent: null,
            placeholder: null,
            toggle: null
        };

        function closeActive() {
            if (!active.menu) return;
            if (active.placeholder && active.originalParent) {
                active.originalParent.replaceChild(active.menu, active.placeholder);
            }
            active.menu.classList.remove('floating-shops-menu');
            active.menu.removeAttribute('style');
            active.menu = null;
            active.originalParent = null;
            active.placeholder = null;
            active.toggle = null;
            document.removeEventListener('click', outsideHandler, true);
            window.removeEventListener('resize', closeActive, true);
            window.removeEventListener('scroll', closeActive, true);
        }

        function outsideHandler(e) {
            if (active.menu && !active.menu.contains(e.target) && !active.toggle.contains(e.target)) {
                closeActive();
            }
        }

        document.addEventListener('show.bs.dropdown', function(ev) {
            const dd = ev.target;
            if (!dd.matches('.shop-table .dropdown')) return;
            setTimeout(() => {
                const btn = dd.querySelector('[data-bs-toggle="dropdown"]');
                const menu = dd.querySelector('.dropdown-menu');
                if (!btn || !menu) return;
                closeActive();
                const rectBtn = btn.getBoundingClientRect();
                const placeholder = document.createElement('div');
                placeholder.style.display = 'none';
                menu.parentNode.replaceChild(placeholder, menu);
                active.placeholder = placeholder;
                active.originalParent = dd;
                active.menu = menu;
                active.toggle = btn;
                const menuWidth = Math.max(menu.offsetWidth, 170);
                let left = rectBtn.right - menuWidth;
                const vw = document.documentElement.clientWidth;
                if (left < 8) left = 8;
                if (left + menuWidth > vw - 8) left = vw - menuWidth - 8;
                menu.style.position = 'fixed';
                menu.style.top = (rectBtn.bottom + 4) + 'px';
                menu.style.left = left + 'px';
                menu.style.width = menuWidth + 'px';
                menu.style.zIndex = 5000;
                if (menu.scrollHeight > 320) {
                    menu.style.maxHeight = '320px';
                    menu.style.overflowY = 'auto';
                }
                document.body.appendChild(menu);
                document.addEventListener('click', outsideHandler, true);
                window.addEventListener('resize', closeActive, true);
                window.addEventListener('scroll', closeActive, true);
            }, 0);
        });

        document.addEventListener('hide.bs.dropdown', function(ev) {
            const dd = ev.target;
            if (active.originalParent && dd === active.originalParent) {
                closeActive();
            }
        });
    })();
</script>