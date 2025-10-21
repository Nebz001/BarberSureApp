<?php
// Minimal owner chat interface to respond to ephemeral booking chats.
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
if (!is_logged_in() || !has_role('owner')) redirect('../login.php');
$user = current_user();
// Owner must manually input channel provided by customer (share out-of-band) or via future integration.
$channel = isset($_GET['c']) && preg_match('/^[A-Za-z0-9_-]{6,60}$/', $_GET['c']) ? $_GET['c'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Ephemeral Chat • Owner • BarberSure</title>
    <link rel="stylesheet" href="../assets/css/owner.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .chat-wrap {
            max-width: 740px;
            margin: 2rem auto;
            padding: 1rem 1.2rem;
            background: #0f1923;
            border: 1px solid #243240;
            border-radius: 14px;
        }

        .chat-messages {
            background: #111f2a;
            border: 1px solid #243240;
            border-radius: 8px;
            padding: .7rem .75rem;
            display: flex;
            flex-direction: column;
            gap: .6rem;
            height: 320px;
            overflow-y: auto;
            font-size: .7rem;
        }

        .chat-line {
            display: flex;
            flex-direction: column;
            gap: .2rem;
        }

        .chat-line .chat-meta {
            font-size: .55rem;
            letter-spacing: .5px;
            opacity: .65;
        }

        .chat-line.chat-owner .chat-text {
            background: #1e2e17;
            color: #bbf7d0;
            border: 1px solid #395c2c;
        }

        .chat-line.chat-customer .chat-text {
            background: #0e1a28;
            color: #cfe3ff;
            border: 1px solid #1e3a5f;
        }

        .chat-text {
            padding: .45rem .55rem;
            border-radius: 8px;
            font-size: .68rem;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .chat-form {
            display: flex;
            flex-direction: column;
            gap: .55rem;
            margin-top: .8rem;
        }

        .chat-form textarea {
            background: #111f2a;
            border: 1px solid #243240;
            color: #e5ebf1;
            border-radius: 8px;
            padding: .6rem .65rem;
            font-size: .7rem;
            resize: vertical;
            min-height: 60px;
        }
    </style>
</head>

<body class="owner-wrapper">
    <div class="chat-wrap">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg" />
        <h1 style="margin:0 0 1rem;font-size:1.15rem;font-weight:600;"><i class="bi bi-chat-left-text" aria-hidden="true"></i> Ephemeral Customer Chat</h1>
        <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem;">
            <input type="text" name="c" value="<?= e($channel) ?>" placeholder="Channel ID" style="flex:1;min-width:220px;padding:.55rem .65rem;border-radius:8px;border:1px solid #243240;background:#111f2a;color:#e5ebf1;" />
            <button class="btn" style="background:#1e2e17;border:1px solid #395c2c;color:#bbf7d0;"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Open</button>
            <span style="font-size:.55rem;color:#9aa5b1;">Provide channel received from customer.</span>
        </form>
        <?php if ($channel): ?>
            <div id="bookingChat" data-channel="<?= e($channel) ?>">
                <div class="chat-messages">
                    <div style="text-align:center;font-size:.6rem;opacity:.7;">Connected to channel <?= e($channel) ?>.</div>
                </div>
                <form class="chat-form">
                    <textarea placeholder="Type response…"></textarea>
                    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="btn" style="background:#1e2e17;border:1px solid #395c2c;color:#bbf7d0;font-size:.65rem;padding:.55rem 1.1rem;"><i class="bi bi-send" aria-hidden="true"></i> Send</button>
                        <span style="font-size:.55rem;color:#9aa5b1;">Messages auto-expire; not stored in DB.</span>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <p style="font-size:.65rem;color:#9aa5b1;margin:0;">Enter a channel ID to begin.</p>
        <?php endif; ?>
    </div>
    <script src="../assets/js/booking_chat.js"></script>
</body>

</html>