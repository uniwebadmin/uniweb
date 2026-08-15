<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
$merchant = requireMerchantAccount();
if (!merchantProfileComplete($merchant)) {
    redirect('merchant_setup.php');
}

ensureWalletEngine();
$wallet = ensureMerchantWalletReady((int)$merchant['id']);
$viewTest = isDashboardTestMode($merchant);
$stats = getMerchantStatsForMode($merchant['id'], $viewTest);
$onboarding = getMerchantOnboardingSteps($merchant);
$onboardingDone = count(array_filter($onboarding, fn($s) => $s['done']));
$db = getDB();
$recentTxns = $db->prepare('SELECT * FROM transactions WHERE merchant_id = ? AND is_test = ? ORDER BY created_at DESC LIMIT 8');
$recentTxns->execute([$merchant['id'], $viewTest ? 1 : 0]);
$transactions = $recentTxns->fetchAll();

if (isset($_GET['dismiss_mfa_prompt'])) {
    $_SESSION['mfa_prompt_dismissed_at'] = time();
    redirect('dashboard.php');
}
ensureMerchant2FA();

$pageTitle = __('dashboard');
require_once __DIR__ . '/header.php';
echo renderMerchantMfaSetupPrompt($merchant, 'dashboard');
?>

<?php if (isDashboardTestMode($merchant) && $onboardingDone < count($onboarding)):
    $onboardPct = (int)round($onboardingDone / max(1, count($onboarding)) * 100);
?>
<div class="glass rounded-xl p-5 mb-6 border border-sky-500/20">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div class="flex-1 min-w-[200px]">
            <p class="font-semibold text-sky-300"><?= __('dash_setup_checklist') ?> (<?= $onboardingDone ?>/<?= count($onboarding) ?>)</p>
            <p class="text-xs text-gray-500 mt-1"><?= __('dash_test_steps') ?></p>
            <div class="h-1.5 bg-gray-800 rounded-full mt-3 overflow-hidden max-w-md">
                <div class="h-full bg-gradient-to-r from-sky-600 to-emerald-500 rounded-full transition-all" style="width:<?= $onboardPct ?>%"></div>
            </div>
            <p class="text-[10px] text-gray-600 mt-1"><?= $onboardPct ?>% complete — finish all steps to go live faster</p>
        </div>
        <a href="merchant_launch.php" class="btn-primary text-xs px-4 py-2">Open Launch Center →</a>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <?php foreach ($onboarding as $step): ?>
        <a href="<?= e($step['url']) ?>" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm border <?= $step['done'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-gray-800 hover:border-sky-500/40' ?>">
            <span class="<?= $step['done'] ? 'text-emerald-400' : 'text-gray-500' ?>"><?= $step['done'] ? '✓' : '○' ?></span>
            <span class="flex-1">
                <span class="block <?= $step['done'] ? 'text-gray-300' : 'text-white' ?>"><?= e($step['label']) ?></span>
                <span class="text-[11px] text-gray-500"><?= e($step['hint']) ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (isDashboardTestMode($merchant)): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div>
            <p class="text-amber-300 font-semibold text-sm flex items-center gap-2"><?= accountModeBadge($merchant) ?> <?= __('dash_sandbox') ?></p>
            <p class="text-amber-400/80 text-xs mt-1"><?= merchantCanGoLive($merchant) ? 'Switch to Live Mode in the header when ready for real payments.' : __('dash_sandbox_note') ?></p>
            <?php $kp = getMerchantKycProgress($merchant); if (!$kp['complete']): ?>
            <p class="text-xs text-amber-500 mt-2">KYC: <?= $kp['uploaded'] ?>/<?= $kp['required'] ?> documents uploaded</p>
            <?php endif; ?>
        </div>
        <?php if (!merchantCanGoLive($merchant)): ?>
        <a href="kyc.php" class="bg-amber-500 hover:bg-amber-400 text-dark-900 text-sm font-semibold px-4 py-2 rounded-lg transition whitespace-nowrap"><?= __('dash_complete_kyc') ?> →</a>
        <?php else: ?>
        <a href="<?= e(merchantModeToggleUrl('live')) ?>" class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold px-4 py-2 rounded-lg transition whitespace-nowrap">● Switch to Live Mode</a>
        <?php endif; ?>
        <span class="text-xs text-amber-400/80"><?= __('dash_wallet') ?>: <?= formatMoney(safeDisplayBalance($wallet['available'], true)) ?></span>
    </div>
</div>
<?php elseif (isDashboardLiveMode($merchant)): ?>
<div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-emerald-300 text-sm font-medium"><?= accountModeBadge($merchant) ?> <?= __('dash_live_note') ?></p>
    <div class="flex items-center gap-3">
        <a href="<?= e(merchantModeToggleUrl('test')) ?>" class="text-xs text-amber-400 hover:text-amber-300 border border-amber-500/30 px-2 py-1 rounded-lg">⚡ Switch to Test</a>
        <span class="text-xs text-gray-500"><?= __('dash_wallet') ?>: <?= formatMoney(safeDisplayBalance($wallet['available'], false)) ?></span>
    </div>
</div>
<?php endif; ?>

<?php renderMerchantCommercialCard($merchant); ?>

<?php
$balanceMode = $viewTest ? 'test' : 'live';
$balanceBreakdown = function_exists('getMerchantBalanceBreakdown') ? getMerchantBalanceBreakdown((int)$merchant['id'], $balanceMode) : null;
if ($balanceBreakdown):
?>
<div class="glass rounded-xl p-6 mb-8 border border-sky-500/20">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <p class="text-sm font-semibold text-sky-300">Balance Breakdown</p>
            <p class="text-xs text-gray-500 mt-1">Real-time ledger state · <?= $viewTest ? 'Test mode' : 'Live mode' ?></p>
        </div>
        <a href="wallet.php" class="text-xs text-sky-400 hover:underline">Full wallet →</a>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
            <p class="text-[10px] text-gray-600 uppercase">Available</p>
            <p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($balanceBreakdown['available']) ?></p>
            <p class="text-[10px] text-gray-600 mt-1">Ready to settle</p>
        </div>
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
            <p class="text-[10px] text-gray-600 uppercase">In Transit</p>
            <p class="text-2xl font-bold text-amber-400 mt-1"><?= formatMoney($balanceBreakdown['in_transit']) ?></p>
            <p class="text-[10px] text-gray-600 mt-1">Pending settlements</p>
        </div>
        <div class="rounded-xl border border-gray-700 bg-gray-800/30 p-4">
            <p class="text-[10px] text-gray-600 uppercase">On Hold</p>
            <p class="text-2xl font-bold text-gray-300 mt-1"><?= formatMoney($balanceBreakdown['hold']) ?></p>
            <p class="text-[10px] text-gray-600 mt-1">Pending txns + reserve</p>
        </div>
        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-4">
            <p class="text-[10px] text-gray-600 uppercase">Settled</p>
            <p class="text-2xl font-bold text-violet-400 mt-1"><?= formatMoney($balanceBreakdown['settled']) ?></p>
            <p class="text-[10px] text-gray-600 mt-1">Total settled to bank</p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <a href="wallet.php" class="stat-card border border-sky-500/30 rounded-xl p-5 bg-sky-500/5 lg:col-span-1 block hover:border-sky-400/50 transition">
        <p class="text-xs text-gray-500">Available to settle</p>
        <p class="text-xl font-bold text-sky-400 mt-1"><?= formatMoney(safeDisplayBalance($wallet['available'], $viewTest)) ?></p>
        <p class="text-[11px] text-gray-600 mt-1"><?= $viewTest ? 'Test balance' : 'Live balance' ?></p>
    </a>
    <?php foreach ([
        [__('dash_today'), formatMoney($stats['today_amount']), $stats['today_count'].' '.__('dash_txns'), 'text-brand-400', 'transactions.php?range=today'],
        [__('dash_month'), formatMoney($stats['month_amount']), $stats['month_count'].' '.__('dash_txns'), 'text-cyan-400', 'transactions.php?from=' . date('Y-m-01') . '&to=' . date('Y-m-d')],
        ['Success Rate', $stats['success_rate'].'%', 'Payment completion', 'text-emerald-400', 'transactions.php?status=success'],
        [__('dash_all_time'), formatMoney($stats['total_amount']), $stats['total_count'].' '.__('dash_txns'), 'text-purple-400', 'transactions.php'],
    ] as [$l,$v,$s,$c,$href]): ?>
    <a href="<?= e($href) ?>" class="stat-card border border-gray-800 rounded-xl p-5 block hover:border-brand-500/40 transition">
        <p class="text-xs text-gray-500"><?= $l ?></p>
        <p class="text-xl font-bold <?= $c ?> mt-1"><?= $v ?></p>
        <p class="text-xs text-gray-600 mt-1"><?= $s ?></p>
    </a>
    <?php endforeach; ?>
</div>

<?php if (!empty($stats['failed_count_7d']) && $stats['failed_count_7d'] > 0): ?>
<div class="glass rounded-xl p-4 mb-6 border border-red-500/20 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm font-semibold text-red-300">Failed Payments (7 days)</p>
        <p class="text-xs text-gray-500 mt-1"><?= (int)$stats['failed_count_7d'] ?> failed transaction(s) in the last 7 days</p>
    </div>
    <a href="transactions.php?status=failed" class="text-xs text-red-400 hover:underline">View failed transactions →</a>
</div>
<?php endif; ?>

<?php if (function_exists('getMerchantHealthScore')): $healthScore = getMerchantHealthScore((int)$merchant['id']); ?>
<div class="glass rounded-xl p-5 mb-8 border border-emerald-500/20">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-emerald-300">Merchant Health Score</p>
            <p class="text-xs text-gray-500 mt-1">Based on KYC, disputes, volume, settlements, support</p>
        </div>
        <div class="flex items-center gap-4">
            <?php $hs = (int)($healthScore['score'] ?? 0); $hcls = $hs >= 80 ? 'text-emerald-400' : ($hs >= 60 ? 'text-amber-400' : ($hs >= 40 ? 'text-yellow-400' : 'text-red-400')); ?>
            <div class="text-center">
                <p class="text-3xl font-bold <?= $hcls ?>"><?= $hs ?></p>
                <p class="text-[10px] text-gray-500">/ 100</p>
            </div>
            <div class="h-2 w-32 bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all <?= $hs >= 80 ? 'bg-emerald-500' : ($hs >= 60 ? 'bg-amber-500' : 'bg-red-500') ?>" style="width:<?= $hs ?>%"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-8">
    <h2 class="text-sm font-semibold text-gray-400 mb-3">Quick Actions</h2>
    <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-8 gap-2 sm:gap-3">
        <?php foreach ([
            ['payment_links.php','Payment Links','M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
            ['orders.php','Orders','M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
            ['qr_code.php',__('qr_code'),'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z'],
            ['settlements.php','Settlements','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['wallet.php','Settlement Balance','M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            ['reports.php','Reports','M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['invoices.php',__('dash_invoice'),'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['api_settings.php','API Keys','M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['support.php','Support','M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z'],
        ] as [$u,$l,$i]): ?>
        <a href="<?= $u ?>" class="glass rounded-xl dash-quick-card card-hover border border-gray-800 hover:border-brand-500/40">
            <span class="dash-quick-icon">
                <svg class="text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="<?= $i ?>"/></svg>
            </span>
            <span class="dash-quick-label"><?= e($l) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
        <h2 class="font-semibold"><?= __('dash_recent_txns') ?></h2>
        <a href="transactions.php" class="text-sm text-brand-400"><?= __('dash_view_all') ?> →</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Txn ID</th><th class="px-5 py-3 text-left">Amount</th>
                <th class="px-5 py-3 text-left">Method</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($transactions)): ?>
                <tr><td colspan="5" class="p-0"><?= renderMerchantEmptyState(
                    'No transactions yet',
                    'Create a payment link or try the ₹1 demo. Test Mode and Live Mode stay separate.',
                    'payment_links.php',
                    'Create a payment link →'
                ) ?></td></tr>
                <?php else: foreach ($transactions as $t): ?>
                <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='<?= e(transactionDetailUrl($t['txn_id'])) ?>'">
                    <td class="px-5 py-3 font-mono text-xs"><a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($t['txn_id']) ?></a></td>
                    <td class="px-5 py-3 font-semibold"><?= formatMoney(capStatAmount((float)$t['amount'])) ?></td>
                    <td class="px-5 py-3 uppercase text-xs text-gray-400"><?= e($t['payment_method']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($t['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($t['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
