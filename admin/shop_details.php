<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
require_login();
if (!has_role('admin')) redirect('login.php');

$shopId = (int)($_GET['id'] ?? 0);
if ($shopId <= 0) redirect('manage_shops.php');

// Fetch shop + owner
$stmt = $pdo->prepare("SELECT b.*, u.full_name AS owner_name, u.email AS owner_email, u.phone AS owner_phone, u.is_verified AS owner_verified
                       FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE b.shop_id=? LIMIT 1");
$stmt->execute([$shopId]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shop) redirect('manage_shops.php');

// Fetch documents for this owner & shop grouped
$docsStmt = $pdo->prepare("SELECT d.*, u.full_name FROM Documents d JOIN Users u ON d.owner_id=u.user_id WHERE (d.owner_id=? OR d.shop_id=?) ORDER BY FIELD(d.status,'pending','rejected','approved'), d.uploaded_at DESC");
$docsStmt->execute([$shop['owner_id'], $shopId]);
$documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Approve / Reject doc
$flash = null;
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $flash = 'Invalid token';
        $flashType = 'danger';
    } else {
        $docId = (int)($_POST['document_id'] ?? 0);
        $act = $_POST['action'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        if ($docId > 0 && in_array($act, ['approve', 'reject'], true)) {
            try {
                $dCheck = $pdo->prepare("SELECT document_id, owner_id FROM Documents WHERE document_id=? LIMIT 1");
                $dCheck->execute([$docId]);
                $dRow = $dCheck->fetch(PDO::FETCH_ASSOC);
                if ($dRow) {
                    $newStatus = $act === 'approve' ? 'approved' : 'rejected';
                    $upd = $pdo->prepare("UPDATE Documents SET status=?, reviewed_at=NOW(), reviewer_id=?, notes=? WHERE document_id=?");
                    $upd->execute([$newStatus, current_user()['user_id'], $notes !== '' ? $notes : null, $docId]);
                    // re-check verification
                    if (!function_exists('evaluate_owner_verification')) require_once __DIR__ . '/../config/functions.php';
                    $eval = evaluate_owner_verification((int)$dRow['owner_id']);
                    if (!empty($eval['ready'])) {
                        $pdo->prepare('UPDATE Users SET is_verified=1 WHERE user_id=?')->execute([(int)$dRow['owner_id']]);
                    }
                    $flash = "Document #$docId $newStatus.";
                }
            } catch (Throwable $e) {
                $flash = 'Action failed';
                $flashType = 'danger';
            }
        }
    }
    // Refresh docs list
    $docsStmt->execute([$shop['owner_id'], $shopId]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function doc_label(string $t): string
{
    $map = [
        'personal_id_front' => 'Valid ID (Front)',
        'personal_id_back' => 'Valid ID (Back)',
        'business_permit' => 'Business Permit / DTI / Clearance',
        'sanitation_certificate' => 'Sanitation Certificate',
        'tax_certificate' => 'Tax Certificate',
        'shop_photo' => 'Shop Photo',
        'other' => 'Other'
    ];
    return $map[$t] ?? $t;
}

$title = 'Shop Details • #' . $shopId;
include __DIR__ . '/partials/layout_start.php';
$csrf = csrf_token();
?>
<main class="admin-main">
    <div class="container-fluid p-4 p-lg-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1 fw-semibold">Shop Details #<?= (int)$shopId ?></h1>
                <div class="text-muted small">Owner: <?= e($shop['owner_name']) ?> • Status: <span class="text-uppercase fw-semibold <?= $shop['status'] === 'approved' ? 'text-success' : ($shop['status'] === 'pending' ? 'text-warning' : 'text-danger') ?>"><?= e($shop['status']) ?></span></div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="manage_shops.php">Back to Shops</a>
            </div>
        </div>
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flashType) ?> py-2 small mb-4"><?= e($flash) ?></div>
        <?php endif; ?>
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><strong class="small">Shop Information</strong></div>
                    <div class="card-body small">
                        <div class="mb-2"><strong>Name:</strong> <?= e($shop['shop_name']) ?></div>
                        <div class="mb-2"><strong>City:</strong> <?= e($shop['city'] ?: '—') ?></div>
                        <div class="mb-2"><strong>Address:</strong> <?= e($shop['address'] ?: '—') ?></div>
                        <div class="mb-2"><strong>Description:</strong><br><span class="text-muted"><?= nl2br(e($shop['description'] ?: '—')) ?></span></div>
                        <div class="mb-2"><strong>Registered At:</strong> <?= e(date('Y-m-d H:i', strtotime($shop['registered_at']))) ?></div>
                        <div class="mb-2"><strong>Owner Email:</strong> <?= e($shop['owner_email']) ?></div>
                        <div class="mb-2"><strong>Owner Phone:</strong> <?= e($shop['owner_phone'] ?: '—') ?></div>
                        <div class="mb-2"><strong>Owner Verified:</strong> <?= $shop['owner_verified'] ? '<span class="text-success fw-semibold">YES</span>' : '<span class="text-danger fw-semibold">NO</span>' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><strong class="small">Verification Progress</strong></div>
                    <div class="card-body small">
                        <?php
                        $eval = evaluate_owner_verification($shop['owner_id']);
                        if ($eval['ready']) {
                            echo '<div class="alert alert-success py-2 small mb-2">All requirements satisfied.</div>';
                        } else {
                            echo '<div class="alert alert-warning py-2 small mb-2">Missing: ' . e(implode(', ', describe_verification_missing($eval['missing']))) . '</div>';
                        }
                        ?>
                        <div class="text-muted" style="font-size:.65rem;">Auto-update occurs when documents are approved.</div>
                    </div>
                </div>
            </div>
        </div>
        <h2 class="h5 fw-semibold mb-3">Submitted Documents</h2>
        <?php if (!$documents): ?>
            <p class="text-muted small">No documents submitted yet.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($documents as $d): ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card h-100 border-<?= $d['status'] === 'pending' ? 'warning' : ($d['status'] === 'approved' ? 'success' : 'danger') ?> border-2">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                <span class="small fw-semibold" style="font-size:.65rem;"><?= e(doc_label($d['doc_type'])) ?></span>
                                <span class="badge rounded-pill text-uppercase small bg-<?= $d['status'] === 'pending' ? 'warning' : ($d['status'] === 'approved' ? 'success' : 'danger') ?>" style="font-size:.5rem;letter-spacing:.5px;"><?= e($d['status']) ?></span>
                            </div>
                            <div class="ratio ratio-4x3 bg-dark">
                                <?php if ($d['file_path']): ?>
                                    <a href="../<?= e($d['file_path']) ?>" target="_blank"><img src="../<?= e($d['file_path']) ?>" alt="doc" style="object-fit:cover;width:100%;height:100%;" loading="lazy"></a>
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center text-muted small">No Image</div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-2 small">
                                <div class="mb-1 text-muted" style="font-size:.55rem;">Uploaded: <?= e(date('M j, H:i', strtotime($d['uploaded_at']))) ?></div>
                                <?php if ($d['notes']): ?><div class="mb-1"><span class="text-muted" style="font-size:.55rem;">Notes:</span> <span style="font-size:.55rem;"><?= e($d['notes']) ?></span></div><?php endif; ?>
                                <?php if ($d['status'] === 'pending'): ?>
                                    <form method="post" class="d-flex flex-column gap-1 mt-1">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>" />
                                        <input type="hidden" name="document_id" value="<?= (int)$d['document_id'] ?>" />
                                        <textarea name="notes" class="form-control form-control-sm" placeholder="Notes (optional)" style="font-size:.55rem;min-height:42px;"></textarea>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-success btn-sm flex-grow-1" name="action" value="approve" onclick="return confirm('Approve this document?');" style="font-size:.55rem;">Approve</button>
                                            <button class="btn btn-danger btn-sm flex-grow-1" name="action" value="reject" onclick="return confirm('Reject this document?');" style="font-size:.55rem;">Reject</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div style="font-size:.5rem;" class="text-muted">Reviewed: <?= $d['reviewed_at'] ? e(date('M j, H:i', strtotime($d['reviewed_at']))) : '—' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>