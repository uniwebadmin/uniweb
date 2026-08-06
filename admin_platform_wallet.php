<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$pageTitle = 'Platform Fee Wallet';
$adminSection = 'financial';

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

require_once __DIR__ . '/includes/header.php';
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
<?php require_once __DIR__ . '/includes/footer.php'; ?>
