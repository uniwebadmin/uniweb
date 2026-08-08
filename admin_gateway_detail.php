<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/includes/partner_engine.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

$gatewayId = (int)($_GET['id'] ?? 0);
if ($gatewayId <= 0) {
    flash('error', 'Invalid gateway ID.');
    redirect('admin_gateway_registry.php');
}

$gateway = getGatewayById($gatewayId);
if (!$gateway) {
    flash('error', 'Gateway not found.');
    redirect('admin_gateway_registry.php');
}

$partnerKey = $gateway['gateway_key'];
$partnerRegistry = getPartnerRegistry();
$partner = $partnerRegistry[$partnerKey] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_keys') {
        $keys = $_POST['keys'] ?? [];
        $result = saveGatewayConfig($gatewayId, $keys);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'API keys saved for ' . e($gateway['gateway_name']) : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId);
    }

    if ($action === 'activate') {
        $result = activateGatewayForAllMerchants($gatewayId);
        if ($result['ok']) {
            flash('success', $result['gateway_name'] . ' activated! Payment method added to ' . $result['merchants'] . ' merchants (OFF by default — they can toggle ON).');
        } else {
            flash('error', $result['error'] ?? 'Activation failed.');
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId);
    }

    if ($action === 'deactivate') {
        $result = deactivateGateway($gatewayId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Gateway deactivated.' : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId);
    }
}

$configKeys = $partner['config_keys'] ?? [];
$testResult = $partner ? partnerTestConnection($partnerKey) : ['ok' => false, 'message' => 'No partner config.'];
$configMeta = json_decode($gateway['config_json'] ?? '{}', true) ?: [];
$isActive = (int)$gateway['is_active'] === 1;

$pageTitle = $gateway['gateway_name'] . ' — Gateway Detail';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="flex items-center gap-3 mb-2">
        <a href="admin_gateway_registry.php" class="text-sm text-gray-400 hover:text-white">← Gateway Registry</a>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg"><?= e($gateway['gateway_name']) ?></h2>
                <p class="text-xs text-gray-500 font-mono mt-1"><?= e($gateway['gateway_key']) ?></p>
                <div class="flex gap-2 mt-2">
                    <?php if ((int)$gateway['supports_collection']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_payout']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_refund']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_recurring']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                </div>
            </div>
            <span class="text-xs px-3 py-1.5 rounded-full <?= $isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>">
                <?= $isActive ? '● Active' : '○ Inactive' ?>
            </span>
        </div>

        <?php if (!empty($gateway['webhook_url'])): ?>
        <div class="bg-dark-900/50 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-500">Webhook URL</p>
            <p class="text-xs font-mono text-sky-400 break-all mt-1"><?= e($gateway['webhook_url']) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($configKeys)): ?>
        <div class="bg-dark-900/50 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-500">Connection Status</p>
            <p class="text-sm mt-1 <?= $testResult['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= e($testResult['message']) ?></p>
        </div>
        <?php endif; ?>

        <div class="flex flex-wrap gap-3">
            <?php if (!$isActive): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="activate">
                <button type="submit" class="btn-primary px-6 py-2.5" onclick="return confirm('Activate <?= e($gateway['gateway_name']) ?>? This will add its payment method to all merchants (OFF by default).')">⚡ Activate Gateway</button>
            </form>
            <?php else: ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="deactivate">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/30" onclick="return confirm('Deactivate this gateway? Merchants will no longer see this payment method.')">Deactivate</button>
            </form>
            <?php endif; ?>
            <?php if ($partner && $partner['docs']): ?>
            <a href="<?= e($partner['docs']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">API Docs ↗</a>
            <?php endif; ?>
            <?php if ($partner && $partner['dashboard']): ?>
            <a href="<?= e($partner['dashboard']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">Dashboard ↗</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($configKeys)): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">API Credentials</h3>
        <p class="text-xs text-gray-500 mb-4">Paste API keys from <?= e($gateway['gateway_name']) ?>. Save first, then Activate above.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_keys">
            <?php foreach ($configKeys as $key => $meta): ?>
            <div>
                <label class="text-sm text-gray-400"><?= e($meta['label']) ?></label>
                <?php if (($meta['type'] ?? 'text') === 'select'): ?>
                <select name="keys[<?= e($key) ?>]" class="input-field mt-1">
                    <?php foreach ($meta['options'] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= getSetting($key, '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="<?= e($meta['type'] ?? 'text') ?>" name="keys[<?= e($key) ?>]" value="<?= e(getSetting($key, '')) ?>" class="input-field mt-1 font-mono text-xs" autocomplete="off">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Keys</button>
        </form>
        <?php if (!empty($configMeta['keys_saved_at'])): ?>
        <p class="text-[11px] text-gray-600 mt-3">Last saved: <?= e($configMeta['keys_saved_at']) ?> · <?= (int)($configMeta['keys_count'] ?? 0) ?> keys</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">API Credentials</h3>
        <p class="text-xs text-gray-500">This gateway has no partner config keys defined. You can still activate it to add its payment method to merchants.</p>
    </div>
    <?php endif; ?>

    <?php if ($partner && !empty($partner['checklist'])): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-3">Onboarding Checklist</h3>
        <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">
            <?php foreach ($partner['checklist'] as $step): ?>
            <li><?= e($step) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php';
