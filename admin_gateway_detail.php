<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/includes/partner_engine.php';
}
if (!function_exists('ensurePartnerControlTables')) {
    require_once __DIR__ . '/includes/partner_control.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

$gatewayId = (int)($_GET['id'] ?? 0);
$partnerKeyParam = trim((string)($_GET['partner'] ?? ''));
$activeTab = trim((string)($_GET['tab'] ?? 'keys'));

if ($gatewayId <= 0 && $partnerKeyParam !== '') {
    $partnerRegistryTemp = getPartnerRegistry();
    if (isset($partnerRegistryTemp[$partnerKeyParam])) {
        $allGws = getRegisteredGateways();
        foreach ($allGws as $ag) {
            if ($ag['gateway_key'] === $partnerKeyParam) {
                $gatewayId = (int)$ag['id'];
                break;
            }
        }
    }
}

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
ensurePartnerControlTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_keys') {
        $keys = $_POST['keys'] ?? [];
        $env = trim((string)($_POST['env'] ?? 'test'));
        $configKeys = $partner['config_keys'] ?? [];
        $last4 = savePartnerCredentials($partnerKey, $env, $keys, $configKeys);
        $result = saveGatewayConfig($gatewayId, $keys);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'API keys saved for ' . e($gateway['gateway_name']) . " (env: {$env}" . ($last4 ? ", last4: ***{$last4}" : '') . ')' : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=keys');
    }

    if ($action === 'activate') {
        $result = activateGatewayForAllMerchants($gatewayId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['gateway_name'] . ' activated!' : ($result['error'] ?? 'Activation failed.'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=' . $activeTab);
    }

    if ($action === 'deactivate') {
        $result = deactivateGateway($gatewayId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Partner deactivated.' : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=' . $activeTab);
    }

    if ($action === 'toggle_method') {
        $method = trim((string)($_POST['method'] ?? ''));
        $enabled = isset($_POST['enabled']);
        $priority = (int)($_POST['priority'] ?? 50);
        $minAmt = (float)($_POST['min_amt'] ?? 0);
        $maxAmt = (float)($_POST['max_amt'] ?? 0);
        $ok = togglePartnerMethod($partnerKey, $method, $enabled, $priority, $minAmt, $maxAmt);
        flash($ok ? 'success' : 'error', $ok ? "Method {$method} " . ($enabled ? 'enabled' : 'disabled') . " for {$partnerKey}" : 'Failed');
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=methods');
    }

    if ($action === 'save_reason_map') {
        $rawCode = trim((string)($_POST['raw_code'] ?? ''));
        $msgEn = trim((string)($_POST['msg_en'] ?? ''));
        $msgHi = trim((string)($_POST['msg_hi'] ?? ''));
        if ($rawCode !== '') {
            $ok = saveReasonMap($partnerKey, $rawCode, $msgEn, $msgHi);
            flash($ok ? 'success' : 'error', $ok ? 'Reason map saved' : 'Failed');
        } else {
            flash('error', 'Raw code is required');
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=logs');
    }
}

$configKeys = $partner['config_keys'] ?? [];
$testResult = $partner ? partnerTestConnection($partnerKey) : ['ok' => false, 'message' => 'No partner config.'];
$configMeta = json_decode($gateway['config_json'] ?? '{}', true) ?: [];
$isActive = (int)$gateway['is_active'] === 1;
$credStatus = getPartnerCredentialStatus($partnerKey);
$partnerMethods = getPartnerMethods($partnerKey);
$apiLogs = partnerGetRecentLogs($partnerKey, 30);
$reasonMaps = getPartnerReasonMaps($partnerKey);

$methodLabels = [
    'upi' => 'UPI', 'credit_card' => 'Credit Card', 'debit_card' => 'Debit Card',
    'netbanking' => 'Net Banking', 'emi' => 'EMI',
    'emandate_upi' => 'E-Mandate UPI', 'emandate_card' => 'E-Mandate Card', 'emandate_nb' => 'E-Mandate NB',
];
$tabs = ['keys' => 'Keys', 'methods' => 'Methods', 'webhooks' => 'Webhooks', 'test' => 'Test', 'logs' => 'Logs'];

$pageTitle = $gateway['gateway_name'] . ' — Partner Detail';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="flex items-center gap-3 mb-2">
        <a href="admin_gateway_registry.php" class="text-sm text-gray-400 hover:text-white">← Partner Registry</a>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg"><?= e($partner['name'] ?? $gateway['gateway_name']) ?> <?= e($partner['icon'] ?? '') ?></h2>
                <p class="text-xs text-gray-500 font-mono mt-1"><?= e($partnerKey) ?></p>
                <div class="flex gap-2 mt-2">
                    <?php if ((int)$gateway['supports_collection']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_payout']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_refund']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                    <?php if ((int)$gateway['supports_recurring']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                </div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <span class="text-xs px-3 py-1.5 rounded-full <?= $isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>">
                    <?= $isActive ? '● Active' : '○ Inactive' ?>
                </span>
                <div class="flex gap-1">
                    <?php if ($credStatus['test']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Test ***<?= e($credStatus['test_last4']) ?></span><?php endif; ?>
                    <?php if ($credStatus['live']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Live ***<?= e($credStatus['live_last4']) ?></span><?php endif; ?>
                    <?php if (!$credStatus['test'] && !$credStatus['live']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400">No Keys</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-3">
            <?php if (!$isActive): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="activate">
                <button type="submit" class="btn-primary px-6 py-2.5" onclick="return confirm('Activate <?= e($gateway['gateway_name']) ?>?')">⚡ Activate</button>
            </form>
            <?php else: ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="deactivate">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/30" onclick="return confirm('Deactivate this partner?')">Deactivate</button>
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

    <div class="flex gap-1 border-b border-gray-800">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=<?= $tabKey ?>" class="px-4 py-2.5 text-sm font-medium <?= $activeTab === $tabKey ? 'text-brand-400 border-b-2 border-brand-500' : 'text-gray-400 hover:text-gray-200' ?>"><?= $tabLabel ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($activeTab === 'keys'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">API Credentials</h3>
        <p class="text-xs text-gray-500 mb-4">Secrets are encrypted at rest. Only last4 is shown after save. Leave password fields blank to keep current value.</p>
        <?php if (!empty($configKeys)): ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_keys">
            <input type="hidden" name="env" value="test">
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
                <input type="<?= e($meta['type'] ?? 'text') ?>" name="keys[<?= e($key) ?>]" value="" placeholder="<?= ($meta['type'] ?? '') === 'password' ? '•••• (leave blank to keep current)' : '' ?>" class="input-field mt-1 font-mono text-xs" autocomplete="off">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Keys</button>
        </form>
        <?php if (!empty($configMeta['keys_saved_at'])): ?>
        <p class="text-[11px] text-gray-600 mt-3">Last saved: <?= e($configMeta['keys_saved_at']) ?> · <?= (int)($configMeta['keys_count'] ?? 0) ?> keys</p>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-xs text-gray-500">This partner has no config keys defined.</p>
        <?php endif; ?>
    </div>
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

    <?php elseif ($activeTab === 'methods'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Payment Methods</h3>
        <p class="text-xs text-gray-500 mb-4">Enable/disable methods for <?= e($gateway['gateway_name']) ?>. Only enabled methods appear at checkout. Priority: lower = higher preference.</p>
        <div class="space-y-3">
            <?php if (empty($partnerMethods)): ?>
            <p class="text-sm text-gray-500 py-4 text-center">No methods seeded. Run sync from Partner Registry.</p>
            <?php else: foreach ($partnerMethods as $pm):
                $methodKey = $pm['method'];
                $label = $methodLabels[$methodKey] ?? ucfirst($methodKey);
                $enabled = (int)$pm['is_enabled'] === 1;
            ?>
            <form method="POST" class="flex flex-wrap items-center gap-3 bg-dark-900/40 rounded-lg p-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle_method">
                <input type="hidden" name="method" value="<?= e($methodKey) ?>">
                <label class="flex items-center gap-2 text-sm text-gray-300 min-w-[140px]">
                    <input type="checkbox" name="enabled" <?= $enabled ? 'checked' : '' ?> class="rounded border-gray-600" onchange="this.form.submit()">
                    <?= e($label) ?>
                </label>
                <div class="flex items-center gap-2 text-xs">
                    <label class="text-gray-500">Priority</label>
                    <input type="number" name="priority" value="<?= (int)$pm['priority'] ?>" class="input-field !py-1 !px-2 w-20 text-xs" min="1" max="99">
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <label class="text-gray-500">Min ₹</label>
                    <input type="number" name="min_amt" value="<?= (float)$pm['min_amt'] ?>" class="input-field !py-1 !px-2 w-24 text-xs" step="0.01" min="0">
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <label class="text-gray-500">Max ₹</label>
                    <input type="number" name="max_amt" value="<?= (float)$pm['max_amt'] ?>" class="input-field !py-1 !px-2 w-24 text-xs" step="0.01" min="0">
                </div>
                <span class="text-[10px] px-2 py-0.5 rounded-full <?= $enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>"><?= $enabled ? 'ON' : 'OFF' ?></span>
            </form>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <?php elseif ($activeTab === 'webhooks'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Webhook Configuration</h3>
        <p class="text-xs text-gray-500 mb-4">Configure this URL at the partner's dashboard. UniWeb verifies signatures and processes events idempotently.</p>
        <?php $webhookUrl = $gateway['webhook_url'] ?: ($partner['webhook'] ?? ''); ?>
        <?php if ($webhookUrl): ?>
        <div class="bg-dark-900/50 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-500">Webhook URL</p>
            <p class="text-xs font-mono text-sky-400 break-all mt-1"><?= e($webhookUrl) ?></p>
        </div>
        <button onclick="navigator.clipboard.writeText('<?= e($webhookUrl) ?>'); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy URL',2000)" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Copy URL</button>
        <?php else: ?>
        <p class="text-sm text-gray-500 py-4">No webhook URL configured for this partner.</p>
        <?php endif; ?>
        <div class="mt-4 pt-4 border-t border-gray-800 text-xs text-gray-500">
            <p>Method partner webhook URL: <code class="text-sky-400"><?= e(rtrim(APP_URL, '/')) ?>/method_partner_webhook.php</code></p>
            <p class="mt-1">Auth header: <code class="text-gray-400">X-UniWeb-Method-Secret</code> — configure in Partner Detail → Keys tab.</p>
        </div>
        <?php
        try {
            $st = getDB()->prepare("SELECT * FROM platform_event_log WHERE event_type LIKE ? ORDER BY created_at DESC LIMIT 10");
            $st->execute(['%' . $partnerKey . '%']);
            $webhookLogs = $st->fetchAll();
        } catch (Throwable $e) { $webhookLogs = []; }
        ?>
        <?php if (!empty($webhookLogs)): ?>
        <div class="mt-6">
            <h4 class="text-sm font-semibold mb-2">Recent Webhook Events</h4>
            <div class="overflow-x-auto max-h-[300px]">
                <table class="w-full text-xs">
                    <thead class="text-gray-500 uppercase bg-dark-900/50 sticky top-0"><tr><th class="px-3 py-2 text-left">Time</th><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Status</th></tr></thead>
                    <tbody class="divide-y divide-gray-800 font-mono">
                        <?php foreach ($webhookLogs as $wl): ?>
                        <tr class="hover:bg-white/5"><td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?= e((string)($wl['created_at'] ?? '')) ?></td><td class="px-3 py-2 text-sky-400"><?= e((string)($wl['event_type'] ?? '')) ?></td><td class="px-3 py-2"><?= e((string)($wl['status'] ?? '')) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'test'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Connection Test</h3>
        <p class="text-xs text-gray-500 mb-4">Test API connectivity with saved credentials.</p>
        <div class="bg-dark-900/50 rounded-lg p-4 mb-4">
            <p class="text-sm <?= $testResult['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= e($testResult['message']) ?></p>
        </div>
        <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=test&action=test&token=<?= csrfToken() ?>" class="btn-primary px-6 py-2.5">Run Test Now</a>
    </div>

    <?php elseif ($activeTab === 'logs'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">API Logs</h3>
        <p class="text-xs text-gray-500 mb-4">Recent API calls for <?= e($gateway['gateway_name']) ?>.</p>
        <div class="overflow-x-auto max-h-[400px]">
            <table class="w-full text-xs">
                <thead class="text-gray-500 uppercase bg-dark-900/50 sticky top-0"><tr><th class="px-4 py-2 text-left">Time</th><th class="px-4 py-2 text-left">Endpoint</th><th class="px-4 py-2 text-left">HTTP</th><th class="px-4 py-2 text-left">Status</th></tr></thead>
                <tbody class="divide-y divide-gray-800 font-mono">
                    <?php if (empty($apiLogs)): ?>
                    <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">No API calls yet.</td></tr>
                    <?php else: foreach ($apiLogs as $log): ?>
                    <tr class="hover:bg-white/5"><td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= e(formatDate($log['created_at'])) ?></td><td class="px-4 py-2 text-sky-400"><?= e($log['endpoint']) ?></td><td class="px-4 py-2"><?= (int)$log['http_code'] ?></td><td class="px-4 py-2"><?= e($log['status']) ?></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Reason Maps</h3>
        <p class="text-xs text-gray-500 mb-4">Map partner error codes to human messages (EN + HI).</p>
        <form method="POST" class="grid sm:grid-cols-3 gap-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_reason_map">
            <input type="text" name="raw_code" placeholder="Error code" class="input-field text-xs font-mono" required>
            <input type="text" name="msg_en" placeholder="English message" class="input-field text-xs">
            <input type="text" name="msg_hi" placeholder="Hindi message" class="input-field text-xs">
            <div class="sm:col-span-3"><button type="submit" class="btn-primary px-4 py-2 text-sm">Add / Update Map</button></div>
        </form>
        <?php if (!empty($reasonMaps)): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="text-gray-500 uppercase"><tr><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-left">EN</th><th class="px-3 py-2 text-left">HI</th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($reasonMaps as $rm): ?>
                    <tr><td class="px-3 py-2 font-mono text-sky-400"><?= e($rm['raw_code']) ?></td><td class="px-3 py-2 text-gray-400"><?= e($rm['msg_en']) ?></td><td class="px-3 py-2 text-gray-400"><?= e($rm['msg_hi']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php';
