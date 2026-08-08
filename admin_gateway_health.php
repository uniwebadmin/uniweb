<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/includes/partner_engine.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

$health = getGatewayHealthSummary();
$pageTitle = 'Gateway Health Monitor';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg">Gateway Health Monitor</h2>
                <p class="text-xs text-gray-500 mt-1">Real-time success rates, response times, and failover status for all gateways.</p>
            </div>
            <div class="flex gap-3">
                <?php
                $healthy = 0; $degraded = 0; $down = 0;
                foreach ($health as $h) {
                    if ((int)$h['is_active'] !== 1) continue;
                    $st = $h['status'] ?? 'healthy';
                    if ($st === 'healthy') $healthy++;
                    elseif ($st === 'degraded') $degraded++;
                    else $down++;
                }
                ?>
                <div class="text-center"><p class="text-2xl font-bold text-emerald-400"><?= $healthy ?></p><p class="text-[10px] text-gray-500 uppercase">Healthy</p></div>
                <div class="text-center"><p class="text-2xl font-bold text-amber-400"><?= $degraded ?></p><p class="text-[10px] text-gray-500 uppercase">Degraded</p></div>
                <div class="text-center"><p class="text-2xl font-bold text-red-400"><?= $down ?></p><p class="text-[10px] text-gray-500 uppercase">Down</p></div>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="font-semibold">Gateway Status</h3>
        </div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($health as $h):
                $isActive = (int)$h['is_active'] === 1;
                $total = (int)$h['total_attempts'];
                $success = (int)$h['success_count'];
                $fail = (int)$h['fail_count'];
                $rate = $total > 0 ? round(($success / $total) * 100, 1) : null;
                $avgMs = $total > 0 ? round((int)$h['total_response_ms'] / $total) : 0;
                $st = $h['status'] ?? 'healthy';
                $stColor = match($st) { 'healthy' => 'emerald', 'degraded' => 'amber', default => 'red' };
            ?>
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-2 h-10 rounded-full bg-<?= $stColor ?>-500/40 flex-shrink-0"></div>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-gray-200"><?= e($h['gateway_name']) ?></p>
                            <?php if (!$isActive): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-700/50 text-gray-400">Inactive</span><?php endif; ?>
                            <?php if ($isActive): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-<?= $stColor ?>-500/20 text-<?= $stColor ?>-400"><?= ucfirst($st) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 font-mono mt-0.5"><?= e($h['gateway_key']) ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-6 text-sm">
                    <?php if ($total > 0): ?>
                    <div class="text-center">
                        <p class="font-semibold <?= $rate !== null && $rate >= 80 ? 'text-emerald-400' : ($rate !== null && $rate >= 50 ? 'text-amber-400' : 'text-red-400') ?>"><?= $rate ?>%</p>
                        <p class="text-[10px] text-gray-500">Success</p>
                    </div>
                    <div class="text-center">
                        <p class="text-gray-300"><?= $avgMs ?>ms</p>
                        <p class="text-[10px] text-gray-500">Avg Response</p>
                    </div>
                    <div class="text-center">
                        <p class="text-gray-300"><?= $total ?></p>
                        <p class="text-[10px] text-gray-500">Attempts</p>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-gray-600">No attempts yet</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-xs text-gray-500">
            <strong class="text-gray-400">How smart routing works:</strong> When a customer pays, the system tries the healthiest gateway first (highest success rate + fastest response). If it fails, it automatically falls back to the next best gateway. Gateways with success rate below 50% are marked "Down" and skipped. Gateways between 50-80% are "Degraded" and tried last.
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php';
