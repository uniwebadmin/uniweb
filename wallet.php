<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '') && ($_POST['action'] ?? '') === 'transfer') {
    $amount = (float)($_POST['amount'] ?? 0);
    $result = processMerchantSettlement($merchantId, $merchant, $amount);
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    if (!$result['ok'] && !empty($result['redirect'])) redirect($result['redirect']);
    redirect('wallet.php');
}

autoWalletRepairIfNeeded();
$wallet = ensureMerchantWalletReady($merchantId);
$isTest = (bool)($wallet['is_test'] ?? isMerchantTest($merchant));
$balance = $wallet['balance'];
$available = $wallet['available'];
$minSettlement = getEffectiveMinSettlement($merchant, $available);
$ledger = getMerchantWalletLedger($merchantId, 50);
$canTransfer = $available >= $minSettlement;

$pageTitle = __('wallet_title');
require_once __DIR__ . '/header.php';
?>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3 text-sm">
    <div>
        <p class="text-sky-200 font-medium">Merchant Wallet</p>
        <p class="text-xs text-gray-500 mt-1"><?= e($merchant['email']) ?> · <?= accountModeBadge($merchant) ?> · <?= (int)$wallet['success_txns'] ?> successful payment(s)</p>
    </div>
    <a href="settlements.php" class="text-sm px-4 py-2.5 rounded-xl border border-gray-700 text-gray-400 hover:text-white">Settlements →</a>
</div>

<?php if ($available < 0.01 && (int)$wallet['success_txns'] < 1): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6">
    <p class="font-semibold text-amber-300">Wallet empty — complete a test payment first</p>
    <p class="text-sm text-amber-400/80 mt-2">After a ₹1 demo payment, your balance will appear here.</p>
    <a href="demo.php" class="inline-block mt-3 btn-primary text-sm px-5 py-2.5">Pay ₹1 test →</a>
</div>
<?php elseif ($available > 0 && !$canTransfer): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6 text-sm text-amber-300">
    Available: <?= walletMoney($available, $isTest) ?> · Min transfer: <?= walletMoney($minSettlement, $isTest) ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <div class="stat-card border border-sky-500/30 rounded-xl p-6 bg-sky-500/5">
        <p class="text-xs text-gray-500 uppercase"><?= __('wallet_balance') ?></p>
        <p class="text-4xl font-bold text-sky-400 mt-2"><?= walletMoney($balance, $isTest) ?></p>
        <p class="text-xs text-gray-500 mt-2"><?= __('wallet_available') ?>: <strong class="text-emerald-400"><?= walletMoney($available, $isTest) ?></strong></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500"><?= __('wallet_success_payments') ?></p>
        <p class="text-2xl font-bold text-brand-400 mt-1"><?= (int)$wallet['success_txns'] ?></p>
        <p class="text-xs text-gray-600 mt-1"><?= $wallet['success_txns'] < 1 ? __('wallet_no_payments') : __('wallet_credited') ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <h3 class="font-semibold text-sm mb-3"><?= __('wallet_quick_transfer') ?></h3>
        <?php if (!$canTransfer): ?>
        <p class="text-xs text-amber-400 mb-2"><?= __('wallet_low_balance') ?> <a href="demo.php" class="text-sky-400 underline"><?= __('wallet_demo_pay') ?></a></p>
        <?php endif; ?>
        <form method="POST" class="space-y-2" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="transfer">
            <input type="number" name="amount" min="<?= $minSettlement ?>" max="<?= max($minSettlement, $available) ?>" step="0.01"
                value="<?= $canTransfer ? max($minSettlement, $available) : ($available > 0 ? $available : $minSettlement) ?>"
                class="input-field text-sm">
            <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-bold transition">
                <?= $canTransfer ? __('wallet_transfer_bank') . ' →' : __('wallet_try_transfer') . ' →' ?>
            </button>
        </form>
        <a href="settlements.php" class="block text-center text-xs text-gray-500 mt-2 hover:text-gray-400"><?= __('wallet_full_transfer') ?> →</a>
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
                <tr><td colspan="3" class="px-5 py-12 text-center text-gray-500 text-xs"><?= __('no_data') ?></td></tr>
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
