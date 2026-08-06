<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$days = (int)($_GET['days'] ?? 30);
if ($days < 1 || $days > 365) $days = 30;

$db = getDB();
$registry = getPartnerRegistry();
$partnerKeys = array_keys($registry);

// Get per-partner stats from transactions
$partnerStats = [];
foreach ($partnerKeys as $key) {
    $stats = [
        'key' => $key,
        'name' => $registry[$key]['name'] ?? ucfirst($key),
        'icon' => $registry[$key]['icon'] ?? '',
        'type' => $registry[$key]['type'] ?? '',
        'configured' => partnerIsConfigured($key),
        'total_txns' => 0,
        'success_txns' => 0,
        'failed_txns' => 0,
        'success_rate' => 0.0,
        'total_volume' => 0.0,
        'avg_amount' => 0.0,
        'avg_settlement_hours' => 0,
        'pending_settlements' => 0,
        'total_fees' => 0.0,
        'chargebacks' => 0,
    ];

    try {
        $st = $db->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume,
                COALESCE(SUM(CASE WHEN status='success' THEN platform_fee ELSE 0 END),0) as fees
             FROM transactions
             WHERE gateway = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([$key, $days]);
        $row = $st->fetch();
        if ($row) {
            $stats['total_txns'] = (int)$row['total'];
            $stats['success_txns'] = (int)$row['success'];
            $stats['failed_txns'] = (int)$row['failed'];
            $stats['total_volume'] = (float)$row['volume'];
            $stats['total_fees'] = (float)$row['fees'];
            $stats['success_rate'] = $stats['total_txns'] > 0 ? round($stats['success_txns'] / $stats['total_txns'] * 100, 1) : 0;
            $stats['avg_amount'] = $stats['success_txns'] > 0 ? round($stats['total_volume'] / $stats['success_txns'], 2) : 0;
        }
    } catch (Throwable $e) {}

    // Settlement delay
    try {
        $st = $db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_at, s.processed_at)) as avg_hours
             FROM transactions t
             JOIN settlements s ON s.transaction_id = t.id
             WHERE t.gateway = ? AND s.status='completed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([$key, $days]);
        $avg = $st->fetchColumn();
        $stats['avg_settlement_hours'] = $avg ? round((float)$avg, 1) : 0;
    } catch (Throwable $e) {}

    // Pending settlements
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM settlements s
             JOIN transactions t ON t.id = s.transaction_id
             WHERE t.gateway = ? AND s.status IN ('pending','processing')
             AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([$key, $days]);
        $stats['pending_settlements'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    // Chargebacks
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM chargebacks c
             JOIN transactions t ON t.id = c.transaction_id
             WHERE t.gateway = ? AND c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $st->execute([$key, $days]);
        $stats['chargebacks'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    $partnerStats[] = $stats;
}

// Overall totals
$totals = [
    'txns' => 0,
    'volume' => 0.0,
    'fees' => 0.0,
    'success' => 0,
    'chargebacks' => 0,
];
foreach ($partnerStats as $p) {
    $totals['txns'] += $p['total_txns'];
    $totals['volume'] += $p['total_volume'];
    $totals['fees'] += $p['total_fees'];
    $totals['success'] += $p['success_txns'];
    $totals['chargebacks'] += $p['chargebacks'];
}

$pageTitle = 'Partner Commercial Dashboard';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Pricing, success rate, settlement delay per partner</p>
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
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Transactions</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($totals['txns']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Volume</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($totals['volume']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Fees Earned</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= formatMoney($totals['fees']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Chargebacks</p><p class="text-2xl font-bold <?= $totals['chargebacks'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($totals['chargebacks']) ?></p></div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Partner Performance (<?= $days ?> days)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[1000px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Partner</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-right">Txns</th>
                <th class="px-4 py-3 text-right">Success Rate</th>
                <th class="px-4 py-3 text-right">Volume</th>
                <th class="px-4 py-3 text-right">Avg Amount</th>
                <th class="px-4 py-3 text-right">Fees Earned</th>
                <th class="px-4 py-3 text-right">Avg Settlement</th>
                <th class="px-4 py-3 text-right">Pending Stlm</th>
                <th class="px-4 py-3 text-right">Chargebacks</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($partnerStats as $p): ?>
                <tr>
                    <td class="px-4 py-3"><span class="text-lg"><?= e($p['icon']) ?></span> <?= e($p['name']) ?> <span class="text-xs text-gray-500 capitalize">(<?= e($p['type']) ?>)</span></td>
                    <td class="px-4 py-3"><?= $p['configured'] ? '<span class="text-xs text-emerald-400">● Active</span>' : '<span class="text-xs text-gray-500">○ Not configured</span>' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= number_format($p['total_txns']) ?></td>
                    <td class="px-4 py-3 text-right text-xs<?php $sr = $p['success_rate']; echo $sr >= 95 ? ' text-emerald-400' : ($sr >= 80 ? ' text-amber-400' : ($sr > 0 ? ' text-red-400' : '')); ?>"><?= $p['total_txns'] > 0 ? $sr . '%' : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= $p['total_volume'] > 0 ? formatMoney($p['total_volume']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= $p['avg_amount'] > 0 ? formatMoney($p['avg_amount']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= $p['total_fees'] > 0 ? formatMoney($p['total_fees']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?php $sh = $p['avg_settlement_hours']; echo $sh > 0 && $sh <= 24 ? ' text-emerald-400' : ($sh > 48 ? ' text-amber-400' : ''); ?>"><?= $p['avg_settlement_hours'] > 0 ? $p['avg_settlement_hours'] . 'h' : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?= $p['pending_settlements'] > 0 ? ' text-amber-400' : '' ?>"><?= $p['pending_settlements'] > 0 ? number_format($p['pending_settlements']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?= $p['chargebacks'] > 0 ? ' text-red-400' : '' ?>"><?= $p['chargebacks'] > 0 ? number_format($p['chargebacks']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <?php
    // Partner pricing reference table
    $pricing = [
        'razorpay' => ['mdr' => '2.0%', 'fixed' => '₹3', 'settlement' => 'T+2', 'notes' => 'Domestic cards, UPI, netbanking'],
        'cashfree' => ['mdr' => '1.75%', 'fixed' => '₹3', 'settlement' => 'T+1', 'notes' => 'UPI at 1.75%, cards 2.0%'],
        'payu' => ['mdr' => '2.0%', 'fixed' => '₹3', 'settlement' => 'T+2', 'notes' => 'Domestic'],
        'axis' => ['mdr' => 'Custom', 'fixed' => 'Custom', 'settlement' => 'T+1', 'notes' => 'Direct banking, negotiated rates'],
        'decentro' => ['mdr' => 'Custom', 'fixed' => 'Custom', 'settlement' => 'T+1', 'notes' => 'BaaS stack, negotiated rates'],
    ];
    ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Partner Pricing Reference</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Partner</th><th class="px-4 py-3 text-left">MDR</th><th class="px-4 py-3 text-left">Fixed Fee</th><th class="px-4 py-3 text-left">Settlement</th><th class="px-4 py-3 text-left">Notes</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($pricing as $key => $p): ?>
                <tr>
                    <td class="px-4 py-3"><?= e($registry[$key]['name'] ?? ucfirst($key)) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($p['mdr']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($p['fixed']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($p['settlement']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e($p['notes']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
