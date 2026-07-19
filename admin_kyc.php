<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);
ensureKycSchema();
$db = getDB();

if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'approve_doc') {
        $db->prepare("UPDATE kyc_documents SET status='approved', reviewed_at=NOW() WHERE id=?")->execute([$id]);
        $doc = $db->prepare('SELECT merchant_id, doc_type FROM kyc_documents WHERE id=?');
        $doc->execute([$id]);
        if ($d = $doc->fetch()) {
            logStaffActivity('kyc_doc_approved', 'Document ' . $d['doc_type'], (int)$d['merchant_id'], 'kyc_document', (string)$id);
        }
    } elseif ($_GET['action'] === 'reject_doc') {
        $db->prepare("UPDATE kyc_documents SET status='rejected', reviewed_at=NOW() WHERE id=?")->execute([$id]);
        $doc = $db->prepare('SELECT merchant_id, doc_type FROM kyc_documents WHERE id=?');
        $doc->execute([$id]);
        if ($d = $doc->fetch()) {
            logStaffActivity('kyc_doc_rejected', 'Document ' . $d['doc_type'], (int)$d['merchant_id'], 'kyc_document', (string)$id);
        }
    } elseif ($_GET['action'] === 'verify_merchant') {
        activateMerchantLive($id);
        logStaffActivity('kyc_merchant_verified', 'Merchant KYC verified and live', $id);
    } elseif ($_GET['action'] === 'reject_merchant') {
        $db->prepare("UPDATE merchants SET kyc_status='rejected' WHERE id=?")->execute([$id]);
        logStaffActivity('kyc_merchant_rejected', 'Merchant KYC rejected', $id);
    }
    flash('success', 'KYC updated.');
    redirect('admin_kyc.php');
}

$pendingDocs = $db->query("SELECT k.*, m.business_name, m.merchant_code, m.business_entity_type FROM kyc_documents k JOIN merchants m ON k.merchant_id=m.id WHERE k.status='pending' ORDER BY k.created_at ASC")->fetchAll();
$pendingMerchants = getPendingKycQueue(50);
$recentSignups = getRecentSignupQueue(12);
$pageTitle = 'KYC Review';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap gap-3">
    <?php if (isSuperAdmin()): ?>
    <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400">Link Watchdog</a>
    <?php endif; ?>
    <a href="manage_merchant.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">All Merchants</a>
</div>

<?php if (!empty($recentSignups)): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-sky-500/20">
    <div class="px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Recent signups — verify new merchants (Individual / Freelancer first)</h2>
        <p class="text-xs text-gray-500 mt-1">Click Verify after documents OK — enables Live mode + Test/Live toggle</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Code</th>
                    <th class="px-5 py-3 text-left">Business</th>
                    <th class="px-5 py-3 text-left">Entity</th>
                    <th class="px-5 py-3 text-left">Contact</th>
                    <th class="px-5 py-3 text-left">KYC</th>
                    <th class="px-5 py-3 text-left">Joined</th>
                    <th class="px-5 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($recentSignups as $m):
                    $mid = (int)$m['id'];
                    $waUrl = merchantWhatsAppUrl($m['phone'] ?? null);
                    $canVerify = in_array(($m['kyc_status'] ?? ''), ['pending', 'submitted'], true);
                ?>
                <tr>
                    <td class="px-5 py-3 font-mono text-xs"><?= adminMerchantLink($mid, $m['merchant_code']) ?></td>
                    <td class="px-5 py-3"><?= adminMerchantLink($mid, $m['business_name'], 'font-medium hover:text-sky-300') ?></td>
                    <td class="px-5 py-3 text-xs"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></td>
                    <td class="px-5 py-3 text-xs">
                        <?= merchantMailtoLink((string)$m['email'], $m['email'], 'text-gray-400 hover:text-sky-300') ?>
                        <?php if ($waUrl): ?><br><a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="text-emerald-400">WhatsApp</a><?php endif; ?>
                    </td>
                    <td class="px-5 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($m['created_at']) ?></td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">
                        <a href="<?= e(adminMerchantUrl($mid)) ?>" class="text-gray-400 hover:text-white mr-2">View</a>
                        <?php if ($canVerify): ?>
                        <a href="?action=verify_merchant&id=<?= $mid ?>&token=<?= csrfToken() ?>" class="text-brand-400 hover:text-brand-300 mr-2" onclick="return confirm('Verify and go Live?')">Verify</a>
                        <a href="?action=reject_merchant&id=<?= $mid ?>&token=<?= csrfToken() ?>" class="text-red-400 hover:text-red-300">Reject</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Pending documents</h2>
            <p class="text-xs text-gray-500 mt-1">Approve docs before verifying the merchant</p>
        </div>
        <?php if (empty($pendingDocs)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No pending documents</p>
        <?php else: foreach ($pendingDocs as $doc): ?>
        <div class="px-6 py-4 border-b border-gray-800">
            <div class="flex justify-between items-start mb-2">
                <div>
                    <p class="font-medium text-sm"><?= adminMerchantLink((int)$doc['merchant_id'], $doc['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                    <p class="text-xs text-gray-500 capitalize"><?= e(entityTypeLabel($doc['business_entity_type'] ?? '')) ?> — <?= str_replace('_', ' ', $doc['doc_type']) ?></p>
                </div>
                <?= statusBadge($doc['status']) ?>
            </div>
            <div class="flex gap-2 mt-2 flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$doc['merchant_id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1 rounded-lg">View Merchant</a>
                <a href="admin_kyc_doc.php?id=<?= $doc['id'] ?>&token=<?= csrfToken() ?>" target="_blank" class="text-xs bg-sky-600/20 text-sky-400 px-3 py-1 rounded-lg">View Doc</a>
                <a href="?action=approve_doc&id=<?= $doc['id'] ?>&token=<?= csrfToken() ?>" class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1 rounded-lg">Approve Doc</a>
                <a href="?action=reject_doc&id=<?= $doc['id'] ?>&token=<?= csrfToken() ?>" class="text-xs bg-red-600/20 text-red-400 px-3 py-1 rounded-lg">Reject</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Pending KYC — verify to enable Live mode</h2>
            <p class="text-xs text-gray-500 mt-1">Individual / Freelancer first · Click Verify after documents OK</p>
        </div>
        <?php if (empty($pendingMerchants)): ?>
        <p class="text-gray-500 text-sm text-center py-8">All caught up!</p>
        <?php else: foreach ($pendingMerchants as $m): ?>
        <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center gap-3 flex-wrap">
            <div>
                <p class="font-medium text-sm"><?= adminMerchantLink((int)$m['id'], $m['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                <p class="text-xs text-gray-500"><?= merchantMailtoLink((string)$m['email']) ?> · <?= adminMerchantLink((int)$m['id'], $m['merchant_code'], 'font-mono text-sky-400') ?></p>
                <p class="text-xs text-gray-600"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1 rounded-lg">View</a>
                <a href="?action=verify_merchant&id=<?= $m['id'] ?>&token=<?= csrfToken() ?>" class="text-xs bg-brand-600 text-white px-3 py-1 rounded-lg" onclick="return confirm('Verify and go Live?')">Verify</a>
                <a href="?action=reject_merchant&id=<?= $m['id'] ?>&token=<?= csrfToken() ?>" class="text-xs bg-red-600/20 text-red-400 px-3 py-1 rounded-lg">Reject</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
