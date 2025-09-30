<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Allow guests & customers only
if (is_logged_in() && !has_role('customer')) redirect('../login.php');

function in_get($k, $d = '')
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}
$q = in_get('q');
$city = in_get('city');
$verified = in_get('verified');
$sort = in_get('sort', 'rating'); // rating | name | reviews
$page = max(1, (int)in_get('page', 1));
$perPage = 12;

$params = [];
$w = ["b.status='approved'"];
if ($q !== '') {
    $w[] = '(b.shop_name LIKE :kw OR b.city LIKE :kw)';
    $params[':kw'] = "%$q%";
}
if ($city !== '') {
    $w[] = 'b.city=:city';
    $params[':city'] = $city;
}
if ($verified === '1') {
    $w[] = 'u.is_verified=1';
}
$whereSql = implode(' AND ', $w);

$cStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.shop_id) FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE $whereSql");
foreach ($params as $k => $v) $cStmt->bindValue($k, $v);
$cStmt->execute();
$total = (int)$cStmt->fetchColumn();
$maxPage = $total ? (int)ceil($total / $perPage) : 1;
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

// Sorting
switch ($sort) {
    case 'name':
        $order = 'b.shop_name ASC';
        break;
    case 'reviews':
        $order = 'reviews_count DESC, avg_rating DESC';
        break;
    default:
        $order = 'avg_rating DESC, reviews_count DESC, b.shop_name ASC';
        $sort = 'rating';
}

$sql = "SELECT b.shop_id,b.shop_name,b.city,b.address,LEFT(IFNULL(b.description,''),160) description,u.is_verified,
	  COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.review_id) reviews_count
	  FROM Barbershops b
	  JOIN Users u ON b.owner_id=u.user_id
	  LEFT JOIN Reviews r ON r.shop_id=b.shop_id
	  WHERE $whereSql
	  GROUP BY b.shop_id
	  ORDER BY $order
	  LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$shops = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cityRows = $pdo->query("SELECT DISTINCT city FROM Barbershops WHERE city IS NOT NULL AND city<>'' ORDER BY city ASC LIMIT 80")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Search Shops • BarberSure</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/customer.css" />
    <style>
        .search-header {
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin-bottom: 1.2rem;
        }

        .search-header h1 {
            font-size: 1.65rem;
            margin: 0;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .filters-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: .7rem;
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border-soft);
            padding: .9rem .95rem 1rem;
            border-radius: var(--radius);
        }

        .filters-wrap input,
        .filters-wrap select {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            font-size: .78rem;
            padding: .6rem .7rem;
            border-radius: var(--radius-sm);
            min-width: 150px;
        }

        .filters-wrap input:focus,
        .filters-wrap select:focus {
            outline: none;
            border-color: var(--c-accent-alt);
            box-shadow: 0 0 0 .12rem rgba(14, 165, 233, .25);
        }

        .filters-actions {
            display: flex;
            gap: .5rem;
        }

        .btn-small {
            font-size: .7rem;
            padding: .6rem .9rem;
        }

        .shops-grid {
            display: grid;
            gap: 1rem;
            margin-top: 1.4rem;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        }

        .tile {
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border-soft);
            padding: .95rem .95rem 1.05rem;
            border-radius: var(--radius-sm);
            display: flex;
            flex-direction: column;
            gap: .45rem;
            position: relative;
            transition: border-color .35s, transform .35s;
        }

        .tile:hover {
            border-color: var(--c-accent-alt);
        }

        .tile-title {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .verify-badge {
            background: var(--c-accent-alt);
            color: #fff;
            font-size: .5rem;
            padding: .22rem .45rem;
            border-radius: 40px;
            letter-spacing: .55px;
            font-weight: 600;
        }

        .rating-line {
            display: flex;
            align-items: center;
            gap: .35rem;
            font-size: .75rem;
            font-weight: 600;
            color: #fbbf24;
        }

        .rating-line span.count {
            color: var(--c-text-soft);
            font-weight: 500;
        }

        .address {
            font-size: .62rem;
            letter-spacing: .45px;
            text-transform: uppercase;
            color: var(--c-text-soft);
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
        }

        .desc {
            font-size: .68rem;
            line-height: 1.5;
            color: var(--c-text-soft);
            flex: 1;
        }

        .pagination {
            margin: 2rem 0 0;
            display: flex;
            justify-content: center;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            min-width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .63rem;
            font-weight: 600;
            letter-spacing: .5px;
            text-decoration: none;
            background: var(--c-bg-alt);
            border: 1px solid var(--c-border);
            color: var(--c-text-soft);
        }

        .pagination a:hover {
            border-color: var(--c-accent-alt);
            color: var(--c-text);
        }

        .pagination .active {
            background: var(--c-accent-alt);
            border-color: var(--c-accent-alt);
            color: #fff;
        }

        .empty {
            padding: 3rem 1rem;
            text-align: center;
            font-size: .8rem;
            color: var(--c-text-soft);
        }

        .tile a {
            color: inherit;
            text-decoration: none;
        }

        .tile-link {
            position: absolute;
            inset: 0;
        }

        .sort-select {
            min-width: 130px;
        }

        @media (max-width:680px) {
            .filters-wrap {
                flex-direction: column;
                align-items: stretch;
            }

            .filters-actions {
                width: 100%;
            }

            .filters-actions .btn {
                flex: 1;
            }
        }
    </style>
</head>

<body class="dashboard-wrapper">
    <header class="header-bar">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="customerNav">☰</button>
        <div class="header-brand">
            <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
            <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
        </div>
        <nav id="customerNav" class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a class="active" href="search.php">Find Shops</a>
            <a href="booking.php">Book</a>
            <a href="bookings_history.php">History</a>
            <a href="profile.php">Profile</a>
            <?php if (is_logged_in()): ?>
                <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                    <button type="submit" class="logout-btn">Logout</button>
                </form>
            <?php else: ?>
                <a href="../login.php" class="btn btn-primary">Sign In</a>
            <?php endif; ?>
        </nav>
    </header>
    <main class="dashboard-main">
        <section class="card" style="padding:1.3rem 1.4rem 1.55rem;margin-bottom:1.6rem;">
            <div class="search-header" style="margin-bottom:1rem;">
                <h1>Find a Barbershop</h1>
                <p style="font-size:.8rem;color:var(--c-text-soft);max-width:720px;line-height:1.55;">Search verified shops, compare ratings and reviews, and discover new places to book your next appointment.</p>
            </div>
            <form method="get" class="filters-wrap" action="search.php">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or city" />
                <select name="city">
                    <option value="">All Cities</option>
                    <?php foreach ($cityRows as $c): ?>
                        <option value="<?= e($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="verified">
                    <option value="">Any</option>
                    <option value="1" <?= $verified === '1' ? 'selected' : '' ?>>Verified</option>
                </select>
                <select name="sort" class="sort-select">
                    <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top Rated</option>
                    <option value="reviews" <?= $sort === 'reviews' ? 'selected' : '' ?>>Most Reviewed</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                </select>
                <div class="filters-actions">
                    <button class="btn btn-primary btn-small" type="submit">Apply</button>
                    <a href="search.php" class="btn btn-small" style="background:var(--c-surface);">Reset</a>
                </div>
            </form>
        </section>
        <div class="shops-grid">
            <?php if (!$shops): ?>
                <div class="empty" style="grid-column:1/-1;">No shops found. Try adjusting your filters.</div>
                <?php else: foreach ($shops as $shop): $rating = round((float)$shop['avg_rating'], 1);
                    $reviews = (int)$shop['reviews_count']; ?>
                    <div class="tile">
                        <a class="tile-link" href="shop_details.php?id=<?= (int)$shop['shop_id'] ?>" aria-label="View <?= e($shop['shop_name']) ?> details"></a>
                        <h3 class="tile-title"><?= e($shop['shop_name']) ?> <?php if ($shop['is_verified']): ?><span class="verify-badge">VERIFIED</span><?php endif; ?></h3>
                        <div class="rating-line"><i class="bi bi-star-fill" style="color:#fbbf24;"></i><span><?= number_format($rating, 1) ?></span><span class="count">(<?= $reviews ?>)</span></div>
                        <div class="address"><span><?= e($shop['city'] ?: '—') ?></span><span><?= e($shop['address'] ? (strlen($shop['address']) > 30 ? substr($shop['address'], 0, 28) . '…' : $shop['address']) : 'No address') ?></span></div>
                        <p class="desc mb-none"><?= e($shop['description']) ?></p>
                    </div>
            <?php endforeach;
            endif; ?>
        </div>
        <?php if ($total > $perPage): ?>
            <div class="pagination" aria-label="Pagination">
                <?php
                $range = 2;
                $start = max(1, $page - $range);
                $end = min($maxPage, $page + $range);
                if ($start > 1) {
                    echo '<a href="' . e(page_link(1)) . '">1</a>';
                    if ($start > 2) echo '<span>…</span>';
                }
                for ($p = $start; $p <= $end; $p++) {
                    if ($p === $page) echo '<span class="active">' . $p . '</span>';
                    else echo '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
                }
                if ($end < $maxPage) {
                    if ($end < $maxPage - 1) echo '<span>…</span>';
                    echo '<a href="' . e(page_link($maxPage)) . '">' . $maxPage . '</a>';
                }
                function page_link($p)
                {
                    $qs = $_GET;
                    $qs['page'] = $p;
                    return 'search.php?' . http_build_query($qs);
                }
                ?>
            </div>
        <?php endif; ?>
    </main>
    <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • Find your style.</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.js"></script>
    <script src="../assets/js/menu-toggle.js"></script>
</body>

</html>