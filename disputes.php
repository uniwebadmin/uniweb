<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureDisputesEngine();
$merchant = getMerchant();
$db = getDB();
$reasonOptions = getDisputeReasonOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $txnId = (int)($_POST['transaction_id'] ?? 0);
    $reasonPick = trim($_POST['reason_code'] ?? '');
    $reasonExtra = trim($_POST['reason_note'] ?? '');
    if (!in_array($reasonPick, $reasonOptions, true)) {
        $reasonPick = 'Other';
    }
    $reason = $reasonPick;
    if ($reasonExtra !== '') {
        $reason .= ' — ' . $reasonExtra;
    }
    $txn = $db->prepare('SELECT * FROM transactions WHERE id = ? AND merchant_id = ?');
    $txn->execute([$txnId, $merchant['id']]);
    if ($txn->fetch() && $reason !== '') {
        $disputeId = generateId('DSP');
        try {
            $db->prepare('INSERT INTO disputes (dispute_id, merchant_id, transaction_id, reason, sla_due_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 5 DAY))')
                ->execute([$disputeId, $merchant['id'], $txnId, $reason]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO disputes (dispute_id, merchant_id, transaction_id, reason) VALUES (?,?,?,?)')
                ->execute([$disputeId, $merchant['id'], $txnId, $reason]);
        }
        flash('success', 'Dispute raised: ' . $disputeId);
        redirect('disputes.php');
    }
    flash('error', 'Select a valid transaction and reason.');
    redirect('disputes.php');
}

$disputes = $db->prepare('SELECT d.*, t.txn_id, t.amount FROM disputes d JOIN transactions t ON d.transaction_id = t.id WHERE d.merchant_id = ? ORDER BY d.created_at DESC');
$disputes->execute([$merchant['id']]);
$disputeList = $disputes->fetchAll();
$txns = $db->prepare("SELECT t.id, t.txn_id, t.amount FROM transactions t
    WHERE t.merchant_id = ? AND t.status IN ('success','pending')
    AND t.id NOT IN (SELECT transaction_id FROM disputes WHERE merchant_id = ?)
    ORDER BY t.created_at DESC LIMIT 30");
$txns->execute([$merchant['id'], $merchant['id']]);
$txnList = $txns->fetchAll();
$pageTitle = 'Disputes';
require_once __DIR__ . '/header.php';
?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Raise Dispute</h2>
        <p class="text-xs text-gray-500 mb-4">Use standard reason codes for bank / aggregator / chargeback review.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Transaction</label>
                <select name="transaction_id" required class="input-field mt-1">
                    <option value="">Select transaction</option>
                    <?php foreach ($txnList as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['txn_id']) ?> — <?= formatMoney(capStatAmount((float)$t['amount'])) ?></option><?php endforeach; ?>
                </select>
                <?php if (empty($txnList)): ?><p class="text-xs text-amber-400 mt-2">No transactions yet — <a href="merchant_payment_pack.php" class="underline">create a test payment</a>.</p><?php endif; ?>
            </div>
            <div>
                <label class="text-sm text-gray-400">Reason code</label>
                <select name="reason_code" required class="input-field mt-1">
                    <?php foreach ($reasonOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400">Note (optional)</label>
                <textarea name="reason_note" rows="2" class="input-field mt-1" placeholder="Extra detail for ops audit"></textarea>
            </div>
            <button type="submit" class="w-full btn-primary py-3">Raise Dispute</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Dispute History</h2></div>
        <?php if (empty($disputeList)): ?><div class="px-6 py-8"><?= renderMerchantEmptyState('No disputes yet', 'Raise a dispute only for problem payments. Start with a test transaction if you have none.', 'transactions.php', 'View Transactions') ?></div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Txn</th>
                <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Reason</th>
                <th class="px-5 py-3 text-left">Due</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($disputeList as $d): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 font-mono text-xs"><?= e($d['dispute_id']) ?></td>
                    <td class="px-5 py-3 font-mono text-xs"><?= txnDetailLink((string)$d['txn_id']) ?></td>
                    <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$d['amount'])) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-400 max-w-[12rem] truncate" title="<?= e($d['reason']) ?>"><?= e($d['reason']) ?></td>
                    <?php
                    $dueTs = strtotime((string)($d['sla_due_at'] ?? ''));
                    $openD = in_array((string)$d['status'], ['open', 'under_review'], true);
                    $overdue = $dueTs && $openD && $dueTs < time();
                    ?>
                    <td class="px-5 py-3 text-xs <?= $overdue ? 'text-red-400 font-semibold' : 'text-gray-500' ?>"><?= !empty($d['sla_due_at']) ? e(formatDate($d['sla_due_at'])) : '—' ?></td>
                    <td class="px-5 py-3"><?= statusBadge($d['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($d['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
