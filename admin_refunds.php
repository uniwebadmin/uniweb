<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
$db = getDB();
ensureRefundsEngine();
$reasonOptions = getRefundReasonOptions();
$merchantFilter = (int)($_GET['merchant_id'] ?? $_POST['merchant_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $txnId = (int)($_POST['transaction_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $reasonPick = trim($_POST['reason_code'] ?? '');
    $reasonExtra = trim($_POST['reason_note'] ?? '');
    if (!in_array($reasonPick, $reasonOptions, true)) {
        $reasonPick = 'Customer requested refund';
    }
    $reason = $reasonPick;
    if ($reasonExtra !== '') {
        $reason .= ' — ' . $reasonExtra;
    }
    $txn = $db->prepare('SELECT merchant_id, txn_id FROM transactions WHERE id=?');
    $txn->execute([$txnId]);
    $t = $txn->fetch();
    if (!$t) {
        flash('error', 'Transaction not found.');
        redirect($merchantFilter > 0 ? 'admin_refunds.php?merchant_id=' . $merchantFilter : 'admin_refunds.php');
    }
    requireMerchantAccess((int)$t['merchant_id']);
    requireStepUpAuth();
    $admin = getAdmin();
    $result = processRefund($txnId, $amount, $reason, (int)($admin['id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Refund ' . $result['refund_id'] . ' processed.' : ($result['error'] ?? 'Refund failed.'));
    if ($result['ok']) {
        logStaffActivity('refund_processed', ($result['refund_id'] ?? '') . ' — ' . formatMoney($amount ?: 0), (int)$t['merchant_id'], 'transaction', $t['txn_id']);
    }
    redirect($merchantFilter > 0 ? 'admin_refunds.php?merchant_id=' . $merchantFilter : 'admin_refunds.php');
}

$refundSql = 'SELECT r.*, m.business_name, m.id AS merchant_row_id, t.txn_id FROM refunds r JOIN merchants m ON r.merchant_id=m.id JOIN transactions t ON t.id=r.transaction_id';
$refundParams = [];
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = trim($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'pending', 'completed', 'failed'], true)) {
    $statusFilter = 'all';
}
if ($merchantFilter > 0) {
    $refundSql .= ' WHERE r.merchant_id = ?';
    $refundParams[] = $merchantFilter;
} else {
    $refundSql .= ' WHERE 1=1';
}
if ($statusFilter !== 'all') {
    $refundSql .= ' AND r.status = ?';
    $refundParams[] = $statusFilter;
}
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $refundSql .= " AND (LOWER(TRIM(COALESCE(r.refund_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)";
    array_push($refundParams, $like, $like, $like);
}
$refundSql .= ' ORDER BY r.created_at DESC LIMIT 200';
$refundStmt = $db->prepare($refundSql);
$refundStmt->execute($refundParams);
$refunds = $refundStmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($refunds as $r) {
        if (!isSuperAdmin() && !staffHasMerchantAccess((int)$r['merchant_row_id'])) {
            continue;
        }
        $csvRows[] = uxCsvRow($r, ['refund_id', 'business_name', 'txn_id', 'amount', 'status', 'created_at']);
    }
    sendCsvDownload(['Refund ID', 'Merchant', 'Transaction', 'Amount', 'Status', 'Date'], $csvRows, 'refunds-' . date('Y-m-d') . '.csv');
}

$txnSql = "SELECT t.id, t.txn_id, t.amount, t.merchant_id, m.business_name FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.status='success' AND t.id NOT IN (SELECT transaction_id FROM refunds WHERE status IN ('pending','completed'))";
$txnParams = [];
if ($merchantFilter > 0) {
    $txnSql .= ' AND t.merchant_id = ?';
    $txnParams[] = $merchantFilter;
}
$txnSql .= ' ORDER BY t.created_at DESC LIMIT 40';
$txnStmt = $db->prepare($txnSql);
$txnStmt->execute($txnParams);
$txns = $txnStmt->fetchAll();
if (!isSuperAdmin()) {
    $refunds = array_values(array_filter($refunds, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_row_id'])));
    $txns = array_values(array_filter($txns, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
}
$pageTitle = 'Refunds';
$filterMerchant = null;
if ($merchantFilter > 0) {
    $fm = $db->prepare('SELECT merchant_code, business_name FROM merchants WHERE id=?');
    $fm->execute([$merchantFilter]);
    $filterMerchant = $fm->fetch() ?: null;
}
require_once __DIR__ . '/header.php';
?>
<?php if ($filterMerchant): ?>
<div class="mb-4 glass rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-2 border border-sky-500/30">
    <p class="text-sm text-gray-300">Refunds for <span class="font-mono text-sky-400"><?= e($filterMerchant['merchant_code']) ?></span> — <?= e($filterMerchant['business_name']) ?></p>
    <a href="admin_refunds.php" class="text-xs text-gray-400 hover:text-white">Clear filter</a>
</div>
<?php endif; ?>
<?= uxListToolbar(uxExportCsvLink(array_filter(['merchant_id' => $merchantFilter ?: null, 'q' => $q ?: null, 'status' => $statusFilter !== 'all' ? $statusFilter : null]))) ?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6 no-print">
        <h2 class="font-semibold mb-4">Process Refund</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><?= uxLabel('refund-txn', 'Transaction', true) ?>
                <select id="refund-txn" name="transaction_id" required class="input-field mt-1" aria-required="true">
                    <option value="">Select transaction</option>
                    <?php foreach ($txns as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['txn_id']) ?> — <?= e($t['business_name']) ?> — <?= formatMoney(capStatAmount((float)$t['amount'])) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><?= uxLabel('refund-amount', 'Amount (blank = full)') ?><input id="refund-amount" type="number" name="amount" min="0" step="0.01" class="input-field mt-1" placeholder="Full refund"></div>
            <div>
                <?= uxLabel('refund-reason', 'Reason code', true) ?>
                <select id="refund-reason" name="reason_code" required class="input-field mt-1" aria-required="true">
                    <?php foreach ($reasonOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><?= uxLabel('refund-note', 'Note (optional)') ?><textarea id="refund-note" name="reason_note" rows="2" class="input-field mt-1" placeholder="Extra detail"></textarea></div>
            <button type="submit" class="w-full btn-primary py-3">Process Refund</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 no-print">
            <h2 class="font-semibold">Refund History</h2>
            <form method="GET" class="mt-3 flex flex-wrap gap-2 items-end" aria-label="Filter refunds">
                <?php if ($merchantFilter > 0): ?><input type="hidden" name="merchant_id" value="<?= (int)$merchantFilter ?>"><?php endif; ?>
                <div class="flex-1 min-w-[140px]"><?= uxLabel('refund-q', 'Search') ?><input id="refund-q" name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Refund ID / txn / merchant"></div>
                <div><?= uxLabel('refund-status', 'Status') ?><select id="refund-status" name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','pending'=>'Pending','completed'=>'Completed','failed'=>'Failed'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $statusFilter===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
                <button type="submit" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
            </form>
        </div>
        </div>
        <?php if (empty($refunds)): ?>
        <?= uxEmptyState('No refunds yet', 'Processed refunds appear here with status and transaction links.') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <?= uxTableCaption('Refund history') ?>
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($refunds as $r): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-5 py-3 font-mono text-xs">
                            <a href="<?= e(transactionDetailUrl($r['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($r['refund_id']) ?></a>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            <a href="<?= e(adminMerchantRefundsUrl((int)$r['merchant_row_id'])) ?>" class="text-sky-400 hover:underline"><?= e($r['business_name']) ?></a>
                        </td>
                        <td class="px-5 py-3 font-mono text-xs"><?= txnDetailLink($r['txn_id']) ?></td>
                        <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$r['amount'])) ?></td>
                        <td class="px-5 py-3"><?= statusBadge($r['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($r['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
