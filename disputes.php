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
        flash('success', 'Dispute raised: ' . $disputeId . ' — Admin will review first.');
        if (function_exists('createNotification')) {
            createNotification((int)$merchant['id'], 'Dispute submitted', $disputeId . ' is with UniWeb Admin first. You will see resolve or network-review updates here.', 'dispute_raised');
        }
        redirect('disputes.php?id=' . rawurlencode($disputeId));
    }
    flash('error', 'Select a valid transaction and reason.');
    redirect('disputes.php');
}

$disputes = $db->prepare('SELECT d.*, t.txn_id, t.amount FROM disputes d JOIN transactions t ON d.transaction_id = t.id WHERE d.merchant_id = ? ORDER BY d.created_at DESC');
$disputes->execute([$merchant['id']]);
$disputeList = $disputes->fetchAll() ?: [];

if (!function_exists('wiringMerchantDisputesQueryState') && is_file(__DIR__ . '/includes/wiring_deep_link_workflow.php')) {
    require_once __DIR__ . '/includes/wiring_deep_link_workflow.php';
}
$dspState = function_exists('wiringMerchantDisputesQueryState')
    ? wiringMerchantDisputesQueryState($_GET)
    : ['disputeQ' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100), 'highlightDisputeId' => '', 'viewKey' => trim((string)($_GET['id'] ?? ''))];
$disputeQ = $dspState['disputeQ'];
$highlightDisputeId = $dspState['highlightDisputeId'];
$viewKey = $dspState['viewKey'];

if ($disputeQ !== '') {
    $qLower = strtolower($disputeQ);
    $disputeList = array_values(array_filter($disputeList, static function (array $d) use ($qLower): bool {
        $hay = strtolower(
            (string)($d['dispute_id'] ?? '') . ' ' .
            (string)($d['txn_id'] ?? '') . ' ' .
            (string)($d['reason'] ?? '') . ' ' .
            (string)($d['status'] ?? '') . ' ' .
            (string)($d['resolution'] ?? '')
        );
        return str_contains($hay, $qLower);
    }));
}

$view = null;
if ($viewKey !== '') {
    foreach ($disputeList as $d) {
        if (
            strcasecmp((string)($d['dispute_id'] ?? ''), $viewKey) === 0
            || (string)(int)($d['id'] ?? 0) === (string)(int)$viewKey
        ) {
            $view = $d;
            break;
        }
    }
    // If filter hid the match, look up directly for this merchant
    if (!$view) {
        $stView = $db->prepare(
            'SELECT d.*, t.txn_id, t.amount FROM disputes d
             JOIN transactions t ON d.transaction_id = t.id
             WHERE d.merchant_id = ? AND (d.dispute_id = ? OR d.id = ?)
             LIMIT 1'
        );
        $stView->execute([(int)$merchant['id'], $viewKey, (int)$viewKey]);
        $view = $stView->fetch() ?: null;
    }
}

$txns = $db->prepare("SELECT t.id, t.txn_id, t.amount FROM transactions t
    WHERE t.merchant_id = ? AND t.status IN ('success','pending')
    AND t.id NOT IN (SELECT transaction_id FROM disputes WHERE merchant_id = ?)
    ORDER BY t.created_at DESC LIMIT 30");
$txns->execute([$merchant['id'], $merchant['id']]);
$txnList = $txns->fetchAll();
$pageTitle = 'Disputes';
require_once __DIR__ . '/header.php';
if (!function_exists('renderComplianceDisputeVsRefundPanel')) {
    require_once __DIR__ . '/includes/compliance_workflow.php';
}
echo renderComplianceDisputeVsRefundPanel('disputes');
echo renderComplianceSupportPathPanel('dsp');
?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-3 glass rounded-xl p-4 border border-sky-500/25 text-sm text-gray-400">
        <p>All new payment disputes and chargebacks → <a href="disputes.php" class="text-sky-400 hover:underline">Disputes</a> (one lane on UniWeb). Legacy bank chargeback evidence rows only: <a href="chargebacks.php?legacy=1" class="text-gray-500 hover:underline">open legacy list</a>.</p>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-2">Raise Dispute</h2>
        <p class="text-xs text-sky-400/90 mb-3">Admin reviews first — then resolve or forward for payment-network review. You are not talking to the bank directly from this page.</p>
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
    <div class="lg:col-span-2 space-y-6">
        <?php if ($view): ?>
        <div class="glass rounded-xl p-6 border border-sky-500/30 <?= $highlightDisputeId !== '' && strcasecmp((string)$view['dispute_id'], $highlightDisputeId) === 0 ? 'ring-2 ring-sky-500/50' : '' ?>" id="dispute-detail">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <p class="font-mono text-sm text-sky-400"><?= e($view['dispute_id']) ?></p>
                    <p class="text-xs text-gray-500 mt-1">Opened <?= e(formatDate($view['created_at'] ?? '')) ?></p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <?= statusBadge((string)$view['status']) ?>
                    <a href="disputes.php" class="text-xs text-gray-400 hover:text-sky-300">Clear</a>
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-[10px] text-gray-600 uppercase">Transaction</p>
                    <p class="font-mono text-xs mt-1"><?= txnDetailLink((string)$view['txn_id']) ?></p>
                    <p class="text-gray-300 mt-1"><?= formatMoney(capStatAmount((float)$view['amount'])) ?></p>
                </div>
                <div>
                    <p class="text-[10px] text-gray-600 uppercase">SLA due</p>
                    <?php
                    $dueTs = strtotime((string)($view['sla_due_at'] ?? ''));
                    $openD = in_array((string)$view['status'], ['open', 'under_review', 'forwarded_partner'], true);
                    $overdue = $dueTs && $openD && $dueTs < time();
                    ?>
                    <p class="text-xs mt-1 <?= $overdue ? 'text-red-400 font-semibold' : 'text-gray-300' ?>">
                        <?= !empty($view['sla_due_at']) ? e(formatDate($view['sla_due_at'])) : '—' ?><?= $overdue ? ' · overdue' : '' ?>
                    </p>
                </div>
            </div>
            <div class="mt-4">
                <p class="text-[10px] text-gray-600 uppercase">Reason</p>
                <p class="text-sm text-gray-200 mt-1 whitespace-pre-wrap"><?= e((string)($view['reason'] ?? '')) ?></p>
            </div>
            <?php if (!empty($view['forwarded_partner_key'])): ?>
            <div class="mt-4 rounded-lg border border-violet-500/30 bg-violet-500/5 p-3 text-xs text-violet-200">
                Admin forwarded this case for payment-network review.
                <?php if (!empty($view['forwarded_at'])): ?>
                <span class="text-gray-500"> · <?= e(formatDate($view['forwarded_at'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($view['forwarded_note'])): ?>
                <p class="text-gray-400 mt-2"><?= e($view['forwarded_note']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($view['resolution'])): ?>
            <div class="mt-4">
                <p class="text-[10px] text-gray-600 uppercase">Admin note / resolution</p>
                <p class="text-sm text-emerald-200/90 mt-1 whitespace-pre-wrap"><?= e((string)$view['resolution']) ?></p>
            </div>
            <?php else: ?>
            <p class="text-xs text-gray-500 mt-4">Waiting for Admin review. Status updates appear here when Admin resolves or forwards the case.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Dispute History</h2>
                    <p class="text-xs text-gray-500 mt-1">Tap a row to open details. Status updates when Admin resolves or forwards your case.</p>
                </div>
                <form method="GET" class="flex gap-2">
                    <label class="sr-only" for="dispute-q">Search disputes</label>
                    <input id="dispute-q" type="search" name="q" value="<?= e($disputeQ) ?>" placeholder="DSP… / txn / reason" class="input-field text-sm w-44 max-w-full" autocomplete="off">
                    <button type="submit" class="btn-primary px-3 py-2 text-xs">Search</button>
                    <?php if ($disputeQ !== '' || $viewKey !== ''): ?>
                    <a href="disputes.php" class="text-xs text-gray-400 hover:text-white px-2 py-2">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php if (empty($disputeList)): ?><div class="px-6 py-8"><?= renderMerchantEmptyState($disputeQ !== '' ? 'No matching disputes' : 'No disputes yet', $disputeQ !== '' ? 'Try another DSP id or clear search.' : 'Raise a dispute only for problem payments. Start with a test transaction if you have none.', 'transactions.php', 'View Transactions') ?></div>
            <?php else: ?>
            <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Reason</th>
                    <th class="px-5 py-3 text-left">Due</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($disputeList as $d):
                        $isOpen = $view && strcasecmp((string)$view['dispute_id'], (string)$d['dispute_id']) === 0;
                        $isHighlight = $highlightDisputeId !== '' && strcasecmp((string)$d['dispute_id'], $highlightDisputeId) === 0;
                        $rowHref = 'disputes.php?id=' . rawurlencode((string)$d['dispute_id']);
                    ?>
                    <tr class="hover:bg-white/5 cursor-pointer <?= $isOpen || $isHighlight ? 'bg-sky-500/10 ring-1 ring-sky-500/40' : '' ?>" onclick="location.href='<?= e($rowHref) ?>#dispute-detail'"<?= $isHighlight ? ' id="dispute-highlight-row"' : '' ?>>
                        <td class="px-5 py-3 font-mono text-xs"><a href="<?= e($rowHref) ?>#dispute-detail" class="text-sky-400 hover:underline" onclick="event.stopPropagation()"><?= e($d['dispute_id']) ?></a></td>
                        <td class="px-5 py-3 font-mono text-xs" onclick="event.stopPropagation()"><?= txnDetailLink((string)$d['txn_id']) ?></td>
                        <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$d['amount'])) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400 max-w-[12rem] truncate" title="<?= e($d['reason']) ?>"><?= e($d['reason']) ?></td>
                        <?php
                        $dueTs = strtotime((string)($d['sla_due_at'] ?? ''));
                        $openD = in_array((string)$d['status'], ['open', 'under_review', 'forwarded_partner'], true);
                        $overdue = $dueTs && $openD && $dueTs < time();
                        ?>
                        <td class="px-5 py-3 text-xs <?= $overdue ? 'text-red-400 font-semibold' : 'text-gray-500' ?>"><?= !empty($d['sla_due_at']) ? e(formatDate($d['sla_due_at'])) : '—' ?></td>
                        <td class="px-5 py-3">
                            <?= statusBadge($d['status']) ?>
                            <?php if (!empty($d['forwarded_partner_key'])): ?>
                            <p class="text-[10px] text-violet-400 mt-1">Forwarded for review</p>
                            <?php endif; ?>
                            <?php if (!empty($d['resolution']) && in_array((string)$d['status'], ['resolved', 'closed', 'forwarded_partner'], true)): ?>
                            <p class="text-[10px] text-gray-500 mt-1 max-w-[10rem] truncate" title="<?= e($d['resolution']) ?>"><?= e($d['resolution']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($d['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($highlightDisputeId !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('dispute-detail') || document.getElementById('dispute-highlight-row');
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
