<?php
// Owner Bootstrap 5 responsive header
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
$user = current_user();
$first = $user ? explode(' ', trim($user['full_name']))[0] : 'Owner';
if (!isset($currentOwnerPage)) $currentOwnerPage = '';
?>
<nav class="navbar navbar-expand-lg navbar-dark py-3" style="background:rgba(18,24,32,.9);backdrop-filter:blur(10px);border-bottom:1px solid #23323f;">
    <div class="container-fluid" style="max-width:1320px;">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3" style="width:38px;height:38px;background:#111823;border:1px solid #23323f;">
                <i class="bi bi-scissors" style="font-size:1.1rem;color:#0ea5e9;"></i>
            </span>
            <span class="fw-bold">Barber<span style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);-webkit-background-clip:text;background-clip:text;color:transparent;">Sure</span><span class="ms-2 badge text-bg-dark border border-info-subtle" style="font-size:.55rem;letter-spacing:.5px;">OWNER</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#ownerNav" aria-controls="ownerNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="ownerNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item"><a class="nav-link <?php echo $currentOwnerPage === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentOwnerPage === 'manage_shop' ? 'active' : ''; ?>" href="manage_shop.php">Manage Shop</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentOwnerPage === 'bookings' ? 'active' : ''; ?>" href="bookings.php">Bookings</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentOwnerPage === 'payments' ? 'active' : ''; ?>" href="payments.php">Payments</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentOwnerPage === 'profile' ? 'active' : ''; ?>" href="profile.php">Profile</a></li>
                <li class="nav-item d-lg-flex align-items-center ms-lg-3 order-5 order-lg-0">
                    <span class="badge rounded-pill text-light" style="background:#1c2732;border:1px solid #23323f;font-size:.6rem;letter-spacing:.5px;">Welcome <?php echo htmlspecialchars($first); ?></span>
                </li>
                <li class="nav-item ms-lg-3">
                    <form action="../logout.php" method="post" class="d-inline" onsubmit="return confirm('Log out now?');">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <button type="submit" class="btn btn-sm btn-danger fw-semibold px-3" style="letter-spacing:.5px;">Logout</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>