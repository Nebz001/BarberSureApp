<?php
// Shared public header (navigation) for BarberSure
// Usage: set $currentPage = 'home' | 'discover' | other before including.
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = isset($_SESSION['user_id']);
if (!isset($currentPage)) {
    $currentPage = '';
}
?>
<nav class="navbar navbar-expand-lg navbar-dark py-3 fixed-top" style="background:rgba(14,18,23,.85); backdrop-filter:blur(12px); border-bottom:1px solid #1d2732;">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 gradient-border" style="width:38px;height:38px;background:#111823;">
                <i class="bi bi-scissors" style="font-size:1.1rem;color:#0ea5e9;"></i>
            </span>
            <span class="logo-text">Barber<span class="gradient-text">Sure</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-controls="navMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="index.php#features">Features</a></li>
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="index.php#why">Why Us</a></li>
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="index.php#pricing">Pricing</a></li>
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="index.php#growth">Growth</a></li>
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'discover' ? 'active' : '' ?>" href="discover.php">Browse Shops</a></li>
                <?php if ($isLoggedIn): ?>
                    <li class="nav-item ms-lg-3"><a href="customer/dashboard.php" class="btn btn-sm cta-secondary px-3">Dashboard</a></li>
                <?php else: ?>
                    <li class="nav-item ms-lg-3"><a href="login.php" class="btn btn-sm cta-secondary px-3">Sign In</a></li>
                    <li class="nav-item"><a href="register.php" class="btn btn-sm cta-btn ms-lg-2 px-3">Get Started</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>