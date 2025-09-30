<?php

/** Sidebar navigation */ ?>
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-content">
        <div class="brand">
            <span class="logo-dot"></span>
            <span>BarberSure Admin</span>
        </div>
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <?php
                $NAV = [
                    ['dashboard.php', 'bi-speedometer2', 'Dashboard'],
                    ['manage_users.php', 'bi-people', 'Users'],
                    ['manage_shops.php', 'bi-shop', 'Shops'],
                    ['payments.php', 'bi-credit-card', 'Payments'],
                    ['documents.php', 'bi-folder2-open', 'Documents'],
                    ['reports.php', 'bi-file-earmark-text', 'Reports'],
                    ['notifications.php', 'bi-bell', 'Notifications'],
                    ['settings.php', 'bi-gear', 'Settings'],
                    ['logs.php', 'bi-clipboard-data', 'Logs'],
                ];
                $current = basename($_SERVER['PHP_SELF']);
                foreach ($NAV as $item) {
                    [$href, $icon, $label] = $item;
                    $active = $current === $href ? 'active' : '';
                    echo '<li class="nav-item"><a class="nav-link ' . $active . '" href="' . $href . '"><i class="bi ' . $icon . '"></i><span>' . $label . '</span></a></li>';
                }
                ?>
                <li class="nav-item mt-3 pt-2 border-top border-secondary">
                    <form method="post" action="../logout.php" onsubmit="return confirm('Are you sure you want to log out?');" class="d-flex">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <button type="submit" class="nav-link text-danger d-flex align-items-center gap-2 bg-transparent border-0 p-0 w-100 text-start" style="cursor:pointer;">
                            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
                        </button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</aside>