<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
if (!function_exists('getPlatformWalletBalance') && is_file(__DIR__ . '/includes/wallet.php')) {
    require_once __DIR__ . '/includes/wallet.php';
}

$pageTitle = 'Platform Fee Ledger';
$adminSection = 'financial';

// C5: Settle commission POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'settle_commission') {
        $amount = (float)($_POST['amount'] ?? 0);
        $settleMode = trim($_POST['settle_mode'] ?? 'wallet');
        $bankAccount = trim($_POST['bank_account'] ?? '');
        $adminBy = $_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $result = settlePlatformCommission($amount, $settleMode, $bankAccount ?: null, $adminBy);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
        redirect('admin_platform_wallet.php');
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$platformBalance = getPlatformWalletBalance();
$transactions = getPlatformWalletTransactions($perPage, $offset);
$totalTxns = countPlatformWalletTransactions();
$totalPages = max(1, (int)ceil($totalTxns / $perPage));

// Total commission earned
$totalCommission = 0;
try {
    $totalCommission = (float)getDB()->query("SELECT COALESCE(SUM(platform_fee), 0) FROM transactions WHERE status='success'")->fetchColumn();
} catch (Throwable $e) {}

// Commission today
$commissionToday = 0;
try {
    $st = getDB()->prepare("SELECT COALESCE(SUM(platform_fee), 0) FROM transactions WHERE status='success' AND DATE(created_at)=CURDATE()");
    $st->execute();
    $commissionToday = (float)$st->fetchColumn();
} catch (Throwable $e) {}

require_once __DIR__ . '/header.php';
?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Platform Fee Wallet</h1>
    <p class="text-sm text-gray-500 mb-6">Commission earned from every payment capture. Separated from merchant wallets.</p>

    <div class="grid sm:grid-cols-3 gap-4 mb-8">
        <div class="glass rounded-xl p-5 stat-card border border-emerald-500/20">
            <p class="text-xs text-gray-500">Current Wallet Balance</p>
            <p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($platformBalance) ?></p>
        </div>
        <div class="glass rounded-xl p-5 stat-card border border-sky-500/20">
            <p class="text-xs text-gray-500">Total Commission Earned</p>
            <p class="text-2xl font-bold text-sky-400 mt-1"><?= formatMoney($totalCommission) ?></p>
        </div>
        <div class="glass rounded-xl p-5 stat-card border border-violet-500/20">
            <p class="text-xs text-gray-500">Commission Today</p>
            <p class="text-2xl font-bold text-violet-400 mt-1"><?= formatMoney($commissionToday) ?></p>
        </div>
    </div>

    <!-- C5: Settle Commission Form -->
    <div class="glass rounded-xl p-6 border border-sky-500/20 mb-8">
        <h2 class="font-semibold text-lg mb-4">Settle Commission</h2>
        <form method="POST" class="grid md:grid-cols-3 gap-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="settle_commission">
            <div>
                <label class="text-xs text-gray-500">Amount (₹)</label>
                <input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($platformBalance, 2, '.', '')) ?>" required class="input-field text-sm mt-1" placeholder="Enter amount to settle">
            </div>
            <div>
                <label class="text-xs text-gray-500">Settlement Mode</label>
                <select name="settle_mode" id="settle_mode" class="input-field text-sm mt-1" onchange="toggleBankField()">
                    <option value="wallet">Wallet Hold (keep in platform wallet)</option>
                    <option value="bank">Bank Transfer (debit platform wallet)</option>
                </select>
            </div>
            <div id="bank_field" style="display:none">
                <label class="text-xs text-gray-500">Bank Account Details</label>
                <input type="text" name="bank_account" class="input-field text-sm mt-1" placeholder="Bank name, account number, IFSC">
            </div>
            <div class="md:col-span-3">
                <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white rounded-lg px-6 py-2.5 text-sm font-medium" onclick="return confirm('Confirm commission settlement?')">Settle →</button>
            </div>
        </form>
    </div>

    <!-- C5: Settlement History -->
    <?php $settlements = getPlatformSettlements(20); if (!empty($settlements)): ?>
    <div class="glass rounded-xl overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Settlement History</h2></div>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Settlement ID</th><th class="px-4 py-3 text-right">Amount</th>
                <th class="px-4 py-3 text-left">Mode</th><th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">By</th><th class="px-4 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($settlements as $s): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-sky-400"><?= e($s['settlement_id'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-xs text-gray-300"><?= formatMoney((float)($s['amount'] ?? 0)) ?></td>
                    <td class="px-4 py-3 text-xs"><span class="px-2 py-0.5 rounded <?= ($s['mode'] ?? '') === 'bank' ? 'bg-amber-500/10 text-amber-400' : 'bg-sky-500/10 text-sky-400' ?>"><?= e($s['mode'] ?? '—') ?></span></td>
                    <td class="px-4 py-3 text-xs text-emerald-400"><?= e($s['status'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e($s['processed_by'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($s['processed_at'] ?? $s['created_at'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold">Platform Wallet Transactions</h2>
        <span class="text-xs text-gray-500"><?= number_format($totalTxns) ?> entries · Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Reference</th><th class="px-4 py-3 text-left">Type</th>
                <th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Balance After</th>
                <th class="px-4 py-3 text-left">Description</th><th class="px-4 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($transactions)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No platform wallet transactions yet.</td></tr>
                <?php else: foreach ($transactions as $t): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= e($t['reference'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs"><span class="px-2 py-0.5 rounded <?= $t['type'] === 'credit' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>"><?= e($t['type'] ?? '—') ?></span></td>
                    <td class="px-4 py-3 text-right text-xs <?= ($t['amount'] ?? 0) >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= formatMoney((float)($t['amount'] ?? 0)) ?></td>
                    <td class="px-4 py-3 text-right text-xs text-gray-400"><?= formatMoney((float)($t['balance_after'] ?? 0)) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate" title="<?= e($t['description'] ?? '') ?>"><?= e(mb_substr($t['description'] ?? '—', 0, 60)) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($t['created_at'] ?? '') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2 mt-6">
        <?php for ($i = max(1, $page - 4); $i <= min($totalPages, $page + 4); $i++): ?>
        <a href="?page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'bg-sky-600 text-white' : 'glass text-gray-400 hover:text-white' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<script>
function toggleBankField() {
    const mode = document.getElementById('settle_mode').value;
    document.getElementById('bank_field').style.display = mode === 'bank' ? '' : 'none';
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
