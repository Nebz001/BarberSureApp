<?php
$title = 'Documents Review • BarberSure Admin';
require_once __DIR__ . '/partials/layout_start.php';

$shopId = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : 0;
$status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status, $allowedStatuses, true)) $status = 'pending';

// Action handling only applies in drill-down mode
$actionMessage = null;
$actionError = null;
if ($shopId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $actionError = 'Invalid session token.';
    } else {
        $act = $_POST['action'] ?? '';
        $docId = (int)($_POST['document_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($docId <= 0) $actionError = 'Invalid document id';
        if (!$actionError && !in_array($act, ['approve', 'reject'], true)) $actionError = 'Unknown action';
        if (!$actionError) {
            try {
                $stmt = $pdo->prepare('SELECT d.*, b.owner_id AS shop_owner FROM Documents d LEFT JOIN Barbershops b ON d.shop_id=b.shop_id WHERE d.document_id=? LIMIT 1');
                $stmt->execute([$docId]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$doc) {
                    $actionError = 'Document not found';
                } else {
                    $newStatus = $act === 'approve' ? 'approved' : 'rejected';
                    $upd = $pdo->prepare('UPDATE Documents SET status=?, reviewed_at=NOW(), reviewer_id=?, notes=? WHERE document_id=?');
                    $upd->execute([$newStatus, $CURRENT_ADMIN['user_id'], $notes !== '' ? $notes : null, $docId]);
                    $actionMessage = 'Document ' . $docId . ' ' . ($act === 'approve' ? 'approved' : 'rejected') . '.';
                    if ($act === 'approve') {
                        if (!function_exists('evaluate_owner_verification')) require_once __DIR__ . '/../config/functions.php';
                        $ownerToCheck = (int)$doc['owner_id'];
                        if ($ownerToCheck > 0) {
                            $eval = evaluate_owner_verification($ownerToCheck);
                            if (!empty($eval['ready'])) {
                                $pdo->prepare('UPDATE Users SET is_verified=1 WHERE user_id=?')->execute([$ownerToCheck]);
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $actionError = 'Action failed: ' . $e->getMessage();
            }
        }
    }
}

function doc_label(string $t): string
{
    $map = [
        'personal_id_front' => 'Valid ID',
        'personal_id_back' => 'ID Back',
        'business_permit' => 'Business Permit / DTI / Clearance',
        'sanitation_certificate' => 'Sanitation Cert',
        'tax_certificate' => 'Tax Cert',
        'shop_photo' => 'Shop Photo',
        'other' => 'Other'
    ];
    return $map[$t] ?? $t;
}

?>
<main class="admin-main" style="padding:1.2rem 1.4rem 2rem;">
    <?php if (!$shopId): ?>
        <h1 style="margin:0 0 1.1rem;font-size:1.4rem;font-weight:600;letter-spacing:.5px;">Document Review • Shops</h1>
        <?php
        // Aggregate per shop only counting documents with explicit shop_id
        $shopRows = $pdo->query("SELECT b.shop_id, b.shop_name, u.full_name owner_name,
            SUM(CASE WHEN d.status='pending' THEN 1 ELSE 0 END) pending_cnt,
            SUM(CASE WHEN d.status='approved' THEN 1 ELSE 0 END) approved_cnt,
            SUM(CASE WHEN d.status='rejected' THEN 1 ELSE 0 END) rejected_cnt,
            COUNT(d.document_id) total_docs
            FROM Barbershops b
            JOIN Users u ON b.owner_id=u.user_id
            LEFT JOIN Documents d ON d.shop_id=b.shop_id
            GROUP BY b.shop_id, b.shop_name, u.full_name
            ORDER BY pending_cnt DESC, b.shop_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
            <?php foreach ($shopRows as $s): ?>
                <a href="documents.php?shop_id=<?= (int)$s['shop_id'] ?>&status=pending" style="text-decoration:none;background:#111827;border:1px solid #374151;padding:1rem .95rem 1.05rem;border-radius:14px;display:flex;flex-direction:column;gap:.55rem;position:relative;">
                    <div style="display:flex;justify-content:space-between;gap:.6rem;align-items:flex-start;">
                        <strong style="font-size:.8rem;letter-spacing:.5px;color:#f3f4f6;flex:1;line-height:1.3;word-break:break-word;"><?= e($s['shop_name']) ?></strong>
                        <?php if ((int)$s['pending_cnt'] > 0): ?><span style="font-size:.55rem;background:#f59e0b1a;color:#fbbf24;border:1px solid #854d0e;padding:.3rem .45rem;border-radius:6px;font-weight:600;letter-spacing:.5px;">PENDING <?= (int)$s['pending_cnt'] ?></span><?php endif; ?>
                    </div>
                    <div style="font-size:.6rem;color:#9ca3af;">Owner: <span style="color:#e5e7eb;"><?= e($s['owner_name']) ?></span></div>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;font-size:.55rem;color:#9ca3af;">
                        <span style="background:#1f2937;border:1px solid #374151;padding:.25rem .5rem;border-radius:20px;">Total <?= (int)$s['total_docs'] ?></span>
                        <span style="background:#1f2937;border:1px solid #374151;padding:.25rem .5rem;border-radius:20px;">Approved <?= (int)$s['approved_cnt'] ?></span>
                        <span style="background:#1f2937;border:1px solid #374151;padding:.25rem .5rem;border-radius:20px;">Rejected <?= (int)$s['rejected_cnt'] ?></span>
                    </div>
                    <div style="margin-top:auto;font-size:.55rem;color:#60a5fa;display:inline-flex;align-items:center;gap:.3rem;">Review Documents →</div>
                </a>
            <?php endforeach; ?>
        </div>
        <p style="margin:1.2rem 0 0;font-size:.6rem;color:#6b7280;">Only documents explicitly tied to a shop are counted here. Owner-level uploads without a shop may appear once a shop is registered.</p>
    <?php else: ?>
        <?php
        // Drill-down: fetch shop + owner
        $shopStmt = $pdo->prepare('SELECT b.shop_id, b.shop_name, u.full_name owner_name, u.user_id owner_id FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE b.shop_id=? LIMIT 1');
        $shopStmt->execute([$shopId]);
        $shop = $shopStmt->fetch(PDO::FETCH_ASSOC);
        if (!$shop) {
            echo '<p style="font-size:.75rem;color:#fca5a5;">Shop not found.</p><p><a href="documents.php" style="color:#3b82f6;font-size:.7rem;">← Back to shops</a></p>';
        } else {
            // Fetch documents for this shop: either explicitly linked or owner-level (shop_id IS NULL AND owner_id=owner)
            $params = [$shopId, $shop['owner_id']];
            $statusFilterSql = '';
            if ($status !== 'all') {
                $statusFilterSql = ' AND d.status = ?';
                $params[] = $status;
            }
            $docStmt = $pdo->prepare("SELECT d.*, u.full_name FROM Documents d JOIN Users u ON d.owner_id=u.user_id WHERE (d.shop_id=? OR (d.shop_id IS NULL AND d.owner_id=?)) $statusFilterSql ORDER BY d.status='pending' DESC, d.uploaded_at DESC LIMIT 250");
            $docStmt->execute($params);
            $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Counts per status for tabs
            $cntStmt = $pdo->prepare("SELECT d.status, COUNT(*) c FROM Documents d WHERE (d.shop_id=? OR (d.shop_id IS NULL AND d.owner_id=?)) GROUP BY d.status");
            $cntStmt->execute([$shopId, $shop['owner_id']]);
            $counts = [];
            foreach ($cntStmt as $r) $counts[$r['status']] = (int)$r['c'];
        ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:0 0 1rem;">
                <div style="display:flex;flex-direction:column;gap:.4rem;">
                    <h1 style="margin:0;font-size:1.25rem;font-weight:600;letter-spacing:.5px;">Documents • <?= e($shop['shop_name']) ?></h1>
                    <div style="font-size:.65rem;color:#9ca3af;">Owner: <span style="color:#f3f4f6;"><?= e($shop['owner_name']) ?></span></div>
                </div>
                <div><a href="documents.php" style="font-size:.65rem;color:#3b82f6;text-decoration:none;">← Back to Shops</a></div>
            </div>
            <div style="display:flex;gap:.55rem;flex-wrap:wrap;margin:0 0 1rem;">
                <?php $tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
                foreach ($tabs as $k => $label):
                    $active = $status === $k ? 'background:#2563eb;color:#fff;' : 'background:#1f2937;color:#e5e7eb;';
                    $badge = '';
                    if ($k !== 'all' && isset($counts[$k])) $badge = ' <span style="opacity:.7;font-size:.6rem;">(' . $counts[$k] . ')</span>';
                ?>
                    <a href="documents.php?shop_id=<?= (int)$shopId ?>&status=<?= urlencode($k) ?>" style="text-decoration:none;padding:.5rem .85rem;border-radius:8px;font-size:.65rem;font-weight:600;<?= $active ?>"><?= $label ?><?= $badge ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($actionError): ?>
                <div style="background:#7f1d1d;color:#fecaca;padding:.7rem .9rem;border-radius:8px;font-size:.65rem;margin:0 0 1rem;"><?= e($actionError) ?></div>
            <?php elseif ($actionMessage): ?>
                <div style="background:#064e3b;color:#a7f3d0;padding:.7rem .9rem;border-radius:8px;font-size:.65rem;margin:0 0 1rem;"><?= e($actionMessage) ?></div>
            <?php endif; ?>
            <?php if (!$docs): ?>
                <p style="font-size:.7rem;color:#9ca3af;margin:1rem 0 0;">No documents found for this filter.</p>
            <?php else: ?>
                <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));">
                    <?php foreach ($docs as $d): ?>
                        <div style="background:#111827;border:1px solid #374151;padding:.85rem .85rem 1rem;border-radius:12px;display:flex;flex-direction:column;gap:.55rem;position:relative;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem;">
                                <strong style="font-size:.7rem;letter-spacing:.5px;color:#f3f4f6;flex:1;line-height:1.3;"><?= e(doc_label($d['doc_type'])) ?></strong>
                                <span style="font-size:.55rem;padding:.25rem .45rem;border-radius:6px;font-weight:600;letter-spacing:.5px;<?php
                                                                                                                                            if ($d['status'] === 'pending') echo 'background:#f59e0b1a;color:#fbbf24;border:1px solid #854d0e;';
                                                                                                                                            elseif ($d['status'] === 'approved') echo 'background:#064e3b;color:#34d399;border:1px solid #065f46;';
                                                                                                                                            else echo 'background:#7f1d1d;color:#fca5a5;border:1px solid #991b1b;';
                                                                                                                                            ?>"><?= strtoupper($d['status']) ?></span>
                            </div>
                            <div style="font-size:.55rem;color:#9ca3af;line-height:1.4;">
                                Uploaded: <?= date('M j, H:i', strtotime($d['uploaded_at'])) ?><br>
                                <?php if ($d['shop_id'] === null): ?><em style="color:#6b7280;">Owner-level (no specific shop)</em><?php endif; ?>
                            </div>
                            <?php if ($d['file_path']): ?>
                                <a href="../<?= e($d['file_path']) ?>" target="_blank" style="display:block;border-radius:8px;overflow:hidden;border:1px solid #374151;background:#1f2937;">
                                    <img src="../<?= e($d['file_path']) ?>" alt="document image" style="width:100%;height:140px;object-fit:cover;display:block;" />
                                </a>
                            <?php endif; ?>
                            <?php if ($d['notes']): ?>
                                <div style="font-size:.55rem;color:#f9fafb;background:#1f2937;padding:.4rem .45rem;border-radius:6px;">Notes: <?= e($d['notes']) ?></div>
                            <?php endif; ?>
                            <?php if ($d['status'] === 'pending'): ?>
                                <form method="post" style="display:flex;flex-direction:column;gap:.4rem;margin-top:.2rem;">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
                                    <input type="hidden" name="document_id" value="<?= (int)$d['document_id'] ?>" />
                                    <textarea name="notes" placeholder="Reviewer notes (optional)" style="background:#1f2937;color:#f3f4f6;border:1px solid #374151;border-radius:6px;font-size:.55rem;padding:.35rem .45rem;min-height:46px;resize:vertical;"></textarea>
                                    <div style="display:flex;gap:.4rem;">
                                        <button type="submit" name="action" value="approve" style="flex:1;background:#065f46;color:#ecfdf5;border:1px solid #059669;padding:.45rem .6rem;font-size:.6rem;font-weight:600;border-radius:6px;cursor:pointer;">Approve</button>
                                        <button type="submit" name="action" value="reject" style="flex:1;background:#7f1d1d;color:#fee2e2;border:1px solid #991b1b;padding:.45rem .6rem;font-size:.6rem;font-weight:600;border-radius:6px;cursor:pointer;">Reject</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div style="font-size:.5rem;color:#6b7280;">Reviewed: <?= $d['reviewed_at'] ? date('M j, H:i', strtotime($d['reviewed_at'])) : '—' ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php } // end shop found
        ?>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>