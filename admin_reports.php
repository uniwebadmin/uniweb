<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$days = (int)($_GET['days'] ?? 30);
if ($days < 1 || $days > 365) $days = 30;

$db = getDB();
$since = date('Y-m-d H:i:s', time() - ($days * 86400));

// Transaction summary
$txSummary = ['total' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0, 'volume' => 0, 'fees' => 0];
try {
    $st = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
        COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume,
        COALESCE(SUM(CASE WHEN status='success' THEN platform_fee ELSE 0 END),0) as fees
        FROM transactions WHERE created_at >= ?");
    $st->execute([$since]);
    $row = $st->fetch();
    if ($row) {
        $txSummary = ['total' => (int)$row['total'], 'success' => (int)$row['success'], 'failed' => (int)$row['failed'], 'pending' => (int)$row['pending'], 'volume' => (float)$row['volume'], 'fees' => (float)$row['fees']];
    }
} catch (Throwable $e) {}

// Settlement summary
$stlSummary = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'amount' => 0];
try {
    $st = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
        COALESCE(SUM(net_amount),0) as amount
        FROM settlements WHERE created_at >= ?");
    $st->execute([$since]);
    $row = $st->fetch();
    if ($row) {
        $stlSummary = ['total' => (int)$row['total'], 'completed' => (int)$row['completed'], 'pending' => (int)$row['pending'], 'failed' => (int)$row['failed'], 'amount' => (float)$row['amount']];
    }
} catch (Throwable $e) {}

// Merchant summary
$merchantSummary = ['total' => 0, 'active' => 0, 'pending_kyc' => 0, 'new_this_period' => 0];
try {
    $merchantSummary['total'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status != 'deleted'")->fetchColumn();
    $merchantSummary['active'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active'")->fetchColumn();
    $merchantSummary['pending_kyc'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status='pending'")->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM merchants WHERE created_at >= ? AND status != 'deleted'");
    $st->execute([$since]);
    $merchantSummary['new_this_period'] = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// Dispute/chargeback summary
$disputeSummary = ['open' => 0, 'resolved' => 0, 'total' => 0];
try {
    $disputeSummary['open'] = (int)$db->query("SELECT COUNT(*) FROM chargebacks WHERE status='open'")->fetchColumn();
    $disputeSummary['resolved'] = (int)$db->query("SELECT COUNT(*) FROM chargebacks WHERE status IN ('won','lost','closed')")->fetchColumn();
    $disputeSummary['total'] = $disputeSummary['open'] + $disputeSummary['resolved'];
} catch (Throwable $e) {}

// Refund summary
$refundSummary = ['total' => 0, 'amount' => 0];
try {
    $st = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount),0) as amount FROM refunds WHERE created_at >= ?");
    $st->execute([$since]);
    $row = $st->fetch();
    if ($row) {
        $refundSummary = ['total' => (int)$row['total'], 'amount' => (float)$row['amount']];
    }
} catch (Throwable $e) {}

// Top merchants by volume
$topMerchants = [];
try {
    $st = $db->prepare("SELECT m.business_name, m.merchant_code,
        COUNT(t.id) as txn_count, COALESCE(SUM(t.amount),0) as volume
        FROM transactions t JOIN merchants m ON m.id=t.merchant_id
        WHERE t.status='success' AND t.created_at >= ?
        GROUP BY t.merchant_id ORDER BY volume DESC LIMIT 10");
    $st->execute([$since]);
    $topMerchants = $st->fetchAll();
} catch (Throwable $e) {}

// Daily volume trend (last 14 days)
$dailyTrend = [];
try {
    $trend = $db->query("SELECT DATE(created_at) as d,
        COUNT(*) as txns, COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume
        FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll();
    $dailyTrend = $trend;
} catch (Throwable $e) {}

// Support tickets
$supportSummary = ['open' => 0, 'closed' => 0];
try {
    $supportSummary['open'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
    $supportSummary['closed'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='closed'")->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = 'Reports & Ops Dashboard';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-2 text-xs" role="navigation" aria-label="Reports hub">
        <span class="text-gray-500 self-center mr-1">Reports:</span>
        <a href="admin_financial_reports.php" class="px-3 py-1.5 rounded-lg text-gray-400 hover:text-white border border-gray-800">Date range</a>
        <a href="admin_reports.php" class="px-3 py-1.5 rounded-lg bg-brand-600/20 text-brand-400 border border-brand-500/30">Ops day summary</a>
    </div>
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Ops snapshot by day range. Primary money report: <a href="admin_financial_reports.php" class="text-brand-400 hover:text-brand-300">Financial Reports (date range)</a>.</p>
        <form method="GET" class="flex gap-2 items-center">
            <select name="days" class="input-field text-xs w-32" onchange="this.form.submit()">
                <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
                <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
                <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Last 1 year</option>
            </select>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Transactions</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($txSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $txSummary['success'] ?> success Â· <?= $txSummary['failed'] ?> failed</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Volume</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($txSummary['volume']) ?></p><p class="text-xs text-gray-500 mt-1">Fees: <?= formatMoney($txSummary['fees']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Settlements</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= number_format($stlSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $stlSummary['completed'] ?> done Â· <?= $stlSummary['pending'] ?> pending</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Merchants</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= number_format($merchantSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $merchantSummary['new_this_period'] ?> new Â· <?= $merchantSummary['pending_kyc'] ?> KYC pending</p></div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Disputes (total)</p><p class="text-2xl font-bold <?= $disputeSummary['open'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($disputeSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $disputeSummary['open'] ?> open Â· <?= $disputeSummary['resolved'] ?> resolved</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Refunds</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($refundSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= formatMoney($refundSummary['amount']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Support Tickets</p><p class="text-2xl font-bold <?= $supportSummary['open'] > 0 ? 'text-amber-400' : 'text-emerald-400' ?> mt-1"><?= number_format($supportSummary['open'] + $supportSummary['closed']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $supportSummary['open'] ?> open Â· <?= $supportSummary['closed'] ?> closed</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Success Rate</p><p class="text-2xl font-bold <?= ($txSummary['total'] > 0 ? ($txSummary['success'] / $txSummary['total'] * 100) : 100) >= 95 ? 'text-emerald-400' : 'text-amber-400' ?> mt-1"><?= $txSummary['total'] > 0 ? round($txSummary['success'] / $txSummary['total'] * 100, 1) : '100' ?>%</p></div>
    </div>

    <?php if (!empty($dailyTrend)): ?>
    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Daily Volume Trend (14 days)</h3>
        <div class="flex items-end gap-1 h-32">
            <?php
            $maxVol = max(array_column($dailyTrend, 'volume') ?: [1]);
            foreach ($dailyTrend as $d):
                $h = $maxVol > 0 ? max(2, ($d['volume'] / $maxVol) * 100) : 2;
            ?>
            <div class="flex-1 flex flex-col items-center gap-1">
                <div class="w-full bg-brand-500/30 rounded-t" style="height: <?= $h ?>%"></div>
                <span class="text-[10px] text-gray-500"><?= date('d', strtotime($d['d'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Top Merchants by Volume (<?= $days ?> days)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[500px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-right">Transactions</th><th class="px-4 py-3 text-right">Volume</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($topMerchants)): ?><tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">No data for this period.</td></tr>
                <?php else: foreach ($topMerchants as $m): ?>
                <tr><td class="px-4 py-3 text-xs"><?= e($m['business_name']) ?> <span class="font-mono text-gray-500"><?= e($m['merchant_code']) ?></span></td><td class="px-4 py-3 text-right text-xs"><?= number_format((int)$m['txn_count']) ?></td><td class="px-4 py-3 text-right text-xs text-emerald-400"><?= formatMoney((float)$m['volume']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
