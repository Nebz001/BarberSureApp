<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;
// Flash message (PRG)
$flash = null;
if (isset($_SESSION['flash_notifications'])) {
    $flash = $_SESSION['flash_notifications'];
    unset($_SESSION['flash_notifications']);
}
// Handle create broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_broadcast'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $_SESSION['flash_notifications'] = ['type' => 'danger', 'text' => 'Invalid CSRF token'];
        header('Location: notifications.php');
        exit;
    } else {
        $titleB = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $audience = $_POST['audience'] ?? 'all';
        if ($titleB && $message) {
            // Fetch target users by audience
            $roleCond = '';
            if ($audience === 'owners') $roleCond = "WHERE role='owner'";
            elseif ($audience === 'customers') $roleCond = "WHERE role='customer'";
            // Build SQL ensuring only users with a non-empty email are selected
            $sql = "SELECT user_id, email, full_name FROM Users ";
            if ($roleCond) {
                $sql .= $roleCond . " AND email IS NOT NULL AND email <> '' ";
            } else {
                $sql .= "WHERE email IS NOT NULL AND email <> '' ";
            }
            $sql .= "ORDER BY user_id ASC";
            $q = $pdo->query($sql);
            $targets = $q->fetchAll(PDO::FETCH_ASSOC);
            $sent = 0;
            $fail = 0;
            $failures = [];
            require_once __DIR__ . '/../config/mailer.php';
            foreach ($targets as $user) {
                $to = $user['email'];
                $subject = $titleB;
                $html = nl2br(e($message));
                $text = $message;
                $result = send_app_email($to, $subject, $html, $text);
                if ($result['sent']) {
                    $sent++;
                } else {
                    $fail++;
                    $failures[] = $to;
                }
            }
            $_SESSION['flash_notifications'] = [
                'type' => ($fail ? 'warning' : 'success'),
                'text' => "Notification sent to $sent user(s)" . ($fail ? ", $fail failed." : ".")
            ];
            if ($fail) $_SESSION['flash_notifications']['failures'] = $failures;
            header('Location: notifications.php?sent=1');
            exit;
        } else {
            $_SESSION['flash_notifications'] = ['type' => 'warning', 'text' => 'Title and message required'];
            header('Location: notifications.php?missing=1');
            exit;
        }
    }
}
// Fetch broadcasts list
$broadcasts = [];
try {
    $broadcasts = $pdo->query("SELECT * FROM Notification_Broadcasts ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$title = 'Notifications • Admin';
include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Notification Center</h1>
                <div class="text-muted small">Broadcast announcements & monitor delivery logs.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="#compose" onclick="document.getElementById('composeCard').scrollIntoView({behavior:'smooth'});return false;">Compose</a>
            </div>
        </div>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> py-2 small mb-4"><?= e($flash['text']) ?></div><?php endif; ?>
        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div id="composeCard" class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Compose Broadcast</h6>
                    </div>
                    <div class="card-body small">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="create_broadcast" value="1">
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Audience</label>
                                <select name="audience" class="form-select form-select-sm">
                                    <option value="all">All Users</option>
                                    <option value="owners">Owners</option>
                                    <option value="customers">Customers</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Channel</label>
                                <div class="form-text xsmall">All broadcasts are sent via Email.</div>
                                <input type="hidden" name="channels[]" value="email">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Title</label>
                                <input type="text" name="title" class="form-control form-control-sm" maxlength="150" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Message</label>
                                <textarea name="message" class="form-control form-control-sm" rows="5" required></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Schedule (optional)</label>
                                <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm">
                                <div class="form-text xsmall">Leave blank to send immediately.</div>
                            </div>
                            <!-- Optional links removed -->
                            <button class="btn btn-sm btn-primary">Create Broadcast</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Recent Broadcasts</h6>
                    </div>
                    <div class="card-body small">
                        <div class="table-responsive small" style="max-height:480px;">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Audience</th>
                                        <th>Channels</th>
                                        <th>Status</th>
                                        <th>Sched</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$broadcasts): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">None yet</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($broadcasts as $b): ?>
                                        <tr>
                                            <td class="xsmall text-muted">#<?= (int)$b['broadcast_id'] ?></td>
                                            <td style="max-width:180px;" class="xsmall"><?= e($b['title']) ?></td>
                                            <td class="xsmall text-muted"><?= e($b['audience']) ?></td>
                                            <td class="xsmall text-muted"><?= e($b['channels']) ?></td>
                                            <td class="xsmall"><?php
                                                                $st = $b['status'];
                                                                $badge = 'secondary';
                                                                if ($st === 'sending') $badge = 'warning';
                                                                elseif ($st === 'completed') $badge = 'success';
                                                                elseif ($st === 'failed') $badge = 'danger';
                                                                echo '<span class="badge bg-' . $badge . ' bg-opacity-75">' . e($st) . '</span>';
                                                                ?></td>
                                            <td class="xsmall text-muted"><?= e($b['scheduled_at'] ? substr($b['scheduled_at'], 0, 16) : '-') ?></td>
                                            <td class="xsmall text-muted"><?= (int)$b['sent_count'] . '/' . ((int)$b['total_targets'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted xsmall mb-0 mt-3">Processor script will dispatch queued items. Failures logged. Refresh to update counts.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<style>
    .xsmall {
        font-size: .65rem;
    }
</style>