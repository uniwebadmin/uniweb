<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
ensurePlatformWalletTables();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_bank') {
        foreach (['platform_bank_name','platform_account_holder','platform_account_number','platform_ifsc'] as $k) {
            $v = trim($_POST[$k] ?? '');
            $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
                ->execute([$k, $v, $v]);
        }
        flash('success', 'Platform bank details saved.');
        redirect('admin_wallet.php');
    }
    if ($action === 'withdraw') {
        $amount = (float)($_POST['amount'] ?? 0);
        $wallet = ensurePlatformWalletReady();
        $available = (float)$wallet['available'];
        $min = getEffectivePlatformMinWithdraw($available);
        if ($amount <= 0) {
            $amount = $available;
        }
        if ($amount < $min) {
            flash('error', 'Minimum withdrawal is ' . walletMoney($min, true) . '. Available: ' . walletMoney($available, true) . '.');
        } elseif ($amount > $available) {
            flash('error', 'Insufficient balance. Available: ' . walletMoney($available, true) . '.');
        } elseif (!getSetting('platform_account_number', '')) {
            flash('error', 'Add platform bank account below first, then Save.');
        } else {
            $settlementId = generateId('PWL');
            // Reservation model: reserve the amount as a pending payout instead of
            // debiting now. available = balance − pending, so the wallet is debited
            // once (at completion with a UTR), never double-counted.
            $db->prepare('INSERT INTO platform_settlements (settlement_id, amount, status, bank_name, account_number, ifsc_code, account_holder) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $settlementId, $amount, 'pending',
                    getSetting('platform_bank_name', ''),
                    getSetting('platform_account_number', ''),
                    getSetting('platform_ifsc', ''),
                    getSetting('platform_account_holder', COMPANY_LEGAL_NAME),
                ]);
            flash('success', 'Withdrawal ' . $settlementId . ' reserved (' . walletMoney($amount, true) . '). Enter the bank UTR under Bank Payouts to complete it after the transfer.');
        }
        redirect('admin_wallet.php');
    }
    if ($action === 'complete_payout') {
        $sid = trim($_POST['settlement_id'] ?? '');
        $utr = trim($_POST['utr'] ?? '');
        if ($sid === '' || $utr === '') {
            flash('error', 'Bank UTR / reference is required to complete a payout.');
            redirect('admin_wallet.php');
        }
        $row = $db->prepare("SELECT * FROM platform_settlements WHERE settlement_id=? AND status IN ('pending','processing')");
        $row->execute([$sid]);
        $ps = $row->fetch();
        if (!$ps) {
            flash('error', 'Payout not found or already settled.');
            redirect('admin_wallet.php');
        }
        $amt = (float)$ps['amount'];
        if (debitPlatformWallet($amt, 'settlement', null, $sid, 'Platform wallet -> bank transfer (UTR ' . $utr . ')')) {
            $db->prepare("UPDATE platform_settlements SET status='completed', utr=?, processed_at=NOW() WHERE settlement_id=?")
                ->execute([$utr, $sid]);
            flash('success', 'Payout ' . $sid . ' completed and wallet debited (UTR ' . $utr . ').');
        } else {
            flash('error', 'Debit failed — balance may be out of sync. Click Sync Wallets, then retry.');
        }
        redirect('admin_wallet.php');
    }
}

if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    flash('error', 'Manual payout completion is disabled. Enter the bank UTR under Bank Payouts to complete a payout.');
    redirect('admin_wallet.php');
}

$wallet = ensurePlatformWalletReady();
$balance = (float)$wallet['balance'];
$available = (float)$wallet['available'];
$pendingPayout = (float)$wallet['pending'];
$totalCommission = (float)$wallet['commission'];
$minWithdraw = getEffectivePlatformMinWithdraw($available);
$canWithdraw = $available >= $minWithdraw && $available >= 1.0;
$ledger = getPlatformWalletLedger(50);
$payouts = $db->query('SELECT * FROM platform_settlements ORDER BY created_at DESC LIMIT 20')->fetchAll();
$uncredited = getUncreditedSuccessCount();

$pageTitle = 'Platform Wallet';
require_once __DIR__ . '/header.php';
?>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3 text-sm">
    <div>
        <p class="text-sky-200 font-medium">Platform Wallet Sync</p>
        <p class="text-xs text-gray-500 mt-1">Wrong balance showing? Click <strong>Sync Wallets</strong><?= $uncredited > 0 ? " · $uncredited payment(s) pending credit" : '' ?>.</p>
    </div>
    <p class="text-xs text-gray-500">Wallet corrections require an audited compensating entry.</p>
</div>

<div class="grid lg:grid-cols-4 gap-4 mb-8">
    <div class="stat-card border border-emerald-500/30 rounded-xl p-5 bg-emerald-500/5 lg:col-span-2">
        <p class="text-xs text-gray-500 uppercase">Platform Wallet (Commission)</p>
        <p class="text-4xl font-bold text-emerald-400 mt-2"><?= walletMoney($balance, true) ?></p>
        <p class="text-xs text-gray-500 mt-2">Earned: <?= walletMoney($totalCommission, true) ?> · Pending payout: <?= walletMoney($pendingPayout, true) ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">Available to Withdraw</p>
        <p class="text-2xl font-bold text-sky-400 mt-1"><?= walletMoney($available, true) ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">Min Withdrawal</p>
        <p class="text-2xl font-bold mt-1"><?= walletMoney($minWithdraw, true) ?></p>
    </div>
</div>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-6 flex flex-wrap items-center justify-between gap-3 text-sm">
    <div>
        <p class="text-sky-200 font-medium">Nodal / Escrow Separation</p>
        <p class="text-xs text-gray-500 mt-1">Customer funds ledger is separate from platform commission. Verify the real nodal/escrow bank account under Nodal Accounts.</p>
    </div>
    <a href="admin_nodal_accounts.php" class="btn-primary text-xs px-4 py-2">Manage Nodal Accounts</a>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Withdraw to Bank</h2>
        <?php if (!getSetting('platform_account_number', '')): ?>
        <p class="text-amber-400 text-xs mb-3">Add your bank account below before withdrawing.</p>
        <?php endif; ?>
        <?php if (!$canWithdraw): ?>
        <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-3 mb-4 text-xs text-amber-200">
            <?php if ($balance < 1): ?>
            Commission balance is empty. It will appear here after merchant test payments.
            <?php elseif ($pendingPayout > 0): ?>
            <?= walletMoney($pendingPayout, true) ?> is locked in pending payout — click <strong>Clear Pending</strong>.
            <?php else: ?>
            Available: <?= walletMoney($available, true) ?> · Min: <?= walletMoney($minWithdraw, true) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <form method="POST" class="space-y-4" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="withdraw">
            <div>
                <label class="text-sm text-gray-400">Amount (₹)</label>
                <input type="number" name="amount" min="1" max="<?= max(1, $available) ?>" step="0.01"
                    value="<?= $canWithdraw ? min($available, max(1, $available)) : 1 ?>"
                    class="input-field mt-1">
                <p class="text-xs text-gray-600 mt-1">Min <?= walletMoney($minWithdraw, true) ?> · Available <?= walletMoney($available, true) ?></p>
            </div>
            <button type="submit" class="btn-primary w-full py-3.5 font-bold">
                <?= $canWithdraw ? 'Request Bank Transfer →' : 'Try Withdraw →' ?>
            </button>
        </form>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Platform Bank Account</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_bank">
            <div><label class="text-xs text-gray-500">Bank Name</label><input type="text" name="platform_bank_name" class="input-field mt-1" value="<?= e(getSetting('platform_bank_name', '')) ?>"></div>
            <div><label class="text-xs text-gray-500">Account Holder</label><input type="text" name="platform_account_holder" class="input-field mt-1" value="<?= e(getSetting('platform_account_holder', COMPANY_LEGAL_NAME)) ?>"></div>
            <div><label class="text-xs text-gray-500">Account Number</label><input type="text" name="platform_account_number" class="input-field mt-1 font-mono" value="<?= e(getSetting('platform_account_number', '')) ?>"></div>
            <div><label class="text-xs text-gray-500">IFSC</label><input type="text" name="platform_ifsc" class="input-field mt-1 font-mono uppercase" value="<?= e(getSetting('platform_ifsc', '')) ?>"></div>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Save Bank Details</button>
        </form>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Commission Ledger</h2></div>
        <div class="overflow-x-auto max-h-96">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50 sticky top-0"><tr>
                    <th class="px-4 py-2 text-left">Date</th><th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2 text-right">Balance</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($ledger)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 text-xs">No commission yet. Entries appear after test payments.</td></tr>
                    <?php else: foreach ($ledger as $w):
                        $amt = walletAmount((float)$w['amount'], true);
                        $desc = (string)($w['description'] ?? '');
                        $txnMatch = [];
                        if (preg_match('/\b(TXN[A-Z0-9]+)\b/i', $desc, $txnMatch)) {
                            $descHtml = preg_replace(
                                '/\b(TXN[A-Z0-9]+)\b/i',
                                '<a href="' . e(transactionDetailUrl($txnMatch[1])) . '" class="text-sky-400 hover:underline">' . e($txnMatch[1]) . '</a>',
                                e($desc),
                                1
                            );
                        } else {
                            $descHtml = e($desc);
                        }
                    ?>
                    <tr>
                        <td class="px-4 py-2 text-xs text-gray-500"><?= formatDate($w['created_at']) ?></td>
                        <td class="px-4 py-2 text-xs"><?= e($w['type']) ?><br><span class="text-gray-600"><?= $descHtml ?></span></td>
                        <td class="px-4 py-2 text-right <?= $amt >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= walletMoney($amt, true) ?></td>
                        <td class="px-4 py-2 text-right text-gray-400"><?= walletMoney((float)$w['balance_after'], true) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Bank Payouts</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-2 text-left">ID</th><th class="px-4 py-2 text-left">Amount</th>
                <th class="px-4 py-2 text-left">Status</th><th class="px-4 py-2 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($payouts)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No payouts yet.</td></tr>
                <?php else: foreach ($payouts as $p): ?>
                <tr>
                    <td class="px-4 py-2 font-mono text-xs"><?= e($p['settlement_id']) ?></td>
                    <td class="px-4 py-2 font-semibold text-emerald-400"><?= walletMoney((float)$p['amount'], true) ?></td>
                    <td class="px-4 py-2"><?= statusBadge($p['status']) ?></td>
                    <td class="px-4 py-2 text-xs text-gray-500">
                        <?php if (in_array($p['status'], ['pending', 'processing'], true)): ?>
                        <form method="POST" class="flex items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="complete_payout">
                            <input type="hidden" name="settlement_id" value="<?= e($p['settlement_id']) ?>">
                            <input type="text" name="utr" placeholder="Bank UTR" required class="input-field !py-1 !text-xs w-28">
                            <button type="submit" class="border border-gray-700 rounded-lg hover:bg-white/5 px-3 py-1 text-xs">Complete</button>
                        </form>
                        <?php else: ?>
                        <?= !empty($p['utr']) ? ('UTR ' . e($p['utr'])) : '—' ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
