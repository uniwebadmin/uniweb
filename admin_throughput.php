<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);

$db = getDB();

// Payments per minute (last 60 minutes)
$ppmData = [];
try {
    $ppmData = $db->query("SELECT
        DATE_FORMAT(created_at, '%H:%i') as minute,
        COUNT(*) as count,
        COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume
        FROM transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
        GROUP BY DATE_FORMAT(created_at, '%H:%i')
        ORDER BY minute ASC")->fetchAll();
} catch (Throwable $e) {}

// Top QR codes by transaction count (last 24h)
$topQrs = [];
try {
    $topQrs = $db->query("SELECT
        q.qr_code, q.label, q.amount, q.qr_type,
        COUNT(t.id) as txn_count,
        COALESCE(SUM(CASE WHEN t.status='success' THEN t.amount ELSE 0 END),0) as volume
        FROM merchant_qr_codes q
        LEFT JOIN transactions t ON t.qr_code_id = q.id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        WHERE q.merchant_id IS NOT NULL
        GROUP BY q.id
        HAVING txn_count > 0
        ORDER BY txn_count DESC
        LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// Top VAs by payment count (last 24h)
$topVas = [];
try {
    $topVas = $db->query("SELECT
        va.va_number, va.label, m.business_name,
        COUNT(t.id) as txn_count,
        COALESCE(SUM(CASE WHEN t.status='success' THEN t.amount ELSE 0 END),0) as volume
        FROM merchant_virtual_accounts va
        LEFT JOIN transactions t ON t.va_number = va.va_number AND t.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LEFT JOIN merchants m ON m.id = va.merchant_id
        GROUP BY va.id
        HAVING txn_count > 0
        ORDER BY txn_count DESC
        LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// Current throughput
$currentPpm = 0;
try {
    $currentPpm = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetchColumn();
} catch (Throwable $e) {}

// Peak today
$peakPpm = 0;
try {
    $peakPpm = (int)$db->query("SELECT max_count FROM (
        SELECT COUNT(*) as max_count
        FROM transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY FLOOR(UNIX_TIMESTAMP(created_at)/60)
        ORDER BY max_count DESC LIMIT 1
    ) t")->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = 'Throughput Monitor';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <p class="text-sm text-gray-400">Real-time payments/minute monitoring per QR and VA</p>

    <div class="grid sm:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Current (last 1 min)</p><p class="text-3xl font-bold text-brand-400 mt-1"><?= $currentPpm ?></p><p class="text-xs text-gray-500 mt-1">payments/min</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Peak today</p><p class="text-3xl font-bold text-amber-400 mt-1"><?= $peakPpm ?></p><p class="text-xs text-gray-500 mt-1">payments/min</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Active QR codes</p><p class="text-3xl font-bold text-emerald-400 mt-1"><?= count($topQrs) ?></p><p class="text-xs text-gray-500 mt-1">with traffic in 24h</p></div>
    </div>

    <?php if (!empty($ppmData)): ?>
    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Payments per Minute (last 60 min)</h3>
        <div class="flex items-end gap-0.5 h-32 overflow-x-auto">
            <?php
            $maxCount = max(array_column($ppmData, 'count') ?: [1]);
            foreach ($ppmData as $d):
                $h = $maxCount > 0 ? max(2, ($d['count'] / $maxCount) * 100) : 2;
            ?>
            <div class="flex flex-col items-center gap-1" style="min-width: 4px">
                <div class="w-1 bg-brand-500/40 rounded-t" style="height: <?= $h ?>%"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-between text-[10px] text-gray-500 mt-2">
            <span><?= e($ppmData[0]['minute'] ?? '') ?></span>
            <span><?= e(end($ppmData)['minute'] ?? '') ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Top QR Codes by Traffic (24h)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[600px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">QR Code</th><th class="px-4 py-3 text-left">Label</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-right">Txns</th><th class="px-4 py-3 text-right">Volume</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($topQrs)): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No QR traffic in last 24h.</td></tr>
                <?php else: foreach ($topQrs as $qr): ?>
                <tr><td class="px-4 py-3 font-mono text-xs"><?= e($qr['qr_code']) ?></td><td class="px-4 py-3 text-xs"><?= e($qr['label']) ?></td><td class="px-4 py-3 text-xs"><?= e($qr['qr_type']) ?></td><td class="px-4 py-3 text-right text-xs"><?= number_format((int)$qr['txn_count']) ?></td><td class="px-4 py-3 text-right text-xs text-emerald-400"><?= formatMoney((float)$qr['volume']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Top Virtual Accounts by Traffic (24h)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[600px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">VA Number</th><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-right">Txns</th><th class="px-4 py-3 text-right">Volume</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($topVas)): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No VA traffic in last 24h.</td></tr>
                <?php else: foreach ($topVas as $va): ?>
                <tr><td class="px-4 py-3 font-mono text-xs"><?= e($va['va_number']) ?></td><td class="px-4 py-3 text-xs"><?= e($va['business_name'] ?? '—') ?></td><td class="px-4 py-3 text-right text-xs"><?= number_format((int)$va['txn_count']) ?></td><td class="px-4 py-3 text-right text-xs text-emerald-400"><?= formatMoney((float)$va['volume']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
