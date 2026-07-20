<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$merchantId = (int)$merchant['id'];
$isTest = isSettlementSandbox($merchant);
// Test Mode: auto-complete any stuck "pending" bank transfers (wallet already debited)
if ($isTest) {
    clearStuckTestSettlements($merchantId);
}
$prefs = getMerchantSettlementPrefs($merchant);
$openBatch = getMerchantOpenBatch($merchantId);
$batchHistory = getMerchantBatchHistory($merchantId, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settle');
    $action = $_POST['action'] ?? 'transfer';
    if ($action === 'settle_now') {
        $result = requestManualSettlement($merchantId, $merchant);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? ($result['message'] ?? 'Settled') : ($result['error'] ?? 'Failed'));
        redirect('settlements.php');
    }
    $amount = (float)($_POST['amount'] ?? 0);
    $result = processMerchantSettlement($merchantId, $merchant, $amount);
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    if (!$result['ok'] && !empty($result['redirect'])) {
        redirect($result['redirect']);
    }
    redirect('settlements.php');
}

$wallet = ensureMerchantWalletReady($merchantId);
$isTest = isSettlementSandbox($merchant);
$walletBalance = $wallet['balance'];
$availableBalance = $wallet['available'];
$minSettlement = getEffectiveMinSettlement($merchant, $availableBalance);

$settlementQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$settlementStatus = trim($_GET['status'] ?? 'all');
if (!in_array($settlementStatus, ['all', 'pending', 'processing', 'completed', 'failed'], true)) $settlementStatus = 'all';
$settlementFrom = trim($_GET['from'] ?? '');
$settlementTo = trim($_GET['to'] ?? '');
$settlementWhere = 's.merchant_id = ?';
$settlementParams = [$merchantId];
if ($settlementQ !== '') {
    $like = '%' . strtolower($settlementQ) . '%';
    $settlementSearch = ["LOWER(TRIM(COALESCE(s.settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(s.utr,''))) LIKE ?"];
    $settlementSearchParams = [$like, $like];
    if (is_numeric($settlementQ)) { $settlementSearch[] = 's.net_amount = ?'; $settlementSearchParams[] = (float)$settlementQ; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementQ)) { $settlementSearch[] = 'DATE(s.created_at) = ?'; $settlementSearchParams[] = $settlementQ; }
    $settlementWhere .= ' AND (' . implode(' OR ', $settlementSearch) . ')';
    array_push($settlementParams, ...$settlementSearchParams);
}
if ($settlementStatus !== 'all') { $settlementWhere .= ' AND s.status = ?'; $settlementParams[] = $settlementStatus; }
if ($settlementFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementFrom)) { $settlementWhere .= ' AND DATE(s.created_at) >= ?'; $settlementParams[] = $settlementFrom; }
if ($settlementTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementTo)) { $settlementWhere .= ' AND DATE(s.created_at) <= ?'; $settlementParams[] = $settlementTo; }
$settlements = $db->prepare("SELECT s.*, b.bank_name, b.account_number FROM settlements s LEFT JOIN bank_accounts b ON s.bank_account_id = b.id WHERE $settlementWhere ORDER BY s.created_at DESC LIMIT 50");
$settlements->execute($settlementParams);
$settlementList = $settlements->fetchAll();

$st = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE merchant_id = ? AND status = 'completed'");
$st->execute([$merchantId]);
$totalSettled = walletAmount((float)$st->fetchColumn());

$bank = $db->prepare('SELECT bank_name, account_number, ifsc_code FROM bank_accounts WHERE merchant_id=? AND is_primary=1 AND status=? LIMIT 1');
$bank->execute([$merchantId, 'active']);
$bankInfo = $bank->fetch();

$pageTitle = __('settlements_title');
require_once __DIR__ . '/header.php';
$canTransfer = $availableBalance >= $minSettlement;
renderMerchantCommercialCard($merchant);
?>

<div class="flex flex-wrap gap-3 mb-6 justify-between items-center">
    <div class="flex flex-wrap gap-2">
        <a href="merchant_settlement_settings.php" class="glass px-4 py-2 rounded-xl text-sm text-violet-300 hover:text-violet-200 border border-violet-500/20">
            ⚙ Settlement Settings
        </a>
        <a href="add_bank.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-400 hover:text-white"><?= __('bank_account') ?></a>
    </div>
    <span class="text-xs text-gray-500">Balances are reconciled from immutable payment records.</span>
</div>

<!-- Settlement mode banner -->
<div class="glass rounded-xl p-5 mb-6 border border-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide">Your Settlement Plan</p>
            <p class="text-lg font-semibold mt-1">
                <?= $prefs['mode'] === 'scheduled' ? 'Scheduled Batch' : 'Manual' ?>
                · <?= settlementRailBadge(resolveSettlementRailForMerchant($merchant)) ?>
            </p>
            <p class="text-xs text-gray-500 mt-2">
                <?php if ($prefs['mode'] === 'scheduled'): ?>
                Interval: <strong class="text-gray-300"><?= e($prefs['interval_label']) ?></strong>
                <?php if ($prefs['next_batch_at']): ?>
                · Next batch: <strong class="text-sky-400"><?= formatDate($prefs['next_batch_at']) ?></strong>
                <?php endif; ?>
                <?php else: ?>
                Click Settle Now whenever you want payout
                <?php endif; ?>
            </p>
        </div>
        <?php if ($openBatch && (int)$openBatch['txn_count'] > 0): ?>
        <div class="text-right">
            <p class="text-xs text-amber-400 uppercase">Open Batch</p>
            <p class="font-mono text-sm text-amber-300"><?= e($openBatch['batch_code']) ?></p>
            <p class="text-2xl font-bold text-emerald-400 mt-1"><?= walletMoney((float)$openBatch['net_amount']) ?></p>
            <p class="text-xs text-gray-500"><?= (int)$openBatch['txn_count'] ?> transactions</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid lg:grid-cols-4 gap-4 mb-8">
    <div class="stat-card border border-sky-500/30 rounded-xl p-5 bg-sky-500/5 lg:col-span-2">
        <p class="text-xs text-gray-500"><?= __('wallet_available') ?></p>
        <p class="text-4xl font-bold text-sky-400 mt-1"><?= walletMoney($availableBalance, $isTest) ?></p>
        <p class="text-xs text-gray-600 mt-1"><?= __('wallet_balance') ?>: <?= walletMoney($walletBalance, $isTest) ?></p>
    </div>
    <div class="stat-card border border-purple-500/30 rounded-xl p-5">
        <p class="text-xs text-gray-500">Transferred</p>
        <p class="text-2xl font-bold text-purple-400 mt-1"><?= walletMoney($totalSettled) ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">Minimum</p>
        <p class="text-2xl font-bold mt-1"><?= walletMoney($minSettlement, $isTest) ?></p>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <!-- Manual Settle Now -->
    <div class="glass rounded-xl p-6 border-2 border-emerald-500/30 bg-emerald-500/5">
        <div class="flex items-center gap-2 mb-4">
            <span class="text-2xl">⚡</span>
            <h2 class="font-bold text-lg text-emerald-300">Settle Now</h2>
        </div>
        <p class="text-xs text-gray-400 mb-4 leading-relaxed">
            <?= $isTest
                ? 'Test Mode — Settle Now completes instantly (sandbox). No real bank transfer.'
                : 'Live Mode — funds move from wallet to a pending settlement. Bank NEFT/IMPS is completed by ops.' ?>
        </p>
        <?php if ($bankInfo): ?>
        <p class="text-xs text-gray-500 mb-4"><?= e($bankInfo['bank_name']) ?> · ****<?= substr($bankInfo['account_number'] ?? '', -4) ?></p>
        <?php else: ?>
        <p class="text-xs text-amber-400 mb-4"><a href="add_bank.php" class="underline">Add bank account →</a></p>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Settle now?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="settle_now">
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white py-4 rounded-xl font-bold text-lg transition shadow-lg shadow-emerald-900/30">
                Settle Now →
            </button>
        </form>
        <p class="text-[10px] text-gray-600 text-center mt-3">
            <?= $openBatch && (int)$openBatch['txn_count'] > 0
                ? (int)$openBatch['txn_count'] . ' txns in batch'
                : 'Transfers available balance' ?>
        </p>
    </div>

    <!-- Custom amount -->
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-4">Custom Amount</h2>
        <?php if (!$canTransfer): ?>
        <p class="text-xs text-amber-400 mb-3"><?= __('wallet_low_balance') ?> <a href="demo.php" class="text-sky-400 underline"><?= __('wallet_demo_pay') ?></a></p>
        <?php endif; ?>
        <form method="POST" class="space-y-3" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="transfer">
            <input type="number" name="amount" min="<?= $minSettlement ?>" max="<?= max($minSettlement, $availableBalance) ?>" step="0.01"
                value="<?= $canTransfer ? max($minSettlement, $availableBalance) : $minSettlement ?>"
                class="input-field text-sm">
            <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold">
                Transfer →
            </button>
        </form>
    </div>

    <!-- Batch history -->
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-4">Batch History</h2>
        <?php if (empty($batchHistory)): ?>
        <p class="text-xs text-gray-500">No batches yet.</p>
        <?php else: ?>
        <div class="space-y-2 max-h-48 overflow-y-auto">
            <?php foreach ($batchHistory as $bh): ?>
            <div class="flex justify-between items-center text-xs py-2 border-b border-gray-800/80">
                <div>
                    <p class="font-mono text-gray-400"><?= e($bh['batch_code']) ?></p>
                    <p class="text-gray-600"><?= (int)$bh['txn_count'] ?> txns · <?= e($bh['batch_type']) ?></p>
                </div>
                <div class="text-right" title="<?= e($bh['api_message'] ?: 'Batch ' . $bh['status']) ?>">
                    <p class="font-semibold text-emerald-400"><?= walletMoney((float)$bh['net_amount']) ?></p>
                    <?= settlementBatchStatusBadge($bh['status']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="GET" data-live-search-form data-results-target="settlement-results" class="glass rounded-xl p-4 mb-5 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[210px]"><label class="text-[10px] text-gray-600 uppercase">Search settlements</label><input name="q" value="<?= e($settlementQ) ?>" class="input-field mt-1 text-sm" placeholder="Settlement ID / UTR / Date / Amount" autocomplete="off"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','pending'=>'Pending','processing'=>'Processing','completed'=>'Complete','failed'=>'Failed'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $settlementStatus===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
    <div><label class="text-[10px] text-gray-600 uppercase">From</label><input type="date" name="from" value="<?= e($settlementFrom) ?>" class="input-field mt-1 text-sm"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">To</label><input type="date" name="to" value="<?= e($settlementTo) ?>" class="input-field mt-1 text-sm"></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>
<div id="settlement-results" class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Bank Transfer History</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left"><?= __('settlements') ?></th>
                <th class="px-5 py-3 text-left"><?= __('bank_account') ?></th><th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($settlementList)): ?>
                <tr><td colspan="5" class="p-0">
                    <?= renderMerchantEmptyState(
                        'No bank transfers yet',
                        'After Live collections, use Settle Now to payout to your bank. History will show here for reconciliation.',
                        'add_bank.php',
                        'Add / check bank account →'
                    ) ?>
                </td></tr>
                <?php else: foreach ($settlementList as $s): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 font-mono text-xs">
                        <a href="settlement_detail.php?id=<?= urlencode($s['settlement_id']) ?>" class="text-sky-400 hover:text-sky-300 hover:underline"><?= e($s['settlement_id']) ?></a>
                    </td>
                    <td class="px-5 py-3 font-semibold text-brand-400"><?= walletMoney((float)$s['net_amount']) ?></td>
                    <td class="px-5 py-3 text-xs"><?= e($s['bank_name'] ?? '—') ?></td>
                    <td class="px-5 py-3">
                        <div title="<?= e(settlementReasonText($s, $merchant)) ?>"><?= settlementStatusBadge($s['status']) ?></div>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($s['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
