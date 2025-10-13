<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
$title = 'Payments • Admin';

// ------------------------------------------------------------------
// Action handling: offline confirmation, dispute actions, renewal scan
// ------------------------------------------------------------------
$flash = ['error' => null, 'success' => null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $flash['error'] = 'Invalid session token.';
    } else {
        $act = $_POST['action'] ?? '';
        try {
            if ($act === 'confirm_offline') {
                $subId = (int)($_POST['subscription_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                if ($subId <= 0 || $amount <= 0) throw new Exception('Invalid subscription or amount');
                // Load subscription
                $s = $pdo->prepare("SELECT * FROM Shop_Subscriptions WHERE subscription_id=? LIMIT 1");
                $s->execute([$subId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Subscription not found');
                if ($row['payment_status'] === 'paid') throw new Exception('Already paid');
                // Create payment + mark subscription
                $shopId = (int)$row['shop_id'];
                $ownerQ = $pdo->prepare('SELECT owner_id FROM Barbershops WHERE shop_id=?');
                $ownerQ->execute([$shopId]);
                $ownerId = (int)$ownerQ->fetchColumn();
                $taxRate = (float)$row['tax_rate'];
                $taxAmt = round($amount * ($taxRate / 100), 2);
                $pdo->beginTransaction();
                $p = $pdo->prepare("INSERT INTO Payments (user_id, shop_id, subscription_id, amount, tax_amount, transaction_type, payment_method, payment_status, paid_at) VALUES (?,?,?,?,?,'subscription','cash','completed',NOW())");
                $p->execute([$ownerId, $shopId, $subId, $amount, $taxAmt]);
                $payId = (int)$pdo->lastInsertId();
                $u = $pdo->prepare('UPDATE Shop_Subscriptions SET payment_status="paid", payment_id=? WHERE subscription_id=?');
                $u->execute([$payId, $subId]);
                $pdo->commit();
                $flash['success'] = 'Offline subscription payment confirmed.';
            } elseif ($act === 'open_dispute') {
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($paymentId <= 0 || $reason === '') throw new Exception('Payment & reason required');
                $ins = $pdo->prepare('INSERT INTO Payment_Disputes (payment_id, opened_by, reason, description) VALUES (?,?,?,?)');
                $ins->execute([$paymentId, $_SESSION['user']['user_id'], $reason, $desc !== '' ? $desc : null]);
                $flash['success'] = 'Dispute opened.';
            } elseif ($act === 'update_dispute') {
                $disputeId = (int)($_POST['dispute_id'] ?? 0);
                $status = $_POST['status_new'] ?? '';
                $resNotes = trim($_POST['resolution_notes'] ?? '');
                if ($disputeId <= 0) throw new Exception('Invalid dispute');
                if (!in_array($status, ['open', 'in_review', 'resolved', 'rejected'], true)) throw new Exception('Bad status');
                $sql = 'UPDATE Payment_Disputes SET status=?, resolution_notes=?, resolved_at=' . ($status === 'resolved' || $status === 'rejected' ? 'NOW()' : 'NULL') . ' WHERE dispute_id=?';
                $upd = $pdo->prepare($sql);
                $upd->execute([$status, $resNotes !== '' ? $resNotes : null, $disputeId]);
                $flash['success'] = 'Dispute updated.';
            } elseif ($act === 'scan_renewals') {
                // Retained for compatibility; keep existing behavior
                $pending = $pdo->query("SELECT subscription_id, shop_id, plan_type, annual_fee, tax_rate, valid_to FROM Shop_Subscriptions WHERE payment_status='paid' AND auto_renew=1 AND valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND renewal_generated_at IS NULL LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                $generated = 0;
                if ($pending) {
                    foreach ($pending as $sub) {
                        $validFrom = date('Y-m-d', strtotime($sub['valid_to'] . ' +1 day'));
                        $validTo = $sub['plan_type'] === 'monthly' ? date('Y-m-d', strtotime($validFrom . ' +1 month -1 day')) : date('Y-m-d', strtotime($validFrom . ' +1 year -1 day'));
                        $ins = $pdo->prepare("INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to, auto_renew, renewal_parent_id) VALUES (?,?,?,?, 'pending', ?, ?, 1, ?)");
                        $ins->execute([$sub['shop_id'], $sub['plan_type'], $sub['annual_fee'], $sub['tax_rate'], $validFrom, $validTo, $sub['subscription_id']]);
                        $upd = $pdo->prepare('UPDATE Shop_Subscriptions SET renewal_generated_at=NOW() WHERE subscription_id=?');
                        $upd->execute([$sub['subscription_id']]);
                        $generated++;
                    }
                }
                $flash['success'] = $generated . ' renewal(s) generated.';
            } elseif ($act === 'create_offline_subscription') {
                // Create a new offline subscription entry (pending payment)
                $shopId = (int)($_POST['shop_id'] ?? 0);
                $plan = $_POST['plan_type'] ?? 'annual';
                $annualFee = (float)($_POST['amount'] ?? 0);
                $taxRate = (float)($_POST['tax_rate'] ?? 0);
                $validFrom = trim($_POST['valid_from'] ?? '');
                $autoRenew = isset($_POST['auto_renew']) ? 1 : 0;

                if ($shopId <= 0) throw new Exception('Select a shop');
                if (!in_array($plan, ['monthly', 'annual'], true)) throw new Exception('Invalid plan');
                if ($annualFee <= 0) throw new Exception('Enter a valid amount');
                if ($taxRate < 0) throw new Exception('Invalid tax rate');
                if ($validFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) throw new Exception('Choose a valid start date');

                $vf = $validFrom;
                $vt = $plan === 'monthly'
                    ? date('Y-m-d', strtotime($vf . ' +1 month -1 day'))
                    : date('Y-m-d', strtotime($vf . ' +1 year -1 day'));

                $ins = $pdo->prepare("INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to, auto_renew) VALUES (?,?,?,?, 'pending', ?, ?, ?)");
                $ins->execute([$shopId, $plan, $annualFee, $taxRate, $vf, $vt, $autoRenew]);
                $flash['success'] = 'Offline subscription created.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash['error'] = $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------
// Transactions filters & pagination (GET)
// ------------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['appointment', 'subscription'], true)) $typeFilter = '';
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['pending', 'completed', 'failed'], true)) $statusFilter = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; // fixed page size for now
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(b.shop_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($typeFilter !== '') {
    $where[] = 'p.transaction_type = ?';
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = 'p.payment_status = ?';
    $params[] = $statusFilter;
}
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Fetch all matching payments (capped) and merge with pending subscriptions (no payment yet) to include subscriptions in table.
$paymentsSql = "SELECT p.*, b.shop_name, u.full_name, u.email,
   (SELECT COUNT(*) FROM Payment_Disputes d WHERE d.payment_id=p.payment_id AND d.status IN ('open','in_review')) AS open_disputes,
   s.plan_type, s.valid_from, s.valid_to
 FROM Payments p
 LEFT JOIN Barbershops b ON p.shop_id=b.shop_id
 LEFT JOIN Users u ON p.user_id=u.user_id
 LEFT JOIN Shop_Subscriptions s ON p.subscription_id = s.subscription_id
 $wsql
 ORDER BY p.created_at DESC
 LIMIT 500"; // safety cap
$payStmt = $pdo->prepare($paymentsSql);
$payStmt->execute($params);
$paymentRows = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending subscriptions with no payment yet (only include if filter allows subscription rows)
$pendingSubsRows = [];
if ($typeFilter !== 'appointment') {
    $subWhere = [];
    $subParams = [];
    if ($search !== '') {
        $subWhere[] = "(b.shop_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
        $like = "%$search%";
        $subParams[] = $like;
        $subParams[] = $like;
        $subParams[] = $like;
    }
    if ($statusFilter !== '') {
        $subWhere[] = 's.payment_status = ?';
        $subParams[] = $statusFilter;
    }
    // Only pending or unpaid matter; keep generic to allow future statuses
    $subWhere[] = 's.payment_id IS NULL';
    if ($typeFilter === 'subscription') { /* already constrained by query choice */
    }
    $subWsql = $subWhere ? ('WHERE ' . implode(' AND ', $subWhere)) : '';
    $subsSql = "SELECT NULL AS payment_id, 'subscription' AS transaction_type, s.shop_id, b.shop_name, b.owner_id AS user_id, u.full_name, u.email,
        s.annual_fee AS amount, ROUND(s.annual_fee * (s.tax_rate/100),2) AS tax_amount,
        s.payment_status AS payment_status, NULL AS payment_method, NULL AS paid_at, s.created_at, s.created_at AS created_at_dup,
        s.subscription_id, s.plan_type, s.valid_from, s.valid_to,
        0 AS open_disputes
        FROM Shop_Subscriptions s
        JOIN Barbershops b ON s.shop_id=b.shop_id
        JOIN Users u ON b.owner_id=u.user_id
        $subWsql
        ORDER BY s.created_at DESC
        LIMIT 300"; // cap
    $subStmt = $pdo->prepare($subsSql);
    $subStmt->execute($subParams);
    $pendingSubsRows = $subStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Combine
$allRows = [];
foreach ($paymentRows as $r) {
    $r['row_kind'] = 'payment';
    $allRows[] = $r;
}
foreach ($pendingSubsRows as $r) {
    $r['row_kind'] = 'subscription_pending';
    $allRows[] = $r;
}

// Sort by created_at DESC (payments already sorted, but merged with subs)
usort($allRows, function ($a, $b) {
    return strcmp($b['created_at'], $a['created_at']);
});

$totalRows = count($allRows);
$totalPages = (int)ceil($totalRows / $perPage);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$transactions = array_slice($allRows, $offset, $perPage);

// Fetch small dashboards / data slices (not paginated, independent of filters)
$offlinePending = $pdo->query("SELECT s.subscription_id, b.shop_name, s.plan_type, s.annual_fee, s.tax_rate, s.valid_from, s.valid_to FROM Shop_Subscriptions s JOIN Barbershops b ON s.shop_id=b.shop_id WHERE s.payment_status='pending' ORDER BY s.created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
$openDisputes = $pdo->query("SELECT d.dispute_id, d.payment_id, d.reason, d.status, p.amount, p.tax_amount, p.transaction_type, p.payment_status FROM Payment_Disputes d JOIN Payments p ON d.payment_id=p.payment_id WHERE d.status IN ('open','in_review') ORDER BY d.created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
// Shops list for creating offline subscription
$shopsList = $pdo->query("SELECT shop_id, shop_name FROM Barbershops ORDER BY shop_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------------
// Export handler (CSV) - uses current filters; exports all filtered rows
// ------------------------------------------------------------------
if (isset($_GET['export']) && strtolower($_GET['export']) === 'csv') {
    // Prepare CSV output
    $filename = 'payments_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    // Optional BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // Headers
    fputcsv($out, [
        'ID',
        'Type',
        'Shop',
        'User',
        'Email',
        'Plan',
        'Valid From',
        'Valid To',
        'Gross',
        'Tax',
        'Status',
        'Method',
        'Paid At',
        'Created At'
    ]);
    foreach ($allRows as $r) {
        $id = $r['row_kind'] === 'subscription_pending' ? ('SUB-' . (int)($r['subscription_id'] ?? 0)) : ('P-' . (int)($r['payment_id'] ?? 0));
        $type = $r['transaction_type'] ?: 'n/a';
        $shop = $r['shop_name'] ?? '';
        $user = $r['full_name'] ?? '';
        $email = $r['email'] ?? '';
        $plan = $type === 'subscription' ? ($r['plan_type'] ?? '') : '';
        $vf = $type === 'subscription' ? ($r['valid_from'] ?? '') : '';
        $vt = $type === 'subscription' ? ($r['valid_to'] ?? '') : '';
        $gross = number_format((float)($r['amount'] ?? 0), 2, '.', '');
        $tax = number_format((float)($r['tax_amount'] ?? 0), 2, '.', '');
        $status = $r['payment_status'] ?? '';
        $method = $r['payment_method'] ?? '';
        $paidAt = $r['paid_at'] ?? '';
        $createdAt = $r['created_at'] ?? '';
        fputcsv($out, [$id, $type, $shop, $user, $email, $plan, $vf, $vt, $gross, $tax, $status, $method, $paidAt, $createdAt]);
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------
// Export handler (Excel .xls via HTML) with styling and alignment
// ------------------------------------------------------------------
if (isset($_GET['export']) && in_array(strtolower($_GET['export']), ['xls', 'excel'], true)) {
    $filename = 'payments_export_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    // Small helper for safe text
    $h = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    // Build filter summary
    $filterSummary = [
        $search !== '' ? ("Search: '" . $search . "'") : null,
        $typeFilter !== '' ? ("Type: " . $typeFilter) : null,
        $statusFilter !== '' ? ("Status: " . $statusFilter) : null,
    ];
    $filterSummary = array_values(array_filter($filterSummary));

    echo '<html><head><meta charset="UTF-8">';
    echo '<style>
        body{font-family:Arial,Helvetica,sans-serif; font-size:12px;}
        table{border-collapse:collapse;}
        th,td{border:1px solid #dee2e6; padding:6px; vertical-align:middle;}
        th{background:#0d6efd; color:#fff; text-align:center; font-weight:bold;}
        .text-right{text-align:right;}
        .text-center{text-align:center;}
        .text-muted{color:#6c757d;}
        .nowrap{white-space:nowrap;}
        .status-completed{background:#d1e7dd; color:#0f5132;}
        .status-pending{background:#fff3cd; color:#664d03;}
        .status-failed{background:#f8d7da; color:#842029;}
        .header{margin-bottom:10px;}
        .header h1{font-size:16px; margin:0 0 4px 0;}
        .header .meta{color:#6c757d; font-size:11px;}
    </style></head><body>';

    echo '<div class="header">';
    echo '<h1>Payments & Subscriptions Export</h1>';
    echo '<div class="meta">Generated: ' . $h(date('Y-m-d H:i:s')) . '</div>';
    if ($filterSummary) {
        echo '<div class="meta">' . $h(implode(' • ', $filterSummary)) . '</div>';
    }
    echo '</div>';

    echo '<table>'; // Column widths
    echo '<colgroup>
        <col style="width:90px;">
        <col style="width:90px;">
        <col style="width:220px;">
        <col style="width:180px;">
        <col style="width:220px;">
        <col style="width:100px;">
        <col style="width:110px;">
        <col style="width:110px;">
        <col style="width:110px;">
        <col style="width:110px;">
        <col style="width:120px;">
        <col style="width:120px;">
        <col style="width:135px;">
        <col style="width:135px;">
    </colgroup>';
    echo '<thead><tr>
        <th>ID</th>
        <th>Type</th>
        <th>Shop</th>
        <th>User</th>
        <th>Email</th>
        <th>Plan</th>
        <th>Valid From</th>
        <th>Valid To</th>
        <th>Gross</th>
        <th>Tax</th>
        <th>Status</th>
        <th>Method</th>
        <th>Paid At</th>
        <th>Created At</th>
    </tr></thead><tbody>';

    foreach ($allRows as $r) {
        $isSub = ($r['transaction_type'] === 'subscription');
        $id = ($r['row_kind'] ?? '') === 'subscription_pending' ? ('SUB-' . (int)($r['subscription_id'] ?? 0)) : ('P-' . (int)($r['payment_id'] ?? 0));
        $status = $r['payment_status'] ?? '';
        $statusClass = 'status-' . strtolower(preg_replace('/[^a-zA-Z]+/', '', $status));
        $grossVal = is_numeric($r['amount'] ?? null) ? number_format((float)$r['amount'], 2, '.', '') : '';
        $taxVal = is_numeric($r['tax_amount'] ?? null) ? number_format((float)$r['tax_amount'], 2, '.', '') : '';
        $vf = $isSub ? ($r['valid_from'] ?? '') : '';
        $vt = $isSub ? ($r['valid_to'] ?? '') : '';
        $paidAt = $r['paid_at'] ?? '';
        $createdAt = $r['created_at'] ?? '';

        echo '<tr>';
        echo '<td class="nowrap text-center">' . $h($id) . '</td>';
        echo '<td class="text-center">' . $h($r['transaction_type'] ?: 'n/a') . '</td>';
        echo '<td>' . $h($r['shop_name'] ?? '') . '</td>';
        echo '<td>' . $h($r['full_name'] ?? '') . '</td>';
        echo '<td>' . $h($r['email'] ?? '') . '</td>';
        echo '<td class="text-center">' . $h($isSub ? ($r['plan_type'] ?? '') : '') . '</td>';
        echo '<td class="nowrap" style="mso-number-format:\'yyyy-mm-dd\';">' . $h($vf ? substr($vf, 0, 10) : '') . '</td>';
        echo '<td class="nowrap" style="mso-number-format:\'yyyy-mm-dd\';">' . $h($vt ? substr($vt, 0, 10) : '') . '</td>';
        echo '<td class="text-right" style="mso-number-format:\'#,##0.00\';">' . $h($grossVal) . '</td>';
        echo '<td class="text-right text-muted" style="mso-number-format:\'#,##0.00\';">' . $h($taxVal) . '</td>';
        echo '<td class="' . $h($statusClass) . ' text-center">' . $h($status) . '</td>';
        echo '<td class="text-center">' . $h($r['payment_method'] ?? '') . '</td>';
        echo '<td class="nowrap" style="mso-number-format:\'yyyy-mm-dd hh:mm\';">' . $h($paidAt ? substr($paidAt, 0, 16) : '') . '</td>';
        echo '<td class="nowrap" style="mso-number-format:\'yyyy-mm-dd hh:mm\';">' . $h($createdAt ? substr($createdAt, 0, 16) : '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}

include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Payments & Subscriptions</h1>
                <div class="text-muted small">Monitor and reconcile platform revenue streams.</div>
            </div>
            <div class="d-flex gap-2">
                <?php
                // Preserve current filters for export links
                $qs = $_GET;
                $qsXls = $qs;
                $qsXls['export'] = 'xls';
                $qsCsv = $qs;
                $qsCsv['export'] = 'csv';
                $exportXlsHref = 'payments.php?' . http_build_query($qsXls);
                $exportCsvHref = 'payments.php?' . http_build_query($qsCsv);
                ?>
                <a class="btn btn-primary btn-sm" href="<?= e($exportXlsHref) ?>" title="Export Excel (.xls)"><i class="bi bi-receipt me-1"></i> Export</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e($exportCsvHref) ?>" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i> CSV</a>
            </div>
        </div>
        <?php if ($flash['error'] || $flash['success']): ?>
            <div class="alert <?= $flash['error'] ? 'alert-danger' : 'alert-success' ?> py-2 small mb-4">
                <?= e($flash['error'] ?: $flash['success']) ?>
            </div>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end small" method="get">
                    <div class="col-sm-4 col-md-3">
                        <label class="form-label text-muted small mb-1">Search</label>
                        <input type="search" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Shop, user or email">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Type</label>
                        <select class="form-select form-select-sm" name="type">
                            <option value="">All</option>
                            <option value="appointment" <?= $typeFilter === 'appointment' ? 'selected' : '' ?>>appointment</option>
                            <option value="subscription" <?= $typeFilter === 'subscription' ? 'selected' : '' ?>>subscription</option>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label text-muted small mb-1">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="">All</option>
                            <?php foreach (['pending', 'completed', 'failed'] as $st): ?>
                                <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $st ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3 d-flex gap-2">
                        <button class="btn btn-secondary btn-sm flex-grow-1">Filter</button>
                        <a href="payments.php" class="btn btn-outline-secondary btn-sm flex-grow-1">Reset</a>
                    </div>
                    <div class="col-sm-2 col-md-2">
                        <label class="form-label text-muted small mb-1">Page Size</label>
                        <select class="form-select form-select-sm" disabled>
                            <option>25</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Transactions</h5>
                <span class="badge text-bg-primary"><?= number_format($totalRows) ?> found</span>
            </div>
            <div class="table-responsive small">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Shop</th>
                            <th>User</th>
                            <th>Plan</th>
                            <th class="text-end">Gross</th>
                            <th class="text-end text-muted">Tax</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Paid/Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$transactions): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No transactions found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                $badgeClass = match ($t['payment_status']) {
                                    'completed' => 'success',
                                    'pending' => 'warning text-dark',
                                    'failed' => 'danger',
                                    default => 'secondary'
                                };
                                ?>
                                <tr>
                                    <td>#<?= (int)$t['payment_id'] ?></td>
                                    <td><span class="badge bg-info-subtle text-info" style="font-size:.65rem;"><?= e($t['transaction_type'] ?: 'n/a') ?></span></td>
                                    <td class="text-truncate" style="max-width:140px;" title="<?= e($t['shop_name']) ?>"><?= e($t['shop_name'] ?: '—') ?></td>
                                    <td class="text-truncate" style="max-width:160px;" title="<?= e($t['full_name']) ?>"><?= e($t['full_name']) ?></td>
                                    <td class="text-end fw-semibold">₱<?= number_format($t['amount'], 2) ?></td>
                                    <td style="font-size:.6rem;">
                                        <?php if ($t['transaction_type'] === 'subscription'): ?>
                                            <span class="d-block text-muted"><?= e($t['plan_type'] ?? '—') ?></span>
                                            <?php if (!empty($t['valid_from']) && !empty($t['valid_to'])): ?>
                                                <span class="text-muted"><?= e(date('y-m-d', strtotime($t['valid_from'])) . ' → ' . date('y-m-d', strtotime($t['valid_to']))) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-muted">₱<?= number_format($t['tax_amount'], 2) ?></td>
                                    <td><span class="badge bg-<?= $badgeClass ?>" style="font-size:.6rem; text-transform:uppercase;"><?= e($t['payment_status']) ?></span></td>
                                    <td><span class="text-muted" style="font-size:.65rem;"><?= e($t['payment_method']) ?></span></td>
                                    <td style="font-size:.65rem;">
                                        <?php if ($t['paid_at']): ?>
                                            <span class="text-success" title="Paid at"><?= e(substr($t['paid_at'], 0, 16)) ?></span><br>
                                        <?php endif; ?>
                                        <span class="text-muted" title="Created at"><?= e(substr($t['created_at'], 0, 16)) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($t['open_disputes'] > 0): ?>
                                            <span class="badge text-bg-warning text-dark" title="Open disputes" style="font-size:.55rem;">Dispute</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer py-2 bg-light">
                    <nav>
                        <ul class="pagination pagination-sm mb-0 flex-wrap">
                            <?php
                            // Build base query string excluding page
                            $qs = $_GET;
                            unset($qs['page']);
                            $base = 'payments.php' . ($qs ? ('?' . http_build_query($qs)) : '');
                            $sep = (strpos($base, '?') !== false) ? '&' : '?';
                            ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $page <= 1 ? '#' : e($base . $sep . 'page=' . ($page - 1)) ?>">«</a></li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): if ($i > 6 && $i < $totalPages) {
                                    if ($i == 7) echo '<li class=\'page-item disabled\'><span class=\'page-link\'>…</span></li>';
                                    continue;
                                } ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($base . $sep . 'page=' . $i) ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $page >= $totalPages ? '#' : e($base . $sep . 'page=' . ($page + 1)) ?>">»</a></li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
        <div class="row g-4">
            <!-- Offline Subscription Confirmations -->
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Pending Offline Subscriptions</h6>
                        <span class="badge text-bg-secondary"><?= count($offlinePending) ?></span>
                    </div>
                    <div class="card-body small">
                        <?php if (!$offlinePending): ?>
                            <p class="text-muted mb-0">No pending offline confirmations.</p>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height:300px;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Shop</th>
                                            <th>Plan</th>
                                            <th>Range</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($offlinePending as $s): $range = e(date('y-m-d', strtotime($s['valid_from'])) . ' → ' . date('y-m-d', strtotime($s['valid_to']))); ?>
                                            <tr>
                                                <td class="text-truncate" style="max-width:120px;" title="<?= e($s['shop_name']) ?>"><?= e($s['shop_name']) ?></td>
                                                <td><?= e(ucfirst($s['plan_type'])) ?></td>
                                                <td><span class="text-muted" style="font-size:.65rem;"><?= $range ?></span></td>
                                                <td>
                                                    <form method="post" class="d-flex gap-1">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="confirm_offline">
                                                        <input type="hidden" name="subscription_id" value="<?= (int)$s['subscription_id'] ?>">
                                                        <input type="number" step="0.01" min="0" name="amount" value="<?= number_format((float)$s['annual_fee'], 2, '.', '') ?>" class="form-control form-control-sm" style="width:90px;" required>
                                                        <button class="btn btn-success btn-sm" title="Confirm offline payment"><i class="bi bi-check2"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <p class="text-muted mt-2 mb-0" style="font-size:.65rem;">Use this list when owners pay via bank deposit, cash, or GCash outside automated gateway.</p>
                    </div>
                </div>
            </div>
            <!-- Disputes -->
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Open Disputes</h6>
                        <span class="badge text-bg-secondary"><?= count($openDisputes) ?></span>
                    </div>
                    <div class="card-body small">
                        <?php if (!$openDisputes): ?>
                            <p class="text-muted mb-2">No open disputes.</p>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height:300px;">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Payment</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($openDisputes as $d): ?>
                                            <tr>
                                                <td>#<?= (int)$d['dispute_id'] ?></td>
                                                <td><span class="text-muted" style="font-size:.65rem;">P#<?= (int)$d['payment_id'] ?></span><br><strong><?= number_format($d['amount'], 2) ?></strong></td>
                                                <td class="text-truncate" style="max-width:120px;" title="<?= e($d['reason']) ?>"><?= e($d['reason']) ?></td>
                                                <td><span class="badge text-bg-warning text-dark" style="font-size:.6rem;"><?= e($d['status']) ?></span></td>
                                                <td>
                                                    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#disputeForm<?= (int)$d['dispute_id'] ?>" aria-expanded="false" aria-controls="disputeForm<?= (int)$d['dispute_id'] ?>" style="font-size:.65rem;">Update</button>
                                                </td>
                                            </tr>
                                            <tr class="collapse" id="disputeForm<?= (int)$d['dispute_id'] ?>">
                                                <td colspan="5" class="bg-light">
                                                    <form method="post" class="row g-2 align-items-end p-2 border rounded small bg-white">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="update_dispute">
                                                        <input type="hidden" name="dispute_id" value="<?= (int)$d['dispute_id'] ?>">
                                                        <div class="col-4">
                                                            <label class="form-label mb-1 small">Status</label>
                                                            <select name="status_new" class="form-select form-select-sm" required>
                                                                <?php foreach (['open', 'in_review', 'resolved', 'rejected'] as $st): ?>
                                                                    <option value="<?= $st ?>" <?= $st === $d['status'] ? 'selected' : '' ?>><?= $st ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-8">
                                                            <label class="form-label mb-1 small">Resolution Notes</label>
                                                            <input type="text" name="resolution_notes" class="form-control form-control-sm" placeholder="Optional" />
                                                        </div>
                                                        <div class="col-12 text-end">
                                                            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <hr class="my-3">
                        <form method="post" class="row g-2 small">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="open_dispute">
                            <div class="col-4">
                                <label class="form-label mb-1 small">Payment ID</label>
                                <input type="number" name="payment_id" min="1" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-8">
                                <label class="form-label mb-1 small">Reason</label>
                                <input type="text" name="reason" maxlength="120" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-1 small">Description (optional)</label>
                                <textarea name="description" rows="2" class="form-control form-control-sm"></textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-outline-primary btn-sm"><i class="bi bi-flag me-1"></i>Open Dispute</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <!-- Add Offline Subscription -->
            <div class="col-xl-3">
                <div class="card mb-4 h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Add Offline Subscription</h6>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_offline_subscription">
                            <div class="col-12">
                                <label class="form-label mb-1 small">Shop</label>
                                <select name="shop_id" class="form-select form-select-sm" required>
                                    <option value="">Select shop…</option>
                                    <?php foreach ($shopsList as $s): ?>
                                        <option value="<?= (int)$s['shop_id'] ?>"><?= e($s['shop_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1 small">Plan</label>
                                <select name="plan_type" id="planSelect" class="form-select form-select-sm">
                                    <option value="monthly">Monthly</option>
                                    <option value="annual" selected>Annual</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1 small">Start Date</label>
                                <input type="date" name="valid_from" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1 small">Amount</label>
                                <input type="number" step="0.01" min="0" name="amount" id="amountInput" class="form-control form-control-sm" placeholder="0.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1 small">Tax Rate (%)</label>
                                <input type="number" step="0.01" min="0" name="tax_rate" class="form-control form-control-sm" placeholder="0" value="0">
                            </div>
                            <div class="col-12 form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="auto_renew" id="autoRenewChk" checked>
                                <label class="form-check-label" for="autoRenewChk" style="font-size:.85rem;">Enable auto-renew</label>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create</button>
                            </div>
                            <div class="col-12">
                                <p class="text-muted mb-0" style="font-size:.65rem;">Creates a pending subscription entry for the selected shop. You can confirm payment later from the Pending Offline Subscriptions list.</p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
    // Auto-fill amount based on plan selection
    document.addEventListener('DOMContentLoaded', function() {
        var planEl = document.getElementById('planSelect');
        var amountEl = document.getElementById('amountInput');
        if (!planEl || !amountEl) return;
        var applyDefault = function() {
            var plan = planEl.value;
            if (plan === 'monthly') {
                amountEl.value = '399';
            } else {
                amountEl.value = '3999';
            }
        };
        // Set default on load based on selected plan
        applyDefault();
        // Update when plan changes
        planEl.addEventListener('change', applyDefault);
    });
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>