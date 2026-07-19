<?php
require_once __DIR__ . '/config.php';
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
    $admin = getAdmin();
    $result = processRefund($txnId, $amount, $reason, (int)($admin['id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Refund ' . $result['refund_id'] . ' processed.' : ($result['error'] ?? 'Refund failed.'));
    if ($result['ok']) {
        $txn = $db->prepare('SELECT merchant_id, txn_id FROM transactions WHERE id=?');
        $txn->execute([$txnId]);
        if ($t = $txn->fetch()) {
            logStaffActivity('refund_processed', ($result['refund_id'] ?? '') . ' — ' . formatMoney($amount ?: 0), (int)$t['merchant_id'], 'transaction', $t['txn_id']);
        }
    }
    redirect($merchantFilter > 0 ? 'admin_refunds.php?merchant_id=' . $merchantFilter : 'admin_refunds.php');
}

$refundSql = 'SELECT r.*, m.business_name, m.id AS merchant_row_id, t.txn_id FROM refunds r JOIN merchants m ON r.merchant_id=m.id JOIN transactions t ON t.id=r.transaction_id';
$refundParams = [];
if ($merchantFilter > 0) {
    $refundSql .= ' WHERE r.merchant_id = ?';
    $refundParams[] = $merchantFilter;
}
$refundSql .= ' ORDER BY r.created_at DESC LIMIT 80';
$refundStmt = $db->prepare($refundSql);
$refundStmt->execute($refundParams);
$refunds = $refundStmt->fetchAll();

$txnSql = "SELECT t.id, t.txn_id, t.amount, m.business_name FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.status='success' AND t.id NOT IN (SELECT transaction_id FROM refunds WHERE status IN ('pending','completed'))";
$txnParams = [];
if ($merchantFilter > 0) {
    $txnSql .= ' AND t.merchant_id = ?';
    $txnParams[] = $merchantFilter;
}
$txnSql .= ' ORDER BY t.created_at DESC LIMIT 40';
$txnStmt = $db->prepare($txnSql);
$txnStmt->execute($txnParams);
$txns = $txnStmt->fetchAll();
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
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Process Refund</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Transaction</label>
                <select name="transaction_id" required class="input-field mt-1">
                    <option value="">Select transaction</option>
                    <?php foreach ($txns as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['txn_id']) ?> — <?= e($t['business_name']) ?> — <?= formatMoney(capStatAmount((float)$t['amount'])) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Amount (blank = full)</label><input type="number" name="amount" min="0" step="0.01" class="input-field mt-1" placeholder="Full refund"></div>
            <div>
                <label class="text-sm text-gray-400">Reason code</label>
                <select name="reason_code" required class="input-field mt-1">
                    <?php foreach ($reasonOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Note (optional)</label><textarea name="reason_note" rows="2" class="input-field mt-1" placeholder="Extra detail"></textarea></div>
            <button type="submit" class="w-full btn-primary py-3">Process Refund</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Refund History</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($refunds)): ?><tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No refunds yet.</td></tr>
                    <?php else: foreach ($refunds as $r): ?>
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
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
