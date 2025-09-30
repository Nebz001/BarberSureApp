<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');

$title = 'Admin Logs • BarberSure';

$logDir = dirname(__DIR__) . '/logs';
$primary = $logDir . '/admin_actions.log';
$pattern = $logDir . '/admin_actions-*.log';
$archives = glob($pattern) ?: [];
rsort($archives); // newest first

// Optional specific archive selection
$selectedArchive = $_GET['archive'] ?? '';
$activeFiles = [$primary];
if ($selectedArchive && preg_match('/^admin_actions-\\d{8}_\\d{6}\\.log$/', $selectedArchive)) {
    $candidate = $logDir . '/' . $selectedArchive;
    if (is_readable($candidate)) $activeFiles = [$candidate];
}

// Read filter inputs
$q = trim($_GET['q'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$from = trim($_GET['from'] ?? ''); // YYYY-MM-DD
$to = trim($_GET['to'] ?? '');
$limit = (int)($_GET['limit'] ?? 200); // legacy param (kept for compatibility)
$limit = max(20, min(1000, $limit));
$perPage = (int)($_GET['per_page'] ?? $limit);
$perPage = max(20, min(500, $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));

$rows = [];
function parse_log_file($file, &$rows, $limit)
{
    if (!file_exists($file) || count($rows) >= $limit) return;
    $fh = @fopen($file, 'r');
    if (!$fh) return;
    // We'll read from end for primary file to get most recent quickly
    $lines = [];
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line !== false && trim($line) !== '') $lines[] = $line;
    }
    fclose($fh);
    $lines = array_reverse($lines); // newest first
    foreach ($lines as $ln) {
        if (count($rows) >= $limit) break;
        $data = json_decode($ln, true);
        if (!is_array($data)) continue;
        $rows[] = $data;
    }
}

foreach ($activeFiles as $af) {
    parse_log_file($af, $rows, $limit);
    if (count($rows) >= $limit) break;
}
// If viewing live primary and need more rows, pull newest archive automatically
if ($activeFiles[0] === $primary && count($rows) < $limit && $archives) {
    parse_log_file($archives[0], $rows, $limit);
}

// Filtering in-memory
$adminIdFilter = trim($_GET['admin_id'] ?? '');
$rows = array_filter($rows, function ($r) use ($q, $actionFilter, $from, $to, $adminIdFilter) {
    if ($actionFilter && ($r['action'] ?? '') !== $actionFilter) return false;
    if ($adminIdFilter !== '' && (string)($r['admin_id'] ?? '') !== $adminIdFilter) return false;
    if ($q) {
        $hay = json_encode($r, JSON_UNESCAPED_SLASHES);
        if (stripos($hay, $q) === false) return false;
    }
    if ($from) {
        $ts = $r['ts'] ?? null;
        if ($ts && strtotime($ts) < strtotime($from . ' 00:00:00')) return false;
    }
    if ($to) {
        $ts = $r['ts'] ?? null;
        if ($ts && strtotime($ts) > strtotime($to . ' 23:59:59')) return false;
    }
    return true;
});

// Reindex (newest first already)
$rows = array_values($rows);

// Export handling (raw, before pagination) ?export=csv|json
$export = strtolower(trim($_GET['export'] ?? ''));
if (in_array($export, ['csv', 'json'], true)) {
    if ($export === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['count' => count($rows), 'entries' => $rows], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($export === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="admin_actions_export.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ts', 'admin_id', 'action', 'target_type', 'target_id', 'ip', 'meta_json']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['ts'] ?? '',
                $r['admin_id'] ?? '',
                $r['action'] ?? '',
                $r['target_type'] ?? '',
                $r['target_id'] ?? '',
                $r['ip'] ?? '',
                isset($r['meta']) ? json_encode($r['meta'], JSON_UNESCAPED_SLASHES) : ''
            ]);
        }
        fclose($out);
        exit;
    }
}

// Pagination slice after filtering
$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($rows, $offset, $perPage);

// Distinct actions for filter options
$actions = [];
foreach ($rows as $r) {
    if (!empty($r['action'])) $actions[$r['action']] = true;
}
$actions = array_keys($actions);
sort($actions);
// Distinct admin IDs
$adminIds = [];
foreach ($rows as $r) {
    if (isset($r['admin_id']) && $r['admin_id'] !== '') $adminIds[$r['admin_id']] = true;
}
$adminIds = array_keys($adminIds);
sort($adminIds, SORT_NUMERIC);

// Action summary counts (post-filter, pre-pagination)
$actionCounts = [];
foreach ($rows as $r) {
    $a = $r['action'] ?? '';
    if ($a !== '') $actionCounts[$a] = ($actionCounts[$a] ?? 0) + 1;
}
ksort($actionCounts);
sort($actions);

include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Admin Action Logs</h1>
                <div class="text-muted small">Newest first (showing up to <?= e($limit) ?> entries across active & last archive).</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="logs.php">Reset</a>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end small" id="filtersForm">
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Archive</label>
                        <select name="archive" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Current</option>
                            <?php foreach ($archives as $a): $bn = basename($a); ?>
                                <option value="<?= e($bn) ?>" <?= $selectedArchive === $bn ? 'selected' : '' ?>><?= e($bn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4 col-md-3">
                        <label class="form-label text-muted small mb-1">Search (JSON contains)</label>
                        <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Text or ID">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Action</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($actions as $a): ?>
                                <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Admin ID</label>
                        <select name="admin_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($adminIds as $aid): ?>
                                <option value="<?= e($aid) ?>" <?= $adminIdFilter === (string)$aid ? 'selected' : '' ?>><?= e($aid) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">From</label>
                        <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">To</label>
                        <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Limit</label>
                        <select name="limit" class="form-select form-select-sm">
                            <?php foreach ([50, 100, 200, 300, 500, 1000] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2 col-md-2">
                        <label class="form-label text-muted small mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            <?php foreach ([20, 50, 100, 200, 300, 500] as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button class="btn btn-secondary btn-sm">Apply</button>
                        <a class="btn btn-outline-secondary btn-sm" href="javascript:void(0)" id="clientSearchBtn" title="Client-side refine (highlight)">Client Search</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?export=json<?= $selectedArchive ? '&archive=' . urlencode($selectedArchive) : '' ?>" title="Export current filtered set as JSON">Export JSON</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?export=csv<?= $selectedArchive ? '&archive=' . urlencode($selectedArchive) : '' ?>" title="Export current filtered set as CSV">Export CSV</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Log Entries <span class="badge rounded-pill bg-secondary bg-opacity-25 text-light small ms-2"><?= $totalRows ?></span></h5>
                <div class="text-muted small">Viewing: <?= e($selectedArchive ?: basename($primary)) ?><?php if ($archives): ?> • Total archives: <?= count($archives) ?><?php endif; ?></div>
            </div>
            <?php if ($actionCounts): ?>
                <div class="px-3 pt-3 small text-muted d-flex flex-wrap gap-3">
                    <?php foreach ($actionCounts as $an => $cnt): ?>
                        <span class="badge bg-dark bg-opacity-50"><?= e($an) ?>: <strong><?= $cnt ?></strong></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="table-responsive" style="max-height:600px;">
                <table class="table table-sm table-dark-mode align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:150px;">Timestamp</th>
                            <th style="width:70px;">Admin</th>
                            <th style="width:110px;">Action</th>
                            <th style="width:110px;">Target</th>
                            <th>Meta</th>
                            <th style="width:110px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$totalRows): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No log entries match filters.</td>
                            </tr>
                            <?php else: foreach ($pagedRows as $r): ?>
                                <tr>
                                    <td class="text-muted small" title="<?= e($r['ts'] ?? '') ?>"><?= e(isset($r['ts']) ? date('Y-m-d H:i:s', strtotime($r['ts'])) : '') ?></td>
                                    <td class="small"><?= e($r['admin_id'] ?? '') ?></td>
                                    <td><span class="badge bg-primary bg-opacity-75"><?= e($r['action'] ?? '') ?></span></td>
                                    <td class="small"><?= e(($r['target_type'] ?? '') . ':' . ($r['target_id'] ?? '')) ?></td>
                                    <td class="small log-meta-cell" data-json='<?= e(json_encode($r, JSON_UNESCAPED_SLASHES)) ?>'>
                                        <?php $meta = $r['meta'] ?? [];
                                        if ($meta && is_array($meta)): ?>
                                            <?php foreach ($meta as $mk => $mv): ?>
                                                <span class="d-inline-block me-2"><span class="text-muted"><?= e($mk) ?>:</span> <?= e(is_scalar($mv) ? $mv : json_encode($mv)) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= e($r['ip'] ?? '') ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-transparent d-flex justify-content-between align-items-center py-2 small flex-wrap gap-2">
                    <div>Page <?= $page ?> / <?= $totalPages ?> • Showing <?= count($pagedRows) ?> of <?= $totalRows ?></div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $buildLink = function ($p) use ($q, $actionFilter, $from, $to, $perPage) {
                                $params = [
                                    'page' => $p,
                                    'per_page' => $perPage,
                                    'q' => $q,
                                    'action' => $actionFilter,
                                    'from' => $from,
                                    'to' => $to
                                ];
                                $qs = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
                                return 'logs.php?' . $qs;
                            };
                            $range = range(max(1, $page - 2), min($totalPages, $page + 2));
                            ?>
                            <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($buildLink(1)) ?>" aria-label="First">«</a></li>
                            <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($buildLink(max(1, $page - 1))) ?>" aria-label="Previous">‹</a></li>
                            <?php foreach ($range as $p): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($buildLink($p)) ?>"><?= $p ?></a></li>
                            <?php endforeach; ?>
                            <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($buildLink(min($totalPages, $page + 1))) ?>" aria-label="Next">›</a></li>
                            <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($buildLink($totalPages)) ?>" aria-label="Last">»</a></li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Notes</h6>
            </div>
            <div class="card-body small text-muted">
                <ul class="ps-3 mb-0" style="list-style:disc;line-height:1.5;">
                    <li>File rotates automatically at 5MB (older file kept with timestamp).</li>
                    <li>Search scans JSON text representation client-side.</li>
                    <li>Expand filters for more precise auditing later (date range, admin id).</li>
                </ul>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<style>
    .table-dark-mode thead th {
        background: #273040;
        color: #fff;
        border-bottom: 1px solid #323d4c;
    }

    .table-dark-mode tbody td {
        border-top: 1px solid #273040;
        background: transparent;
        color: #fff !important;
    }
</style>
<script>
    // Client-side highlighting for additional ad-hoc search within already filtered results
    (function() {
        const btn = document.getElementById('clientSearchBtn');
        if (!btn) return;
        btn.addEventListener('click', function() {
            const term = prompt('Enter client-side search term (case-insensitive)');
            if (!term) return;
            const cells = document.querySelectorAll('.log-meta-cell');
            const regex = new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
            let matches = 0;
            cells.forEach(c => {
                c.classList.remove('log-match');
                const json = c.getAttribute('data-json') || '';
                if (regex.test(json)) {
                    c.classList.add('log-match');
                    matches++;
                }
            });
            alert(matches + ' row(s) highlighted');
        });
    })();
</script>
<style>
    .log-match {
        outline: 2px solid #ffc107;
        outline-offset: 2px;
        background: rgba(255, 193, 7, 0.08);
    }
</style>