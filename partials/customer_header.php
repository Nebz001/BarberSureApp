<?php
// Customer header bar (re-usable across customer-facing pages when logged-in customer views public pages)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/helpers.php';
$user = current_user();
?>
<header class="header-bar">
    <div class="header-brand">
        <span>BarberSure <span style="opacity:.55;font-weight:500;">Customer</span></span>
        <span class="header-badge">Welcome<?= $user ? ', ' . e(explode(' ', trim($user['full_name']))[0]) : '' ?></span>
    </div>
    <nav class="nav-links">
        <a href="../customer/dashboard.php">Dashboard</a>
        <a href="../customer/search.php">Find Shops</a>
        <a href="../customer/bookings_history.php">History</a>
        <a href="../customer/profile.php">Profile</a>
        <form action="../logout.php" method="post" onsubmit="return confirm('Log out now?');" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
            <button type="submit" class="logout-btn">Logout</button>
        </form>
    </nav>
</header>