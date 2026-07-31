<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$db = getDB();

// Last 60 minutes, bucketed by minute — simple TPS-style view without extra tables.
$perMinute = $db->query("SELECT DATE_FORMAT(created_at, '%H:%i') AS bucket,
        COUNT(*) AS total,
        SUM(status IN ('success','paid','captured')) AS ok,
        SUM(status IN ('failed','error')) AS fail
    FROM transactions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
    GROUP BY bucket ORDER BY bucket ASC")->fetchAll();

$today = $db->query("SELECT
        COUNT(*) AS total,
        SUM(status IN ('success','paid','captured')) AS ok,
        SUM(status IN ('failed','error')) AS fail,
        SUM(status = 'pending') AS pending,
        COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN amount ELSE 0 END),0) AS volume
    FROM transactions WHERE created_at >= CURDATE()")->fetch();

$last1h = $db->query("SELECT COUNT(*) AS total,
        SUM(status IN ('success','paid','captured')) AS ok,
        SUM(status IN ('failed','error')) AS fail
    FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetch();

$totalToday = max(1, (int)$today['total']);
$successRate = round(((int)$today['ok'] / $totalToday) * 100, 1);
$failRate = round(((int)$today['fail'] / $totalToday) * 100, 1);
$tps1h = round((int)$last1h['total'] / 60, 2);

// Per-gateway/mode breakdown for today.
$byMode = $db->query("SELECT collection_mode, COUNT(*) AS total,
        SUM(status IN ('success','paid','captured')) AS ok,
        SUM(status IN ('failed','error')) AS fail
    FROM transactions WHERE created_at >= CURDATE() GROUP BY collection_mode ORDER BY total DESC")->fetchAll();

// VA health snapshot (ties into Phase 1 load-balancing).
$vaHealth = [];
try {
    $vaHealth = $db->query("SELECT merchant_id, va_number, label, status, txn_count_today, fail_count_today
        FROM merchant_virtual_accounts ORDER BY fail_count_today DESC, txn_count_today DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {
    // table may not be migrated yet
}
$disabledVaCount = 0;
try {
    $disabledVaCount = (int)$db->query("SELECT COUNT(*) FROM merchant_virtual_accounts WHERE status='disabled'")->fetchColumn();
} catch (Throwable $e) {
}

$unresolvedErrors = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;

$pageTitle = 'Transaction Monitor';
require_once __DIR__ . '/header.php';
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <a href="admin_platform_status.php" class="text-sm text-gray-400 hover:text-white">← Platform Status</a>
    <a href="admin_transaction_monitor.php" class="text-xs text-brand-400 hover:text-brand-300">Refresh</a>
</div>

<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">TPS (last 1h avg)</p>
        <p class="text-2xl font-bold text-brand-400 mt-1"><?= $tps1h ?>/min</p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Today — Total</p>
        <p class="text-2xl font-bold mt-1"><?= (int)$today['total'] ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Success rate</p>
        <p class="text-2xl font-bold text-emerald-400 mt-1"><?= $successRate ?>%</p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Fail rate</p>
        <p class="text-2xl font-bold <?= $failRate > 5 ? 'text-red-400' : 'text-amber-400' ?> mt-1"><?= $failRate ?>%</p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Unresolved errors</p>
        <p class="text-2xl font-bold <?= $unresolvedErrors > 0 ? 'text-red-400' : 'text-gray-300' ?> mt-1"><?= $unresolvedErrors ?></p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-5 py-4 border-b border-gray-800"><h2 class="font-semibold">Last 60 minutes (per-minute)</h2></div>
        <?php if (empty($perMinute)): ?>
        <div class="px-5 py-8 text-center text-sm text-gray-500">No transactions in the last hour.</div>
        <?php else: ?>
        <div class="overflow-x-auto max-h-80 overflow-y-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50 sticky top-0"><tr>
                <th class="px-4 py-2 text-left">Minute</th><th class="px-4 py-2 text-left">Total</th>
                <th class="px-4 py-2 text-left">OK</th><th class="px-4 py-2 text-left">Fail</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach (array_reverse($perMinute) as $row): ?>
                <tr>
                    <td class="px-4 py-2 font-mono text-xs"><?= e($row['bucket']) ?></td>
                    <td class="px-4 py-2"><?= (int)$row['total'] ?></td>
                    <td class="px-4 py-2 text-emerald-400"><?= (int)$row['ok'] ?></td>
                    <td class="px-4 py-2 <?= (int)$row['fail'] > 0 ? 'text-red-400' : 'text-gray-500' ?>"><?= (int)$row['fail'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
            <h2 class="font-semibold">Virtual Account health</h2>
            <a href="admin_virtual_accounts.php" class="text-xs text-brand-400 hover:text-brand-300">Manage →</a>
        </div>
        <?php if ($disabledVaCount > 0): ?>
        <div class="mx-5 mt-4 bg-red-500/10 border border-red-500/30 rounded-lg px-3 py-2 text-xs text-red-300"><?= $disabledVaCount ?> VA(s) auto-disabled after repeated failures.</div>
        <?php endif; ?>
        <?php if (empty($vaHealth)): ?>
        <div class="px-5 py-8 text-center text-sm text-gray-500">No virtual accounts provisioned yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-2 text-left">VA</th><th class="px-4 py-2 text-left">Today</th>
                <th class="px-4 py-2 text-left">Fails</th><th class="px-4 py-2 text-left">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($vaHealth as $va): ?>
                <tr>
                    <td class="px-4 py-2 font-mono text-xs"><?= e($va['va_number']) ?></td>
                    <td class="px-4 py-2"><?= (int)$va['txn_count_today'] ?></td>
                    <td class="px-4 py-2 <?= (int)$va['fail_count_today'] > 0 ? 'text-amber-400' : 'text-gray-500' ?>"><?= (int)$va['fail_count_today'] ?></td>
                    <td class="px-4 py-2"><?= statusBadge($va['status'] === 'active' ? 'active' : 'suspended') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden border border-gray-800">
    <div class="px-5 py-4 border-b border-gray-800"><h2 class="font-semibold">Today by collection mode</h2></div>
    <?php if (empty($byMode)): ?>
    <div class="px-5 py-8 text-center text-sm text-gray-500">No transactions today yet.</div>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Mode</th><th class="px-5 py-3 text-left">Total</th>
            <th class="px-5 py-3 text-left">Success</th><th class="px-5 py-3 text-left">Fail</th>
            <th class="px-5 py-3 text-left">Success %</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($byMode as $row): $t = max(1, (int)$row['total']); ?>
            <tr>
                <td class="px-5 py-3"><?= e(function_exists('collectionModeLabel') ? collectionModeLabel((string)$row['collection_mode']) : ($row['collection_mode'] ?: '—')) ?></td>
                <td class="px-5 py-3"><?= (int)$row['total'] ?></td>
                <td class="px-5 py-3 text-emerald-400"><?= (int)$row['ok'] ?></td>
                <td class="px-5 py-3 <?= (int)$row['fail'] > 0 ? 'text-red-400' : 'text-gray-500' ?>"><?= (int)$row['fail'] ?></td>
                <td class="px-5 py-3"><?= round(((int)$row['ok'] / $t) * 100, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <p class="px-5 py-3 text-xs text-gray-500 border-t border-gray-800">Volume settled today (successful only): <?= formatMoney((float)$today['volume']) ?></p>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
