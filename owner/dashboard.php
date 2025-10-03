<?php
require_once __DIR__ . '/../config/auth.php';

require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

// Ensure we have verification status
$isVerified = (int)($user['is_verified'] ?? -1);
if ($isVerified === -1) {
    $vStmt = $pdo->prepare("SELECT is_verified FROM Users WHERE user_id=? LIMIT 1");
    $vStmt->execute([$ownerId]);
    $isVerified = (int)$vStmt->fetchColumn();
}

// Aggregated metrics (shops total + approved)
$stmt = $pdo->prepare("SELECT COUNT(*) AS total_cnt, SUM(status='approved') AS approved_cnt FROM Barbershops WHERE owner_id=?");
$stmt->execute([$ownerId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_cnt' => 0, 'approved_cnt' => 0];
$shopsTotal = (int)$row['total_cnt'];
$shopsApproved = (int)$row['approved_cnt'];

// Appointments today & upcoming 7 days
$stmt = $pdo->prepare("SELECT 
    SUM(DATE(appointment_date)=CURDATE()) AS today_cnt,
    SUM(appointment_date >= NOW() AND appointment_date < DATE_ADD(NOW(), INTERVAL 7 DAY)) AS next7
    FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id WHERE b.owner_id=?");
$stmt->execute([$ownerId]);
$apptStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['today_cnt' => 0, 'next7' => 0];

// Revenue last 30 days (completed appointment payments linked to owner shops)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) amt FROM Payments p JOIN Barbershops b ON p.shop_id=b.shop_id WHERE b.owner_id=? AND p.payment_status='completed' AND p.transaction_type='appointment' AND p.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute([$ownerId]);
$rev30 = (float)$stmt->fetchColumn();

// Reviews last 30 days: count & avg
$stmt = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(AVG(rating),0) avg_rating FROM Reviews r JOIN Barbershops b ON r.shop_id=b.shop_id WHERE b.owner_id=? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stmt->execute([$ownerId]);
$revStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'avg_rating' => 0];

// Upcoming appointments (next 8)
$upStmt = $pdo->prepare("SELECT a.appointment_id,a.appointment_date,a.status,a.payment_option,a.is_paid,s.service_name,b.shop_name FROM Appointments a JOIN Barbershops b ON a.shop_id=b.shop_id JOIN Services s ON a.service_id=s.service_id WHERE b.owner_id=? AND a.appointment_date >= NOW() ORDER BY a.appointment_date ASC LIMIT 8");
$upStmt->execute([$ownerId]);
$upcoming = $upStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Recent reviews (last 5)
$revStmt = $pdo->prepare("SELECT r.review_id,r.rating,LEFT(r.comment,160) comment,r.created_at,b.shop_name FROM Reviews r JOIN Barbershops b ON r.shop_id=b.shop_id WHERE b.owner_id=? ORDER BY r.created_at DESC LIMIT 5");
$revStmt->execute([$ownerId]);
$recentReviews = $revStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Simple revenue sparkline data (14 days)
$sparkStmt = $pdo->prepare("SELECT DATE(p.paid_at) d, COALESCE(SUM(p.amount),0) amt FROM Payments p JOIN Barbershops b ON p.shop_id=b.shop_id WHERE b.owner_id=? AND p.payment_status='completed' AND p.transaction_type='appointment' AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(p.paid_at) ORDER BY d ASC");
$sparkStmt->execute([$ownerId]);
$sparkRows = $sparkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sparkMap = [];
foreach ($sparkRows as $r) $sparkMap[$r['d']] = (float)$r['amt'];
$sparkSeries = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $sparkSeries[] = ['d' => substr($day, 5), 'v' => (float)($sparkMap[$day] ?? 0)];
}
$maxSpark = max(array_column($sparkSeries, 'v')) ?: 1;

// -------------------------------------------------------------
// Document Upload Handling (verification documents submission)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_verification_docs' && !$isVerified) {
    // rudimentary CSRF using session token if available
    $csrfOk = true;
    if (function_exists('verify_csrf')) {
        $csrfOk = verify_csrf($_POST['csrf'] ?? '');
    }
    $docErrors = [];
    if (!$csrfOk) {
        $docErrors[] = 'Session expired. Please refresh and try again.';
    }
    // Ensure Documents table exists
    $docsTableExists = false;
    try {
        $pdo->query("SELECT 1 FROM Documents LIMIT 1");
        $docsTableExists = true;
    } catch (Throwable $e) {
        $docErrors[] = 'Document storage unavailable.';
    }
    if ($docsTableExists && empty($docErrors)) {
        $targetDir = dirname(__DIR__) . '/storage/documents';
        if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
        $ins = $pdo->prepare("INSERT INTO Documents (owner_id, shop_id, doc_type, file_path, status) VALUES (?,?,?,?, 'pending')");
        $shopIdForDocs = null;
        $ps = $pdo->prepare("SELECT shop_id FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC LIMIT 1");
        $ps->execute([$ownerId]);
        $shopIdForDocs = $ps->fetchColumn() ?: null;

        $storeFile = function ($field, string $docType) use (&$docErrors, $targetDir, $ins, $ownerId, $shopIdForDocs) {
            if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return;
            $f = $_FILES[$field];
            $tmp = $f['tmp_name'];
            if (!is_uploaded_file($tmp)) return;
            $mime = mime_content_type($tmp);
            if (strpos($mime, 'image/') !== 0) {
                $docErrors[] = 'File for ' . $docType . ' must be an image.';
                return;
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!preg_match('/^(jpe?g|png|webp)$/', $ext)) $ext = 'jpg';
            $name = $docType . '_' . $ownerId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $dest = $targetDir . '/' . $name;
            if (!move_uploaded_file($tmp, $dest)) {
                $docErrors[] = 'Failed to store ' . $docType . ' file.';
                return;
            }
            $relPath = 'storage/documents/' . $name; // relative for serving
            try {
                $ins->execute([$ownerId, $shopIdForDocs, $docType, $relPath]);
            } catch (Throwable $e) {
                $docErrors[] = 'DB error saving ' . $docType;
            }
        };

        // Government ID front & back (separate slots)
        $storeFile('valid_id_front', 'personal_id_front');
        $storeFile('valid_id_back', 'personal_id_back');
        // Business permit like doc
        $storeFile('business_permit', 'business_permit');
        // Sanitation certificate
        $storeFile('sanitation_certificate', 'sanitation_certificate');
        // Tax certificate
        $storeFile('tax_certificate', 'tax_certificate');
        // Multiple shop photos
        if (isset($_FILES['shop_photos']) && is_array($_FILES['shop_photos']['name'])) {
            $count = count($_FILES['shop_photos']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['shop_photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $tmpName = $_FILES['shop_photos']['tmp_name'][$i];
                if (!is_uploaded_file($tmpName)) continue;
                $mime = mime_content_type($tmpName);
                if (strpos($mime, 'image/') !== 0) {
                    $docErrors[] = 'Shop photo must be image.';
                    continue;
                }
                $ext = strtolower(pathinfo($_FILES['shop_photos']['name'][$i], PATHINFO_EXTENSION));
                if (!preg_match('/^(jpe?g|png|webp)$/', $ext)) $ext = 'jpg';
                $name = 'shop_photo_' . $ownerId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $dest = $targetDir . '/' . $name;
                if (!move_uploaded_file($tmpName, $dest)) {
                    $docErrors[] = 'Failed to store shop photo.';
                    continue;
                }
                $relPath = 'storage/documents/' . $name;
                try {
                    $ins->execute([$ownerId, $shopIdForDocs, 'shop_photo', $relPath]);
                } catch (Throwable $e) {
                    $docErrors[] = 'DB error saving shop photo';
                }
            }
        }
    }
    if (empty($docErrors)) {
        header('Location: dashboard.php?docs=submitted');
        exit;
    }
}

// Detect pending documents for status badge
$hasPendingDocs = false;
if (!$isVerified) {
    try {
        $pd = $pdo->prepare("SELECT COUNT(*) FROM Documents WHERE owner_id=? AND status='pending'");
        $pd->execute([$ownerId]);
        $hasPendingDocs = ((int)$pd->fetchColumn()) > 0;
    } catch (Throwable $e) { /* ignore */
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Owner Dashboard • BarberSure</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <style>
        .rating-star {
            color: #fbbf24;
            font-size: .6rem;
        }

        .rev-rating {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            font-weight: 600;
        }

        .spark span {
            background: linear-gradient(180deg, #f59e0b, #b45309);
        }

        .truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
        }
    </style>
</head>

<body class="owner-shell owner-wrapper">
    <header class="owner-header">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="ownerNav">☰</button>
        <?php $__first = $user ? e(explode(' ', trim($user['full_name']))[0]) : 'Owner'; ?>
        <div class="owner-brand">BarberSure <span style="opacity:.55;font-weight:500;">Owner</span><span class="owner-badge">Welcome <?= $__first ?></span></div>
        <nav id="ownerNav" class="owner-nav">
            <a class="active" href="dashboard.php">Dashboard</a>
            <a href="manage_shop.php">Manage Shop</a>
            <a href="bookings.php">Bookings</a>
            <a href="messages.php">Messages</a>
            <a href="payments.php">Payments</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="owner-main">
        <?php if ($isVerified): ?>
            <section class="card" style="padding:1.2rem 1.25rem 1.35rem;margin-bottom:1.5rem;">
                <h1 style="margin:0;font-weight:600;letter-spacing:.4px;font-size:1.55rem;">Welcome back<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?>!</h1>
                <p style="font-size:.75rem;color:var(--o-text-soft);margin:.6rem 0 0;max-width:780px;line-height:1.55;">Monitor performance across your barbershops: appointments, revenue, and customer feedback — all in one unified view.</p>
            </section>
            <div class="metrics-grid">
                <div class="metric"><span class="metric-label">Total Shops</span><span class="metric-value"><?= $shopsTotal ?></span><span class="metric-trend">Approved: <?= $shopsApproved ?></span></div>
                <div class="metric"><span class="metric-label">Today Appts</span><span class="metric-value"><?= (int)$apptStats['today_cnt'] ?></span><span class="metric-trend">Next 7d: <?= (int)$apptStats['next7'] ?></span></div>
                <div class="metric"><span class="metric-label">Revenue 30d</span><span class="metric-value">₱<?= number_format($rev30, 0) ?></span><span class="metric-trend">Avg/day: ₱<?= number_format($rev30 / 30, 0) ?></span></div>
                <div class="metric"><span class="metric-label">Reviews 30d</span><span class="metric-value"><?= (int)$revStats['cnt'] ?></span><span class="metric-trend">Avg Rating: <?= number_format((float)$revStats['avg_rating'], 1) ?></span></div>
                <div class="metric"><span class="metric-label">Spark Revenue</span>
                    <div class="spark" aria-label="Revenue last 14 days">
                        <?php foreach ($sparkSeries as $pt): $h = $pt['v'] > 0 ? max(2, (int)round(($pt['v'] / $maxSpark) * 34)) : 2; ?><span title="<?= e($pt['d'] . ': ₱' . number_format($pt['v'], 0)) ?>" style="height:<?= $h ?>px;"></span><?php endforeach; ?>
                    </div><span class="metric-trend">Last 14d trend</span>
                </div>
            </div>
            <div class="section-grid">
                <div class="card" style="display:flex;flex-direction:column;gap:.8rem;">
                    <h2 style="font-size:1.05rem;margin:0 0 .2rem;">Quick Actions</h2>
                    <div class="flex wrap gap-sm">
                        <a href="register_shop.php" class="btn btn-primary">Register Shop</a>
                        <a href="manage_shop.php" class="btn">Manage Shops</a>
                        <a href="bookings.php" class="btn">View Bookings</a>
                        <a href="payments.php" class="btn">Payments</a>
                        <a href="profile.php" class="btn">Profile</a>
                    </div>
                </div>
                <div class="card" style="display:flex;flex-direction:column;gap:.7rem;">
                    <h2>Upcoming Appointments</h2>
                    <?php if (!$upcoming): ?><p class="small-muted" style="margin:.2rem 0 .4rem;">No upcoming appointments.</p><?php else: ?>
                        <ul class="list" aria-label="Upcoming appointments">
                            <?php foreach ($upcoming as $a): $dt = strtotime($a['appointment_date']); ?>
                                <li class="list-item">
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">
                                        <strong style="font-size:.72rem;letter-spacing:.4px;"><?= e($a['shop_name']) ?></strong>
                                        <span class="badge badge-status-<?= e($a['status']) ?>"><?= strtoupper(e($a['status'])) ?></span>
                                    </div>
                                    <div class="small-muted" style="display:flex;gap:.6rem;flex-wrap:wrap;">
                                        <span><?= date('M d, g:i A', $dt) ?></span>
                                        <span><?= e($a['service_name']) ?></span>
                                        <span><?= e(strtoupper($a['payment_option'])) ?></span>
                                        <?php if ($a['is_paid']): ?><span class="badge badge-pay-paid">PAID</span><?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="card" style="display:flex;flex-direction:column;gap:.7rem;">
                    <h2>Recent Reviews</h2>
                    <?php if (!$recentReviews): ?><p class="small-muted" style="margin:.2rem 0 .4rem;">No reviews yet.</p><?php else: ?>
                        <ul class="list" aria-label="Recent reviews">
                            <?php foreach ($recentReviews as $r): ?>
                                <li class="list-item">
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">
                                        <strong style="font-size:.72rem;letter-spacing:.4px;"><?= e($r['shop_name']) ?></strong>
                                        <span class="rev-rating"><span class="rating-star">★</span><?= (int)$r['rating'] ?></span>
                                    </div>
                                    <div class="small-muted" style="display:flex;gap:.6rem;flex-wrap:wrap;">
                                        <span><?= date('M d, g:i A', strtotime($r['created_at'])) ?></span>
                                    </div>
                                    <?php if ($r['comment']): ?><div class="truncate" style="font-size:.63rem;line-height:1.45;color:var(--o-text-soft);"><?= e($r['comment']) ?></div><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <footer class="footer">&copy; <?= date('Y') ?> BarberSure • Empowering barbershop owners.</footer>
        <?php else: ?>
            <?php
            // Limited view data: first shop & limited services
            $limShop = null;
            $limServices = [];
            $sStmt = $pdo->prepare("SELECT shop_id, shop_name, city, open_time, close_time, status FROM Barbershops WHERE owner_id=? ORDER BY shop_id ASC LIMIT 1");
            $sStmt->execute([$ownerId]);
            $limShop = $sStmt->fetch(PDO::FETCH_ASSOC);
            if ($limShop) {
                $svcL = $pdo->prepare("SELECT service_name, duration_minutes, price FROM Services WHERE shop_id=? ORDER BY service_name ASC LIMIT 6");
                $svcL->execute([$limShop['shop_id']]);
                $limServices = $svcL->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            ?>
            <style>
                /* Orange / Gold limited mode palette */
                .locked-feature {
                    position: relative;
                    overflow: hidden;
                    min-height: 175px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    padding: 1.25rem 1.1rem;
                    border: 1px dashed #5a3a12;
                    border-radius: 14px;
                    background: #201409;
                }

                .locked-feature.blur::before {
                    content: "";
                    position: absolute;
                    inset: 0;
                    backdrop-filter: blur(3px);
                    background: linear-gradient(135deg, #201409cc, #3a220dd9);
                }

                .locked-overlay {
                    position: absolute;
                    inset: 0;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    padding: 1.15rem;
                    gap: .55rem;
                    z-index: 2;
                    color: #d7c4ad;
                    font-size: .75rem;
                }

                .locked-icon {
                    width: 44px;
                    height: 44px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 14px;
                    background: linear-gradient(135deg, #4a2d12, #2d1a0d);
                    box-shadow: 0 0 0 1px #704015 inset, 0 4px 10px -4px #000;
                    transition: transform .25s ease, box-shadow .25s ease;
                }

                .locked-feature:hover .locked-icon {
                    transform: translateY(-3px);
                    box-shadow: 0 0 0 1px #8b5a23 inset, 0 6px 14px -6px #000;
                }

                .locked-feature:focus-within .locked-icon {
                    outline: 2px solid #f59e0b;
                    outline-offset: 2px;
                }

                .locked-icon svg {
                    width: 22px;
                    height: 22px;
                    stroke: #fcd34d;
                    stroke-width: 1.5;
                    fill: none;
                }

                .cta-banner {
                    background: linear-gradient(135deg, #3a230d, #2a1707);
                    border: 1px solid #5c3b16;
                    padding: 1.2rem 1.25rem 1.3rem;
                    border-radius: 14px;
                    margin: 0 0 1.4rem;
                    display: flex;
                    flex-direction: column;
                    gap: .7rem;
                }

                .cta-banner h1 {
                    margin: 0;
                    font-size: 1.45rem;
                    font-weight: 700;
                    letter-spacing: .5px;
                    background: linear-gradient(90deg, #fff3c4, #fbbf24, #f59e0b);
                    -webkit-background-clip: text;
                    background-clip: text;
                    color: transparent;
                }

                .cta-banner p {
                    margin: 0;
                    font-size: .82rem;
                    line-height: 1.6;
                    color: #e8d7c2;
                }

                .btn-accent {
                    background: linear-gradient(90deg, #f59e0b, #d97706);
                    color: #2d1400;
                    font-weight: 700;
                    border: 0;
                    padding: .7rem 1.2rem;
                    border-radius: 10px;
                    font-size: .78rem;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: .45rem;
                    box-shadow: 0 0 0 1px #b45309 inset, 0 2px 6px -2px #000, 0 6px 14px -4px #00000066;
                }

                .btn-accent:hover {
                    filter: brightness(1.05);
                }

                .btn-outline {
                    background: #2a1a0d;
                    border: 1px solid #7a4a16;
                    color: #f8e7d2;
                    padding: .65rem 1.05rem;
                    border-radius: 10px;
                    font-size: .72rem;
                    text-decoration: none;
                    font-weight: 600;
                }

                .btn-outline:hover {
                    background: #3a240f;
                }

                .lim-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 1rem;
                    margin: 0 0 1.6rem;
                }

                .mini-note {
                    font-size: .6rem;
                    color: #c8b49b;
                    margin: .4rem 0 0;
                }

                .shop-box {
                    background: radial-gradient(circle at 25% 20%, #3a210d 0%, #2b190c 55%, #231207 100%);
                    border: 1px solid #6a3b16;
                    padding: 1.3rem 1.25rem 1.35rem;
                    border-radius: 18px;
                    display: flex;
                    flex-direction: column;
                    gap: .9rem;
                    position: relative;
                    box-shadow: 0 4px 10px -4px #000, 0 0 0 1px #834919 inset;
                }

                .shop-box:before {
                    content: "";
                    position: absolute;
                    inset: 0;
                    border-radius: inherit;
                    background: linear-gradient(140deg, #f59e0b22, #0000 60%);
                    pointer-events: none;
                }

                .shop-header-flex {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: .75rem;
                }

                .shop-box h2 {
                    margin: 0;
                    font-size: 1.25rem;
                    letter-spacing: .6px;
                    font-weight: 700;
                    background: linear-gradient(90deg, #ffe9b8, #fcd34d, #f59e0b);
                    -webkit-background-clip: text;
                    background-clip: text;
                    color: transparent;
                }

                .shop-meta {
                    font-size: .82rem;
                    line-height: 1.55;
                    color: #f1d7b9;
                    display: grid;
                    gap: .3rem;
                }

                .shop-meta strong {
                    font-size: .92rem;
                    letter-spacing: .45px;
                    color: #fff;
                }

                .status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: .35rem;
                    font-size: .6rem;
                    font-weight: 700;
                    letter-spacing: .7px;
                    padding: .4rem .7rem;
                    border-radius: 30px;
                    background: #f59e0b1a;
                    border: 1px solid #f59e0b40;
                    color: #fcd34d;
                }

                .status-badge svg {
                    width: 13px;
                    height: 13px;
                    stroke: #fbbf24;
                    stroke-width: 1.6;
                    fill: none;
                }

                .verify-steps {
                    margin: .25rem 0 0;
                    display: grid;
                    gap: .45rem;
                    font-size: .63rem;
                    letter-spacing: .35px;
                    color: #e8d2ba;
                    list-style: none;
                    padding: 0;
                }

                .verify-steps li {
                    display: flex;
                    align-items: flex-start;
                    gap: .45rem;
                }

                .verify-steps li span.icon {
                    width: 16px;
                    height: 16px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 4px;
                    background: #3f260f;
                    border: 1px solid #714017;
                    flex-shrink: 0;
                }

                .verify-steps li span.icon svg {
                    width: 10px;
                    height: 10px;
                    stroke: #fcd34d;
                    stroke-width: 1.8;
                    fill: none;
                }

                .svc-tags {
                    display: flex;
                    flex-wrap: wrap;
                    gap: .35rem;
                }

                .svc-tags span {
                    background: #3d240e;
                    border: 1px solid #714017;
                    font-size: .6rem;
                    padding: .3rem .55rem;
                    border-radius: 30px;
                    color: #f1d7b2;
                    letter-spacing: .55px;
                }

                .why-box {
                    background: #2d1a0d;
                    border: 1px solid #5d3615;
                    padding: 1.15rem 1.15rem 1.25rem;
                    border-radius: 16px;
                    display: flex;
                    flex-direction: column;
                    gap: .75rem;
                }

                .why-box ul {
                    margin: .1rem 0 0;
                    padding: .1rem 0 0 1.05rem;
                    display: grid;
                    gap: .65rem;
                    font-size: .8rem;
                    line-height: 1.5;
                    color: #f1ddc7;
                }

                .why-box li {
                    line-height: 1.4;
                }

                .highlight-cta {
                    background: linear-gradient(135deg, #4a2d12, #2d1a0d);
                    border: 1px solid #7a4719;
                    padding: 1.25rem 1.4rem 1.45rem;
                    border-radius: 20px;
                    display: flex;
                    flex-direction: column;
                    gap: .85rem;
                }

                .highlight-cta h3 {
                    margin: 0;
                    font-size: 1.15rem;
                    letter-spacing: .45px;
                    font-weight: 700;
                    color: #ffecbf;
                }

                .locked-label {
                    font-size: .58rem;
                    font-weight: 700;
                    letter-spacing: .6px;
                    color: #fcd34d;
                    background: #f59e0b1a;
                    border: 1px solid #f59e0b40;
                    padding: .25rem .55rem;
                    border-radius: 30px;
                }

                /* Two-column pairing for shop + why subscribe */
                .lim-pair {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    align-items: stretch;
                    gap: 1.6rem;
                    margin-top: 1.4rem;
                    margin-bottom: 4.5rem;
                    /* larger separation beneath the paired cards */
                }

                .lim-pair>.shop-box,
                .lim-pair>.why-box {
                    height: 100%;
                }

                @media (max-width:860px) {
                    .lim-pair {
                        grid-template-columns: 1fr;
                    }

                    .lim-pair>.shop-box {
                        margin-bottom: 1.8rem;
                    }

                    /* mobile vertical gap between cards */
                }

                /* Clear, generous space before the highlight CTA */
                .lim-pair+.highlight-cta {
                    margin-top: 0;
                    /* spacing now provided by lim-pair bottom margin */
                }
            </style>
            <div class="cta-banner">
                <h1>Welcome to BarberSure! 🚀</h1>
                <p>Your shop is registered but not yet verified. Complete your subscription to unlock full features and start attracting customers.</p>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <a href="profile.php?verify=1" class="btn-accent">Verify &amp; Subscribe Now</a>
                    <a href="profile.php" class="btn-outline">Update Profile</a>
                </div>
            </div>
            <div class="lim-pair">
                <div class="shop-box">
                    <div class="shop-header-flex">
                        <h2>Shop Profile</h2>
                        <span class="status-badge" title="Current status: <?= $hasPendingDocs ? 'Documents submitted - pending review' : 'Not Verified' ?>">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 3 3 9l9 6 9-6-9-6Z" />
                                <path d="M3 15l9 6 9-6" />
                            </svg>
                            <?= $hasPendingDocs ? 'PENDING REVIEW' : 'NOT VERIFIED' ?>
                        </span>
                    </div>
                    <?php if ($limShop): ?>
                        <div class="shop-meta">
                            <strong><?= e($limShop['shop_name']) ?></strong>
                            <span><?= e($limShop['city']) ?></span>
                            <?php if ($limShop['open_time'] && $limShop['close_time']): ?><span>Hours: <em style="font-style:normal;color:#ffd166;"><?= e($limShop['open_time']) ?> – <?= e($limShop['close_time']) ?></em></span><?php endif; ?>
                            <?php if ($limServices): ?><span>Sample Services (<?= count($limServices) ?>)</span><?php endif; ?>
                        </div>
                        <?php if ($limServices): ?>
                            <div class="svc-tags" aria-label="Sample services">
                                <?php foreach ($limServices as $svc): ?><span><?= e($svc['service_name']) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="font-size:.72rem;color:#e3c9b0;margin:0;font-weight:500;">No shop registered yet. Add one to begin verification.</p>
                    <?php endif; ?>
                    <ul class="verify-steps" aria-label="Verification steps">
                        <li><span class="icon"><svg viewBox="0 0 24 24">
                                    <path d="M5 12l5 5 9-13" />
                                </svg></span><span>Add complete shop details</span></li>
                        <li><span class="icon"><svg viewBox="0 0 24 24">
                                    <path d="M5 12l5 5 9-13" />
                                </svg></span><span>Set operating hours</span></li>
                        <li><span class="icon"><svg viewBox="0 0 24 24">
                                    <path d="M5 12l5 5 9-13" />
                                </svg></span><span>Publish at least 3 services</span></li>
                        <li><span class="icon"><svg viewBox="0 0 24 24">
                                    <path d="M5 12l5 5 9-13" />
                                </svg></span><span>Choose a subscription plan</span></li>
                    </ul>
                    <p class="mini-note" style="margin-top:.4rem;">Customers cannot see your shop until verification is complete.</p>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <button type="button" class="btn-accent" style="padding:.6rem 1.05rem;" onclick="openDocModal()" <?= $hasPendingDocs ? 'disabled style="opacity:.6;cursor:not-allowed;padding:.6rem 1.05rem;"' : '' ?>><?= $hasPendingDocs ? 'Documents Submitted' : 'Complete Verification' ?></button>
                    </div>
                </div><!-- end shop-box -->
                <div class="why-box">
                    <h2 style="margin:0;font-size:1.2rem;letter-spacing:.5px;background:linear-gradient(90deg,#fcd34d,#fbbf24,#f59e0b);-webkit-background-clip:text;background-clip:text;color:transparent;">Why Subscribe?</h2>
                    <ul>
                        <li>📈 Get more visibility — appear in customer search results.</li>
                        <li>🔔 Instant booking notifications.</li>
                        <li>📊 Insights into your customers and shop performance.</li>
                        <li>🌟 Priority placement in listings.</li>
                    </ul>
                    <div style="background:linear-gradient(135deg,#4a2d12,#2d1a0d);border:1px solid #7a4719;padding:.7rem .85rem;border-radius:12px;font-size:.72rem;color:#f9e7ce;box-shadow:0 0 0 1px #8b5a23 inset,0 4px 10px -6px #000;">Shops with a subscription get <strong style="color:#ffd166;">2x more bookings!</strong></div>
                    <div style="margin:1rem 0 0;display:grid;gap:.9rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));align-items:stretch;">
                        <div style="background:#3a230d;border:1px solid #7a4719;border-radius:16px;padding:.95rem .95rem 1rem;display:flex;flex-direction:column;gap:.6rem;box-shadow:0 0 0 1px #8b5523 inset,0 2px 8px -4px #000;">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <div style="width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#4d2d12,#2d1a0d);display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px #90551f inset;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="#fcd34d" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="5" width="18" height="16" rx="2" ry="2" />
                                        <line x1="3" y1="10" x2="21" y2="10" />
                                        <line x1="8" y1="3" x2="8" y2="7" />
                                        <line x1="16" y1="3" x2="16" y2="7" />
                                    </svg>
                                </div>
                                <h3 style="margin:0;font-size:.9rem;letter-spacing:.45px;color:#ffe3b0;font-weight:600;">Bookings Calendar</h3>
                            </div>
                            <p style="margin:0;font-size:.68rem;line-height:1.45;color:#d8c2aa;">View and manage customer bookings — <strong style="color:#ffd166;">unlock by subscribing.</strong></p>
                        </div>
                        <div style="background:#3a230d;border:1px solid #7a4719;border-radius:16px;padding:.95rem .95rem 1rem;display:flex;flex-direction:column;gap:.6rem;box-shadow:0 0 0 1px #8b5523 inset,0 2px 8px -4px #000;">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <div style="width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#4d2d12,#2d1a0d);display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px #90551f inset;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="#fcd34d" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 12h4l3 8 4-16 3 8h4" />
                                    </svg>
                                </div>
                                <h3 style="margin:0;font-size:.9rem;letter-spacing:.45px;color:#ffe3b0;font-weight:600;">Customer Insights</h3>
                            </div>
                            <p style="margin:0;font-size:.68rem;line-height:1.45;color:#d8c2aa;">Track popular services, repeat customers, peak hours — <strong style="color:#ffd166;">subscription only.</strong></p>
                        </div>
                        <div style="background:#3a230d;border:1px solid #7a4719;border-radius:16px;padding:.95rem .95rem 1rem;display:flex;flex-direction:column;gap:.6rem;box-shadow:0 0 0 1px #8b5523 inset,0 2px 8px -4px #000;">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <div style="width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#4d2d12,#2d1a0d);display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px #90551f inset;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="#fcd34d" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                                        <path d="M13.73 21a2 2 0 01-3.46 0" />
                                    </svg>
                                </div>
                                <h3 style="margin:0;font-size:.9rem;letter-spacing:.45px;color:#ffe3b0;font-weight:600;">Notifications</h3>
                            </div>
                            <p style="margin:0;font-size:.68rem;line-height:1.45;color:#d8c2aa;">Instant SMS & email alerts for new bookings — <strong style="color:#ffd166;">subscribe to enable.</strong></p>
                        </div>
                    </div>
                </div><!-- end why-box -->
            </div><!-- end lim-pair -->
            <div class="highlight-cta" style="display:flex;flex-direction:column;gap:1rem;align-items:stretch;">
                <div style="display:flex;flex-direction:column;gap:.45rem;">
                    <h3 style="margin:0;font-size:1.35rem;letter-spacing:.5px;">Step 2: Subscription Page</h3>
                    <p style="margin:0;font-size:.9rem;color:#e8d2ba;font-weight:500;">Choose Your Plan</p>
                </div>
                <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));">
                    <div style="background:linear-gradient(140deg,#3a230d,#2b190c 55%,#241307);border:1px solid #6f3d16;border-radius:14px;padding:1.1rem 1.05rem 1.15rem;display:flex;flex-direction:column;gap:.65rem;position:relative;box-shadow:0 2px 6px -2px rgba(0,0,0,.45),0 0 0 1px #7e461a inset;">
                        <h4 style="margin:0;font-size:1.05rem;letter-spacing:.4px;background:linear-gradient(90deg,#ffe9b8,#fcd34d,#f59e0b);-webkit-background-clip:text;background-clip:text;color:transparent;">Monthly Plan</h4>
                        <div style="font-size:1.35rem;font-weight:600;color:#ffdca3;">💳 ₱499 <span style="font-size:.7rem;font-weight:600;color:#f5c565;letter-spacing:.6px;">/ MONTH</span></div>
                        <ul style="list-style:none;margin:0;padding:0;font-size:.72rem;line-height:1.25rem;color:#e8d2ba;letter-spacing:.35px;">
                            <li>Cancel anytime</li>
                            <li>Full access to bookings</li>
                            <li>Notifications & insights</li>
                        </ul>
                        <a href="payments.php?plan=monthly" class="btn-accent" style="margin-top:auto;text-align:center;">Select</a>
                    </div>
                    <div style="background:radial-gradient(circle at 25% 18%,#4d2d12 0%,#3a210d 55%,#2b1709 100%);border:1px solid #9a5a1d;border-radius:14px;padding:1.1rem 1.05rem 1.15rem;display:flex;flex-direction:column;gap:.65rem;position:relative;box-shadow:0 4px 12px -4px #000,0 0 0 1px #a76728 inset;">
                        <div style="position:absolute;top:-11px;right:10px;background:linear-gradient(90deg,#fbbf24,#f59e0b);color:#2d1400;font-size:.6rem;padding:.35rem .6rem;border-radius:8px;letter-spacing:.65px;font-weight:700;box-shadow:0 2px 6px -2px #000;">BEST VALUE</div>
                        <h4 style="margin:0;font-size:1.05rem;letter-spacing:.4px;background:linear-gradient(90deg,#ffe9b8,#fcd34d,#f59e0b);-webkit-background-clip:text;background-clip:text;color:transparent;">Yearly Plan</h4>
                        <div style="font-size:1.35rem;font-weight:600;color:#ffdca3;">💳 ₱4,999 <span style="font-size:.7rem;font-weight:600;color:#f5c565;letter-spacing:.6px;">/ YEAR</span></div>
                        <div style="font-size:.62rem;color:#ffdd9b;margin-top:-.2rem;letter-spacing:.45px;">Equivalent to ₱416 / month (save ~17%)</div>
                        <ul style="list-style:none;margin:0;padding:0;font-size:.72rem;line-height:1.25rem;color:#eed9c3;letter-spacing:.35px;">
                            <li>Priority placement in search</li>
                            <li>Full access & insights</li>
                            <li>Exclusive seasonal promotions</li>
                        </ul>
                        <a href="payments.php?plan=yearly" class="btn-accent" style="margin-top:auto;text-align:center;background:linear-gradient(90deg,#f59e0b,#d97706);border-color:#f59e0b;">Select</a>
                    </div>
                </div>
            </div>
            <!-- Document Upload Modal -->
            <div id="docUploadModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);">
                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#1b120a;border:1px solid #5d3615;border-radius:18px;padding:1.4rem 1.35rem 1.55rem;max-width:640px;width:92%;box-shadow:0 8px 40px -10px #000,0 0 0 1px #744418 inset;display:flex;flex-direction:column;gap:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                        <h3 style="margin:0;font-size:1.1rem;letter-spacing:.5px;background:linear-gradient(90deg,#ffe9b8,#fcd34d,#f59e0b);-webkit-background-clip:text;background-clip:text;color:transparent;">Submit Verification Documents</h3>
                        <button type="button" onclick="closeDocModal()" style="background:#2d1a0d;border:1px solid #704218;color:#f8e7d2;font-size:.65rem;padding:.4rem .65rem;border-radius:8px;cursor:pointer;">Close</button>
                    </div>
                    <?php if (isset($docErrors) && $docErrors): ?>
                        <div style="background:#3a1e10;border:1px solid #a13333;color:#fca5a5;padding:.6rem .75rem;border-radius:10px;font-size:.63rem;line-height:1.4;">
                            <?php foreach ($docErrors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:1.1rem;" onsubmit="return confirm('Submit documents now?');">
                        <input type="hidden" name="action" value="submit_verification_docs" />
                        <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" /><?php endif; ?>
                        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));">
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Government ID (Front)</span>
                                <input type="file" name="valid_id_front" accept="image/*" style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">PNG / JPG / WEBP</span>
                            </label>
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Government ID (Back)</span>
                                <input type="file" name="valid_id_back" accept="image/*" style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">Same ID back side</span>
                            </label>
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Business Permit / DTI / Barangay Clearance</span>
                                <input type="file" name="business_permit" accept="image/*" style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">Upload one clear image</span>
                            </label>
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Sanitation Certificate</span>
                                <input type="file" name="sanitation_certificate" accept="image/*" style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">Latest approved copy</span>
                            </label>
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Tax Certificate</span>
                                <input type="file" name="tax_certificate" accept="image/*" style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">Proof of tax compliance</span>
                            </label>
                            <label style="display:flex;flex-direction:column;gap:.45rem;background:#241207;border:1px solid #593514;padding:.85rem .85rem 1rem;border-radius:14px;cursor:pointer;">
                                <span style="font-size:.65rem;font-weight:600;letter-spacing:.55px;color:#f3d9b9;">Shop Photo(s)</span>
                                <input type="file" name="shop_photos[]" accept="image/*" multiple style="font-size:.6rem;color:#d8c2aa;" required />
                                <span style="font-size:.5rem;color:#b9956d;">Front & Interior (you can select multiple)</span>
                            </label>
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:.6rem;flex-wrap:wrap;">
                            <button type="button" onclick="closeDocModal()" class="btn-outline" style="font-size:.62rem;">Cancel</button>
                            <button type="submit" class="btn-accent" style="font-size:.62rem;">Submit Documents</button>
                        </div>
                        <p style="margin:.2rem 0 0;font-size:.55rem;color:#b08d63;">Once submitted, documents cannot be edited until reviewed by admin.</p>
                    </form>
                </div>
            </div>
            <script>
                function openDocModal() {
                    const m = document.getElementById('docUploadModal');
                    if (m) m.style.display = 'block';
                }

                function closeDocModal() {
                    const m = document.getElementById('docUploadModal');
                    if (m) m.style.display = 'none';
                }
                window.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') closeDocModal();
                });
                window.addEventListener('click', (e) => {
                    const m = document.getElementById('docUploadModal');
                    if (e.target === m) closeDocModal();
                });
                <?php if (isset($docErrors) && $docErrors): ?>openDocModal();
                <?php endif; ?>
                <?php if (isset($_GET['docs']) && $_GET['docs'] === 'submitted'): ?>
                    setTimeout(() => {
                        const badge = document.querySelector('.status-badge');
                        if (badge) badge.classList.add('pulse');
                    }, 500);
                <?php endif; ?>
            </script>
            <footer class="footer" style="margin-top:2rem;">&copy; <?= date('Y') ?> BarberSure • Empowering barbershop owners.</footer>
        <?php endif; ?>
    </main>
</body>

</html>
<script src="../assets/js/menu-toggle.js"></script>