<?php
// Modular dashboard (restored dark version)
require_once __DIR__ . '/partials/auth_check.php';
// Metrics & data pulls
$userCounts = get_user_counts();
$shopCounts = get_shop_counts();
$revenue    = get_revenue_summary();
$upcoming   = get_upcoming_appointments(8);
$alerts     = get_quick_alerts();

// 7-day user growth (new users per day)
$userGrowth = [];
try {
    $stmt = $pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM Users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)");
    $map = [];
    foreach ($stmt as $r) {
        $map[$r['d']] = (int)$r['c'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $userGrowth[] = ['date' => $d, 'count' => ($map[$d] ?? 0)];
    }
} catch (Throwable $e) {
}

// Monthly revenue trend (subscriptions + appointment payments) last 12 months
$revTrend = [];
try {
    $sql = "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, SUM(amount) total FROM Payments WHERE payment_status='completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY ym ORDER BY ym";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $revMap = [];
    foreach ($rows as $r) {
        $revMap[$r['ym']] = (float)$r['total'];
    }
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} month"));
        $revTrend[] = ['ym' => $ym, 'value' => ($revMap[$ym] ?? 0)];
    }
} catch (Throwable $e) {
}

// Appointment status distribution
global $pdo;
$apptStatus = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0, 'completed' => 0];
try {
    foreach ($pdo->query("SELECT status,COUNT(*) c FROM Appointments GROUP BY status") as $r) {
        if (isset($apptStatus[$r['status']])) $apptStatus[$r['status']] = (int)$r['c'];
    }
} catch (Throwable $e) {
}

$recentAppointments = [];
try {
    $rs = $pdo->query("SELECT a.appointment_id,a.appointment_date,a.status,u.full_name AS customer,b.shop_name FROM Appointments a JOIN Users u ON a.customer_id=u.user_id JOIN Barbershops b ON a.shop_id=b.shop_id ORDER BY a.appointment_date DESC LIMIT 6");
    $recentAppointments = $rs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

$recentShops = [];
try {
    $ps = $pdo->query("SELECT shop_id,shop_name,status,registered_at FROM Barbershops WHERE status='pending' ORDER BY registered_at DESC LIMIT 5");
    $recentShops = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

$title = 'Dashboard • BarberSure Admin';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/sidebar.php';
?>
<main class="admin-main">
    <div class="container-fluid px-4 py-3 px-lg-4 py-lg-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Dashboard</h1>
                <div class="text-muted small">Welcome back! Here's what's happening.</div>
            </div>
            <div class="d-flex gap-2">
                <div class="btn-group">
                    <button class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-1"></i> New</button>
                    <ul class="dropdown-menu dropdown-menu-end small">
                        <li><a class="dropdown-item" href="manage_users.php?action=create">User</a></li>
                        <li><a class="dropdown-item" href="../owner/register_shop.php" target="_blank">Shop</a></li>
                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#quickBroadcastModal">Broadcast</button></li>
                        <li><a class="dropdown-item" href="reports.php#schedule-new">Report Schedule</a></li>
                    </ul>
                </div>
                <a href="reports.php" class="btn btn-outline-secondary btn-sm">Export</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php
            $totalShops = ($shopCounts['total'] ?? 0);
            $pendingShops = $shopCounts['pending'] ?? 0;
            $approvedShops = $shopCounts['approved'] ?? 0;
            $rejectedShops = $shopCounts['rejected'] ?? 0;
            $appointmentsTotals = ['total' => 0, 'last7' => 0, 'prev7' => 0];
            try {
                // Total appointments overall
                $appointmentsTotals['total'] = (int)$pdo->query("SELECT COUNT(*) FROM Appointments")->fetchColumn();
                // Last 7 days (including today)
                $appointmentsTotals['last7'] = (int)$pdo->query("SELECT COUNT(*) FROM Appointments WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
                // Previous 7-day window
                $appointmentsTotals['prev7'] = (int)$pdo->query("SELECT COUNT(*) FROM Appointments WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND appointment_date < DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
            } catch (Throwable $e) {
            }
            // Compute growth percent
            $apptGrowthPct = 0;
            if ($appointmentsTotals['prev7'] > 0) {
                $apptGrowthPct = (($appointmentsTotals['last7'] - $appointmentsTotals['prev7']) / max(1, $appointmentsTotals['prev7'])) * 100;
            } elseif ($appointmentsTotals['last7'] > 0) {
                $apptGrowthPct = 100; // brand new spike
            }
            // Format growth label
            $apptGrowthLabel = ($apptGrowthPct === 0) ? '0%' : (($apptGrowthPct > 0 ? '+' : '') . round($apptGrowthPct, 1) . '%');
            $topCards = [
                ['Total Users', $userCounts['total'] ?? 0, 'bi-people', 'primary', '+' . ($userCounts['total'] ? '12.5%' : '0%')],
                ['Revenue', '₱' . nfmt(($revenue['subscription_paid'] ?? 0) + ($revenue['appointment_payments'] ?? 0)), 'bi-currency-exchange', 'success', '+' . ($revenue['subscription_paid'] ? '8.2%' : '0%')],
                ['Appointments', $appointmentsTotals['total'], 'bi-calendar2-check', 'warning', $apptGrowthLabel],
                ['Barbershops', $totalShops, 'bi-shop', 'info', $approvedShops . 'A / ' . $pendingShops . 'P / ' . $rejectedShops . 'R']
            ];
            foreach ($topCards as $card) {
                [$label, $value, $icon, $color, $delta] = $card;
                $deltaClass = $label === 'Barbershops' ? 'text-muted small fw-normal' : (str_starts_with($delta, '-') ? 'text-danger' : 'text-success');
                echo '<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="rounded bg-' . $color . ' bg-opacity-10 text-' . $color . ' p-3"><i class="bi ' . $icon . ' fs-5"></i></div><div><div class="text-muted small">' . e($label) . '</div><div class="fs-5 fw-semibold">' . e($value) . '</div><div class="small ' . $deltaClass . '">' . e($delta) . '</div></div></div></div></div>';
            }
            ?>
        </div>
        <?php
        // Recent activity unified feed: latest from users, shops, appointments, critical admin log
        $activities = [];
        try {
            foreach ($pdo->query("SELECT full_name, created_at FROM Users ORDER BY created_at DESC LIMIT 5") as $u) {
                $activities[] = ['icon' => 'bi-person-plus', 'label' => "New user: " . e(explode(' ', trim($u['full_name']))[0]), 'time' => strtotime($u['created_at'])];
            }
        } catch (Throwable $e) {
        }
        try {
            foreach ($recentShops as $rs) {
                $activities[] = ['icon' => 'bi-shop', 'label' => "Shop pending: " . e($rs['shop_name']), 'time' => strtotime($rs['registered_at'])];
            }
        } catch (Throwable $e) {
        }
        try {
            foreach ($pdo->query("SELECT appointment_id, status, appointment_date FROM Appointments ORDER BY appointment_date DESC LIMIT 5") as $ap) {
                $activities[] = ['icon' => 'bi-scissors', 'label' => "Appt #" . (int)$ap['appointment_id'] . " " . e($ap['status']), 'time' => strtotime($ap['appointment_date'])];
            }
        } catch (Throwable $e) {
        }
        // Admin log parse (last ~100 lines) for key actions
        try {
            $logDir = dirname(__DIR__) . '/logs';
            $logFile = $logDir . '/admin_actions.log';
            if (is_readable($logFile)) {
                $fh = @fopen($logFile, 'r');
                if ($fh) {
                    $lines = [];
                    $buffer = '';
                    $pos = -1;
                    $lineCount = 0;
                    $maxLines = 120;
                    $fileSize = filesize($logFile);
                    while ($lineCount < $maxLines && (-$pos) <= $fileSize) {
                        fseek($fh, $pos, SEEK_END);
                        $char = fgetc($fh);
                        if ($char === "\n") {
                            if ($buffer !== '') {
                                $lines[] = strrev($buffer);
                                $buffer = '';
                                $lineCount++;
                            }
                        } else {
                            $buffer .= $char;
                        }
                        $pos--;
                    }
                    if ($buffer !== '') $lines[] = strrev($buffer);
                    fclose($fh);
                    foreach ($lines as $ln) {
                        $data = json_decode($ln, true);
                        if (!is_array($data) || empty($data['action'])) continue;
                        $t = strtotime($data['ts'] ?? '');
                        if (!$t) continue;
                        $act = $data['action'];
                        $labelMap = [
                            'verify_owner' => 'Owner verified',
                            'soft_delete' => 'User soft deleted',
                            'hard_delete' => 'User hard deleted',
                            'suspend_user' => 'User suspended',
                            'activate_user' => 'User reactivated'
                        ];
                        if (isset($labelMap[$act])) {
                            $activities[] = ['icon' => 'bi-shield-check', 'label' => $labelMap[$act], 'time' => $t];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }
        usort($activities, fn($a, $b) => $b['time'] <=> $a['time']);
        $activities = array_slice($activities, 0, 8);
        ?>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="card-title mb-0">Revenue Overview</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-secondary active">7D</button>
                            <button class="btn btn-outline-secondary">30D</button>
                            <button class="btn btn-outline-secondary">90D</button>
                            <button class="btn btn-outline-secondary">1Y</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueTrendChart" height="140"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="activity-feed" id="recent-activity">
                            <?php if ($activities): foreach ($activities as $a):
                                    $ago = time() - $a['time'];
                                    $mins = floor($ago / 60);
                                    $disp = $mins < 60 ? $mins . 'm ago' : floor($mins / 60) . 'h ago'; ?>
                                    <div class="activity-item">
                                        <div class="activity-icon"><i class="bi <?= e($a['icon']) ?>"></i></div>
                                        <div class="activity-main">
                                            <div class="activity-label"><?= $a['label'] ?></div>
                                            <div class="activity-time"><?= e($disp) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <div class="text-muted small">No recent activity.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7 col-xl-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">User Growth (Last 7 Days)</h5>
                    </div>
                    <div class="card-body"><canvas id="userGrowthChart" height="130"></canvas></div>
                </div>
            </div>
            <div class="col-lg-5 col-xl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Appointment Status Distribution</h5>
                    </div>
                    <div class="card-body pt-3">
                        <div class="row g-3 align-items-center">
                            <div class="col-6">
                                <div class="position-relative mx-auto" style="max-width:180px">
                                    <canvas id="apptStatusChart" height="180"></canvas>
                                    <div class="position-absolute top-50 start-50 translate-middle text-center" style="pointer-events:none;">
                                        <div class="text-muted small" style="letter-spacing:.5px"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <ul class="list-unstyled mb-0 small appt-status-legend" id="apptStatusLegend"></ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-8 col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Recent Appointments</h5>
                    </div>
                    <div class="card-body table-responsive small">
                        <?php if ($recentAppointments): ?>
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Customer</th>
                                        <th>Shop</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentAppointments as $ra): ?>
                                        <tr>
                                            <td>#<?= e($ra['appointment_id']) ?></td>
                                            <td><?= e($ra['customer']) ?></td>
                                            <td><?= e($ra['shop_name']) ?></td>
                                            <td><span class="badge rounded-pill text-bg-<?php echo $ra['status'] === 'pending' ? 'warning' : ($ra['status'] === 'confirmed' ? 'info' : ($ra['status'] === 'cancelled' ? 'secondary' : 'success')); ?>"><?= e(ucfirst($ra['status'])) ?></span></td>
                                            <td><?= e(date('M d, Y H:i', strtotime($ra['appointment_date']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?><p class="text-muted mb-0">No appointment data yet.</p><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-lg-5">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Quick Alerts</h5>
                        <span class="text-muted small"><?= $alerts ? count($alerts) : 0 ?> items</span>
                    </div>
                    <div class="card-body">
                        <?php if ($alerts): ?>
                            <ul class="quick-alerts" id="quick-alerts">
                                <?php foreach ($alerts as $al) {
                                    $txt = trim($al);
                                    $icon = 'bi-info-circle';
                                    if (stripos($txt, 'fail') !== false || stripos($txt, 'error') !== false) {
                                        $icon = 'bi-exclamation-octagon';
                                    } elseif (stripos($txt, 'pending') !== false || stripos($txt, 'await') !== false) {
                                        $icon = 'bi-hourglass-split';
                                    } elseif (stripos($txt, 'payment') !== false || stripos($txt, 'invoice') !== false) {
                                        $icon = 'bi-credit-card';
                                    } elseif (stripos($txt, 'user') !== false || stripos($txt, 'account') !== false) {
                                        $icon = 'bi-person';
                                    } elseif (stripos($txt, 'shop') !== false) {
                                        $icon = 'bi-shop';
                                    }
                                    echo '<li class="qa-item"><div class="qa-ico"><i class="bi ' . $icon . '"></i></div><div class="qa-text">' . e($txt) . '<small>' . date('H:i') . '</small></div></li>';
                                } ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">No alerts right now 🎉</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /.container-fluid -->
</main>
<script>
    const revenueData = <?= json_encode($revenue, JSON_NUMERIC_CHECK) ?>;
    const apptStatus = <?= json_encode($apptStatus, JSON_NUMERIC_CHECK) ?>;
    const userGrowth = <?= json_encode($userGrowth, JSON_NUMERIC_CHECK) ?>; // [{date:YYYY-MM-DD,count:n}]
    const userGrowthLabels = userGrowth.map(r => {
        const d = new Date(r.date + 'T00:00:00');
        return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric'
        });
    });
    const growthData = userGrowth.map(r => r.count);
    const revTrend = <?= json_encode($revTrend, JSON_NUMERIC_CHECK) ?>; // [{ym:YYYY-MM,value:n}]

    (() => {
        const ctx = document.getElementById('revenueTrendChart');
        if (!ctx) return;
        const rev = revTrend.reduce((a, r) => a + r.value, 0);
        const profit = rev * 0.62; // placeholder margin assumption
        const months = revTrend.map(r => {
            const [y, m] = r.ym.split('-');
            return new Date(y, m - 1, 1).toLocaleString('en-US', {
                month: 'short'
            });
        });
        const seriesRev = revTrend.map(r => r.value);
        const seriesProfit = revTrend.map(r => Math.round(r.value * 0.62));
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Revenue',
                    data: seriesRev,
                    borderColor: '#F59E0B',
                    backgroundColor: 'rgba(245,158,11,.08)',
                    tension: .4,
                    fill: true
                }, {
                    label: 'Profit',
                    data: seriesProfit,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16,185,129,.08)',
                    tension: .4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: true
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: v => '₱' + v
                        }
                    }
                }
            }
        });
    })();

    (() => {
        const ctx = document.getElementById('userGrowthChart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: userGrowthLabels,
                datasets: [{
                    label: 'New Users',
                    data: growthData,
                    backgroundColor: '#F59E0B',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    })();

    (() => {
        const ctx = document.getElementById('apptStatusChart');
        if (!ctx) return;
        const raw = {
            pending: apptStatus.pending || 0,
            confirmed: apptStatus.confirmed || 0,
            cancelled: apptStatus.cancelled || 0,
            completed: apptStatus.completed || 0
        };
        const labels = ['Pending', 'Confirmed', 'Cancelled', 'Completed'];
        const colors = ['#F59E0B', '#FBBF24', '#6B7280', '#10B981'];
        const dataArr = [raw.pending, raw.confirmed, raw.cancelled, raw.completed];
        const total = dataArr.reduce((a, b) => a + b, 0);
        const legendEl = document.getElementById('apptStatusLegend');
        const totalEl = document.getElementById('apptStatusTotal');
        const totalLbl = document.getElementById('apptStatusTotalLabel');
        if (totalEl) totalEl.textContent = total;
        if (totalLbl) totalLbl.textContent = total + ' total';
        if (legendEl) {
            legendEl.innerHTML = '';
            labels.forEach((lbl, i) => {
                const val = dataArr[i];
                const pct = total ? ((val / total) * 100) : 0;
                const li = document.createElement('li');
                li.className = 'mb-2';
                li.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-1"><div class="d-flex align-items-center gap-2"><span class="legend-dot" style="background:${colors[i]}"></span><span>${lbl}</span></div><span class="text-muted">${val}</span></div><div class="progress" style="height:4px;"><div class="progress-bar" role="progressbar" style="width:${pct.toFixed(1)}%;background:${colors[i]};"></div></div><div class="text-muted" style="font-size:.65rem;margin-top:2px;">${pct.toFixed(1)}%</div>`;
                legendEl.appendChild(li);
            });
            if (!total) legendEl.innerHTML = '<li class="text-muted">No data</li>';
        }
        const centerPlugin = {
            id: 'centerText',
            beforeDraw(chart) {
                if (!total) return;
                const {
                    ctx
                } = chart;
                const x = chart.getDatasetMeta(0).data[0].x;
                const y = chart.getDatasetMeta(0).data[0].y;
                ctx.save();
                ctx.font = '600 14px Inter, system-ui, sans-serif';
                ctx.fillStyle = '#e5e7eb';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(total, x, y - 4);
                ctx.font = '500 10px Inter, system-ui, sans-serif';
                ctx.fillStyle = '#9ca3af';
                ctx.fillText('Total', x, y + 12);
                ctx.restore();
            }
        };
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: dataArr,
                    backgroundColor: colors.map(c => {
                        const g = ctx.getContext('2d').createLinearGradient(0, 0, 180, 180);
                        g.addColorStop(0, c + 'DD');
                        g.addColorStop(1, c + '66');
                        return g;
                    }),
                    borderWidth: 2,
                    borderColor: '#1f2937'
                }]
            },
            options: {
                cutout: '68%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: p => {
                                const v = p.parsed;
                                const q = total ? (v / total * 100).toFixed(1) : 0;
                                return `${p.label}: ${v} (${q}%)`;
                            }
                        }
                    }
                }
            },
            plugins: [centerPlugin]
        });
    })();
</script>
<style>
    .appt-status-legend .legend-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        box-shadow: 0 0 0 2px #1f2937;
    }

    .appt-status-legend li:last-child {
        margin-bottom: 0;
    }

    .appt-status-legend .progress {
        background: #2d3748;
    }

    .appt-status-legend .progress-bar {
        transition: width .4s ease;
    }

    @media (max-width:575.98px) {
        #apptStatusChart {
            height: 140px !important;
        }
    }
</style>
<?php include __DIR__ . '/partials/footer.php'; ?>
<!-- Quick Broadcast Modal -->
<div class="modal fade" id="quickBroadcastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-slideout modal-sm modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header py-2">
                <h6 class="modal-title">Quick Broadcast</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="notifications.php" class="needs-validation" novalidate>
                <input type="hidden" name="create_broadcast" value="1">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="modal-body py-3">
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Audience</label>
                        <select name="audience" class="form-select form-select-sm">
                            <option value="all">All Users</option>
                            <option value="owners">Owners</option>
                            <option value="customers">Customers</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Channels</label>
                        <div class="d-flex flex-wrap gap-2">
                            <label class="form-check small"><input class="form-check-input" type="checkbox" name="channels[]" value="system" checked> System</label>
                            <label class="form-check small"><input class="form-check-input" type="checkbox" name="channels[]" value="email"> Email</label>
                            <label class="form-check small"><input class="form-check-input" type="checkbox" name="channels[]" value="sms"> SMS</label>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Title</label>
                        <input type="text" name="title" class="form-control form-control-sm" maxlength="150" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Message</label>
                        <textarea name="message" class="form-control form-control-sm" rows="4" required></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small text-muted mb-1">Schedule (optional)</label>
                        <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm">
                    </div>
                    <div class="form-text xsmall mb-2">Leave schedule blank to send immediately.</div>
                    <div class="border rounded p-2 mb-2">
                        <div class="text-muted xsmall mb-1">Link (optional)</div>
                        <div class="row g-1 align-items-center">
                            <div class="col-4"><input name="link1_label" class="form-control form-control-sm" placeholder="Label"></div>
                            <div class="col-8"><input name="link1_url" class="form-control form-control-sm" placeholder="https://..."></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-sm btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>