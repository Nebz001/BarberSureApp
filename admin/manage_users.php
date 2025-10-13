<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;

// Detect optional columns (backwards compatibility if migration not yet applied)
$hasSuspend = true;
try {
    $pdo->query("SELECT is_suspended FROM Users LIMIT 1");
} catch (Throwable $e) {
    $hasSuspend = false;
}
$hasSoftDelete = true;
try {
    $pdo->query("SELECT deleted_at FROM Users LIMIT 1");
} catch (Throwable $e) {
    $hasSoftDelete = false;
}

// Action handling
$actionMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $actionMsg = ['type' => 'danger', 'text' => 'Invalid CSRF token'];
    } else {
        $act = $_POST['action'] ?? '';
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0 && $act) {
            try {
                if ($act === 'suspend') {
                    if ($hasSuspend) {
                        $pdo->prepare("UPDATE Users SET is_suspended=1 WHERE user_id=? AND role!='admin'")->execute([$uid]);
                        $actionMsg = ['type' => 'warning', 'text' => 'User suspended'];
                        if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'suspend', 'user', $uid);
                    } else {
                        $actionMsg = ['type' => 'danger', 'text' => 'Suspension feature not available (run DB migration to add is_suspended column).'];
                    }
                } elseif ($act === 'activate') {
                    if ($hasSuspend) {
                        $pdo->prepare("UPDATE Users SET is_suspended=0 WHERE user_id=?")->execute([$uid]);
                        $actionMsg = ['type' => 'success', 'text' => 'User reactivated'];
                        if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'activate', 'user', $uid);
                    } else {
                        $actionMsg = ['type' => 'danger', 'text' => 'Activation not available until migration applied.'];
                    }
                } elseif ($act === 'delete') {
                    try {
                        if ($hasSoftDelete) {
                            $pdo->prepare("UPDATE Users SET deleted_at=NOW() WHERE user_id=? AND role!='admin'")->execute([$uid]);
                            $actionMsg = ['type' => 'danger', 'text' => 'User marked deleted'];
                            if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'soft_delete', 'user', $uid);
                        } else {
                            throw new Exception('no soft delete');
                        }
                    } catch (Throwable $e) {
                        $pdo->prepare("DELETE FROM Users WHERE user_id=? AND role!='admin'")->execute([$uid]);
                        $actionMsg = ['type' => 'danger', 'text' => 'User removed'];
                        if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'hard_delete', 'user', $uid);
                    }
                } elseif ($act === 'resetpw') {
                    if (!function_exists('generate_temp_password')) require_once __DIR__ . '/../config/functions.php';
                    $temp = generate_temp_password(12);
                    $hash = password_hash($temp, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE Users SET password_hash=? WHERE user_id=?")->execute([$hash, $uid]);
                    $actionMsg = ['type' => 'info', 'text' => 'Temporary password: ' . $temp];
                    if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'reset_password', 'user', $uid, ['temp_len' => strlen($temp)]);
                } elseif ($act === 'verify_owner') {
                    // Evaluate prerequisites before verifying
                    if (function_exists('evaluate_owner_verification')) {
                        $eval = evaluate_owner_verification($uid);
                        if (!$eval['ready']) {
                            $readableMissing = function_exists('describe_verification_missing') ? describe_verification_missing($eval['missing']) : $eval['missing'];
                            $actionMsg = ['type' => 'danger', 'text' => 'Cannot verify yet. Missing: ' . implode(', ', $readableMissing)];
                        } else {
                            $pdo->prepare("UPDATE Users SET is_verified=1 WHERE user_id=? AND role='owner'")->execute([$uid]);
                            $actionMsg = ['type' => 'success', 'text' => 'Owner verified'];
                            if (function_exists('log_admin_action')) log_admin_action(current_user()['user_id'] ?? null, 'verify_owner', 'user', $uid);
                        }
                    } else {
                        $actionMsg = ['type' => 'danger', 'text' => 'Verification function not available'];
                    }
                }
            } catch (Throwable $e) {
                $actionMsg = ['type' => 'danger', 'text' => 'Action failed'];
            }
        }
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$params = [];
$where = [];
if ($q !== '') {
    $where[] = '(full_name LIKE :q OR email LIKE :q)';
    $params[':q'] = "%$q%";
}
if ($roleFilter !== '' && in_array($roleFilter, ['customer', 'owner', 'admin'], true)) {
    $where[] = 'role = :role';
    $params[':role'] = $roleFilter;
}
// soft delete filtering
if ($hasSoftDelete) {
    $where[] = 'deleted_at IS NULL';
}

// suspension expression fallback
$suspExpr = $hasSuspend ? '(CASE WHEN COALESCE(is_suspended,0)=1 THEN 1 ELSE 0 END) AS is_suspended' : '0 AS is_suspended';

$sql = 'SELECT user_id, full_name, email, role, is_verified, ' . $suspExpr . ', created_at FROM Users';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC LIMIT 250';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = 'Manage Users • Admin';
include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">User Management</h1>
                <div class="text-muted small">Search, filter, suspend, reset passwords & verify owners.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="manage_users.php">Refresh</a>
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
                        <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Name or email">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach (['customer', 'owner', 'admin'] as $r): ?>
                                <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5 col-md-3 d-flex gap-2">
                        <button class="btn btn-secondary btn-sm flex-grow-1">Filter</button>
                        <a href="manage_users.php" class="btn btn-outline-secondary btn-sm flex-grow-1">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mb-4 user-table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Users</h5>
                <span class="badge rounded-pill bg-secondary bg-opacity-25 text-light small"><?= count($users) ?> shown</span>
            </div>
            <div class="table-responsive position-relative" style="max-height:520px;">
                <table class="table table-sm table-dark-mode align-middle mb-0 user-table">
                    <thead>
                        <tr>
                            <th style="width:48px;">User</th>
                            <th style="width:150px;">Name</th>
                            <th style="width:200px;">Email</th>
                            <th style="width:70px;">Role</th>
                            <th style="width:70px;">Owner ✔</th>
                            <th style="width:80px;">Status</th>
                            <th style="width:80px;">Created</th>
                            <th style="width:55px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No users found</td>
                            </tr>
                            <?php else: foreach ($users as $u):
                                $initials = strtoupper(substr(trim($u['full_name']), 0, 1));
                                $badgeColor = function_exists('role_badge_class') ? role_badge_class($u['role']) : ($u['role'] === 'admin' ? 'primary' : ($u['role'] === 'owner' ? 'info' : 'secondary'));
                                $statusHtml = $u['is_suspended'] ? '<span class="badge bg-danger bg-opacity-75">Suspended</span>' : '<span class="badge bg-success bg-opacity-75">Active</span>';
                            ?>
                                <tr class="user-row<?= $u['is_suspended'] ? ' row-suspended' : '' ?>">
                                    <td>
                                        <div class="avatar-sm rounded-circle d-flex align-items-center justify-content-center fw-semibold text-uppercase bg-<?php echo $badgeColor; ?> bg-opacity-25 text-<?php echo $badgeColor; ?>">
                                            <?= e($initials) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold small mb-0 lh-sm truncate" title="<?= e($u['full_name']) ?>"><?= e($u['full_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-muted xsmall truncate" title="<?= e($u['email']) ?>" style="font-size:.675rem;letter-spacing:.3px;"><?= e($u['email']) ?></div>
                                    </td>
                                    <td><span class="badge rounded-pill bg-<?php echo $badgeColor; ?> bg-opacity-75"><?= e($u['role']) ?></span></td>
                                    <td><?= $u['role'] === 'owner' ? ($u['is_verified'] ? '<span class="text-success small fw-semibold">Yes</span>' : '<span class="text-warning small fw-semibold">No</span>') : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= $statusHtml ?></td>
                                    <td class="text-muted small"><?= e(function_exists('format_date_short') ? format_date_short($u['created_at']) : date('y-m-d', strtotime($u['created_at']))) ?></td>
                                    <td class="text-end">
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle px-1 py-1" data-bs-toggle="dropdown" aria-expanded="false"></button>
                                                <div class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-sm small py-1">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <input type="hidden" name="action" value="<?= $u['is_suspended'] ? 'activate' : 'suspend' ?>">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-<?php echo $u['is_suspended'] ? 'play-circle' : 'pause-circle'; ?>"></i> <?= $u['is_suspended'] ? 'Activate' : 'Suspend' ?></button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Reset password for this user?');">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <input type="hidden" name="action" value="resetpw">
                                                        <button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="bi bi-key"></i> Reset Password</button>
                                                    </form>
                                                    <?php if ($u['role'] === 'owner' && !$u['is_verified']): ?>
                                                        <?php $eval = evaluate_owner_verification((int)$u['user_id']);
                                                        $canVerify = $eval['ready'];
                                                        $missingList = $canVerify ? '' : 'Missing: ' . implode(', ', $eval['missing']); ?>
                                                        <form method="post" class="d-inline" onsubmit="return <?= $canVerify ? "confirm('All prerequisites satisfied. Verify this owner?')" : "(alert('Cannot verify. $missingList'); false)"; ?>;">
                                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                            <input type="hidden" name="action" value="verify_owner">
                                                            <button class="dropdown-item d-flex align-items-center gap-2 <?= $canVerify ? '' : 'disabled opacity-75' ?>" type="submit" <?= $canVerify ? '' : 'tabindex="-1" aria-disabled="true"' ?> title="<?= e($canVerify ? 'Ready for verification' : $missingList) ?>">
                                                                <i class="bi bi-check2-circle"></i> Verify Owner
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <div class="dropdown-divider my-1"></div>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this user? This may be irreversible.');">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button class="dropdown-item text-danger d-flex align-items-center gap-2" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
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
<style>
    /* User management dark enhancements */
    .user-table-card {
        background: #1f2530;
        border: 1px solid #2c3442;
    }

    .user-table-card .card-header {
        background: linear-gradient(90deg, #242b36, #1f2530);
        border-bottom: 1px solid #2c3442;
    }

    .user-table thead th {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #273040;
        font-size: .75rem;
        /* enlarged */
        text-transform: uppercase;
        letter-spacing: .55px;
        font-weight: 600;
        color: #ffffff;
        /* force white */
        border-bottom: 1px solid #323d4c;
        padding-top: .55rem;
        padding-bottom: .55rem;
    }

    .user-table tbody tr {
        transition: background .18s ease, color .18s ease;
        background: transparent !important;
        /* transparent row background */
    }

    .user-table tbody tr:hover {
        background: #2b3442 !important;
    }

    .user-table tbody tr.row-suspended {
        opacity: .75;
    }

    .user-table tbody td {
        border-top: 1px solid #273040;
        background: transparent !important;
        color: #ffffff !important;
    }

    .truncate {
        max-width: 100%;
        display: inline-block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: bottom;
    }

    /* Slightly narrower name/email on very wide tables to free space for right columns */
    @media (min-width: 1400px) {
        .user-table thead th:nth-child(2) {
            /* Name */
            width: 140px !important;
        }

        .user-table thead th:nth-child(3) {
            /* Email */
            width: 180px !important;
        }
    }

    /* Compact specific columns (Role -> Actions) */
    .user-table td:nth-child(4),
    .user-table td:nth-child(5),
    .user-table td:nth-child(6),
    .user-table td:nth-child(7),
    .user-table td:nth-child(8),
    .user-table thead th:nth-child(4),
    .user-table thead th:nth-child(5),
    .user-table thead th:nth-child(6),
    .user-table thead th:nth-child(7),
    .user-table thead th:nth-child(8) {
        padding-left: .4rem !important;
        padding-right: .4rem !important;
    }

    .user-table tbody td .text-muted,
    .user-table tbody td .xsmall,
    .user-table tbody td small {
        color: #ffffff !important;
        opacity: 0.9;
    }

    .user-table .avatar-sm {
        width: 38px;
        height: 38px;
        font-size: .85rem;
    }

    .user-table .dropdown-menu-dark {
        --bs-dropdown-bg: #1e2430;
        --bs-dropdown-link-hover-bg: #293140;
        z-index: 1200;
    }

    .user-table .dropdown-item {
        padding: .4rem .75rem;
    }

    .user-table .dropdown-item i {
        font-size: .9rem;
    }

    .user-table .badge {
        font-weight: 500;
    }

    .user-table .badge.bg-success {
        background: #059669 !important;
    }

    .user-table .badge.bg-danger {
        background: #dc2626 !important;
    }

    .user-table .badge.bg-primary {
        background: #3b82f6 !important;
    }

    .user-table .badge.bg-info {
        background: #374151 !important;
    }

    .user-table .badge.bg-secondary {
        background: #6b7280 !important;
    }

    .xsmall {
        font-size: .6rem;
    }

    /* Force badge text white (role & status) */
    .user-table .badge {
        color: #ffffff !important;
    }

    /* Ensure specific colored badges remain white text */
    .user-table .badge.bg-success,
    .user-table .badge.bg-danger,
    .user-table .badge.bg-primary,
    .user-table .badge.bg-info,
    .user-table .badge.bg-secondary {
        color: #ffffff !important;
    }

    /* Search input placeholder: softer semi-white */
    form input::placeholder {
        color: rgba(255, 255, 255, 0.55) !important;
        opacity: 1;
    }

    @media (max-width: 991.98px) {
        .user-table-card .table-responsive {
            max-height: unset;
        }

        .user-table thead th {
            position: static;
        }
    }
</style>
<script>
    // Floating action dropdown portal so menu hovers over list without altering row height / scroll container
    (function() {
        const active = {
            menu: null,
            originalParent: null,
            placeholder: null,
            toggle: null
        };

        function closeActive() {
            if (!active.menu) return;
            // Restore menu to original parent (for bootstrap to clean up) or remove clone
            if (active.placeholder && active.originalParent) {
                active.originalParent.replaceChild(active.menu, active.placeholder);
            }
            active.menu.classList.remove('floating-users-menu');
            active.menu.style.position = '';
            active.menu.style.top = '';
            active.menu.style.left = '';
            active.menu.style.width = '';
            active.menu.style.height = '';
            active.menu.style.zIndex = '';
            active.menu.style.maxHeight = '';
            active.menu.style.overflowY = '';
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
            if (!dd.matches('.user-table .dropdown')) return; // only our table
            // Let bootstrap position first (next tick after 'show')
            setTimeout(() => {
                const btn = dd.querySelector('[data-bs-toggle="dropdown"]');
                const menu = dd.querySelector('.dropdown-menu');
                if (!btn || !menu) return;

                // If already floating another, close
                closeActive();

                const rectBtn = btn.getBoundingClientRect();
                // Insert placeholder so original flow height unaffected
                const placeholder = document.createElement('div');
                placeholder.style.display = 'none';
                menu.parentNode.replaceChild(placeholder, menu);
                active.placeholder = placeholder;
                active.originalParent = dd;
                active.menu = menu;
                active.toggle = btn;

                // Apply floating styles
                menu.classList.add('floating-users-menu');
                menu.style.position = 'fixed';
                menu.style.top = (rectBtn.bottom + 4) + 'px';
                // Align right edge with button right
                const menuWidth = Math.max(menu.offsetWidth, 170);
                let left = rectBtn.right - menuWidth;
                const viewportWidth = document.documentElement.clientWidth;
                if (left < 8) left = 8; // keep some padding
                if (left + menuWidth > viewportWidth - 8) left = viewportWidth - menuWidth - 8;
                menu.style.left = left + 'px';
                menu.style.width = menuWidth + 'px';
                menu.style.zIndex = 5000;
                // Constrain height if too tall
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
                // Bootstrap triggered hide (e.g., toggle clicked again)
                closeActive();
            }
        });
    })();
</script>