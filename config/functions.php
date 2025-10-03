<?php
// Shared application-level helper / domain functions (beyond basic auth helpers)
// Make sure db + session + auth utilities are loaded by callers.

if (!isset($pdo)) {
    // Attempt to include DB if not already present
    $dbPath = __DIR__ . '/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
    }
}

/**
 * Fetch aggregated user counts grouped by role.
 * Returns array:
 * [ 'total' => int, 'customers' => int, 'owners' => int, 'admins' => int ]
 */
function get_user_counts(): array
{
    global $pdo;
    if (!$pdo) return [];
    $sql = "SELECT role, COUNT(*) c FROM Users GROUP BY role";
    $stmt = $pdo->query($sql);
    $out = ['total' => 0, 'customers' => 0, 'owners' => 0, 'admins' => 0];
    foreach ($stmt as $row) {
        $role = $row['role'];
        $count = (int)$row['c'];
        $out['total'] += $count;
        if ($role === 'customer') $out['customers'] = $count;
        elseif ($role === 'owner') $out['owners'] = $count;
        elseif ($role === 'admin') $out['admins'] = $count;
    }
    return $out;
}

/**
 * Barbershop status counts.
 * Returns: ['total'=>..,'pending'=>..,'approved'=>..,'rejected'=>..]
 */
function get_shop_counts(): array
{
    global $pdo;
    if (!$pdo) return [];
    $sql = "SELECT status, COUNT(*) c FROM Barbershops GROUP BY status";
    $stmt = $pdo->query($sql);
    $out = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($stmt as $row) {
        $status = $row['status'];
        $count = (int)$row['c'];
        $out['total'] += $count;
        if (isset($out[$status])) $out[$status] = $count;
    }
    return $out;
}

/**
 * Revenue summary from subscriptions & payments.
 * Returns numeric values (floats) keyed.
 */
function get_revenue_summary(): array
{
    global $pdo;
    if (!$pdo) return [];
    $summary = [
        'subscription_paid' => 0.0,
        'subscription_pending' => 0.0,
        'appointment_payments' => 0.0,
        'failed_payments' => 0.0,
        'tax_collected' => 0.0
    ];
    // Subscription fees (assume Payments table holds final amounts incl tax maybe; here we derive)
    $q1 = $pdo->query("SELECT SUM(annual_fee) s_paid FROM Shop_Subscriptions WHERE payment_status='paid'");
    $summary['subscription_paid'] = (float)($q1->fetchColumn() ?: 0);
    $q1b = $pdo->query("SELECT SUM(annual_fee) s_pending FROM Shop_Subscriptions WHERE payment_status='pending'");
    $summary['subscription_pending'] = (float)($q1b->fetchColumn() ?: 0);
    // Appointment payments (completed)
    $q2 = $pdo->query("SELECT SUM(amount) FROM Payments WHERE payment_status='completed' AND transaction_type='appointment'");
    $summary['appointment_payments'] = (float)($q2->fetchColumn() ?: 0);
    // Failed payments
    $q3 = $pdo->query("SELECT SUM(amount) FROM Payments WHERE payment_status='failed'");
    $summary['failed_payments'] = (float)($q3->fetchColumn() ?: 0);
    // Tax collected
    $q4 = $pdo->query("SELECT SUM(tax_amount) FROM Payments WHERE payment_status='completed'");
    $summary['tax_collected'] = (float)($q4->fetchColumn() ?: 0);
    return $summary;
}

/**
 * Upcoming appointments across system.
 * limit: number of rows.
 */
function get_upcoming_appointments(int $limit = 5): array
{
    global $pdo;
    if (!$pdo) return [];
    $stmt = $pdo->prepare("SELECT a.appointment_id, a.appointment_date, a.status, u.full_name AS customer, b.shop_name
		FROM Appointments a
		JOIN Users u ON a.customer_id = u.user_id
		JOIN Barbershops b ON a.shop_id = b.shop_id
		WHERE a.appointment_date >= NOW() AND a.status IN ('pending','confirmed')
		ORDER BY a.appointment_date ASC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Quick alerts (arrays of messages) detecting potential admin actions.
 */
function get_quick_alerts(): array
{
    global $pdo;
    if (!$pdo) return [];
    $alerts = [];
    // Expiring subscriptions in next 15 days
    $exp = $pdo->query("SELECT COUNT(*) FROM Shop_Subscriptions WHERE payment_status='paid' AND valid_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)");
    $countExp = (int)$exp->fetchColumn();
    if ($countExp > 0) $alerts[] = "$countExp subscription(s) expiring within 15 days";
    // Failed payments last 7 days
    $failed = $pdo->query("SELECT COUNT(*) FROM Payments WHERE payment_status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $failedCount = (int)$failed->fetchColumn();
    if ($failedCount > 0) $alerts[] = "$failedCount failed payment(s) in last 7 days";
    // Pending shops
    $pendingShops = $pdo->query("SELECT COUNT(*) FROM Barbershops WHERE status='pending'");
    $pendCount = (int)$pendingShops->fetchColumn();
    if ($pendCount > 0) $alerts[] = "$pendCount barbershop(s) awaiting approval";
    // Unverified owners (if is_verified field exists)
    try {
        $unv = $pdo->query("SELECT COUNT(*) FROM Users WHERE role='owner' AND is_verified=0");
        $unvCount = (int)$unv->fetchColumn();
        if ($unvCount > 0) $alerts[] = "$unvCount owner account(s) unverified";
    } catch (Throwable $e) { /* field might not exist */
    }
    // Recent critical admin actions (last 24h) from log file (lightweight parse)
    try {
        $logDir = dirname(__DIR__) . '/logs';
        $logFile = $logDir . '/admin_actions.log';
        if (is_readable($logFile)) {
            $cut = time() - 86400; // 24h
            $criticalCounts = ['verify_owner' => 0, 'hard_delete' => 0, 'soft_delete' => 0];
            $fh = @fopen($logFile, 'r');
            if ($fh) {
                // Read last ~200 lines (seek from end) for efficiency
                $lines = [];
                $buffer = '';
                $pos = -1;
                $lineCount = 0;
                $maxLines = 200;
                $fileSize = filesize($logFile);
                while ($lineCount < $maxLines && (-$pos) <= $fileSize) {
                    fseek($fh, $pos, SEEK_END);
                    $char = fgetc($fh);
                    if ($char === "\n") {
                        if ($buffer !== '') {
                            $lines[] = strrev($buffer);
                            $buffer = '';
                            $lineCount++;
                        }
                    } else {
                        $buffer .= $char;
                    }
                    $pos--;
                }
                if ($buffer !== '') $lines[] = strrev($buffer);
                fclose($fh);
                foreach ($lines as $ln) {
                    $data = json_decode($ln, true);
                    if (!is_array($data)) continue;
                    if (!isset($data['ts']) || strtotime($data['ts']) < $cut) continue;
                    $a = $data['action'] ?? '';
                    if (isset($criticalCounts[$a])) $criticalCounts[$a]++;
                }
                foreach ($criticalCounts as $act => $cnt) {
                    if ($cnt > 0) {
                        if ($act === 'verify_owner') $alerts[] = "$cnt owner verification(s) in last 24h";
                        elseif ($act === 'hard_delete') $alerts[] = "$cnt hard delete(s) in last 24h";
                        elseif ($act === 'soft_delete') $alerts[] = "$cnt user soft delete(s) in last 24h";
                    }
                }
            }
        }
    } catch (Throwable $e) { /* ignore log parsing issues */
    }
    return $alerts;
}

/* ========== Admin Action Logging (with rotation) ========== */
if (!function_exists('rotate_admin_log')) {
    function rotate_admin_log(int $maxBytes = 5242880): void // 5MB default
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $file = $logDir . '/admin_actions.log';
        if (file_exists($file) && filesize($file) >= $maxBytes) {
            $ts = date('Ymd_His');
            $archive = $logDir . '/admin_actions-' . $ts . '.log';
            @rename($file, $archive);
        }
    }
}

if (!function_exists('log_admin_action')) {
    function log_admin_action(int $adminId, string $action, ?string $targetType = null, $targetId = null, array $meta = []): void
    {
        rotate_admin_log();
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $file = $logDir . '/admin_actions.log';
        $record = [
            'ts' => date('c'),
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'meta' => $meta
        ];
        @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Determine if an owner account meets all verification prerequisites.
 * Returns array with:
 *  [ 'ready' => bool, 'missing' => string[], 'details' => array ]
 * Criteria:
 *  - At least one government ID (front & back) approved OR both pending but not rejected
 *  - Selfie (optional but recommended) not enforced here yet
 *  - Business permit approved
 *  - Sanitation & tax certificate approved
 *  - Shop exists & at least 1 approved shop photo
 *  - At least 1 service listed for the shop
 *  - (Removed) Active subscription requirement: Verification no longer depends on subscription to avoid circular block.
 */
function evaluate_owner_verification(int $ownerId): array
{
    global $pdo;
    $result = ['ready' => false, 'missing' => [], 'details' => []];
    if (!$pdo || $ownerId <= 0) return $result;
    // Fetch primary shop (assume first)
    $shopId = null;
    $stmt = $pdo->prepare("SELECT shop_id FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC LIMIT 1");
    $stmt->execute([$ownerId]);
    $shopId = $stmt->fetchColumn();
    if (!$shopId) {
        $result['missing'][] = 'shop_registration';
    }
    // Documents grouped
    $docs = [];
    try {
        $dStmt = $pdo->prepare("SELECT doc_type, status FROM Documents WHERE owner_id=?" . ($shopId ? " OR shop_id=?" : ""));
        if ($shopId) $dStmt->execute([$ownerId, $shopId]);
        else $dStmt->execute([$ownerId]);
        foreach ($dStmt as $row) {
            $docs[$row['doc_type']][] = $row['status'];
        }
    } catch (Throwable $e) {
        // Documents table may not exist yet
        $result['missing'][] = 'documents_table';
    }
    // Helper to check approved doc
    $hasApproved = function (string $type) use ($docs): bool {
        if (!isset($docs[$type])) return false;
        foreach ($docs[$type] as $st) if ($st === 'approved') return true;
        return false;
    };
    if (!($hasApproved('personal_id_front') && $hasApproved('personal_id_back'))) {
        $result['missing'][] = 'government_id';
    }
    if (!$hasApproved('business_permit')) $result['missing'][] = 'business_permit';
    if (!$hasApproved('sanitation_certificate')) $result['missing'][] = 'sanitation_certificate';
    if (!$hasApproved('tax_certificate')) $result['missing'][] = 'tax_certificate';
    // Shop related checks
    if ($shopId) {
        // Shop photos
        $photoApproved = $hasApproved('shop_photo');
        if (!$photoApproved) $result['missing'][] = 'shop_photo';
        // Services
        $svc = $pdo->prepare("SELECT COUNT(*) FROM Services WHERE shop_id=?");
        $svc->execute([$shopId]);
        if ((int)$svc->fetchColumn() < 1) $result['missing'][] = 'services';
        // Subscription no longer required for verification; owners can subscribe only AFTER verification.
    }
    $result['ready'] = empty($result['missing']);
    $result['details'] = ['shop_id' => $shopId, 'docs_found' => array_keys($docs)];
    return $result;
}

/**
 * Determine if a shop currently has an active paid subscription.
 */
function is_subscribed(int $shopId): bool
{
    global $pdo;
    if (!$pdo || $shopId <= 0) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Shop_Subscriptions WHERE shop_id=? AND payment_status='paid' AND CURDATE() BETWEEN valid_from AND valid_to");
    $stmt->execute([$shopId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

/**
 * Activate a subscription (creates Shop_Subscriptions + Payments rows transactionally)
 * Params:
 *  - ownerId
 *  - shopId (target shop)
 *  - planType: monthly|yearly
 *  - baseAmount (numeric)
 *  - taxRate percent (e.g. 12 for 12%)
 * Returns array [success=>bool, message=>string]
 */
function activate_subscription(int $ownerId, int $shopId, string $planType, float $baseAmount, float $taxRate = 0.0): array
{
    global $pdo;
    if (!$pdo) return ['success' => false, 'message' => 'DB unavailable'];
    $planType = $planType === 'monthly' ? 'monthly' : 'yearly';
    if ($ownerId <= 0 || $shopId <= 0) return ['success' => false, 'message' => 'Invalid owner or shop'];
    // Enforce owner account verification prior to purchasing a subscription
    try {
        $vStmt = $pdo->prepare("SELECT is_verified FROM Users WHERE user_id=? LIMIT 1");
        $vStmt->execute([$ownerId]);
        $isVerified = (int)$vStmt->fetchColumn();
        if ($isVerified !== 1) {
            return ['success' => false, 'message' => 'Your owner account is not yet verified. Complete verification steps before subscribing.'];
        }
    } catch (Throwable $e) {
        // If the field is missing treat as not verified for safety
        return ['success' => false, 'message' => 'Verification status unavailable. Please contact support.'];
    }
    // Prevent duplicate overlapping subscriptions: check if there is a paid subscription that is still valid (valid_to >= today)
    $dupStmt = $pdo->prepare("SELECT subscription_id, valid_to FROM Shop_Subscriptions WHERE shop_id=? AND payment_status='paid' AND valid_to >= CURDATE() ORDER BY valid_to DESC LIMIT 1");
    $dupStmt->execute([$shopId]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return ['success' => false, 'message' => 'An active subscription already exists until ' . $existing['valid_to']];
    }
    // derive validity dates
    $validFrom = date('Y-m-d');
    if ($planType === 'monthly') {
        $validTo = date('Y-m-d', strtotime('+1 month -1 day'));
    } else {
        $validTo = date('Y-m-d', strtotime('+1 year -1 day'));
    }
    // Business rule: No tax applied to owner subscription purchases (tax only on customer bookings)
    $taxRate = 0.0;
    $taxAmount = 0.00;
    $total = $baseAmount; // total charged equals base amount
    try {
        $pdo->beginTransaction();
        // Insert subscription (pending first)
        $insSub = $pdo->prepare("INSERT INTO Shop_Subscriptions (shop_id, plan_type, annual_fee, tax_rate, payment_status, valid_from, valid_to) VALUES (?,?,?,?, 'pending', ?, ?)");
        $insSub->execute([$shopId, $planType, $baseAmount, 0.0, $validFrom, $validTo]);
        $subId = (int)$pdo->lastInsertId();
        // Insert payment record (completed - simulated gateway)
        $insPay = $pdo->prepare("INSERT INTO Payments (user_id, shop_id, subscription_id, amount, tax_amount, transaction_type, payment_method, payment_status, paid_at) VALUES (?,?,?,?,0.00,'subscription','online','completed',NOW())");
        $insPay->execute([$ownerId, $shopId, $subId, $baseAmount, 0.00]);
        $payId = (int)$pdo->lastInsertId();
        // Update subscription with payment_id and mark paid
        $upd = $pdo->prepare("UPDATE Shop_Subscriptions SET payment_status='paid', payment_id=? WHERE subscription_id=?");
        $upd->execute([$payId, $subId]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Subscription activated', 'subscription_id' => $subId, 'payment_id' => $payId, 'valid_to' => $validTo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Activation failed: ' . $e->getMessage()];
    }
}

/** Format number short */
function nfmt($num): string
{
    return number_format((float)$num, 2);
}

/** Escape convenience (if helpers not loaded) */
if (!function_exists('e')) {
    function e($str)
    {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/** Return short y-m-d date safely */
if (!function_exists('format_date_short')) {
    function format_date_short($ts): string
    {
        if (!$ts) return '';
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if ($t <= 0) return '';
        return date('y-m-d', $t);
    }
}

/** Map role to bootstrap badge color */
if (!function_exists('role_badge_class')) {
    function role_badge_class(string $role): string
    {
        return $role === 'admin' ? 'primary' : ($role === 'owner' ? 'info' : 'secondary');
    }
}

/** Dynamically resolve hashed admin template asset (first match) */
if (!function_exists('get_admin_template_asset')) {
    function get_admin_template_asset(string $pattern): string
    {
        static $cache = [];
        if (isset($cache[$pattern])) return $cache[$pattern];
        $root = dirname(__DIR__); // project root
        $assetDir = $root . '/Admin-template/dist-modern/assets';
        $matches = glob($assetDir . '/' . $pattern);
        if (!$matches) return $cache[$pattern] = '#';
        $file = basename($matches[0]);
        // base_url available? fallback to relative path
        if (function_exists('base_url')) {
            $cache[$pattern] = base_url('Admin-template/dist-modern/assets/' . $file);
        } else {
            $cache[$pattern] = '/Admin-template/dist-modern/assets/' . $file; // assume root
        }
        return $cache[$pattern];
    }
}

/** Log admin actions to file (JSON lines) */
if (!function_exists('log_admin_action')) {
    function log_admin_action($adminId, string $action, string $targetType, $targetId, array $meta = []): void
    {
        try {
            $root = dirname(__DIR__);
            $logDir = $root . '/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            $file = $logDir . '/admin_actions.log';
            // Simple size-based rotation (>5MB => rotate)
            $max = 5 * 1024 * 1024; // 5MB
            if (file_exists($file) && filesize($file) > $max) {
                $ts = date('Ymd_His');
                @rename($file, $logDir . "/admin_actions-$ts.log");
            }
            $record = [
                'ts' => date('c'),
                'admin_id' => $adminId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'meta' => $meta
            ];
            @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Silently ignore logging errors
        }
    }
}

/**
 * Generate a cryptographically strong temporary password.
 * Ensures at least one lowercase, uppercase, digit and symbol.
 * Excludes visually ambiguous characters to improve support calls.
 */
if (!function_exists('generate_temp_password')) {
    function generate_temp_password(int $length = 12): string
    {
        $length = max(8, min($length, 64));
        $sets = [
            'lower' => 'abcdefghjkmnpqrstuvwxyz', // removed i,l,o
            'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ', // removed I,L,O
            'digit' => '23456789',                // removed 0,1
            'symbol' => '!@#$%&*?-'
        ];
        $all = implode('', $sets);
        $pwd = [];
        // guarantee each set
        foreach ($sets as $pool) {
            $pwd[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        while (count($pwd) < $length) {
            $pwd[] = $all[random_int(0, strlen($all) - 1)];
        }
        // shuffle
        for ($i = count($pwd) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$pwd[$i], $pwd[$j]] = [$pwd[$j], $pwd[$i]];
        }
        return implode('', $pwd);
    }
}

/**
 * Map verification prerequisite codes to readable labels.
 * Returns array of labels in same order.
 */
if (!function_exists('describe_verification_missing')) {
    function describe_verification_missing(array $codes): array
    {
        $map = [
            'shop_registration'    => 'Shop registration',
            'government_id'        => 'Government ID (front & back)',
            'business_permit'      => 'Business permit',
            'sanitation_certificate' => 'Sanitation certificate',
            'tax_certificate'      => 'Tax certificate',
            'shop_photo'           => 'Approved shop photo',
            'services'             => 'At least one service',
            'active_subscription'  => 'Active paid subscription',
            'documents_table'      => 'Documents table / records',
        ];
        $out = [];
        foreach ($codes as $c) {
            $out[] = $map[$c] ?? $c;
        }
        return $out;
    }
}

/** Resolve a date range preset (today, last_7_days, etc.) into [start,end] (Y-m-d). */
if (!function_exists('resolve_report_range')) {
    function resolve_report_range(string $preset, ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = new DateTimeImmutable('today');
        $start = $today;
        $end = $today; // defaults
        switch ($preset) {
            case 'today':
                break;
            case 'yesterday':
                $start = $today->modify('-1 day');
                $end = $today->modify('-1 day');
                break;
            case 'last_7_days':
                $start = $today->modify('-6 days');
                break;
            case 'last_30_days':
                $start = $today->modify('-29 days');
                break;
            case 'this_month':
                $start = $today->modify('first day of this month');
                break;
            case 'last_month':
                $start = $today->modify('first day of last month');
                $end = $today->modify('last day of last month');
                break;
            case 'this_year':
                $start = $today->modify('first day of January ' . $today->format('Y'));
                break;
            case 'last_year':
                $year = (int)$today->format('Y') - 1;
                $start = new DateTimeImmutable("first day of January $year");
                $end = new DateTimeImmutable("last day of December $year");
                break;
            case 'custom':
            default:
                if ($customStart && $customEnd) {
                    $cs = date_create_from_format('Y-m-d', $customStart) ?: $today;
                    $ce = date_create_from_format('Y-m-d', $customEnd) ?: $today;
                    if ($ce < $cs) {
                        $tmp = $cs;
                        $cs = $ce;
                        $ce = $tmp;
                    }
                    $start = DateTimeImmutable::createFromMutable($cs);
                    $end = DateTimeImmutable::createFromMutable($ce);
                }
                break;
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}

/** Basic stub for sending report emails (extend to integrate real mailer). */
if (!function_exists('send_report_email')) {
    function send_report_email(array $recipients, string $subject, string $htmlBody, string $textBody = '', ?string $attachmentPath = null): bool
    {
        // Placeholder: log to file for now
        try {
            $root = dirname(__DIR__);
            $logDir = $root . '/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            $file = $logDir . '/report_emails.log';
            $record = [
                'ts' => date('c'),
                'to' => $recipients,
                'subject' => $subject,
                'html_len' => strlen($htmlBody),
                'text_len' => strlen($textBody),
                'attachment' => $attachmentPath,
            ];
            @file_put_contents($file, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/** Insert a report log skeleton row and return its ID */
if (!function_exists('start_report_log')) {
    function start_report_log(string $reportKey, ?int $scheduleId, string $rangeStart, string $rangeEnd, array $recipients): int
    {
        global $pdo;
        if (!$pdo) return 0;
        $stmt = $pdo->prepare("INSERT INTO Report_Logs (schedule_id, report_key, range_start, range_end, recipients) VALUES (?,?,?,?,?)");
        $stmt->execute([$scheduleId, $reportKey, $rangeStart, $rangeEnd, implode(',', $recipients)]);
        return (int)$pdo->lastInsertId();
    }
}

/** Finalize a report log */
if (!function_exists('finish_report_log')) {
    function finish_report_log(int $logId, string $status, ?string $message = null, ?string $outputPath = null): void
    {
        global $pdo;
        if (!$pdo || $logId <= 0) return;
        $stmt = $pdo->prepare("UPDATE Report_Logs SET status=?, message=?, completed_at=NOW(), output_path=? WHERE log_id=?");
        $stmt->execute([$status, $message, $outputPath, $logId]);
    }
}

/** Compute next run time given frequency (simplified). */
if (!function_exists('compute_next_run')) {
    function compute_next_run(string $frequency): DateTimeImmutable
    {
        $now = new DateTimeImmutable();
        switch ($frequency) {
            case 'daily':
                return $now->modify('+1 day');
            case 'weekly':
                return $now->modify('+7 days');
            case 'monthly':
                return $now->modify('+1 month');
            default:
                return $now->modify('+1 day');
        }
    }
}

/** Process due report schedules (call from cron). */
if (!function_exists('process_due_report_schedules')) {
    function process_due_report_schedules(): array
    {
        global $pdo;
        if (!$pdo) return [];
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT * FROM Report_Schedules WHERE active=1 AND (next_run_at IS NULL OR next_run_at <= ?) ORDER BY next_run_at ASC LIMIT 25");
        $stmt->execute([$now]);
        $processed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sch) {
            [$rangeStart, $rangeEnd] = resolve_report_range($sch['range_preset'], $sch['custom_start'], $sch['custom_end']);
            $recipients = array_filter(array_map('trim', explode(',', $sch['recipients'])));
            $logId = start_report_log($sch['report_key'], (int)$sch['schedule_id'], $rangeStart, $rangeEnd, $recipients);
            // Generate basic summary content placeholder
            // Use new generator functions
            try {
                $data = generate_report_data($sch['report_key'], $rangeStart, $rangeEnd);
                $html = generate_report_html($sch['report_key'], $rangeStart, $rangeEnd, $data);
                $csvPath = null;
                if (in_array($sch['format'], ['csv', 'both'])) {
                    $csvPath = generate_report_csv_file($sch['report_key'], $rangeStart, $rangeEnd, $data);
                }
                $subject = '[Report] ' . $sch['name'];
                $text = strip_tags($html);
                $sent = send_report_email($recipients, $subject, $html, $text, $csvPath);
                finish_report_log($logId, $sent ? 'success' : 'failed', $sent ? 'Email sent' : 'Email send failed', $csvPath);
            } catch (Throwable $e) {
                finish_report_log($logId, 'failed', 'Generation error: ' . $e->getMessage());
            }
            // Update schedule next_run_at & last_run_at
            $next = compute_next_run($sch['frequency']);
            $upd = $pdo->prepare("UPDATE Report_Schedules SET last_run_at=NOW(), next_run_at=? WHERE schedule_id=?");
            $upd->execute([$next->format('Y-m-d H:i:s'), (int)$sch['schedule_id']]);
            $processed[] = ['schedule_id' => (int)$sch['schedule_id'], 'log_id' => $logId];
        }
        return $processed;
    }
}

/** Generate structured data for a given report key */
if (!function_exists('generate_report_data')) {
    function generate_report_data(string $reportKey, string $start, string $end): array
    {
        global $pdo;
        $reportKey = strtolower($reportKey);
        $out = [
            'meta' => [
                'report_key' => $reportKey,
                'generated_at' => date('c'),
                'range_start' => $start,
                'range_end' => $end,
            ],
            'sections' => []
        ];
        $rangeParams = [$start . ' 00:00:00', $end . ' 23:59:59'];
        $rangeDateOnly = [$start, $end];
        $safe = function ($sql, $params) use ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        };
        switch ($reportKey) {
            case 'monthly_summary':
            case 'summary':
                $totalUsers = (int)$safe("SELECT COUNT(*) FROM Users", []);
                $owners = (int)$safe("SELECT COUNT(*) FROM Users WHERE role='owner'", []);
                $customers = (int)$safe("SELECT COUNT(*) FROM Users WHERE role='customer'", []);
                $newUsers = (int)$safe("SELECT COUNT(*) FROM Users WHERE DATE(created_at) BETWEEN ? AND ?", $rangeDateOnly);
                $shops = (int)$safe("SELECT COUNT(*) FROM Barbershops", []);
                $newShops = (int)$safe("SELECT COUNT(*) FROM Barbershops WHERE DATE(registered_at) BETWEEN ? AND ?", $rangeDateOnly);
                $bookings = (int)$safe("SELECT COUNT(*) FROM Appointments WHERE appointment_date BETWEEN ? AND ?", $rangeParams);
                $completed = (int)$safe("SELECT COUNT(*) FROM Appointments WHERE status='completed' AND appointment_date BETWEEN ? AND ?", $rangeParams);
                $cancelled = (int)$safe("SELECT COUNT(*) FROM Appointments WHERE status='cancelled' AND appointment_date BETWEEN ? AND ?", $rangeParams);
                $revenue = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM Payments WHERE payment_status='completed' AND paid_at BETWEEN ? AND ?", $rangeParams);
                $subsActive = (int)$safe("SELECT COUNT(*) FROM Shop_Subscriptions WHERE payment_status='paid' AND valid_from <= ? AND valid_to >= ?", [$end, $start]);
                $out['sections'][] = [
                    'title' => 'Key Metrics',
                    'rows' => [
                        ['Metric', 'Value'],
                        ['Total Users', $totalUsers],
                        ['New Users (range)', $newUsers],
                        ['Owners', $owners],
                        ['Customers', $customers],
                        ['Total Shops', $shops],
                        ['New Shops (range)', $newShops],
                        ['Bookings (total)', $bookings],
                        ['Bookings Completed', $completed],
                        ['Bookings Cancelled', $cancelled],
                        ['Revenue (payments)', number_format($revenue, 2)],
                        ['Active Subscriptions', $subsActive],
                    ]
                ];
                break;
            case 'annual_summary':
                // Year vs previous year revenue & bookings
                $year = substr($start, 0, 4);
                $prevYear = (string)((int)$year - 1);
                $revYear = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM Payments WHERE payment_status='completed' AND YEAR(paid_at)=?", [$year]);
                $revPrev = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM Payments WHERE payment_status='completed' AND YEAR(paid_at)=?", [$prevYear]);
                $bookYear = (int)$safe("SELECT COUNT(*) FROM Appointments WHERE YEAR(appointment_date)=?", [$year]);
                $bookPrev = (int)$safe("SELECT COUNT(*) FROM Appointments WHERE YEAR(appointment_date)=?", [$prevYear]);
                $shopsYear = (int)$safe("SELECT COUNT(*) FROM Barbershops WHERE YEAR(registered_at)=?", [$year]);
                $shopsPrev = (int)$safe("SELECT COUNT(*) FROM Barbershops WHERE YEAR(registered_at)=?", [$prevYear]);
                $out['sections'][] = [
                    'title' => 'Annual Comparison',
                    'rows' => [
                        ['Metric', $prevYear, $year],
                        ['Revenue', number_format($revPrev, 2), number_format($revYear, 2)],
                        ['Bookings', $bookPrev, $bookYear],
                        ['New Shops', $shopsPrev, $shopsYear],
                    ]
                ];
                break;
            case 'revenue_breakdown':
                $rows = [];
                $stmt = $pdo->prepare("SELECT transaction_type, payment_status, COUNT(*) cnt, COALESCE(SUM(amount),0) total, COALESCE(SUM(tax_amount),0) tax FROM Payments WHERE paid_at BETWEEN ? AND ? GROUP BY transaction_type, payment_status ORDER BY transaction_type, payment_status");
                $stmt->execute($rangeParams);
                $rows[] = ['Type', 'Status', 'Count', 'Amount', 'Tax'];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [$r['transaction_type'] ?: '-', $r['payment_status'], $r['cnt'], number_format($r['total'], 2), number_format($r['tax'], 2)];
                }
                $out['sections'][] = ['title' => 'Revenue Breakdown', 'rows' => $rows];
                break;
            case 'bookings_analytics':
                // Status distribution
                $rows = [['Status', 'Count']];
                $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM Appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status ORDER BY c DESC");
                $stmt->execute($rangeParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [$r['status'], $r['c']];
                }
                $out['sections'][] = ['title' => 'Bookings by Status', 'rows' => $rows];
                // Top services
                $srv = [['Service', 'Bookings']];
                $stmt = $pdo->prepare("SELECT s.service_name, COUNT(*) c FROM Appointments a JOIN Services s ON a.service_id=s.service_id WHERE a.appointment_date BETWEEN ? AND ? GROUP BY s.service_name ORDER BY c DESC LIMIT 10");
                $stmt->execute($rangeParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $srv[] = [$r['service_name'], $r['c']];
                }
                $out['sections'][] = ['title' => 'Top Services', 'rows' => $srv];
                break;
            case 'user_activity':
                $rows = [['Role', 'New Users']];
                $stmt = $pdo->prepare("SELECT role, COUNT(*) c FROM Users WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY role");
                $stmt->execute($rangeDateOnly);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [$r['role'], $r['c']];
                }
                $out['sections'][] = ['title' => 'New Users by Role', 'rows' => $rows];
                break;
            case 'shop_performance':
                // Top shops by booking count
                $top = [['Shop', 'Bookings']];
                $stmt = $pdo->prepare("SELECT b.shop_name, COUNT(*) c FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id WHERE a.appointment_date BETWEEN ? AND ? GROUP BY b.shop_name ORDER BY c DESC LIMIT 10");
                $stmt->execute($rangeParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $top[] = [$r['shop_name'], $r['c']];
                }
                $out['sections'][] = ['title' => 'Top Shops (Bookings)', 'rows' => $top];
                break;
            default:
                $out['sections'][] = ['title' => 'Notice', 'rows' => [['Unsupported report key', $reportKey]]];
        }
        return $out;
    }
}

/** Convert structured report data to simple HTML */
if (!function_exists('generate_report_html')) {
    function generate_report_html(string $reportKey, string $start, string $end, array $data): string
    {
        $html = '<h2 style="margin:0 0 10px;">Report: ' . htmlspecialchars($reportKey) . '</h2>';
        $html .= '<div style="color:#666;font-size:12px;margin-bottom:15px;">Range ' . htmlspecialchars($start) . ' to ' . htmlspecialchars($end) . '</div>';
        foreach ($data['sections'] as $section) {
            $html .= '<h4 style="margin:15px 0 5px;">' . htmlspecialchars($section['title']) . '</h4>';
            $html .= '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-size:12px;min-width:300px;">';
            foreach ($section['rows'] as $i => $row) {
                $html .= '<tr style="' . ($i === 0 ? 'background:#222;color:#fff;' : 'background:#fafafa;') . '">';
                foreach ($row as $cell) {
                    $html .= '<td>' . htmlspecialchars((string)$cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }
        return $html;
    }
}

/** Create a CSV file for the report data in storage/reports and return path */

/* ========== Notification Broadcast Helpers ========== */
if (!function_exists('build_broadcast_targets')) {
    function build_broadcast_targets(string $audience): array
    {
        global $pdo;
        if (!$pdo) return [];
        switch ($audience) {
            case 'owners':
                $stmt = $pdo->query("SELECT user_id FROM Users WHERE role='owner'");
                break;
            case 'customers':
                $stmt = $pdo->query("SELECT user_id FROM Users WHERE role='customer'");
                break;
            case 'all':
            default:
                $stmt = $pdo->query("SELECT user_id FROM Users");
        }
        return array_map(fn($r) => (int)$r['user_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('queue_notification_broadcast')) {
    function queue_notification_broadcast(int $broadcastId, array $userIds, array $channels): void
    {
        global $pdo;
        if (!$pdo || !$userIds) return;
        $ins = $pdo->prepare("INSERT INTO Notification_Queue (broadcast_id,user_id,channel) VALUES (?,?,?)");
        foreach ($userIds as $uid) {
            foreach ($channels as $ch) {
                $ins->execute([$broadcastId, $uid, $ch]);
            }
        }
        // update totals
        $upd = $pdo->prepare("UPDATE Notification_Broadcasts SET total_targets=? WHERE broadcast_id=?");
        $upd->execute([count($userIds) * count($channels), $broadcastId]);
    }
}

if (!function_exists('send_channel_message')) {
    function send_channel_message(string $channel, int $userId, string $title, string $message, array $links = [], ?int $broadcastId = null): bool
    {
        global $pdo;
        // Very basic stub: record system notification & log file; email/sms simulated
        try {
            if ($channel === 'system') {
                $stmt = $pdo->prepare("INSERT INTO Notifications (user_id,message,type) VALUES (?,?,?)");
                $body = $title . ' - ' . $message;
                if ($links) {
                    $parts = [];
                    foreach ($links as $lk) {
                        if (!empty($lk['label']) && !empty($lk['url'])) $parts[] = $lk['label'] . ': ' . $lk['url'];
                    }
                    if ($parts) $body .= "\n" . implode("\n", $parts);
                }
                $stmt->execute([$userId, $body, 'system']);
                // Log system notification to a dedicated file for testing/inspection
                $root = dirname(__DIR__);
                $logDir = $root . '/logs';
                if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
                $notifFile = $logDir . '/notifications_all.log';
                $rec = [
                    'ts' => date('c'),
                    'channel' => 'system',
                    'user_id' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'links' => $links,
                    'broadcast_id' => $broadcastId
                ];
                @file_put_contents($notifFile, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
            } else {
                // email / sms simulated logs
                $root = dirname(__DIR__);
                $logDir = $root . '/logs';
                if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
                $baseRec = [
                    'ts' => date('c'),
                    'channel' => $channel,
                    'user_id' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'links' => $links,
                    'broadcast_id' => $broadcastId
                ];
                // Fetch phone/email if needed
                try {
                    $u = $pdo->prepare("SELECT email, phone, role FROM Users WHERE user_id=?");
                    $u->execute([$userId]);
                    if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                        $baseRec['email'] = $row['email'];
                        $baseRec['phone'] = $row['phone'];
                        $baseRec['role'] = $row['role'];
                    }
                } catch (Throwable $e) {
                }
                $fileUnified = $logDir . '/notify_channels.log';
                @file_put_contents($fileUnified, json_encode($baseRec) . "\n", FILE_APPEND | LOCK_EX);
                if ($channel === 'sms') {
                    // Rotation helper inline (2MB)
                    $rotate = function (string $file, int $maxBytes = 2097152) {
                        if (file_exists($file) && filesize($file) >= $maxBytes) {
                            $ts = date('Ymd_His');
                            @rename($file, $file . '.' . $ts);
                        }
                    };
                    $smsDir = $logDir;
                    $smsAll = $smsDir . '/sms_all.log';
                    $smsRoles = [];
                    $role = $baseRec['role'] ?? 'unknown';
                    $smsRoles[] = $smsDir . '/sms_' . $role . '.log';
                    $rotate($smsAll);
                    foreach ($smsRoles as $rf) $rotate($rf);
                    @file_put_contents($smsAll, json_encode($baseRec) . "\n", FILE_APPEND | LOCK_EX);
                    foreach ($smsRoles as $rf) @file_put_contents($rf, json_encode($baseRec) . "\n", FILE_APPEND | LOCK_EX);
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('process_due_notification_broadcasts')) {
    function process_due_notification_broadcasts(int $batchSize = 100): array
    {
        global $pdo;
        if (!$pdo) return [];
        $now = date('Y-m-d H:i:s');
        // Move eligible broadcasts from queued to sending
        $pdo->exec("UPDATE Notification_Broadcasts SET status='sending', started_at=IF(started_at IS NULL,NOW(),started_at) WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW())");
        // Fetch queue items
        $stmt = $pdo->prepare("SELECT q.queue_id,q.broadcast_id,q.user_id,q.channel,b.title,b.message,b.link1_label,b.link1_url,b.link2_label,b.link2_url,b.link3_label,b.link3_url FROM Notification_Queue q JOIN Notification_Broadcasts b ON q.broadcast_id=b.broadcast_id WHERE q.status='pending' ORDER BY q.queue_id ASC LIMIT ?");
        $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $processed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $links = [];
            for ($i = 1; $i <= 3; $i++) {
                $lbl = $row['link' . $i . '_label'] ?? null;
                $url = $row['link' . $i . '_url'] ?? null;
                if ($lbl && $url) $links[] = ['label' => $lbl, 'url' => $url];
            }
            $ok = send_channel_message($row['channel'], (int)$row['user_id'], $row['title'], $row['message'], $links, (int)$row['broadcast_id']);
            $upd = $pdo->prepare("UPDATE Notification_Queue SET status=?, attempts=attempts+1, last_attempt_at=NOW(), sent_at=IF(?='sent',NOW(),sent_at), error_message=? WHERE queue_id=?");
            $upd->execute([$ok ? 'sent' : 'failed', $ok ? 'sent' : 'failed', $ok ? null : 'Send failed', (int)$row['queue_id']]);
            $processed[] = (int)$row['queue_id'];
        }
        // Update aggregate counts per broadcast
        $aggStmt = $pdo->query("SELECT broadcast_id, SUM(status='sent') sent_cnt, SUM(status='failed') fail_cnt FROM Notification_Queue GROUP BY broadcast_id");
        foreach ($aggStmt->fetchAll(PDO::FETCH_ASSOC) as $agg) {
            $u = $pdo->prepare("UPDATE Notification_Broadcasts SET sent_count=?, fail_count=?, completed_at=IF(sent_count+fail_count>=total_targets,NOW(),completed_at), status=IF(sent_count+fail_count>=total_targets,'completed',status) WHERE broadcast_id=?");
            $u->execute([(int)$agg['sent_cnt'], (int)$agg['fail_cnt'], (int)$agg['broadcast_id']]);
        }
        return $processed;
    }
}
// --- Admin Test SMS Helper (logs only, does not hit real provider) ---
if (!function_exists('generate_report_csv_file')) {
    function generate_report_csv_file(string $reportKey, string $start, string $end, array $data): ?string
    {
        $root = dirname(__DIR__);
        $dir = $root . '/storage/reports';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9_]+/i', '_', $reportKey . '_' . $start . '_' . $end) . '.csv';
        $fp = @fopen($file, 'w');
        if (!$fp) return null;
        fputcsv($fp, ['Report', $reportKey]);
        fputcsv($fp, ['Range', $start, $end]);
        foreach ($data['sections'] as $section) {
            fputcsv($fp, []);
            fputcsv($fp, ['Section', $section['title']]);
            foreach ($section['rows'] as $row) {
                fputcsv($fp, $row);
            }
        }
        fclose($fp);
        return $file;
    }
}

/* ========== Settings Helpers ========== */
if (!function_exists('get_setting')) {
    function get_setting(string $key, $default = null)
    {
        global $pdo;
        if (!$pdo) return $default;
        $stmt = $pdo->prepare("SELECT setting_value FROM Settings WHERE setting_key=?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }
}

if (!function_exists('set_setting')) {
    function set_setting(string $key, $value): bool
    {
        global $pdo;
        if (!$pdo) return false;
        $stmt = $pdo->prepare("REPLACE INTO Settings (setting_key, setting_value) VALUES (?,?)");
        return $stmt->execute([$key, (string)$value]);
    }
}

if (!function_exists('get_all_settings')) {
    function get_all_settings(): array
    {
        global $pdo;
        if (!$pdo) return [];
        $rows = $pdo->query("SELECT setting_key, setting_value FROM Settings")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        return $out;
    }
}

if (!function_exists('ensure_service_category')) {
    function ensure_service_category(string $slug, string $name, ?string $desc = null): bool
    {
        global $pdo;
        if (!$pdo) return false;
        $stmt = $pdo->prepare("INSERT IGNORE INTO Service_Categories (slug,name,description) VALUES (?,?,?)");
        return $stmt->execute([$slug, $name, $desc]);
    }
}

if (!function_exists('list_service_categories')) {
    function list_service_categories(): array
    {
        global $pdo;
        if (!$pdo) return [];
        $stmt = $pdo->query("SELECT * FROM Service_Categories ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('delete_service_category')) {
    function delete_service_category(int $categoryId): bool
    {
        global $pdo;
        if (!$pdo) return false;
        $stmt = $pdo->prepare("DELETE FROM Service_Categories WHERE category_id=?");
        return $stmt->execute([$categoryId]);
    }
}

if (!function_exists('cleanup_retention')) {
    function cleanup_retention(): array
    {
        global $pdo;
        if (!$pdo) return [];
        $daysLogs = (int)(get_setting('retention_logs_days', 90));
        $daysNotifications = (int)(get_setting('retention_notifications_days', 180));
        $res = ['logs_deleted' => 0, 'notifications_deleted' => 0];
        try {
            $stmt = $pdo->prepare("DELETE FROM Notifications WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$daysNotifications]);
            $res['notifications_deleted'] = $stmt->rowCount();
        } catch (Throwable $e) { /* ignore */
        }
        return $res;
    }
}
