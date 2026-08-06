<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);

// POST: reset circuit breaker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'reset' && isset($_POST['gateway'])) {
        $ok = resetCircuitBreaker(trim($_POST['gateway']));
        flash($ok ? 'success' : 'error', $ok ? 'Circuit breaker reset.' : 'Failed to reset.');
        redirect('admin_circuit_breaker.php');
    }
}

$cbStatus = getCircuitBreakerStatus();
$registry = getPartnerRegistry();

$pageTitle = 'Circuit Breaker';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <p class="text-sm text-gray-400">Gateway circuit breaker states — auto-open on repeated 429/5xx, auto-recovery with probe</p>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Gateway Circuit States</h2></div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($cbStatus as $gw => $cb):
                $reg = $registry[$gw] ?? null;
                $name = $reg['name'] ?? ucfirst($gw);
                $icon = $reg['icon'] ?? '';
                $state = $cb['state'];
                $scls = match($state) {
                    'closed' => 'text-emerald-400 bg-emerald-500/10',
                    'open' => 'text-red-400 bg-red-500/10',
                    'half_open' => 'text-amber-400 bg-amber-500/10',
                };
                $slabel = match($state) {
                    'closed' => 'CLOSED (healthy)',
                    'open' => 'OPEN (down — fail fast)',
                    'half_open' => 'HALF-OPEN (probing)',
                };
            ?>
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-xl"><?= e($icon) ?></span>
                    <div>
                        <p class="text-sm font-medium"><?= e($name) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Failures: <?= (int)$cb['failure_count'] ?>
                            <?php if ($cb['last_failure_at']): ?> · Last: <?= formatDate($cb['last_failure_at']) ?><?php endif; ?>
                            <?php if ($cb['opened_at']): ?> · Opened: <?= formatDate($cb['opened_at']) ?><?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs px-3 py-1.5 rounded-full font-semibold <?= $scls ?>"><?= $slabel ?></span>
                    <?php if ($state !== 'closed'): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="gateway" value="<?= e($gw) ?>">
                        <button type="submit" class="text-xs text-sky-400 hover:underline">Reset</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-3">How it works</h3>
        <ul class="space-y-2 text-sm text-gray-400">
            <li><strong class="text-emerald-400">CLOSED</strong> — Normal operation. Requests pass through. Failures counted in 5-min window.</li>
            <li><strong class="text-red-400">OPEN</strong> — 5+ failures in 5 min. All requests fail fast (no outbound call). 60s cooldown.</li>
            <li><strong class="text-amber-400">HALF-OPEN</strong> — After cooldown, one probe request allowed. Success → CLOSED, failure → OPEN.</li>
            <li class="pt-2 text-xs">Smart routing automatically diverts traffic to healthy gateways when a circuit is open.</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
