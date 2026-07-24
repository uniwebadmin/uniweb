<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
ensureRefundsEngine();

$reasonOptions = getRefundReasonOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('refund');
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
    $st = $db->prepare('SELECT id FROM transactions WHERE id = ? AND merchant_id = ?');
    $st->execute([$txnId, $merchant['id']]);
    if ($st->fetch()) {
        $result = processRefund($txnId, $amount, $reason);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Refund ' . $result['refund_id'] . ' processed.' : ($result['error'] ?? 'Refund failed.'));
    } else {
        flash('error', 'Transaction not found.');
    }
    redirect('refunds.php');
}

$refunds = getMerchantRefunds((int)$merchant['id']);
$refundQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(20);
if ($refundQ !== '') {
    $refunds = array_values(array_filter($refunds, static function ($r) use ($refundQ) {
        $hay = strtolower(($r['refund_id'] ?? '') . ' ' . ($r['txn_id'] ?? '') . ' ' . ($r['reason'] ?? ''));
        return str_contains($hay, strtolower($refundQ));
    }));
}
$refundTotal = count($refunds);
$refunds = array_slice($refunds, $listParams['offset'], $listParams['perPage']);
$txns = $db->prepare("SELECT id, txn_id, amount FROM transactions WHERE merchant_id = ? AND status = 'success' AND id NOT IN (SELECT transaction_id FROM refunds WHERE status IN ('pending','completed')) ORDER BY created_at DESC LIMIT 30");
$txns->execute([$merchant['id']]);
$txnList = $txns->fetchAll();
$pageTitle = 'Refunds';
require_once __DIR__ . '/header.php';
echo renderPrintStylesheet();
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-gray-400">Process refunds with standard reason codes (ops / dispute ready)</p>
    <div class="flex gap-2 items-center">
        <?= renderExportCsvLink('export_refunds.php') ?>
        <a href="refund_policy.php" target="_blank" class="text-xs text-sky-400">Refund policy →</a>
    </div>
</div>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-4">Request Refund</h2>
        <?php if (!merchantTeamCan('refund')): ?>
        <p class="text-sm text-amber-300/90">Your team role can view refund history but cannot process refunds. Ask an Admin or Finance member.</p>
        <?php else: ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Transaction</label>
                <select name="transaction_id" required class="input-field mt-1">
                    <option value="">Select transaction</option>
                    <?php foreach ($txnList as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['txn_id']) ?> — <?= formatMoney(capStatAmount((float)$t['amount'])) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Amount (blank = full)</label><input type="number" name="amount" min="0" step="0.01" class="input-field mt-1" placeholder="Full refund"></div>
            <div><label class="text-sm text-gray-400">Reason code</label>
                <select name="reason_code" required class="input-field mt-1">
                    <?php foreach ($reasonOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Note (optional)</label><textarea name="reason_note" rows="2" class="input-field mt-1" placeholder="Extra detail for audit"></textarea></div>
            <button type="submit" class="w-full btn-primary py-3" <?= empty($txnList) ? 'disabled' : '' ?>>Process Refund</button>
        </form>
        <p class="text-xs text-gray-500 mt-4">Refunds debit your wallet balance. Keep sufficient funds before processing.</p>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?php if (empty($refunds)): ?>
        <?= renderMerchantEmptyState(
            'No refunds yet',
            'When you process a refund, it will appear here with status and reason for bank / aggregator review.',
            empty($txnList) ? 'payment_links.php' : null,
            empty($txnList) ? 'Create a payment link first →' : null
        ) ?>
        <?php else: ?>
        <div class="glass rounded-xl overflow-hidden border border-gray-800">
            <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Refund History</h2>
                <form method="GET" class="flex gap-2 items-center">
                    <label class="sr-only" for="refund-q">Search refunds</label>
                    <input id="refund-q" type="search" name="q" value="<?= e($refundQ) ?>" placeholder="Refund / txn ID" class="input-field text-sm">
                    <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
                </form>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[520px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Txn</th><th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($refunds as $r): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-5 py-3 font-mono text-xs"><?= e($r['refund_id']) ?></td>
                        <td class="px-5 py-3 font-mono text-xs"><?= e($r['txn_id']) ?></td>
                        <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$r['amount'])) ?></td>
                        <td class="px-5 py-3"><?= statusBadge($r['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($r['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= renderListPagination($listParams['page'], $refundTotal, $listParams['perPage'], ['q' => $refundQ]) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
