<?php
require_once __DIR__ . '/config.php';
if (!function_exists('uxFormLabel')) {
    require_once __DIR__ . '/includes/page_ux.php';
}
requireLogin();
$merchant = getMerchant();
$db = getDB();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '') && ($_POST['action'] ?? '') === 'transfer') {
    requireMerchantTeamCapability('settle');
    $amount = (float)($_POST['amount'] ?? 0);
    $result = processMerchantSettlement($merchantId, $merchant, $amount);
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    if (!$result['ok'] && !empty($result['redirect'])) redirect($result['redirect']);
    redirect('wallet.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash('error', 'Session expired — refresh this page and try again. No transfer was submitted.');
    redirect('wallet.php');
}

$wallet = ensureMerchantWalletReady($merchantId);
$isTest = (bool)($wallet['is_test'] ?? isMerchantTest($merchant));
$balance = $wallet['balance'];
$available = $wallet['available'];
$pendingOut = (float)($wallet['pending_out'] ?? 0);
$onHold = (float)($wallet['on_hold'] ?? 0);
$minSettlement = getEffectiveMinSettlement($merchant, $available);
$ledger = getMerchantWalletLedger($merchantId, 50);
$canTransfer = $available >= $minSettlement;

$pageTitle = __('wallet_title');
require_once __DIR__ . '/header.php';
?>
<?= renderPagePrintStyles() ?>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3 text-sm">
    <div>
        <p class="text-sky-200 font-medium">Settlement Balance</p>
        <p class="text-xs text-gray-500 mt-1">Money waiting to go to your bank. This is not a customer PPI wallet.</p>
        <p class="text-xs text-gray-500 mt-1">Successful payments credit the merchant baaki (after Admin/partner cut from Admin-saved %). Open any transaction for the full split.</p>
        <p class="text-xs text-gray-500 mt-1"><?= e($merchant['email']) ?> · <?= accountModeBadge($merchant) ?> · <?= (int)$wallet['success_txns'] ?> successful payment(s)</p>
    </div>
    <a href="settlements.php" class="text-sm px-4 py-2.5 rounded-xl border border-gray-700 text-gray-400 hover:text-white">Settlements →</a>
    <span class="no-print"><?= renderPrintButton() ?></span>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="stat-card border border-sky-500/30 rounded-xl p-5 bg-sky-500/5">
        <p class="text-[10px] text-gray-600 uppercase">Ledger balance</p>
        <p class="text-2xl font-bold text-sky-400 mt-1"><?= walletMoney($balance, $isTest) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Net credits after fees</p>
    </div>
    <div class="stat-card border border-emerald-500/30 rounded-xl p-5 bg-emerald-500/5">
        <p class="text-[10px] text-gray-600 uppercase">Available to settle</p>
        <p class="text-2xl font-bold text-emerald-400 mt-1"><?= walletMoney($available, $isTest) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Ready for bank transfer</p>
    </div>
    <div class="stat-card border border-amber-500/20 rounded-xl p-5">
        <p class="text-[10px] text-gray-600 uppercase">In transit to bank</p>
        <p class="text-2xl font-bold text-amber-300 mt-1"><?= walletMoney($pendingOut, $isTest) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Pending / processing settlements</p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-[10px] text-gray-600 uppercase">Payment holds</p>
        <p class="text-2xl font-bold text-gray-300 mt-1"><?= walletMoney($onHold, $isTest) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Pending transactions not yet success</p>
    </div>
    <?php
    $walletSettled = 0.0;
    try {
        $sst = $db->prepare("SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE merchant_id=? AND status='success' AND is_test=?");
        $sst->execute([$merchantId, $isTest ? 1 : 0]);
        $walletSettled = (float)$sst->fetchColumn();
    } catch (Throwable $e) {}
    ?>
    <div class="stat-card border border-violet-500/30 rounded-xl p-5 bg-violet-500/5">
        <p class="text-[10px] text-gray-600 uppercase">Total settled</p>
        <p class="text-2xl font-bold text-violet-400 mt-1"><?= walletMoney($walletSettled, $isTest) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Successfully transferred to bank</p>
    </div>
</div>

<?php if ($available < 0.01 && (int)$wallet['success_txns'] < 1): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6">
    <p class="font-semibold text-amber-300">No settlement amount yet — complete a test payment first</p>
    <p class="text-sm text-amber-400/80 mt-2">After a ₹1 demo payment, your balance will appear here.</p>
    <a href="merchant_register.php" class="inline-block mt-3 btn-primary text-sm px-5 py-2.5">Pay ₹1 test →</a>
</div>
<?php elseif ($available > 0 && !$canTransfer): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6 text-sm text-amber-300">
    Available: <?= walletMoney($available, $isTest) ?> · Min transfer: <?= walletMoney($minSettlement, $isTest) ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500"><?= __('wallet_success_payments') ?></p>
        <p class="text-2xl font-bold text-brand-400 mt-1"><?= (int)$wallet['success_txns'] ?></p>
        <p class="text-xs text-gray-600 mt-1"><?= $wallet['success_txns'] < 1 ? __('wallet_no_payments') : __('wallet_credited') ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <h3 class="font-semibold text-sm mb-3"><?= __('wallet_quick_transfer') ?></h3>
        <?php if (!merchantTeamCan('settle')): ?>
        <p class="text-xs text-amber-400">Your team role cannot initiate settlements. Ask Admin/Finance.</p>
        <?php else: ?>
        <?php if (!$canTransfer): ?>
        <p class="text-xs text-amber-400 mb-2"><?= __('wallet_low_balance') ?> <a href="merchant_register.php" class="text-sky-400 underline"><?= __('wallet_demo_pay') ?></a></p>
        <?php endif; ?>
        <form method="POST" class="space-y-2" novalidate aria-label="Quick bank transfer">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="transfer">
            <?= uxFormLabel(uxFieldId('transfer-amount'), 'Amount') ?>
            <input type="number" name="amount" id="<?= e(uxFieldId('transfer-amount')) ?>" min="<?= $minSettlement ?>" max="<?= max($minSettlement, $available) ?>" step="0.01"
                value="<?= $canTransfer ? max($minSettlement, $available) : ($available > 0 ? $available : $minSettlement) ?>"
                class="input-field text-sm">
            <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-bold transition">
                <?= $canTransfer ? __('wallet_transfer_bank') . ' →' : __('wallet_try_transfer') . ' →' ?>
            </button>
        </form>
        <a href="settlements.php" class="block text-center text-xs text-gray-500 mt-2 hover:text-gray-400"><?= __('wallet_full_transfer') ?> →</a>
        <?php endif; ?>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold"><?= __('wallet_ledger') ?></h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left"><?= __('transactions') ?></th>
                <th class="px-5 py-3 text-right"><?= __('wallet_balance') ?></th>
                <th class="px-5 py-3 text-right"><?= __('wallet_available') ?></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($ledger)): ?>
                <tr><td colspan="3" class="p-0"><?= renderMerchantEmptyState('No settlement activity yet', 'Complete a test or live payment to see ledger entries here.', 'merchant_register.php', 'Try ₹1 demo →') ?></td></tr>
                <?php else: foreach ($ledger as $w):
                    $amt = safeDisplayBalance((float)$w['amount'], $isTest);
                    $balAfter = safeDisplayBalance((float)$w['balance_after'], $isTest);
                ?>
                <tr>
                    <td class="px-5 py-3">
                        <p class="text-xs text-gray-400"><?= formatDate($w['created_at']) ?> · <?= e($w['type']) ?></p>
                        <p class="text-[11px] text-gray-600"><?= e($w['description'] ?? '') ?></p>
                    </td>
                    <td class="px-5 py-3 text-right <?= $amt >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= formatMoney($amt) ?></td>
                    <td class="px-5 py-3 text-right text-gray-400"><?= formatMoney($balAfter) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
