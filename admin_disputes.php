<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
ensureDisputesEngine();
$db = getDB();

if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $id = (int)$_GET['id'];
    $resolution = trim($_GET['resolution'] ?? '');
    if ($_GET['action'] === 'review') {
        $db->prepare("UPDATE disputes SET status='under_review' WHERE id=?")->execute([$id]);
        $d = $db->prepare('SELECT merchant_id FROM disputes WHERE id=?'); $d->execute([$id]);
        if ($row = $d->fetch()) logStaffActivity('dispute_review', 'Dispute #' . $id, (int)$row['merchant_id']);
        flash('success', 'Dispute marked under review.');
    } elseif ($_GET['action'] === 'resolve') {
        $db->prepare("UPDATE disputes SET status='resolved', resolution=? WHERE id=?")->execute([$resolution ?: 'Resolved by admin', $id]);
        $d = $db->prepare('SELECT merchant_id FROM disputes WHERE id=?'); $d->execute([$id]);
        if ($row = $d->fetch()) logStaffActivity('dispute_resolved', $resolution ?: 'Resolved', (int)$row['merchant_id']);
        flash('success', 'Dispute resolved.');
    } elseif ($_GET['action'] === 'close') {
        $db->prepare("UPDATE disputes SET status='closed', resolution=? WHERE id=?")->execute([$resolution ?: 'Closed', $id]);
        $d = $db->prepare('SELECT merchant_id FROM disputes WHERE id=?'); $d->execute([$id]);
        if ($row = $d->fetch()) logStaffActivity('dispute_closed', $resolution ?: 'Closed', (int)$row['merchant_id']);
        flash('success', 'Dispute closed.');
    }
    redirect('admin_disputes.php');
}

$openCount = (int)$db->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn();
$disputes = $db->query('SELECT d.*, m.business_name, m.id AS merchant_row_id, t.txn_id, t.amount FROM disputes d JOIN merchants m ON d.merchant_id=m.id JOIN transactions t ON t.id=d.transaction_id ORDER BY d.created_at DESC LIMIT 80')->fetchAll();
$pageTitle = 'Disputes';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
        <h2 class="font-semibold">Dispute Queue (<?= $openCount ?> open)</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Txn</th>
                <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Reason</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($disputes)): ?><tr><td colspan="7" class="px-5 py-12 text-center text-gray-500">No disputes.</td></tr>
                <?php else: foreach ($disputes as $d): ?>
                <tr<?= uiRowClick(transactionDetailUrl($d['txn_id'])) ?>>
                    <td class="px-5 py-3 font-mono text-xs"><a href="<?= e(transactionDetailUrl($d['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($d['dispute_id']) ?></a></td>
                    <td class="px-5 py-3 text-xs"><?= adminMerchantLink((int)$d['merchant_row_id'], $d['business_name']) ?></td>
                    <td class="px-5 py-3 font-mono text-xs"><?= txnDetailLink($d['txn_id']) ?></td>
                    <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$d['amount'])) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-400 max-w-xs truncate" title="<?= e($d['reason']) ?>"><?= e($d['reason']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($d['status']) ?></td>
                    <td class="px-5 py-3 whitespace-nowrap"<?= uiStopClick() ?>>
                        <?php if ($d['status'] === 'open'): ?>
                        <a href="?action=review&id=<?= $d['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-amber-400 mr-2">Review</a>
                        <?php endif; ?>
                        <?php if (in_array($d['status'], ['open','under_review'], true)): ?>
                        <a href="?action=resolve&id=<?= $d['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400 mr-2">Resolve</a>
                        <a href="?action=close&id=<?= $d['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-gray-400">Close</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
