<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
requireLogin();
requireMerchantTeamCapability('settings');

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensureMethodRequestSchema();
if (function_exists('ensureSettlementEngine')) {
    ensureSettlementEngine();
}

$map = merchantMethodRequestMap($merchantId);
$enabled = getMerchantEnabledMethods($merchant);
$unlocked = in_array('instant_settlement', $enabled, true);
$status = $unlocked ? 'approved' : (string)($map['instant_settlement'] ?? 'not_requested');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '') && $unlocked) {
    $interval = max(15, min(1440, (int)($_POST['batch_interval_minutes'] ?? 15)));
    $rail = trim((string)($_POST['settlement_rail'] ?? 'platform_pg'));
    $allowedRails = ['platform_pg', 'wallet', 'axis_va'];
    if (!in_array($rail, $allowedRails, true)) {
        $rail = 'platform_pg';
    }
    try {
        getDB()->prepare(
            "UPDATE merchants SET settlement_mode='scheduled', settlement_rail=?, batch_interval_minutes=?, settlement_use_platform_default=0, next_batch_at=? WHERE id=?"
        )->execute([
            $rail,
            $interval,
            date('Y-m-d H:i:s', time() + ($interval * 60)),
            $merchantId,
        ]);
        flash('success', 'Faster batch timing saved. Live bank payout still waits for partner keys — this is not instant forever.');
    } catch (Throwable $e) {
        try {
            getDB()->prepare("UPDATE merchants SET settlement_mode='scheduled', batch_interval_minutes=? WHERE id=?")
                ->execute([$interval, $merchantId]);
            flash('success', 'Settlement interval saved. Bank transfer still needs partner rails.');
        } catch (Throwable $e2) {
            flash('error', 'Could not save settings.');
        }
    }
    redirect('merchant_instant_settlement.php');
}

$merchant = getMerchant();
$prefs = function_exists('getMerchantSettlementPrefs') ? getMerchantSettlementPrefs($merchant) : [
    'mode' => $merchant['settlement_mode'] ?? 'manual',
    'interval_minutes' => (int)($merchant['batch_interval_minutes'] ?? 120),
    'rail' => $merchant['settlement_rail'] ?? 'platform_pg',
];
$delayMin = function_exists('merchantSettlementDelayMinutes') ? merchantSettlementDelayMinutes($merchant) : (int)($prefs['interval_minutes'] ?? 120);
$keysReady = function_exists('isGatewayConfigured')
    && (isGatewayConfigured('razorpay') || isGatewayConfigured('payu') || isGatewayConfigured('cashfree') || isGatewayConfigured('axis'));

$pageTitle = 'Faster Settlement Batches';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
        <p class="font-semibold text-amber-200">Not a live instant bank payout by itself</p>
        <p class="text-xs text-amber-100/80 mt-1 leading-relaxed">
            This page only shortens UniWeb’s settlement <strong>batch schedule</strong> after partner unlock.
            Money reaches your bank only when partner keys, cron, and the chosen rail are ready.
            We do not advertise “instant forever” or guaranteed same-minute bank credit.
        </p>
    </div>
    <div class="glass rounded-xl p-6">
        <h1 class="text-xl font-bold mb-2">Faster Settlement Batches</h1>
        <p class="text-sm text-gray-500 mb-4">Optional short schedule (e.g. every 15–60 min) after Instant Settlement is unlocked. Until partner keys are live, batches may queue without a bank transfer.</p>

        <div class="rounded-xl border border-gray-800 bg-dark-900/40 p-4 mb-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Access status</p>
            <p class="font-semibold <?= $unlocked ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= e(methodRequestStatusLabel($status === 'not_requested' ? 'pending' : $status)) ?>
            </p>
            <?php if (!$unlocked): ?>
            <p class="text-xs text-gray-500 mt-2">Waiting on admin → partner approval. Settings stay locked until Instant Settlement is enabled for your account.</p>
            <?php else: ?>
            <p class="text-xs text-emerald-400/80 mt-2">Unlocked. Choose how often UniWeb closes your settlement batch — not how fast the partner bank pays out.</p>
            <?php endif; ?>
            <p class="text-xs mt-2 <?= $keysReady ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= $keysReady
                    ? 'Partner keys detected — live transfer can run when cron + rail allow.'
                    : 'Partner keys pending — batches can queue; bank transfer waits for keys.' ?>
            </p>
        </div>

        <?php if ($unlocked): ?>
        <form method="POST" class="space-y-4 border-t border-gray-800 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400">Batch every (minutes)</label>
                <select name="batch_interval_minutes" class="input-field mt-1">
                    <?php foreach ([15 => '15 min (fastest batch)', 30 => '30 min', 60 => '1 hour', 120 => '2 hours'] as $m => $lab): ?>
                    <option value="<?= $m ?>" <?= (int)($prefs['interval_minutes'] ?? 15) === $m ? 'selected' : '' ?>><?= e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400">Settlement rail</label>
                <select name="settlement_rail" class="input-field mt-1">
                    <?php foreach (['platform_pg' => 'Platform PG / partner payout', 'wallet' => 'Settlement balance ledger only', 'axis_va' => 'Axis VA'] as $rk => $rl): ?>
                    <option value="<?= e($rk) ?>" <?= ($prefs['rail'] ?? '') === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="text-xs text-gray-500">Vertical delay guide: ~<?= (int)$delayMin ?> minutes (business type). Your batch interval above controls the cron window.</p>
            <?php if (!empty($merchant['next_batch_at'])): ?>
            <p class="text-xs text-sky-400">Next batch window: <?= e(formatDate($merchant['next_batch_at'])) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn-primary px-5 py-2.5">Save batch schedule</button>
            <a href="merchant_settlement_settings.php" class="block text-sm text-sky-400 mt-2">Full settlement settings →</a>
        </form>
        <?php endif; ?>
    </div>
    <a href="collection_settings.php" class="inline-block text-sm text-sky-400">← Collection Settings</a>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
