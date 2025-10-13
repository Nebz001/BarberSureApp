<?php
// Shop Details page
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Validate input
$shopId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$shopId || $shopId <= 0) {
    http_response_code(404);
    $error = 'Shop not found.';
}

$shop = null;
$services = [];
$reviews = [];

if (empty($error)) {
    // Detect optional columns so we don't break on older schemas
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='Barbershops' AND COLUMN_NAME IN ('open_time','close_time','shop_phone','latitude','longitude')");
    $colStmt->execute();
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasOpen = in_array('open_time', $cols, true);
    $hasClose = in_array('close_time', $cols, true);
    $hasPhone = in_array('shop_phone', $cols, true);
    $hasLat = in_array('latitude', $cols, true);
    $hasLng = in_array('longitude', $cols, true);

    $selectCols = "s.shop_id, s.owner_id, s.shop_name, s.description, s.address, s.city, s.status, s.registered_at, (SELECT full_name FROM Users uo WHERE uo.user_id = s.owner_id) AS owner_name";
    if ($hasPhone) $selectCols .= ", s.shop_phone";
    if ($hasOpen) $selectCols .= ", s.open_time";
    if ($hasClose) $selectCols .= ", s.close_time";
    if ($hasLat) $selectCols .= ", s.latitude";
    if ($hasLng) $selectCols .= ", s.longitude";

    // Fetch shop with rating stats; only show approved shops publicly
    $sql = "SELECT $selectCols,
                (SELECT ROUND(AVG(r.rating), 1) FROM Reviews r WHERE r.shop_id = s.shop_id) AS avg_rating,
                (SELECT COUNT(*) FROM Reviews r WHERE r.shop_id = s.shop_id) AS review_count
            FROM Barbershops s
            WHERE s.shop_id = :id AND s.status = 'approved'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $shopId]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shop) {
        http_response_code(404);
        $error = 'Shop not found or not available.';
    } else {
        // Services
        $svc = $pdo->prepare("SELECT service_id, service_name, duration_minutes, price FROM Services WHERE shop_id = :id ORDER BY price ASC, service_name ASC");
        $svc->execute([':id' => $shopId]);
        $services = $svc->fetchAll(PDO::FETCH_ASSOC);

        // Recent Reviews
        $rev = $pdo->prepare(
            "SELECT r.review_id, r.rating, r.comment, r.created_at, u.full_name
			 FROM Reviews r
			 JOIN Users u ON u.user_id = r.customer_id
			 WHERE r.shop_id = :id
			 ORDER BY r.created_at DESC
			 LIMIT 5"
        );
        $rev->execute([':id' => $shopId]);
        $reviews = $rev->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Helper to render star icons based on average rating
function render_stars($avg)
{
    if ($avg === null || $avg === '') return '<span class="text-muted">No ratings yet</span>';
    $full = floor($avg);
    $half = ($avg - $full) >= 0.5 ? 1 : 0;
    $empty = 5 - $full - $half;
    $out = '';
    for ($i = 0; $i < $full; $i++) $out .= '<i class="bi bi-star-fill" style="color:#0ea5e9"></i>';
    if ($half) $out .= '<i class="bi bi-star-half" style="color:#0ea5e9"></i>';
    for ($i = 0; $i < $empty; $i++) $out .= '<i class="bi bi-star" style="color:#0ea5e9"></i>';
    return $out . '<span class="ms-2 small text-muted">' . number_format((float)$avg, 1) . '</span>';
}

// Time formatter (accepts HH:MM or HH:MM:SS)
function fmt_time_pretty($t)
{
    if (!$t) return null;
    // Normalize to HH:MM:SS for strtotime
    if (preg_match('/^\d{2}:\d{2}$/', $t)) {
        $t .= ':00';
    }
    $ts = strtotime($t);
    if ($ts === false) return $t;
    return date('g:i A', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= isset($shop['shop_name']) ? e($shop['shop_name']) . ' • ' : '' ?>BarberSure • Shop Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/public.css" />
    <style>
        .details-hero {
            padding: calc(clamp(3rem, 9vh, 6rem) + 1.2rem) 0 2.25rem;
            background: #0e1217;
        }

        .shop-card {
            background: #141b22;
            border: 1px solid #1f2a36;
            border-radius: 16px;
            padding: 1.25rem 1.25rem 1.35rem;
        }

        .chip {
            font-size: .65rem;
            letter-spacing: .5px;
            font-weight: 600;
            padding: .25rem .6rem;
            border-radius: 40px;
            border: 1px solid #2a3a49;
            color: #d8dde3;
            background: rgba(255, 255, 255, .03);
        }

        .svc-item {
            background: #1b2530;
            border: 1px solid #22303d;
            border-radius: 10px;
            padding: .7rem .8rem;
        }

        .svc-price {
            background: linear-gradient(135deg, #0ea5e9, #3b82f6);
            color: #111;
            font-size: .7rem;
            font-weight: 800;
            padding: .25rem .5rem;
            border-radius: 40px;
        }

        .review-item {
            background: #1b2530;
            border: 1px solid #22303d;
            border-radius: 10px;
            padding: .8rem .9rem;
        }

        .cta-book {
            background: linear-gradient(135deg, #0ea5e9, #3b82f6);
            border: 0;
            font-weight: 700;
            letter-spacing: .4px;
            box-shadow: 0 8px 30px -6px rgba(0, 0, 0, .4);
        }

        .cta-book:hover {
            filter: brightness(1.06);
        }

        .muted {
            color: #9aa5b1;
        }

        .map-card {
            background: #141b22;
            border: 1px solid #1f2a36;
            border-radius: 16px;
            padding: 1rem;
        }

        .mini-map {
            width: 100%;
            aspect-ratio: 16 / 10;
            border: 0;
            border-radius: 12px;
            overflow: hidden;
        }

        .map-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-top: .6rem;
        }

        .map-actions .btn-directions {
            background: #1b2530;
            border: 1px solid #22303d;
            color: #e5ebf1;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .map-actions .btn-directions:hover {
            filter: brightness(1.06);
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('page-transition-ready');
        });
    </script>
</head>

<body class="page-transition-init">
    <?php $cu = current_user();
    $isCustomerView = $cu && (($cu['role'] ?? null) === 'customer'); ?>
    <?php if ($isCustomerView) {
        // Load customer header styles to ensure proper header bar rendering
        echo '<link rel="stylesheet" href="../assets/css/customer.css" />';
        include __DIR__ . '/../partials/customer_header.php';
    } else {
        include __DIR__ . '/../partials/public_header.php';
    } ?>

    <main>
        <section class="details-hero">
            <div class="content-max">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-dark border-0" role="alert">
                        <?= e($error) ?>
                    </div>
                <?php else: ?>
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-8">
                            <div class="shop-card">
                                <?php $user = current_user();
                                $role = $user['role'] ?? null;
                                $isOwnerOfShop = $role === 'owner' && isset($user['user_id']) && (int)$user['user_id'] === (int)$shop['owner_id']; ?>
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <h1 class="mb-1" style="font-weight:800; letter-spacing:.2px;">
                                            <?= e($shop['shop_name']) ?>
                                        </h1>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <div><?= render_stars($shop['avg_rating']) ?></div>
                                            <span class="muted">•</span>
                                            <span class="muted"><?= (int)($shop['review_count'] ?? 0) ?> reviews</span>
                                            <?php if ($user): ?>
                                                <span class="muted">•</span>
                                                <?php if ($isOwnerOfShop): ?>
                                                    <span class="chip" style="background:rgba(251,191,36,.08);border-color:#5f4a13;color:#f3e8a1;">
                                                        <i class="bi bi-shield-check me-1"></i>Your Shop
                                                    </span>
                                                <?php elseif ($role === 'customer'): ?>
                                                    <span class="chip">Signed in as Customer</span>
                                                <?php elseif ($role === 'owner'): ?>
                                                    <span class="chip">Owner Account</span>
                                                <?php elseif ($role === 'admin'): ?>
                                                    <span class="chip">Admin View</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column align-items-end gap-2">
                                        <?php if (!$user): ?>
                                            <a class="btn btn-sm cta-book" href="../login.php?next=customer/booking.php%3Fshop%3D<?= (int)$shop['shop_id'] ?>">
                                                <i class="bi bi-box-arrow-in-right me-1"></i> Sign in to Book
                                            </a>
                                        <?php elseif ($role === 'customer'): ?>
                                            <div class="d-flex flex-wrap gap-2">
                                                <a class="btn btn-sm cta-book" href="booking.php?shop=<?= (int)$shop['shop_id'] ?>">
                                                    <i class="bi bi-calendar2-check me-1"></i> Book Now
                                                </a>
                                                <?php
                                                // Pre-booking ephemeral channel (customer + shop only) using session id + shop id
                                                $sessionId = session_id();
                                                $preChannel = 'pre_' . (int)$shop['shop_id'] . '_' . substr(hash('sha256', $sessionId . '|' . (int)$shop['shop_id']), 0, 20);
                                                ?>
                                                <button type="button" class="btn btn-sm btn-outline-light" id="openPreChat" data-channel="<?= e($preChannel) ?>" style="background:#1b2530;border:1px solid #22303d;font-weight:600;letter-spacing:.4px;">
                                                    <i class="bi bi-chat-dots me-1"></i> Message Shop
                                                </button>
                                            </div>
                                        <?php elseif ($isOwnerOfShop): ?>
                                            <a class="btn btn-sm cta-book" href="../owner/manage_shop.php">
                                                <i class="bi bi-tools me-1"></i> Manage Shop
                                            </a>
                                        <?php elseif ($role === 'admin'): ?>
                                            <a class="btn btn-sm cta-book" href="../admin/manage_shops.php">
                                                <i class="bi bi-speedometer2 me-1"></i> Admin: Manage Shops
                                            </a>
                                        <?php else: ?>
                                            <a class="btn btn-sm cta-book" href="booking.php?shop=<?= (int)$shop['shop_id'] ?>">
                                                <i class="bi bi-calendar2-check me-1"></i> Book Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <hr class="my-3" style="border-color:#22303d;" />
                                <div class="d-flex flex-column gap-2">
                                    <?php if (!empty($shop['address'])): ?>
                                        <div class="d-flex align-items-center gap-2 muted">
                                            <i class="bi bi-geo-alt-fill" style="color:#0ea5e9"></i>
                                            <span><?= e($shop['address']) ?><?= !empty($shop['city']) ? ', ' . e($shop['city']) : '' ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php $openT = $shop['open_time'] ?? null;
                                    $closeT = $shop['close_time'] ?? null;
                                    if (!empty($openT) && !empty($closeT)): ?>
                                        <div class="d-flex align-items-center gap-2 muted">
                                            <i class="bi bi-clock-fill" style="color:#0ea5e9"></i>
                                            <span>Open: <?= e(fmt_time_pretty($openT)) ?> &ndash; <?= e(fmt_time_pretty($closeT)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($shop['owner_name'])): ?>
                                        <div class="d-flex align-items-center gap-2 muted">
                                            <i class="bi bi-person-badge-fill" style="color:#0ea5e9"></i>
                                            <span>Owner: <?= e($shop['owner_name']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($shop['shop_phone'])): ?>
                                        <div class="d-flex align-items-center gap-2 muted">
                                            <i class="bi bi-telephone-fill" style="color:#0ea5e9"></i>
                                            <span><?= e($shop['shop_phone']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($shop['description'])): ?>
                                        <p class="mb-0 muted" style="line-height:1.6;">
                                            <?= nl2br(e($shop['description'])) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                            $lat = isset($shop['latitude']) ? (float)$shop['latitude'] : null;
                            $lng = isset($shop['longitude']) ? (float)$shop['longitude'] : null;
                            $hasCoords = ($lat !== null && $lng !== null);
                            $addrStr = trim(($shop['address'] ?? '') . ' ' . ($shop['city'] ?? ''));
                            $mapSrc = '';
                            $directionsHref = '';
                            if ($hasCoords) {
                                $mapSrc = 'https://www.google.com/maps?q=' . rawurlencode(number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '')) . '&z=16&output=embed';
                                $directionsHref = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode(number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '')) . '&travelmode=driving';
                            } elseif ($addrStr !== '') {
                                $mapSrc = 'https://www.google.com/maps?q=' . rawurlencode($addrStr) . '&z=15&output=embed';
                                $directionsHref = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($addrStr) . '&travelmode=driving';
                            }
                            if ($mapSrc !== ''): ?>
                                <div class="map-card mt-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h2 class="h6 mb-0" style="letter-spacing:.3px;">Location</h2>
                                        <span class="chip">Map Preview</span>
                                    </div>
                                    <iframe class="mini-map" src="<?= e($mapSrc) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" aria-label="Shop location on Google Maps"></iframe>
                                    <div class="map-actions">
                                        <small class="text-muted">Powered by Google Maps</small>
                                        <a class="btn btn-sm btn-directions" target="_blank" rel="noopener" href="<?= e($directionsHref) ?>">
                                            <i class="bi bi-geo-alt-fill me-1" style="color:#0ea5e9"></i> Get Directions
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4">
                                <h2 class="h5 mb-3">Services</h2>
                                <?php if (!$services): ?>
                                    <div class="alert alert-secondary bg-transparent text-light border-0">No services listed.</div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <?php foreach ($services as $svc): ?>
                                            <div class="col-md-6">
                                                <div class="svc-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <div class="fw-semibold">
                                                            <?= e($svc['service_name']) ?>
                                                        </div>
                                                        <div class="muted small">~ <?= (int)$svc['duration_minutes'] ?> min</div>
                                                    </div>
                                                    <div class="svc-price">₱<?= number_format((float)$svc['price'], 2) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="shop-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h2 class="h6 mb-0">Recent Reviews</h2>
                                    <span class="chip"><?= (int)($shop['review_count'] ?? 0) ?> total</span>
                                </div>
                                <?php if (!$reviews): ?>
                                    <div class="muted">No reviews yet.</div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($reviews as $rv): ?>
                                            <div class="review-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="fw-semibold"><?= e($rv['full_name']) ?></div>
                                                    <div>
                                                        <?php echo render_stars((float)$rv['rating']); ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($rv['comment'])): ?>
                                                    <div class="mt-1 muted" style="font-size:.9rem; line-height:1.5;">
                                                        <?= nl2br(e($rv['comment'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="muted mt-2" style="font-size:.7rem;">
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date('M j, Y', strtotime($rv['created_at'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <footer class="dashboard-footer">&copy; <?= date('Y') ?> BarberSure • View Barbershop.</footer>
    </main>

    <div id="preChatModal" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content" style="background:#141b22;border:1px solid #22303d;">
                <div class="modal-header" style="border-color:#22303d;">
                    <h5 class="modal-title" style="font-weight:700;letter-spacing:.4px;display:flex;align-items:center;gap:.6rem;">
                        <i class="bi bi-chat-dots" style="color:#fbbf24;"></i> Chat with Shop
                        <span style="font-size:.55rem;letter-spacing:.5px;font-weight:600;background:#1b2530;border:1px solid #22303d;padding:.25rem .55rem;border-radius:40px;">Ephemeral</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="preChatBody" style="padding:1rem 1.1rem 1.2rem;">
                    <div class="d-flex flex-wrap gap-3" style="align-items:stretch;justify-content:space-between;">
                        <div class="pre-chat-messages" style="flex:2 1 520px;min-width:360px;display:flex;flex-direction:column;gap:.7rem;background:#1b2530;border:1px solid #22303d;border-radius:12px;padding:.75rem .8rem;max-height:420px;overflow-y:auto;font-size:.72rem;line-height:1.45;">
                            <div class="text-center text-muted" style="font-size:.62rem;">Ask about pricing, availability, or a style. Messages auto-clear.</div>
                        </div>
                        <form id="preChatForm" style="flex:1 1 320px;min-width:280px;display:flex;flex-direction:column;gap:.75rem;">
                            <div style="display:flex;flex-direction:column;gap:.4rem;">
                                <label style="font-size:.6rem;letter-spacing:.5px;font-weight:600;color:#9aa5b1;">Message</label>
                                <textarea rows="10" placeholder="Type your question here…" style="flex:1;background:#1b2530;border:1px solid #22303d;color:#e5ebf1;border-radius:12px;padding:.7rem .75rem;font-size:.7rem;resize:vertical;min-height:220px;line-height:1.5;"></textarea>
                            </div>
                            <div style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;">
                                <button type="submit" class="btn btn-sm cta-book" style="font-size:.7rem;padding:.65rem 1.1rem;font-weight:600;">Send</button>
                                <span class="text-muted" style="font-size:.55rem;">Channel: <span id="preChatChannelLabel"></span></span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="../assets/js/pre_chat.js"></script>
</body>

</html>