<?php
// Customer Bootstrap 5 responsive header (separate file to avoid name clash with existing custom one)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
$user = current_user();
$first = $user ? explode(' ', trim($user['full_name']))[0] : 'Customer';
if (!isset($currentCustomerPage)) $currentCustomerPage = '';
?>
<nav class="navbar navbar-expand-lg navbar-dark py-3" style="background:rgba(16,22,30,.9);backdrop-filter:blur(10px);border-bottom:1px solid #22303d;">
    <div class="container-fluid" style="max-width:1320px;">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3" style="width:38px;height:38px;background:#111823;border:1px solid #22303d;">
                <i class="bi bi-scissors" style="font-size:1.1rem;color:#0ea5e9;"></i>
            </span>
            <span class="fw-bold">Barber<span style="background:linear-gradient(135deg,#0ea5e9,#3b82f6);-webkit-background-clip:text;background-clip:text;color:transparent;">Sure</span><span class="ms-2 badge text-bg-dark border border-info-subtle" style="font-size:.55rem;letter-spacing:.5px;">CUSTOMER</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#customerNav" aria-controls="customerNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="customerNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item"><a class="nav-link <?php echo $currentCustomerPage === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentCustomerPage === 'search' ? 'active' : ''; ?>" href="search.php">Find Shops</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentCustomerPage === 'booking' ? 'active' : ''; ?>" href="booking.php">Book</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentCustomerPage === 'history' ? 'active' : ''; ?>" href="bookings_history.php">History</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $currentCustomerPage === 'profile' ? 'active' : ''; ?>" href="profile.php">Profile</a></li>
                <li class="nav-item ms-lg-3 d-lg-flex align-items-center order-5 order-lg-0">
                    <span class="badge rounded-pill text-light" style="background:#1b2530;border:1px solid #22303d;font-size:.6rem;letter-spacing:.5px;">Welcome <?php echo htmlspecialchars($first); ?></span>
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