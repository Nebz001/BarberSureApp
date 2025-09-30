<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;

// Handle range inputs
$preset = $_GET['range'] ?? 'last_30_days';
$customStart = $_GET['start'] ?? null;
$customEnd = $_GET['end'] ?? null;
[$rangeStart, $rangeEnd] = resolve_report_range($preset, $customStart, $customEnd);

// CSV export handler (multi-report)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $reportKey = $_GET['rk'] ?? 'summary';
    try {
        $data = generate_report_data($reportKey, $rangeStart, $rangeEnd);
        $filename = 'report_' . preg_replace('/[^a-z0-9_]+/i', '_', $reportKey) . '_' . $rangeStart . '_' . $rangeEnd . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Report', $reportKey]);
        fputcsv($out, ['Range', $rangeStart, $rangeEnd]);
        foreach ($data['sections'] as $section) {
            fputcsv($out, []);
            fputcsv($out, ['Section', $section['title']]);
            foreach ($section['rows'] as $row) {
                fputcsv($out, $row);
            }
        }
        fclose($out);
    } catch (Throwable $e) {
        header('Content-Type: text/plain', true, 500);
        echo 'Error generating report';
    }
    exit;
}

// Scheduling CRUD (basic)
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_action'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $msg = ['type' => 'danger', 'text' => 'Invalid CSRF'];
    } else {
        $act = $_POST['schedule_action'];
        try {
            if ($act === 'create') {
                $name = trim($_POST['name'] ?? '');
                $reportKey = trim($_POST['report_key'] ?? 'summary');
                $freq = $_POST['frequency'] ?? 'monthly';
                $rangePreset = $_POST['range_preset'] ?? 'last_30_days';
                $recipients = trim($_POST['recipients'] ?? '');
                $format = $_POST['format'] ?? 'html';
                if ($name && $recipients) {
                    $stmt = $pdo->prepare("INSERT INTO Report_Schedules (report_key,name,frequency,range_preset,recipients,format,created_by,next_run_at) VALUES (?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([$reportKey, $name, $freq, $rangePreset, $recipients, $format, current_user()['user_id']]);
                    $msg = ['type' => 'success', 'text' => 'Schedule created'];
                } else {
                    $msg = ['type' => 'danger', 'text' => 'Name & recipients required'];
                }
            } elseif ($act === 'toggle') {
                $id = (int)$_POST['schedule_id'];
                $pdo->prepare("UPDATE Report_Schedules SET active=1-active WHERE schedule_id=?")->execute([$id]);
                $msg = ['type' => 'info', 'text' => 'Schedule toggled'];
            } elseif ($act === 'delete') {
                $id = (int)$_POST['schedule_id'];
                $pdo->prepare("DELETE FROM Report_Schedules WHERE schedule_id=?")->execute([$id]);
                $msg = ['type' => 'warning', 'text' => 'Schedule deleted'];
            } elseif ($act === 'run_now') {
                $id = (int)$_POST['schedule_id'];
                $sch = $pdo->prepare("SELECT * FROM Report_Schedules WHERE schedule_id=?");
                $sch->execute([$id]);
                if ($row = $sch->fetch(PDO::FETCH_ASSOC)) {
                    [$rs, $re] = resolve_report_range($row['range_preset'], $row['custom_start'], $row['custom_end']);
                    $recips = array_filter(array_map('trim', explode(',', $row['recipients'])));
                    $logId = start_report_log($row['report_key'], $row['schedule_id'], $rs, $re, $recips);
                    $html = '<h2>Manual Run: ' . e($row['report_key']) . '</h2><p>Range ' . e($rs) . ' - ' . e($re) . '</p>';
                    $sent = send_report_email($recips, '[Report Manual] ' . $row['name'], $html, strip_tags($html));
                    finish_report_log($logId, $sent ? 'success' : 'failed', $sent ? 'Email sent' : 'Send failed');
                    $msg = ['type' => $sent ? 'success' : 'danger', 'text' => $sent ? 'Report emailed' : 'Email failed'];
                }
            }
        } catch (Throwable $e) {
            $msg = ['type' => 'danger', 'text' => 'Action failed'];
        }
    }
}

// Fetch schedules & recent logs
$schedules = [];
$logs = [];
try {
    $schedules = $pdo->query("SELECT * FROM Report_Schedules ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}
try {
    $logs = $pdo->query("SELECT * FROM Report_Logs ORDER BY generated_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$title = 'Reports • Admin';
include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">System Reports</h1>
                <div class="text-muted small">Custom ranges, exports, and scheduled emailed reports.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-primary btn-sm" href="reports.php?export=csv&rk=summary&range=<?= e($preset) ?>&start=<?= e($rangeStart) ?>&end=<?= e($rangeEnd) ?>"><i class="bi bi-download me-1"></i> Export CSV</a>
                <a class="btn btn-outline-secondary btn-sm" href="#schedule-create" onclick="document.getElementById('scheduleCreateCard').scrollIntoView({behavior:'smooth'});return false;">New Schedule</a>
            </div>
        </div>
        <?php if ($msg): ?><div class="alert alert-<?= e($msg['type']) ?> py-2 small mb-3"><?= e($msg['text']) ?></div><?php endif; ?>
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted mb-1">Preset</label>
                        <select name="range" class="form-select form-select-sm">
                            <?php foreach (['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'custom'] as $p): ?>
                                <option value="<?= $p ?>" <?= $preset === $p ? 'selected' : '' ?>><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted mb-1">Start</label>
                        <input type="date" name="start" value="<?= e($rangeStart) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small text-muted mb-1">End</label>
                        <input type="date" name="end" value="<?= e($rangeEnd) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 col-lg-2 d-flex gap-2">
                        <button class="btn btn-secondary btn-sm flex-grow-1">Apply</button>
                        <a href="reports.php" class="btn btn-outline-secondary btn-sm flex-grow-1">Reset</a>
                    </div>
                    <div class="col-12 small text-muted">Range resolved: <strong><?= e($rangeStart) ?></strong> to <strong><?= e($rangeEnd) ?></strong></div>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-body small text-muted">Metrics below are placeholders – integrate deeper analytics over time.</div>
        </div>
        <div class="row g-4">
            <?php
            $reports = [
                ['monthly_summary', 'Monthly Summary', 'Users growth, shops onboarded, bookings volume, revenue.', 'CSV'],
                ['annual_summary', 'Annual Summary', 'Year-over-year performance overview and tax totals.', 'CSV'],
                ['revenue_breakdown', 'Revenue Breakdown', 'Appointments vs subscriptions, taxes collected, failed attempts.', 'CSV'],
                ['bookings_analytics', 'Bookings Analytics', 'Peak hours (future), popular services, status ratios.', 'CSV'],
                ['user_activity', 'User Activity', 'New users by role (login tracking future).', 'CSV'],
                ['shop_performance', 'Shop Performance', 'Top shops, activity signals, potential churn.', 'CSV'],
            ];
            foreach ($reports as $r) {
                [$key, $name, $desc, $type] = $r;
                $link = 'reports.php?export=csv&rk=' . urlencode($key) . '&range=' . urlencode($preset) . '&start=' . urlencode($rangeStart) . '&end=' . urlencode($rangeEnd);
                echo '<div class="col-sm-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
                    . '<h6 class="fw-semibold mb-1">' . e($name) . '</h6>'
                    . '<p class="text-muted small flex-grow-1 mb-3" style="line-height:1.4;">' . e($desc) . '</p>'
                    . '<a class="btn btn-sm btn-outline-primary align-self-start" href="' . e($link) . '">Export ' . e($type) . '</a>'
                    . '</div></div></div>';
            }
            ?>
        </div>
        <div id="scheduleCreateCard" class="card mt-5">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Report Scheduling</h6>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <form method="post" class="border rounded p-3 bg-dark text-light small">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="schedule_action" value="create">
                            <div class="mb-2">
                                <label class="form-label mb-1 small text-muted">Name</label>
                                <input type="text" name="name" class="form-control form-control-sm bg-transparent text-light" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1 small text-muted">Report Key</label>
                                <select name="report_key" class="form-select form-select-sm">
                                    <option value="monthly_summary">monthly_summary</option>
                                    <option value="annual_summary">annual_summary</option>
                                    <option value="revenue_breakdown">revenue_breakdown</option>
                                    <option value="bookings_analytics">bookings_analytics</option>
                                    <option value="user_activity">user_activity</option>
                                    <option value="shop_performance">shop_performance</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1 small text-muted">Frequency</label>
                                <select name="frequency" class="form-select form-select-sm">
                                    <option value="daily">daily</option>
                                    <option value="weekly">weekly</option>
                                    <option value="monthly" selected>monthly</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1 small text-muted">Range Preset</label>
                                <select name="range_preset" class="form-select form-select-sm">
                                    <?php foreach (['last_30_days', 'this_month', 'last_month', 'this_year'] as $rp): ?>
                                        <option value="<?= $rp ?>"><?= $rp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1 small text-muted">Recipients (comma emails)</label>
                                <textarea name="recipients" class="form-control form-control-sm bg-transparent text-light" rows="2" placeholder="admin@example.com" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label mb-1 small text-muted">Format</label>
                                <select name="format" class="form-select form-select-sm">
                                    <option value="html">HTML Email</option>
                                    <option value="csv">CSV Attachment</option>
                                    <option value="both">HTML + CSV</option>
                                </select>
                            </div>
                            <button class="btn btn-primary btn-sm">Create Schedule</button>
                        </form>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="small text-muted mb-2">Existing Schedules</h6>
                        <div class="table-responsive small" style="max-height:260px;">
                            <table class="table table-sm table-dark-mode align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Freq</th>
                                        <th>Range</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$schedules): ?><tr>
                                            <td colspan="5" class="text-muted text-center">None</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($schedules as $sch): ?>
                                        <tr>
                                            <td class="small"><?= e($sch['name']) ?></td>
                                            <td class="text-muted xsmall"><?= e($sch['frequency']) ?></td>
                                            <td class="text-muted xsmall"><?= e($sch['range_preset']) ?></td>
                                            <td><?= $sch['active'] ? '<span class="badge bg-success bg-opacity-75">active</span>' : '<span class="badge bg-secondary bg-opacity-50">off</span>' ?></td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="schedule_action" value="toggle">
                                                        <input type="hidden" name="schedule_id" value="<?= (int)$sch['schedule_id'] ?>">
                                                        <button class="btn btn-outline-secondary" title="Toggle">T</button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Run now?');">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="schedule_action" value="run_now">
                                                        <input type="hidden" name="schedule_id" value="<?= (int)$sch['schedule_id'] ?>">
                                                        <button class="btn btn-outline-primary" title="Run Now"><i class="bi bi-play"></i></button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete schedule?');">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="schedule_action" value="delete">
                                                        <input type="hidden" name="schedule_id" value="<?= (int)$sch['schedule_id'] ?>">
                                                        <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="small text-muted mb-2">Recent Runs</h6>
                        <div class="table-responsive small" style="max-height:260px;">
                            <table class="table table-sm table-dark-mode align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Report</th>
                                        <th>Status</th>
                                        <th>Range</th>
                                        <th>At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$logs): ?><tr>
                                            <td colspan="4" class="text-muted text-center">None</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($logs as $lg): ?>
                                        <tr>
                                            <td class="xsmall"><?= e($lg['report_key']) ?></td>
                                            <td class="xsmall"><?= $lg['status'] === 'success' ? '<span class="badge bg-success bg-opacity-75">ok</span>' : ($lg['status'] === 'failed' ? '<span class="badge bg-danger bg-opacity-75">fail</span>' : '<span class="badge bg-warning bg-opacity-75">run</span>') ?></td>
                                            <td class="xsmall text-muted"><?= e(($lg['range_start'] ?? '') . '–' . ($lg['range_end'] ?? '')) ?></td>
                                            <td class="xsmall text-muted"><?= e(substr($lg['generated_at'], 0, 16)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Cron helper line removed as requested -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<style>
    .xsmall {
        font-size: .6rem;
    }

    .table-dark-mode thead th {
        background: #273040;
    }
</style>