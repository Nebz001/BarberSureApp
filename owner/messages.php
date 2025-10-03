<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_login();
if (!has_role('owner')) redirect('../login.php');
$user = current_user();
$ownerId = (int)($user['user_id'] ?? 0);

// Load shops for selection
$shopsStmt = $pdo->prepare("SELECT shop_id, shop_name FROM Barbershops WHERE owner_id=? ORDER BY shop_name ASC");
$shopsStmt->execute([$ownerId]);
$shops = $shopsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedShopId = isset($_GET['shop']) ? (int)$_GET['shop'] : 0;
if ($shops) {
    $valid = false;
    foreach ($shops as $s) {
        if ((int)$s['shop_id'] === $selectedShopId) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        $selectedShopId = (int)$shops[0]['shop_id'];
    }
}
$currentShopName = '';
foreach ($shops as $s) {
    if ((int)$s['shop_id'] === $selectedShopId) {
        $currentShopName = $s['shop_name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Messages • Owner • BarberSure</title>
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        .msg-layout {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .msg-col {
            background: #0f1a24;
            border: 1px solid #223240;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
        }

        .msg-col h3 {
            margin: 0;
            font-size: .72rem;
            letter-spacing: .5px;
            text-transform: uppercase;
            font-weight: 600;
            color: #8aa6bf;
        }

        .msg-list {
            flex: 1;
            overflow-y: auto;
            padding: .55rem .6rem;
            display: flex;
            flex-direction: column;
            gap: .45rem;
            font-size: .7rem;
            line-height: 1.25;
            height: 460px;
            /* fixed to prevent growth on new messages */
        }

        .msg-empty {
            opacity: .6;
            font-size: .6rem;
        }

        .msg-item {
            background: #132230;
            border: 1px solid #1f2f3a;
            padding: .45rem .5rem;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }

        .msg-item:hover {
            border-color: #0ea5e9;
        }

        .msg-item.active {
            background: #0f2733;
            border-color: #0ea5e9;
        }

        .msg-item .mi-top {
            display: flex;
            justify-content: space-between;
            font-size: .5rem;
            opacity: .75;
        }

        .msg-item .mi-msg {
            font-size: .55rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            opacity: .85;
        }

        .chat-view {
            flex: 2 1 420px;
            min-width: 360px;
            max-height: 520px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: .6rem .7rem;
            display: flex;
            flex-direction: column;
            gap: .55rem;
            font-size: .7rem;
            line-height: 1.4;
            height: 460px;
            /* sync with list height */
        }

        @media (max-width: 860px) {

            .msg-list,
            .chat-messages {
                height: 400px;
            }
        }

        @media (max-width: 640px) {

            .msg-list,
            .chat-messages {
                height: 340px;
            }
        }

        .chat-hint {
            opacity: .6;
            font-size: .55rem;
        }

        .cv-header {
            padding: .55rem .75rem;
            border-bottom: 1px solid #1d2b37;
            font-size: .6rem;
            letter-spacing: .5px;
            font-weight: 600;
            color: #8aa6bf;
            display: flex;
            justify-content: space-between;
        }

        .chat-line {
            display: flex;
            flex-direction: column;
            max-width: 78%;
        }

        .chat-line.owner {
            align-self: flex-end;
            text-align: right;
        }

        .chat-meta {
            font-size: .55rem;
            letter-spacing: .4px;
            opacity: .6;
            margin: 0 3px 3px;
            font-weight: 500;
        }

        .chat-bubble {
            background: #1b2c38;
            border: 1px solid #253d4e;
            padding: .55rem .75rem .6rem;
            border-radius: 14px;
            font-size: .68rem;
            line-height: 1.35;
            color: #e2e8f0;
            box-shadow: 0 2px 4px -2px rgba(0, 0, 0, .45);
        }

        .chat-line.owner .chat-bubble {
            background: linear-gradient(135deg, #2563eb, #0ea5e9);
            border: 1px solid #0ea5e9;
            color: #fff;
        }

        .shop-switch .shop-tab {
            background: #132230;
            border: 1px solid #1f2f3a;
            padding: .38rem .7rem;
            border-radius: 20px;
            font-size: .6rem;
            color: #b7c6d3;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .shop-switch .shop-tab:hover {
            border-color: #0ea5e9;
            color: #fff;
        }

        .shop-switch .shop-tab.active {
            background: #0f2733;
            border-color: #0ea5e9;
            color: #fff;
        }

        .badge-sec {
            background: #1e293b;
            border: 1px solid #334155;
            font-size: .5rem;
            padding: .2rem .4rem;
            border-radius: 6px;
            letter-spacing: .5px;
        }

        .msg-filters {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .inquiries-col {
            flex: 1 1 240px;
            min-width: 230px;
            max-height: 480px;
        }

        .bookings-col {
            flex: 1 1 240px;
            min-width: 230px;
            max-height: 480px;
        }

        form.chat-send {
            border-top: 1px solid #1d2b37;
            padding: .55rem .65rem;
            display: flex;
            flex-direction: column;
            gap: .55rem;
        }

        form.chat-send textarea {
            background: #132230;
            border: 1px solid #223746;
            color: #e2e8f0;
            border-radius: 8px;
            padding: .65rem .75rem;
            font-size: .7rem;
            resize: vertical;
            min-height: 52px;
            max-height: 120px;
        }
    </style>
</head>

<body class="owner-shell owner-wrapper">
    <header class="owner-header">
        <button class="nav-hamburger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="ownerNav">☰</button>
        <?php $__first = $user ? e(explode(' ', trim($user['full_name']))[0]) : 'Owner'; ?>
        <div class="owner-brand">BarberSure <span style="opacity:.55;font-weight:500;">Owner</span><span class="owner-badge">Welcome <?= $__first ?></span></div>
        <nav id="ownerNav" class="owner-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="manage_shop.php">Manage Shop</a>
            <a href="bookings.php">Bookings</a>
            <a class="active" href="messages.php">Messages</a>
            <a href="payments.php">Payments</a>
            <a href="profile.php">Profile</a>
            <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </nav>
    </header>
    <main class="owner-main">
        <section class="card" style="padding:1.1rem 1.2rem 1.25rem;margin-bottom:1.3rem;display:flex;flex-direction:column;gap:.55rem;">
            <h1 style="margin:0;font-size:1.45rem;font-weight:600;">Messages Center</h1>
            <p style="margin:0;font-size:.72rem;color:var(--o-text-soft);line-height:1.55;max-width:840px;">View and reply to customer pre-booking inquiries and booking-related chats. These conversations are ephemeral (file-based) and not permanent records.</p>
            <?php if (count($shops) > 1): ?>
                <div class="shop-switch" style="display:flex;flex-wrap:wrap;gap:.55rem;align-items:center;margin-top:.6rem;">
                    <span style="font-size:.55rem;letter-spacing:.5px;font-weight:600;color:#7e95aa;">SHOP:</span>
                    <?php foreach ($shops as $s): $active = ((int)$s['shop_id'] === $selectedShopId); ?>
                        <a href="?shop=<?= (int)$s['shop_id'] ?>" class="shop-tab<?= $active ? ' active' : '' ?>" style="text-decoration:none;">
                            <?= e($s['shop_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$shops): ?>
            <div class="card" style="padding:1rem;">
                <p style="margin:0;font-size:.7rem;">No shops registered yet. <a href="manage_shop.php" style="color:#f59e0b;">Create your first shop</a> to receive inquiries.</p>
            </div>
        <?php else: ?>
            <section class="msg-layout">
                <div class="msg-col inquiries-col" aria-label="Inquiries list">
                    <div style="padding:.5rem .65rem; border-bottom:1px solid #1d2b37; display:flex; justify-content:space-between; align-items:center;">
                        <h3>Inquiries</h3>
                        <span class="badge-sec" id="countInquiries">0</span>
                    </div>
                    <div class="msg-list" id="listInquiries">
                        <div class="msg-empty">No inquiries.</div>
                    </div>
                </div>
                <div class="msg-col bookings-col" aria-label="Bookings chat list">
                    <div style="padding:.5rem .65rem; border-bottom:1px solid #1d2b37; display:flex; justify-content:space-between; align-items:center;">
                        <h3>Bookings</h3>
                        <span class="badge-sec" id="countBookings">0</span>
                    </div>
                    <div class="msg-list" id="listBookings">
                        <div class="msg-empty">No booking chats.</div>
                    </div>
                </div>
                <div class="msg-col chat-view" id="chatView" data-shop-id="<?= $selectedShopId ?>">
                    <div class="cv-header"><span id="chatTitle">Select a conversation</span><span style="font-size:.5rem;opacity:.55;">Auto-refresh</span></div>
                    <div class="chat-messages" id="chatMessages">
                        <div class="chat-hint">Choose a conversation to view messages.</div>
                    </div>
                    <form class="chat-send" id="chatSendForm" style="display:none;">
                        <textarea placeholder="Type a reply..."></textarea>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary" style="font-size:.6rem;padding:.45rem .9rem;">Send</button>
                            <span style="font-size:.5rem;opacity:.5;">Ephemeral • Not stored permanently</span>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <footer class="footer" style="margin-top:2rem;">&copy; <?= date('Y') ?> BarberSure</footer>
    <script src="../assets/js/menu-toggle.js"></script>
    <script>
        window.__OWNER_MESSAGES_CFG = {
            shopId: <?= (int)$selectedShopId ?>,
            autoBooking: <?= isset($_GET['booking']) ? (int)$_GET['booking'] : 'null' ?>
        };
    </script>
    <script src="../assets/js/owner_messages.js"></script>
</body>

</html>