<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;
// Handle create broadcast
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_broadcast'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'danger', 'text' => 'Invalid CSRF token'];
    } else {
        $titleB = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $audience = $_POST['audience'] ?? 'all';
        $channels = $_POST['channels'] ?? [];
        if (!is_array($channels)) $channels = [];
        $channels = array_values(array_intersect($channels, ['email', 'sms', 'system']));
        if (!$channels) $channels = ['system'];
        $scheduleAt = trim($_POST['scheduled_at'] ?? '');
        $scheduleAt = $scheduleAt !== '' ? date('Y-m-d H:i:s', strtotime($scheduleAt)) : null;
        $link1_label = trim($_POST['link1_label'] ?? '');
        $link1_url = trim($_POST['link1_url'] ?? '');
        $link2_label = trim($_POST['link2_label'] ?? '');
        $link2_url = trim($_POST['link2_url'] ?? '');
        $link3_label = trim($_POST['link3_label'] ?? '');
        $link3_url = trim($_POST['link3_url'] ?? '');
        if ($titleB && $message) {
            try {
                $stmt = $pdo->prepare("INSERT INTO Notification_Broadcasts (title,message,channels,audience,scheduled_at,created_by,link1_label,link1_url,link2_label,link2_url,link3_label,link3_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$titleB, $message, implode(',', $channels), $audience, $scheduleAt, current_user()['user_id'], $link1_label ?: null, $link1_url ?: null, $link2_label ?: null, $link2_url ?: null, $link3_label ?: null, $link3_url ?: null]);
                $bid = (int)$pdo->lastInsertId();
                if (!$scheduleAt || strtotime($scheduleAt) <= time()) {
                    // queue immediately
                    $targets = build_broadcast_targets($audience);
                    queue_notification_broadcast($bid, $targets, $channels);
                }
                $flash = ['type' => 'success', 'text' => 'Broadcast created' . ($scheduleAt ? ' (scheduled)' : ' & queued')];
            } catch (Throwable $e) {
                $flash = ['type' => 'danger', 'text' => 'Create failed'];
            }
        } else {
            $flash = ['type' => 'warning', 'text' => 'Title and message required'];
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
                                <label class="form-label small text-muted mb-1">Channels</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach (['system' => 'System', 'email' => 'Email', 'sms' => 'SMS'] as $val => $lab): ?>
                                        <label class="form-check small"><input class="form-check-input" type="checkbox" name="channels[]" value="<?= e($val) ?>" <?= $val === 'system' ? 'checked' : '' ?>> <?= e($lab) ?></label>
                                    <?php endforeach; ?>
                                </div>
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
                            <div class="border rounded p-2 mb-3">
                                <div class="text-muted xsmall mb-1">Optional Links (shown in body)</div>
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <div class="row g-2 align-items-center mb-1">
                                        <div class="col-4"><input name="link<?= $i ?>_label" class="form-control form-control-sm" placeholder="Label"></div>
                                        <div class="col-8"><input name="link<?= $i ?>_url" class="form-control form-control-sm" placeholder="https://..."></div>
                                    </div>
                                <?php endfor; ?>
                            </div>
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