<?php

/** Top navbar */ ?>
<header class="admin-header">
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <span class="fw-bold text-primary">BarberSure Admin</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small d-none d-md-inline"><?= e(date('M d, Y H:i')) ?></span>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><?= e(explode(' ', trim($CURRENT_ADMIN['full_name']))[0] ?? 'Admin') ?></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
</header>