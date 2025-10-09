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
    if ($reportKey === 'shop_performance') {
        header('Content-Type: text/plain', true, 400);
        echo 'Shop Performance export has been disabled.';
        exit;
    }
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

// Excel (.xls via HTML) export handler (multi-report, styled)
if (isset($_GET['export']) && in_array(strtolower($_GET['export']), ['xls', 'excel'], true)) {
    $reportKey = $_GET['rk'] ?? 'summary';
    if ($reportKey === 'shop_performance') {
        header('Content-Type: text/plain', true, 400);
        echo 'Shop Performance export has been disabled.';
        exit;
    }
    try {
        $data = generate_report_data($reportKey, $rangeStart, $rangeEnd);
        $titleMap = [
            'summary' => 'Monthly Summary',
            'monthly_summary' => 'Monthly Summary',
            'annual_summary' => 'Annual Summary',
            'revenue_breakdown' => 'Revenue Breakdown',
            'bookings_analytics' => 'Bookings Analytics',
            'user_activity' => 'User Activity',
            'shop_performance' => 'Shop Performance',
        ];
        $niceTitle = $titleMap[strtolower($reportKey)] ?? ucfirst(str_replace('_', ' ', $reportKey));
        $filename = 'report_' . preg_replace('/[^a-z0-9_]+/i', '_', $reportKey) . '_' . $rangeStart . '_' . $rangeEnd . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo "\xEF\xBB\xBF"; // BOM for UTF-8 Excel
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        echo '<html><head><meta charset="UTF-8"><style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:12px;}
            .header{margin-bottom:10px;}
            .header h1{font-size:16px;margin:0 0 4px 0;}
            .meta{color:#6c757d;font-size:11px;}
            table{border-collapse:collapse;margin:12px 0;}
            th,td{border:1px solid #dee2e6;padding:6px;vertical-align:middle;}
            thead th, .head{background:#0d6efd;color:#fff;text-align:center;font-weight:bold;}
            tbody tr:nth-child(even){background:#f9fbff;}
            .text-right{text-align:right;}
            .text-center{text-align:center;}
            .nowrap{white-space:nowrap;}
            .num{mso-number-format:"#,##0";}
            .num2{mso-number-format:"#,##0.00";}
            .date{mso-number-format:"yyyy-mm-dd";}
            .datetime{mso-number-format:"yyyy-mm-dd hh:mm";}
        </style></head><body>';
        echo '<div class="header">';
        echo '<h1>' . $h($niceTitle) . '</h1>';
        echo '<div class="meta">Range: ' . $h($rangeStart) . ' to ' . $h($rangeEnd) . '</div>';
        echo '<div class="meta">Generated: ' . $h(date('Y-m-d H:i:s')) . '</div>';
        echo '</div>';

        // Render each section as a table
        foreach ($data['sections'] as $section) {
            $rows = $section['rows'] ?? [];
            if (!$rows) continue;
            echo '<table>';
            echo '<thead><tr><th colspan="' . count($rows[0]) . '" class="head">' . $h($section['title']) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($rows as $ri => $row) {
                // Treat first row as header
                if ($ri === 0) {
                    echo '<tr class="head">';
                    foreach ($row as $cell) echo '<td>' . $h($cell) . '</td>';
                    echo '</tr>';
                    continue;
                }
                echo '<tr>';
                foreach ($row as $ci => $cell) {
                    $cellStr = (string)$cell;
                    $class = '';
                    // Basic type detection for alignment/format
                    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $cellStr)) {
                        $class = (strlen($cellStr) > 10) ? 'datetime nowrap' : 'date nowrap';
                    } elseif (is_numeric($cellStr)) {
                        $class = (strpos($cellStr, '.') !== false) ? 'num2 text-right' : 'num text-right';
                    } elseif (preg_match('/^-?\d{1,3}(,\d{3})*(\.\d+)?$/', $cellStr)) {
                        // 1,234.56 formatted string => align right
                        $class = (strpos($cellStr, '.') !== false) ? 'text-right' : 'text-right';
                    }
                    echo '<td' . ($class ? ' class="' . $h($class) . '"' : '') . '>' . $h($cellStr) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</body></html>';
    } catch (Throwable $e) {
        header('Content-Type: text/plain', true, 500);
        echo 'Error generating report';
    }
    exit;
}

$msg = null; // scheduling removed

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
                <a class="btn btn-primary btn-sm" href="reports.php?export=xls&rk=summary&range=<?= e($preset) ?>&start=<?= e($rangeStart) ?>&end=<?= e($rangeEnd) ?>" title="Export Excel (.xls)"><i class="bi bi-download me-1"></i> Export</a>
                <a class="btn btn-outline-secondary btn-sm" href="reports.php?export=csv&rk=summary&range=<?= e($preset) ?>&start=<?= e($rangeStart) ?>&end=<?= e($rangeEnd) ?>" title="Export CSV">CSV</a>
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
                ['monthly_summary', 'Monthly Summary', 'Users growth, shops onboarded, bookings volume, revenue.'],
                ['annual_summary', 'Annual Summary', 'Year-over-year performance overview and tax totals.'],
                ['revenue_breakdown', 'Revenue Breakdown', 'Appointments vs subscriptions, taxes collected, failed attempts.'],
                ['bookings_analytics', 'Bookings Analytics', 'Peak hours (future), popular services, status ratios.'],
                ['user_activity', 'User Activity', 'New users by role (login tracking future).'],
            ];
            foreach ($reports as $r) {
                [$key, $name, $desc] = $r;
                $qBase = '&rk=' . urlencode($key) . '&range=' . urlencode($preset) . '&start=' . urlencode($rangeStart) . '&end=' . urlencode($rangeEnd);
                $linkXls = 'reports.php?export=xls' . $qBase;
                $linkCsv = 'reports.php?export=csv' . $qBase;
                echo '<div class="col-sm-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
                    . '<h6 class="fw-semibold mb-1">' . e($name) . '</h6>'
                    . '<p class="text-muted small flex-grow-1 mb-3" style="line-height:1.4;">' . e($desc) . '</p>'
                    . '<div class="d-flex gap-2">'
                    . '<a class="btn btn-sm btn-outline-primary" href="' . e($linkXls) . '" title="Export Excel (.xls)"><i class="bi bi-download me-1"></i> Export</a>'
                    . '<a class="btn btn-sm btn-outline-secondary" href="' . e($linkCsv) . '" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i> CSV</a>'
                    . '</div>'
                    . '</div></div></div>';
            }
            ?>
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