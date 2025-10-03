<?php
// Public directory of approved barbershops in Batangas.
// Filters: keyword (q), city, service, verified (1), page, per_page
// Dependencies: config/db.php, config/functions.php

session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$currentPage = 'discover';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

// Basic sanitization helper
function input($key, $default = null)
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

$q = input('q', '');
$city = input('city', '');
$service = input('service', '');
$verified = input('verified', ''); // '1' for verified only
$page = max(1, (int) input('page', 1));
$perPage = (int) input('per_page', 12);
if (!in_array($perPage, [6, 12, 24, 36], true)) $perPage = 12;

$params = [];
$wheres = ["b.status='approved'"];
if ($q !== '') {
    $wheres[] = "(b.shop_name LIKE :kw OR b.city LIKE :kw)";
    $params[':kw'] = "%$q%";
}
if ($city !== '') {
    $wheres[] = "b.city = :city";
    $params[':city'] = $city;
}
// Verified filter references owner verification flag
if ($verified === '1') {
    $wheres[] = "u.is_verified = 1";
}
// Service filter via EXISTS subquery
if ($service !== '') {
    $wheres[] = "EXISTS (SELECT 1 FROM Services s WHERE s.shop_id=b.shop_id AND s.service_name LIKE :svc)";
    $params[':svc'] = "%$service%";
}

$whereSql = implode(' AND ', $wheres);

// Count total
$countSql = "SELECT COUNT(DISTINCT b.shop_id) FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE $whereSql";
$cStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $cStmt->bindValue($k, $v);
$cStmt->execute();
$total = (int) $cStmt->fetchColumn();

$offset = ($page - 1) * $perPage;
if ($offset >= $total && $total > 0) {
    // Adjust to last page
    $page = (int) ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;
}

$sql = "SELECT b.shop_id, b.shop_name, b.city, b.address, b.description, u.is_verified,
        COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(r.review_id) AS reviews_count
        FROM Barbershops b
        JOIN Users u ON b.owner_id = u.user_id
        LEFT JOIN Reviews r ON r.shop_id = b.shop_id
        WHERE $whereSql
        GROUP BY b.shop_id
        ORDER BY b.shop_name ASC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$shops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct cities & services for filter selects
$cities = $pdo->query("SELECT DISTINCT city FROM Barbershops WHERE status='approved' AND city IS NOT NULL AND city<>'' ORDER BY city ASC")->fetchAll(PDO::FETCH_COLUMN);
$servicesDistinct = $pdo->query("SELECT DISTINCT service_name FROM Services s JOIN Barbershops b ON s.shop_id=b.shop_id WHERE b.status='approved' ORDER BY service_name ASC")->fetchAll(PDO::FETCH_COLUMN);

function star_rating_html($avg, $count)
{
    if ($count < 1) return '<span class="text-muted small">No reviews yet</span>';
    $full = floor($avg);
    $half = ($avg - $full) >= 0.5;
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full) $html .= '<i class="bi bi-star-fill text-warning"></i>';
        elseif ($half && $i == $full + 1) {
            $html .= '<i class="bi bi-star-half text-warning"></i>';
            $half = false;
        } else $html .= '<i class="bi bi-star text-warning"></i>';
    }
    $html .= '<span class="ms-2 small">' . number_format($avg, 1) . ' <span class="text-muted">(' . (int)$count . ')</span></span>';
    return $html;
}

$baseUrl = 'discover.php';
$queryBase = $_GET; // copy

function build_page_link($pageNum)
{
    $q = $_GET;
    $q['page'] = $pageNum;
    return 'discover.php?' . http_build_query($q);
}

$totalPages = $perPage ? (int) ceil($total / $perPage) : 1;
if ($totalPages < 1) $totalPages = 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Discover Barbershops • BarberSure Batangas</title>
    <meta name="description" content="Browse approved and verified barbershops across Batangas. Filter by city, service, and trust badges. Book now, pay in person." />
    <meta property="og:title" content="Discover Batangas Barbershops • BarberSure" />
    <meta property="og:description" content="Browse verified Batangas barbershops. Filter by city & service. Book locally, pay in person." />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://example.com/discover.php" />
    <meta property="og:image" content="https://example.com/assets/images/og-barbersure.png" />
    <link rel="canonical" href="https://example.com/discover.php" />
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "CollectionPage",
            "name": "Batangas Barbershop Directory",
            "description": "Directory of approved and verified Batangas barbershops on BarberSure.",
            "url": "https://example.com/discover.php"
        }
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <!-- Shared public styles (after Bootstrap & fonts) -->
    <link rel="stylesheet" href="assets/css/public.css" />
    <style>
        /* Base shared styles removed (now in assets/css/public.css); page-specific below */
        .layout-wrapper {
            flex: 1 0 auto;
            display: flex;
            flex-direction: column;
        }

        .card-shop {
            background: linear-gradient(145deg, #121b24, #0e141a);
            border: 1px solid #1e2a35;
            border-radius: 20px;
            transition: transform .4s, border-color .4s, box-shadow .4s, background .5s;
            box-shadow: 0 4px 18px -6px #000;
        }

        .card-shop:hover {
            transform: translateY(-6px);
            border-color: #2d4255;
            box-shadow: 0 12px 34px -12px rgba(0, 0, 0, .75), 0 0 0 1px #2d4255 inset;
            background: linear-gradient(145deg, #16232e, #101920);
        }

        .badge-verified {
            background: linear-gradient(90deg, #10b981, #059669);
            border: 0;
            font-weight: 600;
            font-size: .55rem;
            letter-spacing: .75px;
            padding: .35rem .5rem;
            border-radius: 999px;
            box-shadow: 0 2px 6px -2px rgba(0, 0, 0, .6);
        }

        .small-text {
            font-size: .75rem;
        }

        .filter-box {
            background: rgba(20, 27, 34, .85);
            border: 1px solid #1f2732;
            border-radius: 22px;
            backdrop-filter: blur(8px);
            box-shadow: 0 6px 28px -10px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .04) inset;
        }

        .page-link {
            background: #141b22;
            border-color: #1f2732;
            color: #d8dde3;
        }

        .page-item.active .page-link {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            border-color: #8f6a1a;
        }

        .form-select,
        .form-control {
            background: #111823;
            border-color: #1f2732;
            color: #d8dde3;
        }

        .form-control:focus,
        .form-select:focus {
            box-shadow: 0 0 0 2px rgba(245, 158, 11, .35);
        }

        .btn-gradient {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            border: 0;
            font-weight: 600;
            letter-spacing: .4px;
            box-shadow: 0 6px 22px -8px rgba(0, 0, 0, .55);
        }

        .btn-gradient:hover {
            filter: brightness(1.08);
        }

        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .text-truncate-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Shared CTA & accent styles (parity with index) */
        .cta-btn {
            background: var(--grad-primary);
            border: 0;
            font-weight: 600;
            letter-spacing: .5px;
            box-shadow: 0 8px 30px -6px rgba(0, 0, 0, .4);
        }

        .cta-btn:hover {
            filter: brightness(1.08);
        }

        .cta-secondary {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .cta-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .gradient-text {
            background: var(--grad-primary);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Smooth reveal animation */
        .reveal-seq {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity .7s cubic-bezier(.16, .8, .24, 1), transform .7s cubic-bezier(.16, .8, .24, 1);
        }

        .reveal-seq.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal-seq {
                transition: none;
                opacity: 1;
                transform: none;
            }
        }

        .discover-top-band {
            position: relative;
            background: radial-gradient(circle at 15% 20%, rgba(99, 102, 241, .18), transparent 70%), radial-gradient(circle at 85% 25%, rgba(245, 158, 11, .18), transparent 70%), linear-gradient(180deg, #0d1319, #0b1015);
        }

        .discover-top-band:before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(99, 102, 241, .08), transparent, rgba(245, 158, 11, .08));
            pointer-events: none;
        }

        .heading-wrap h1 {
            font-weight: 750;
            letter-spacing: .5px;
        }

        .heading-wrap .sub {
            font-size: .85rem;
            color: #a6b3c2;
        }

        .shop-stats-bar {
            font-size: .65rem;
            letter-spacing: .5px;
            text-transform: uppercase;
            color: #8ba2b9;
        }
    </style>
</head>

<body class="text-light pt-fixed-offset page-transition-init" style="--discover-extra-offset:0.75rem;">
    <div class="layout-wrapper">
        <?php include __DIR__ . '/partials/public_header.php'; ?>
        <section class="discover-top-band mb-5 pb-1">
            <div class="container pt-4" style="padding-top:var(--discover-extra-offset);">
                <div class="heading-wrap mb-4">
                    <h1 class="h3 mb-2 gradient-text">Discover Barbershops</h1>
                    <div class="sub">Browse approved Batangas shops, filter by city & services, and explore ratings.</div>
                </div>
                <div class="row g-4 align-items-end mb-4 filter-box p-4">
                    <form class="row g-3" method="get" action="discover.php">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Keyword</label>
                            <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search name or city" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">City</label>
                            <select name="city" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($cities as $c): ?>
                                    <option value="<?= e($c) ?>" <?= $c === $city ? 'selected' : '' ?>><?= e($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Service</label>
                            <select name="service" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($servicesDistinct as $svc): ?>
                                    <option value="<?= e($svc) ?>" <?= $svc === $service ? 'selected' : '' ?>><?= e($svc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">&nbsp;</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="verified" value="1" id="verifiedChk" <?= $verified === '1' ? 'checked' : '' ?> />
                                <label for="verifiedChk" class="form-check-label small">Verified only</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button class="btn btn-gradient px-4">Apply Filters</button>
                            <a href="discover.php" class="btn btn-outline-light">Reset</a>
                            <div class="ms-auto d-flex align-items-center gap-2">
                                <label class="small text-muted mb-0">Per Page</label>
                                <select name="per_page" class="form-select form-select-sm" style="width:auto;">
                                    <?php foreach ([6, 12, 24, 36] as $pp): ?>
                                        <option value="<?= $pp ?>" <?= $pp === $perPage ? 'selected' : '' ?>><?= $pp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
        <section class="container mb-5">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">Browse Barbershops</h1>
                <div class="small text-muted">Showing <?= $total ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> result(s)</div>
            </div>
            <?php if (!$total): ?>
                <div class="p-5 text-center bg-dark rounded-4 border border-secondary">
                    <p class="mb-2">No shops match your filters yet.</p>
                    <p class="small text-muted mb-0">Try removing a filter or check back soon as more Batangas shops join.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php $animIndex = 0;
                    foreach ($shops as $shop): $animIndex++; ?>
                        <div class="col-md-6 col-lg-4 reveal-seq" data-reveal-delay="<?= 40 * ($animIndex - 1) ?>">
                            <div class="card-shop h-100 p-3 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h2 class="h6 mb-0 me-2 text-truncate" title="<?= e($shop['shop_name']) ?>"><?= e($shop['shop_name']) ?></h2>
                                    <?php if ($shop['is_verified']): ?>
                                        <span class="badge badge-verified">Verified</span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mb-2"><i class="bi bi-geo-alt me-1 text-info"></i><?= e($shop['city'] ?: 'Batangas') ?></div>
                                <div class="small mb-2 text-truncate-3"><?= e(mb_strimwidth($shop['description'] ?? 'No description provided.', 0, 180, '…')) ?></div>
                                <div class="mb-2 small">
                                    <?= star_rating_html((float)$shop['avg_rating'], (int)$shop['reviews_count']) ?>
                                </div>
                                <div class="mt-auto d-flex">
                                    <?php if ($isLoggedIn): ?>
                                        <a class="btn btn-sm btn-outline-light w-100" href="shop_details.php?id=<?= (int)$shop['shop_id'] ?>">View Details</a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-light w-100" href="login.php?next=<?= urlencode('shop_details.php?id=' . (int)$shop['shop_id']) ?>">View Details</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4" aria-label="Pagination">
                        <ul class="pagination pagination-sm justify-content-center gap-1 flex-wrap">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= build_page_link($p) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php include __DIR__ . '/partials/public_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Page load fade-in (respects reduced motion)
        (function() {
            const prefersReduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (prefersReduce) {
                document.body.classList.remove('page-transition-init');
                return;
            }
            window.requestAnimationFrame(() => {
                document.body.classList.add('page-transition-ready');
                document.body.classList.remove('page-transition-init');
            });
        })();
    </script>
    <script>
        (function() {
            const cards = document.querySelectorAll('.reveal-seq');
            if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                cards.forEach(c => c.classList.add('visible'));
                return;
            }
            const obs = new IntersectionObserver(entries => {
                entries.forEach(e => {
                    if (e.isIntersecting) {
                        const delay = parseInt(e.target.getAttribute('data-reveal-delay') || '0', 10);
                        setTimeout(() => e.target.classList.add('visible'), delay);
                        obs.unobserve(e.target);
                    }
                });
            }, {
                threshold: 0.12
            });
            cards.forEach(c => obs.observe(c));
        })();
    </script>
</body>

</html>