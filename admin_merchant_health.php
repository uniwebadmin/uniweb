<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'risk']);
if (!function_exists('getMerchantHealthRanking') && is_file(__DIR__ . '/includes/merchant_health.php')) {
    require_once __DIR__ . '/includes/merchant_health.php';
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'recalculate') {
        $count = recalculateAllHealthScores();
        flash('success', "Recalculated health scores for {$count} merchants.");
        redirect('admin_merchant_health.php');
    }
}

$ranking = getMerchantHealthRanking(50);
$distribution = getHealthScoreDistribution();
$avgScore = 0;
if (!empty($ranking)) {
    $scores = array_column($ranking, 'health_score');
    $avgScore = round(array_sum($scores) / count($scores), 1);
}

$pageTitle = 'Merchant Health Score';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">KYC quality + dispute rate + volume + settlement + support</p>
        <form method="POST" class="inline-block">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="recalculate">
            <button type="submit" class="btn-primary px-4 py-2 text-xs">Recalculate All</button>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Average Score</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= $avgScore ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Excellent (80+)</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= $distribution['excellent'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Good (60-79)</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= $distribution['good'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Fair (40-59)</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= $distribution['fair'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Poor (20-39)</p><p class="text-2xl font-bold text-orange-400 mt-1"><?= $distribution['poor'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Critical (<20)</p><p class="text-2xl font-bold text-red-400 mt-1"><?= $distribution['critical'] ?></p></div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Merchant Health Ranking</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[900px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Merchant</th>
                <th class="px-4 py-3 text-right">Health</th>
                <th class="px-4 py-3 text-right">KYC</th>
                <th class="px-4 py-3 text-right">Disputes</th>
                <th class="px-4 py-3 text-right">Volume</th>
                <th class="px-4 py-3 text-right">Settlement</th>
                <th class="px-4 py-3 text-right">Support</th>
                <th class="px-4 py-3 text-left">Trend</th>
                <th class="px-4 py-3 text-left">Reasons</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($ranking)): ?><tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No health scores yet. Click "Recalculate All" to generate.</td></tr>
                <?php else: foreach ($ranking as $m): ?>
                <tr>
                    <td class="px-4 py-3 text-xs"><?= e($m['business_name']) ?> <span class="font-mono text-gray-500"><?= e($m['merchant_code']) ?></span></td>
                    <?php $hs = (int)$m['health_score']; $hcls = $hs >= 80 ? 'text-emerald-400' : ($hs >= 60 ? 'text-sky-400' : ($hs >= 40 ? 'text-amber-400' : ($hs >= 20 ? 'text-orange-400' : 'text-red-400'))); ?>
                    <td class="px-4 py-3 text-right <?= $hcls ?> font-bold"><?= $hs ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$m['kyc_quality_score'] ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$m['dispute_rate_score'] ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$m['volume_score'] ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$m['settlement_score'] ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$m['support_score'] ?></td>
                    <td class="px-4 py-3 text-xs"><?php $t = $m['trend'] ?? 'stable'; $tcls = $t === 'up' ? 'text-emerald-400' : ($t === 'down' ? 'text-red-400' : 'text-gray-500'); ?><span class="<?= $tcls ?>"><?= $t === 'up' ? '↑' : ($t === 'down' ? '↓' : '→') ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate"><?php $r = json_decode((string)($m['reasons'] ?? '[]'), true); echo e(implode(', ', array_slice($r ?: [], 0, 3))) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
