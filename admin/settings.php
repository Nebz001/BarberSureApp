<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');
global $pdo;

$section = $_GET['section'] ?? null;
$flash = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_action'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'danger', 'text' => 'Invalid CSRF'];
    } else {
        $act = $_POST['settings_action'];
        try {
            if ($act === 'update_tax') {
                $vat = trim($_POST['vat_rate'] ?? '');
                if (is_numeric($vat)) set_setting('tax_vat_percent', $vat);
                $flash = ['type' => 'success', 'text' => 'Tax settings saved'];
            } elseif ($act === 'update_location') {
                set_setting('map_default_lat', trim($_POST['map_default_lat'] ?? ''));
                set_setting('map_default_lng', trim($_POST['map_default_lng'] ?? ''));
                set_setting('map_search_radius_km', trim($_POST['map_search_radius_km'] ?? ''));
                $flash = ['type' => 'success', 'text' => 'Location defaults updated'];
            } elseif ($act === 'add_category') {
                $name = trim($_POST['name'] ?? '');
                if ($name) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                    ensure_service_category($slug, $name, trim($_POST['description'] ?? ''));
                    $flash = ['type' => 'success', 'text' => 'Category added'];
                }
            } elseif ($act === 'delete_category') {
                $cid = (int)($_POST['category_id'] ?? 0);
                if ($cid) delete_service_category($cid);
                $flash = ['type' => 'warning', 'text' => 'Category deleted'];
            } elseif ($act === 'create_admin') {
                $email = trim($_POST['email'] ?? '');
                $name = trim($_POST['full_name'] ?? '');
                $pass = $_POST['password'] ?? '';
                if ($email && $name && strlen($pass) >= 6) {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO Users (full_name, email, password_hash, role, username) VALUES (?,?,?,?,?)");
                    $stmt->execute([$name, $email, $hash, 'admin', substr(preg_replace('/[^a-z0-9]/i', '', strtolower($name)), 0, 30)]);
                    $flash = ['type' => 'success', 'text' => 'Admin account created'];
                } else {
                    $flash = ['type' => 'danger', 'text' => 'Invalid admin details'];
                }
            } elseif ($act === 'update_integrations') {
                set_setting('email_provider', trim($_POST['email_provider'] ?? ''));
                set_setting('email_api_key', trim($_POST['email_api_key'] ?? ''));
                set_setting('sms_provider', trim($_POST['sms_provider'] ?? ''));
                set_setting('sms_api_key', trim($_POST['sms_api_key'] ?? ''));
                $flash = ['type' => 'success', 'text' => 'Integration settings saved'];
            } elseif ($act === 'update_retention') {
                set_setting('retention_logs_days', (int)($_POST['retention_logs_days'] ?? 90));
                set_setting('retention_notifications_days', (int)($_POST['retention_notifications_days'] ?? 180));
                if (isset($_POST['run_cleanup'])) {
                    $res = cleanup_retention();
                    $flash = ['type' => 'info', 'text' => 'Cleanup executed: ' . $res['notifications_deleted'] . ' notifications pruned'];
                } else {
                    $flash = ['type' => 'success', 'text' => 'Retention settings updated'];
                }
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'text' => 'Action failed'];
        }
    }
}

$settingsAll = get_all_settings();
$categories = list_service_categories();

$title = 'Settings • Admin';
include __DIR__ . '/partials/layout_start.php';
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">System Settings</h1>
                <div class="text-muted small">Platform configuration & management.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="settings.php">Overview</a>
            </div>
        </div>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> py-2 small mb-4"><?= e($flash['text']) ?></div><?php endif; ?>
        <?php if (!$section): ?>
            <div class="row g-4">
                <?php
                $settings = [
                    ['tax', 'Tax Rates', 'Manage VAT or service tax percentages used in subscriptions & payments.', 'Edit Tax'],
                    ['categories', 'Service Categories', 'Define standard categories customers can filter by (e.g. Haircut, Shave).', 'Manage'],
                    ['location', 'Location Defaults', 'Adjust default map center and radius for shop discovery.', 'Configure'],
                    ['admins', 'Admin Accounts', 'Create additional admin users and assign limited roles.', 'Manage Admins'],
                    ['integrations', 'Email / SMS Integrations', 'Configure outbound providers and API keys (secure storage needed).', 'Configure'],
                    ['retention', 'Data Retention', 'Define how long logs, notifications, and inactive data are kept.', 'Policies'],
                ];
                foreach ($settings as $s) {
                    [$key, $name, $desc, $btn] = $s;
                    echo '<div class="col-sm-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
                        . '<h6 class="fw-semibold mb-1">' . e($name) . '</h6>'
                        . '<p class="text-muted small flex-grow-1 mb-3" style="line-height:1.4;">' . e($desc) . '</p>'
                        . '<a class="btn btn-sm btn-outline-primary align-self-start" href="settings.php?section=' . e(urlencode($key)) . '">' . e($btn) . '</a>'
                        . '</div></div></div>';
                }
                ?>
            </div>
        <?php else: ?>
            <?php if ($section === 'tax'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Tax Rates</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="update_tax">
                            <div class="col-md-3"><label class="form-label small text-muted mb-1">VAT %</label><input type="number" step="0.01" name="vat_rate" value="<?= e($settingsAll['tax_vat_percent'] ?? '12.00') ?>" class="form-control form-control-sm"></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
                        </form>
                    </div>
                </div>
            <?php elseif ($section === 'categories'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Service Categories</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="add_category">
                            <div class="col-md-4"><input name="name" required class="form-control form-control-sm" placeholder="Name"></div>
                            <div class="col-md-6"><input name="description" class="form-control form-control-sm" placeholder="Description"></div>
                            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                        </form>
                        <div class="table-responsive" style="max-height:300px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$categories): ?><tr>
                                            <td colspan="3" class="text-center text-muted">None</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($categories as $c): ?><tr>
                                            <td class="xsmall fw-semibold"><?= e($c['name']) ?></td>
                                            <td class="xsmall text-muted"><?= e($c['description']) ?></td>
                                            <td class="text-end">
                                                <form method="post" onsubmit="return confirm('Delete category?');" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="delete_category"><input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>"><button class="btn btn-outline-danger btn-sm xsmall">Del</button></form>
                                            </td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif ($section === 'location'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Location Defaults</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="update_location">
                            <div class="col-md-3"><label class="form-label small text-muted mb-1">Map Center Lat</label><input name="map_default_lat" class="form-control form-control-sm" value="<?= e($settingsAll['map_default_lat'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label small text-muted mb-1">Map Center Lng</label><input name="map_default_lng" class="form-control form-control-sm" value="<?= e($settingsAll['map_default_lng'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label small text-muted mb-1">Search Radius (km)</label><input name="map_search_radius_km" class="form-control form-control-sm" value="<?= e($settingsAll['map_search_radius_km'] ?? '10') ?>"></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
                        </form>
                    </div>
                </div>
            <?php elseif ($section === 'admins'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Admin Accounts</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-3 mb-4"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="create_admin">
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">Full Name</label><input name="full_name" class="form-control form-control-sm" required></div>
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">Email</label><input type="email" name="email" class="form-control form-control-sm" required></div>
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">Password</label><input type="password" name="password" class="form-control form-control-sm" required></div>
                            <div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Create</button></div>
                        </form>
                        <?php $admins = $pdo->query("SELECT user_id, full_name, email, created_at FROM Users WHERE role='admin' ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); ?>
                        <div class="table-responsive" style="max-height:300px;">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admins as $a): ?><tr>
                                            <td class="xsmall fw-semibold"><?= e($a['full_name']) ?></td>
                                            <td class="xsmall text-muted"><?= e($a['email']) ?></td>
                                            <td class="xsmall text-muted"><?= e(substr($a['created_at'], 0, 16)) ?></td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif ($section === 'integrations'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Email / SMS Integrations</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="update_integrations">
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">Email Provider</label><input name="email_provider" class="form-control form-control-sm" value="<?= e($settingsAll['email_provider'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label xsmall text-muted mb-1">Email API Key</label><input name="email_api_key" class="form-control form-control-sm" value="<?= e($settingsAll['email_api_key'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">SMS Provider</label><input name="sms_provider" class="form-control form-control-sm" value="<?= e($settingsAll['sms_provider'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label xsmall text-muted mb-1">SMS API Key</label><input name="sms_api_key" class="form-control form-control-sm" value="<?= e($settingsAll['sms_api_key'] ?? '') ?>"></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
                        </form>
                        <p class="xsmall text-muted mt-3 mb-0">Secrets currently stored in plain DB (improve with encrypted vault in production).</p>
                    </div>
                </div>
            <?php elseif ($section === 'retention'): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Data Retention Policies</h6><a class="small" href="settings.php">Back</a>
                    </div>
                    <div class="card-body small">
                        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="settings_action" value="update_retention">
                            <div class="col-md-3"><label class="form-label xsmall text-muted mb-1">Logs Days</label><input type="number" name="retention_logs_days" class="form-control form-control-sm" value="<?= e($settingsAll['retention_logs_days'] ?? '90') ?>"></div>
                            <div class="col-md-4"><label class="form-label xsmall text-muted mb-1">Notifications Days</label><input type="number" name="retention_notifications_days" class="form-control form-control-sm" value="<?= e($settingsAll['retention_notifications_days'] ?? '180') ?>"></div>
                            <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Save</button><button name="run_cleanup" value="1" class="btn btn-sm btn-outline-warning" onclick="return confirm('Run cleanup now?');">Run Cleanup Now</button></div>
                        </form>
                        <p class="xsmall text-muted mb-0 mt-3">Cleanup currently prunes old Notifications only (extend as needed).</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning small">Unknown section.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<style>
    .xsmall {
        font-size: .65rem
    }
</style>