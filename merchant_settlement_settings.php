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
        'interval_minutes' => (int)($_POST['batch_interval_minutes'] ?? 120),
    ]);
    flash('success', 'Settlement settings saved.');
    redirect('merchant_settlement_settings.php');
}

$prefs = getMerchantSettlementPrefs($merchant);
$platform = getPlatformSettlementDefaults();
$intervals = getSettlementBatchIntervals();
$rails = getSettlementRails();
$modes = getSettlementModes();

$pageTitle = 'Settlement Settings';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="settlements.php" class="text-sm text-gray-400 hover:text-white">← <?= __('settlements') ?></a>
    </div>

    <div class="glass rounded-xl p-6 mb-6 border border-violet-500/20">
        <h1 class="text-xl font-bold mb-1"><?= $pageTitle ?></h1>
        <p class="text-sm text-gray-500">Choose scheduled batching or manual settlement.</p>
    </div>

    <form method="POST" class="glass rounded-xl p-6 space-y-5 border border-gray-800">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <label class="flex items-start gap-3 p-4 rounded-xl border border-gray-800 bg-dark-900/40 cursor-pointer">
            <input type="checkbox" name="use_platform_default" value="1" <?= $prefs['use_platform_default'] ? 'checked' : '' ?> class="mt-1 rounded border-gray-600" id="use-platform">
            <div>
                <p class="font-medium text-sm">Use platform defaults</p>
                <p class="text-xs text-gray-500 mt-1">
                    Current:
                    <?= e($modes[$platform['mode']] ?? $platform['mode']) ?> ·
                    <?= e($intervals[$platform['interval_minutes']] ?? '') ?> ·
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
            <div>
                <label class="text-sm text-gray-400">Settlement Rail</label>
                <select name="settlement_rail" class="input-field mt-1">
                    <?php foreach ($rails as $k => $r): ?>
                    <option value="<?= $k ?>" <?= $prefs['rail'] === $k ? 'selected' : '' ?>><?= e($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-600 mt-1"><?= e($rails[$prefs['rail']]['short'] ?? '') ?></p>
            </div>
            <div>
                <label class="text-sm text-gray-400">Batch Interval</label>
                <select name="batch_interval_minutes" class="input-field mt-1">
                    <?php foreach ($intervals as $mins => $label): ?>
                    <option value="<?= $mins ?>" <?= (int)$prefs['interval_minutes'] === $mins ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
