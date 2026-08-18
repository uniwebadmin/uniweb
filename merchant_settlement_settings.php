<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settle');
    saveMerchantSettlementPrefs($merchantId, [
        'use_platform_default' => !empty($_POST['use_platform_default']),
        'mode' => $_POST['settlement_mode'] ?? 'manual',
        'rail' => $_POST['settlement_rail'] ?? 'wallet',
        'settlement_cycle' => $_POST['settlement_cycle'] ?? '',
        'interval_minutes' => (int)($_POST['batch_interval_minutes'] ?? 0),
    ]);
    flash('success', 'Settlement settings saved.');
    redirect('merchant_settlement_settings.php');
}

$prefs = getMerchantSettlementPrefs($merchant);
$platform = getPlatformSettlementDefaults();
$intervals = getSettlementBatchIntervals();
$cycles = getSettlementCycleOptions();
$rails = getSettlementRails();
$modes = getSettlementModes();
$collectionMode = getMerchantCollectionMode($merchant);
// Direct UPI (P2M): no rail picker — funds already land in merchant bank UPI.
// Partner PG / Axis: auto-map rail from collection mode (no free-form rail choice).
$autoRail = match ($collectionMode) {
    'axis_va' => 'axis_va',
    'payu_split', 'razorpay_route', 'cashfree_route', 'platform_pg' => 'platform_pg',
    default => null,
};
$showRailPicker = $autoRail !== null;

$pageTitle = 'Settlement Settings';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="settlements.php" class="text-sm text-gray-400 hover:text-white">← <?= __('settlements') ?></a>
    </div>

    <div class="glass rounded-xl p-6 mb-6 border border-violet-500/20">
        <h1 class="text-xl font-bold mb-1"><?= $pageTitle ?></h1>
        <p class="text-sm text-gray-500">Choose scheduled batching or manual settlement. Cycle follows Admin default (T+0 / T+1 / T+2) unless you override.</p>
    </div>

    <div class="glass rounded-xl p-4 mb-6 border border-sky-500/20 text-sm">
        <p class="font-medium text-sky-300">Your settlement status</p>
        <p class="text-xs text-gray-400 mt-1"><?= e($prefs['status_line']) ?></p>
        <p class="text-xs text-gray-500 mt-2">Platform default: <strong class="text-gray-300"><?= e($platform['cycle_label']) ?></strong><?= $prefs['use_platform_default'] ? ' (you are using this)' : ' · you overrode cycle to ' . e($prefs['cycle']) ?>.</p>
        <?php if ($prefs['mode'] === 'scheduled' && !empty($prefs['next_batch_at'])): ?>
        <p class="text-xs text-emerald-400 mt-1">Next batch: <?= e(formatDate($prefs['next_batch_at'])) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$showRailPicker): ?>
    <div class="glass rounded-xl p-5 mb-6 border border-emerald-500/25 bg-emerald-500/5">
        <p class="text-sm font-medium text-emerald-300">Direct UPI (P2M)</p>
        <p class="text-xs text-gray-400 mt-1">Your collection mode settles straight to your UPI-linked bank account. UniWeb does not hold or re-route these funds — no settlement rail to configure. Use Settlements only for wallet / PG balances if you later enable a partner gateway.</p>
    </div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-xl p-6 space-y-5 border border-gray-800">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="settlement_rail" value="<?= e($showRailPicker ? $autoRail : 'wallet') ?>">

        <label class="flex items-start gap-3 p-4 rounded-xl border border-gray-800 bg-dark-900/40 cursor-pointer">
            <input type="checkbox" name="use_platform_default" value="1" <?= $prefs['use_platform_default'] ? 'checked' : '' ?> class="mt-1 rounded border-gray-600" id="use-platform">
            <div>
                <p class="font-medium text-sm">Use platform defaults</p>
                <p class="text-xs text-gray-500 mt-1">
                    Current:
                    <?= e($platform['cycle']) ?> ·
                    <?= e($modes[$platform['mode']] ?? $platform['mode']) ?> ·
                    <?= e($rails[$platform['rail']]['label'] ?? $platform['rail']) ?>
                </p>
            </div>
        </label>

        <div id="merchant-prefs" class="space-y-4 <?= $prefs['use_platform_default'] ? 'opacity-50 pointer-events-none' : '' ?>">
            <div>
                <label class="text-sm text-gray-400">Settlement Mode</label>
                <select name="settlement_mode" class="input-field mt-1">
                    <?php foreach ($modes as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $prefs['mode'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($showRailPicker): ?>
            <div>
                <label class="text-sm text-gray-400">Settlement Rail</label>
                <p class="input-field mt-1 opacity-90"><?= e($rails[$autoRail]['label'] ?? $autoRail) ?></p>
                <p class="text-[11px] text-gray-600 mt-1">Auto-selected from your collection mode (<?= e(collectionModeLabel($collectionMode, true)) ?>). Other rails are managed by UniWeb.</p>
            </div>
            <?php endif; ?>
            <div>
                <label class="text-sm text-gray-400">Settlement cycle</label>
                <select name="settlement_cycle" class="input-field mt-1">
                    <?php foreach ($cycles as $code => $meta): ?>
                    <option value="<?= e($code) ?>" <?= $prefs['cycle'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-600 mt-1">Same engine as Admin — T+0 / T+1 / T+2. No separate settlement product.</p>
            </div>
            <input type="hidden" name="batch_interval_minutes" value="<?= (int)$prefs['interval_minutes'] ?>">
        </div>

        <button type="submit" class="btn-primary w-full py-3 font-semibold"><?= __('save') ?></button>
    </form>
</div>

<script>
document.getElementById('use-platform')?.addEventListener('change', function(){
    const el = document.getElementById('merchant-prefs');
    if (!el) return;
    el.classList.toggle('opacity-50', this.checked);
    el.classList.toggle('pointer-events-none', this.checked);
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
