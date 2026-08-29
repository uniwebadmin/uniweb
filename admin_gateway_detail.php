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
if (!function_exists('requeuePartnerForwardAfterKeysSaved')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}
if (!function_exists('payoutPartnerKeysConfigured')) {
    require_once __DIR__ . '/includes/payout.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

$gatewayId = (int)($_GET['id'] ?? 0);
$partnerKeyParam = trim((string)($_GET['partner'] ?? ''));
$activeTab = trim((string)($_GET['tab'] ?? 'keys'));
if ($activeTab === 'pricing') { $activeTab = 'commercial'; }

if ($gatewayId <= 0 && $partnerKeyParam !== '') {
    // D5: Look up by gateway_key in registry — works for both hardcoded and custom-registered partners
    $allGws = getRegisteredGateways();
    foreach ($allGws as $ag) {
        if ($ag['gateway_key'] === $partnerKeyParam) {
            $gatewayId = (int)$ag['id'];
            break;
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
$partnerIsBuiltin = function_exists('isPartnerRegistryKey') && isPartnerRegistryKey($partnerKey);
ensurePartnerControlTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_keys') {
        $keys = $_POST['keys'] ?? [];
        $env = trim((string)($_POST['env'] ?? 'live'));
        if (!in_array($env, ['test', 'live'], true)) {
            $env = 'live';
        }
        $configKeys = $partner['config_keys'] ?? [];
        if ($env === 'live' && !empty($partner['env_key'])) {
            $keys[(string)$partner['env_key']] = function_exists('partnerLiveEnvironmentValue')
                ? partnerLiveEnvironmentValue($partnerKey)
                : ($partnerKey === 'cashfree' ? 'production' : 'live');
        }
        $last4 = savePartnerCredentials($partnerKey, $env, $keys, $configKeys);
        if (function_exists('saveSetting') && !empty($partner['env_key'])) {
            $envSettingKey = (string)$partner['env_key'];
            if ($env === 'live') {
                saveSetting($envSettingKey, function_exists('partnerLiveEnvironmentValue') ? partnerLiveEnvironmentValue($partnerKey) : ($partnerKey === 'cashfree' ? 'production' : 'live'));
            } else {
                $envFromKeys = trim((string)($keys[$envSettingKey] ?? ''));
                if ($envFromKeys !== '') {
                    saveSetting($envSettingKey, $envFromKeys);
                }
            }
        }
        $msg = $last4 && $last4 !== 'no_keys'
            ? 'API keys saved for ' . e($gateway['gateway_name']) . " (env: {$env}, last4: ***{$last4})"
            : ($last4 === 'no_keys' ? 'No key values submitted — nothing changed.' : 'API keys saved for ' . e($gateway['gateway_name']) . " (env: {$env})");
        if ($last4 !== 'no_keys' && function_exists('requeuePartnerForwardAfterKeysSaved')) {
            $requeued = requeuePartnerForwardAfterKeysSaved($partnerKey);
            if ($requeued > 0) {
                $msg .= " · {$requeued} forward row(s) re-queued.";
            }
        }
        if ($last4 !== 'no_keys' && in_array($partnerKey, ['razorpayx', 'cashfree', 'razorpay'], true)) {
            if (!function_exists('onPayoutRailUnlocked') && is_file(__DIR__ . '/includes/payout_workflow.php')) {
                require_once __DIR__ . '/includes/payout_workflow.php';
            }
            if (function_exists('onPayoutRailUnlocked') && function_exists('payoutPartnerKeysConfigured') && payoutPartnerKeysConfigured()) {
                try {
                    $payoutKick = onPayoutRailUnlocked();
                    if (!empty($payoutKick['promoted']) || !empty($payoutKick['dispatch']['success'])) {
                        $msg .= ' · Payout queue advanced.';
                    }
                } catch (Throwable $e) {
                    error_log('onPayoutRailUnlocked (gateway_detail): ' . $e->getMessage());
                }
            }
        }
        if ($last4 !== 'no_keys' && in_array($partnerKey, ['razorpay', 'cashfree', 'decentro'], true)) {
            if (!function_exists('onRecurringRailUnlocked') && is_file(__DIR__ . '/includes/recurring_workflow.php')) {
                require_once __DIR__ . '/includes/recurring_workflow.php';
            }
            if (function_exists('onRecurringRailUnlocked') && function_exists('recurringAutopayPartnerKeysConfigured') && recurringAutopayPartnerKeysConfigured()) {
                try {
                    $recKick = onRecurringRailUnlocked();
                    if (!empty($recKick['reasons_synced'])) {
                        $msg .= ' · ' . (int)$recKick['reasons_synced'] . ' mandate reason(s) updated.';
                    }
                } catch (Throwable $e) {
                    error_log('onRecurringRailUnlocked (gateway_detail): ' . $e->getMessage());
                }
            }
        }
        flash($last4 === 'no_keys' ? 'warning' : 'success', $msg);
        if (function_exists('logStaffActivity')) { logStaffActivity('partner_keys_saved', 'Saved ' . $env . ' keys for ' . $partnerKey . ' (last4: ' . ($last4 ?: 'n/a') . ')', null, 'partner', $partnerKey); }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=keys&env=' . $env);
    }

    if ($action === 'copy_test_keys_to_live') {
        $last4 = copyPartnerCredentialsToLive($partnerKey);
        flash($last4 === 'no_keys' ? 'warning' : 'success', $last4 === 'no_keys'
            ? 'No Test keys to copy. Paste keys on the Live tab.'
            : 'Copied Test keys to Live (last4 ***' . $last4 . ').');
        if ($last4 !== 'no_keys' && function_exists('requeuePartnerForwardAfterKeysSaved')) {
            requeuePartnerForwardAfterKeysSaved($partnerKey);
        }
        if ($last4 !== 'no_keys' && function_exists('logStaffActivity')) {
            logStaffActivity('partner_keys_copied_to_live', 'Copied test keys to live for ' . $partnerKey, null, 'partner', $partnerKey);
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=keys&env=live');
    }

    if ($action === 'activate') {
        $result = activateGatewayForAllMerchants($gatewayId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['gateway_name'] . ' activated!' : ($result['error'] ?? 'Activation failed.'));
        if ($result['ok'] && function_exists('logStaffActivity')) { logStaffActivity('partner_activated', 'Activated partner ' . $partnerKey, null, 'partner', $partnerKey); }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=' . $activeTab);
    }

    if ($action === 'deactivate') {
        $result = deactivateGateway($gatewayId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Partner turned OFF (hidden from merchant checkout).' : ($result['error'] ?? 'Error'));
        if ($result['ok'] && function_exists('logStaffActivity')) { logStaffActivity('partner_deactivated', 'Deactivated partner ' . $partnerKey, null, 'partner', $partnerKey); }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=' . $activeTab);
    }

    if ($action === 'delete') {
        $result = deleteInactiveGateway($gatewayId);
        if ($result['ok']) {
            if (function_exists('logStaffActivity')) {
                logStaffActivity('partner_deleted', 'Deleted inactive partner ' . ($result['gateway_key'] ?? $partnerKey), null, 'partner', (string)($result['gateway_key'] ?? $partnerKey));
            }
            flash('success', ($result['gateway_name'] ?? 'Partner') . ' deleted from registry.');
            redirect('admin_gateway_registry.php');
        }
        flash('error', $result['error'] ?? 'Could not delete.');
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

    if ($action === 'toggle_go_live') {
        $goLive = ((string)($_POST['go_live'] ?? '')) === '1';
        $adminEmail = $_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin';
        $result = setPartnerGoLive($gatewayId, $goLive, $adminEmail);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? ($goLive ? 'Partner is now live on public website.' : 'Partner removed from public website.') : ($result['error'] ?? 'Failed'));
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=golive');
    }

    if ($action === 'save_method_mdr') {
        $method = trim((string)($_POST['method'] ?? ''));
        $mdr = (float)($_POST['base_mdr_percent'] ?? 0);
        $adminEmail = $_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin';
        try {
            $result = setPartnerMethodMdr($partnerKey, $method, $mdr, $adminEmail);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "Partner MDR (P) for {$method} set to {$mdr}%" : ($result['error'] ?? 'Failed'));
            if ($result['ok'] && function_exists('logStaffActivity')) { logStaffActivity('partner_method_mdr_saved', "{$method} MDR set to {$mdr}% for {$partnerKey}", null, 'partner', $partnerKey); }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('pricing_method_save', $e->getMessage(), ['partner_key' => $partnerKey, 'method' => $method]);
            }
            flash('error', 'Could not save method MDR. Error logged.');
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=commercial');
    }

    if ($action === 'save_commercial') {
        $baseMdr = (float)($_POST['base_mdr_percent'] ?? 0);
        $settlementMode = trim((string)($_POST['settlement_mode'] ?? 'standard_settle_mode'));
        $adminEmail = $_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin';
        try {
            if (!function_exists('setPartnerCommercial')) {
                require_once __DIR__ . '/includes/split_settlement.php';
            }
            $ok = setPartnerCommercial($partnerKey, $baseMdr, $settlementMode, $adminEmail);
            flash($ok ? 'success' : 'error', $ok ? "Default partner MDR (P) set to {$baseMdr}%" : 'Failed to save commercial terms');
            if ($ok && function_exists('logStaffActivity')) { logStaffActivity('partner_commercial_saved', "Default MDR {$baseMdr}%, mode {$settlementMode} for {$partnerKey}", null, 'partner', $partnerKey); }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('pricing_tab_save', $e->getMessage(), ['partner_key' => $partnerKey, 'base_mdr' => $baseMdr]);
            }
            flash('error', 'Could not save commercial terms. Error logged.');
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=commercial');
    }

    if ($action === 'save_route_config') {
        try {
            if (!function_exists('setPartnerRouteConfig')) {
                require_once __DIR__ . '/includes/split_settlement.php';
            }
            $adminEmail = $_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin';
            $cfg = [
                'route_enabled' => isset($_POST['route_enabled']) ? 1 : 0,
                'route_mode' => trim((string)($_POST['route_mode'] ?? 'off')),
                'route_provider' => trim((string)($_POST['route_provider'] ?? 'none')),
                'route_linked_account_hint' => trim((string)($_POST['route_linked_account_hint'] ?? '')),
                'route_split_on' => trim((string)($_POST['route_split_on'] ?? 'capture')),
                'route_status' => trim((string)($_POST['route_status'] ?? 'scaffold')),
            ];
            $ok = setPartnerRouteConfig($partnerKey, $cfg, $adminEmail);
            flash($ok ? 'success' : 'error', $ok ? 'Route/split config saved.' : 'Failed to save route config.');
            if ($ok && function_exists('logStaffActivity')) { logStaffActivity('partner_route_config_saved', 'Route config saved for ' . $partnerKey . ': mode=' . $cfg['route_mode'] . ', status=' . $cfg['route_status'], null, 'partner', $partnerKey); }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('route_tab_save', $e->getMessage(), ['partner_key' => $partnerKey]);
            }
            flash('error', 'Could not save route config. Error logged.');
        }
        redirect('admin_gateway_detail.php?id=' . $gatewayId . '&tab=commercial');
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
$tabs = ['keys' => 'Keys', 'methods' => 'Methods', 'commercial' => 'Commercial', 'webhooks' => 'Webhooks', 'golive' => 'Go-live', 'test' => 'Test', 'logs' => 'Logs'];
$webhookUrl = trim((string)($gateway['webhook_url'] ?: ($partner['webhook'] ?? '')));
$goLiveChecklist = function_exists('partnerGoLiveChecklist')
    ? partnerGoLiveChecklist($partnerKey, $gateway, $webhookUrl)
    : ['items' => [], 'ready' => false];
$isGoLive = (int)($gateway['public_go_live'] ?? 0) === 1;

$pageTitle = $gateway['gateway_name'] . ' — Partner Detail';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="rounded-xl border border-sky-500/25 bg-sky-500/5 px-4 py-3 text-xs text-gray-400">
        <strong class="text-sky-300">Roles:</strong> Admin controls all merchants. This partner is a payment/KYC <em class="text-gray-300 not-italic">rail</em> (keys + methods). There is no separate partner merchant portal.
    </div>
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
                    <?= function_exists('partnerIntegrationStateBadgeHtml') ? partnerIntegrationStateBadgeHtml($partnerKey) : '' ?>
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
            <?php if (!$partnerIsBuiltin): ?>
            <form method="POST" class="inline" onsubmit="return confirm('Permanently delete this inactive custom partner from the registry?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/30">Delete</button>
            </form>
            <?php endif; ?>
            <?php else: ?>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="deactivate">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-amber-500/10 text-amber-300 border border-amber-500/30" onclick="return confirm('Turn OFF this partner? Merchants will no longer see its methods on checkout.')">Turn OFF</button>
            </form>
            <?php endif; ?>
            <a href="admin_gateway_detail.php?id=<?= (int)$gatewayId ?>&amp;tab=keys&amp;env=test" class="glass px-5 py-2.5 rounded-xl text-sm text-sky-300 hover:text-sky-200">Change keys →</a>
            <?php if ($partner && $partner['docs']): ?>
            <a href="<?= e($partner['docs']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">API Docs ↗</a>
            <?php endif; ?>
            <?php if ($partner && $partner['dashboard']): ?>
            <a href="<?= e($partner['dashboard']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">Dashboard ↗</a>
            <?php endif; ?>
        </div>
        <p class="text-[11px] text-gray-600 mt-3">Change keys = Keys tab (Test then Live). Turn OFF hides methods. Delete only works after Turn OFF, and only for custom partners (not PayU / cards / UPI).</p>
    </div>

    <div class="flex gap-1 border-b border-gray-800 overflow-x-auto">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=<?= $tabKey ?><?= $tabKey === 'keys' ? '&env=test' : '' ?>" class="px-4 py-2.5 text-sm font-medium <?= $activeTab === $tabKey ? 'text-brand-400 border-b-2 border-brand-500' : 'text-gray-400 hover:text-gray-200' ?>"><?= $tabLabel ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($activeTab === 'keys'): ?>
    <?php
        // A3: Keys tab defaults to Test / Sandbox (paste Test first, then Live)
        $keyEnv = preg_replace('/[^a-z]/', '', (string)($_GET['env'] ?? 'test'));
        if (!in_array($keyEnv, ['test', 'live'], true)) $keyEnv = 'test';
        $existingCreds = getPartnerCredentials($partnerKey, $keyEnv);
    ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Partner API credentials (Admin pipe)</h3>
        <ol class="text-xs text-gray-400 mb-3 space-y-1 list-decimal list-inside">
            <li><strong class="text-gray-300">Paste Test / Sandbox</strong> keys from the partner dashboard → Save</li>
            <li>Open the <a href="admin_gateway_detail.php?id=<?= (int)$gatewayId ?>&amp;tab=test" class="text-sky-400 hover:underline">Test</a> tab → Run Test Connection</li>
            <li>When production keys arrive → paste on <strong class="text-emerald-300">Live</strong> → Save → Test again</li>
        </ol>
        <p class="text-xs text-gray-500 mb-2">Encrypted at rest; only last4 shown after save. Leave password fields blank to keep the current value. Merchants never receive these partner keys — they use UniWeb API keys only (<code class="text-gray-400">api_settings.php</code>).</p>
        <div class="flex gap-2 mb-4">
            <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=keys&env=test" class="text-xs px-3 py-1.5 rounded-lg <?= $keyEnv === 'test' ? 'bg-sky-500/20 text-sky-400' : 'glass text-gray-400' ?>">1 · Test / Sandbox</a>
            <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=keys&env=live" class="text-xs px-3 py-1.5 rounded-lg <?= $keyEnv === 'live' ? 'bg-emerald-500/20 text-emerald-400' : 'glass text-gray-400' ?>">2 · Live / Production</a>
            <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=test" class="text-xs px-3 py-1.5 rounded-lg glass text-violet-300 hover:text-violet-200">3 · Test Connection →</a>
        </div>
        <?php if ($keyEnv === 'live'): ?>
        <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 mb-4 text-sm text-emerald-200">You are on <strong>LIVE / PRODUCTION</strong> keys (real money). Sandbox keys stay on the Test tab — never treat staging as live.</div>
        <?php else: ?>
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 mb-4 text-sm text-amber-200">You are on <strong>TEST / SANDBOX</strong> keys (no real settlement). Paste production keys only on the <a href="admin_gateway_detail.php?id=<?= $gatewayId ?>&tab=keys&env=live" class="underline text-emerald-300">Live / Production</a> tab.</div>
        <?php endif; ?>
        <?php if ($partnerKey === 'decentro'): ?>
        <div class="rounded-lg border border-sky-500/30 bg-sky-500/10 p-3 mb-4 text-xs text-sky-200">Decentro is a <strong>partner</strong>. UniWeb does not become the bank. Staging dashboard ≠ production money.</div>
        <?php endif; ?>
        <?php if ($partnerKey === 'pinelabs'): ?>
        <div class="rounded-lg border border-violet-500/30 bg-violet-500/10 p-3 mb-4 text-xs text-violet-200">Use canonical fields: <strong>Merchant ID</strong>, <strong>Access Code</strong>, <strong>Secure Key</strong>. Do not paste into old gateway_settings names (<code class="text-gray-400">pinelabs_api_key</code>). Keys save encrypted in Partner Registry only.</div>
        <?php endif; ?>
        <?php if ($partnerKey === 'rbl'): ?>
        <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 mb-4 text-xs text-rose-200"><strong>No demo defaults.</strong> Paste <strong>Client ID</strong>, <strong>Secret</strong>, <strong>Corp ID</strong>, and <strong>Master Account</strong> from RBL dashboard. Bina Corp ID + Master Account ke VA / UPI / payout band — fake values (jaise VAOPENBANK) code mein nahi bharte.</div>
        <?php endif; ?>
        <?php if ($keyEnv === 'live' && !empty($credStatus['test']) && empty($credStatus['live'])): ?>
        <form method="POST" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="copy_test_keys_to_live">
            <p class="text-xs text-amber-300 mb-2">Keys are currently saved under Test only. If those are production keys, copy them to Live.</p>
            <button type="submit" class="text-xs px-4 py-2 rounded-lg bg-emerald-600/20 text-emerald-300 border border-emerald-500/40" onclick="return confirm('Copy Test keys into Live for this partner?')">Copy Test keys → Live</button>
        </form>
        <?php endif; ?>
        <?php if (!empty($configKeys)): ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_keys">
            <input type="hidden" name="env" value="<?= e($keyEnv) ?>">
            <?php foreach ($configKeys as $key => $meta): ?>
            <div>
                <label class="text-sm text-gray-400"><?= e($meta['label']) ?></label>
                <?php if (($meta['type'] ?? 'text') === 'select'): ?>
                <?php
                    $opts = $meta['options'] ?? [];
                    $livePick = isset($opts['production']) ? 'production' : (isset($opts['live']) ? 'live' : '');
                    $testPick = isset($opts['sandbox']) ? 'sandbox' : (isset($opts['test']) ? 'test' : '');
                    $sel = (string)($existingCreds[$key] ?? '');
                    if ($keyEnv === 'live') {
                        $sel = in_array($sel, ['live', 'production'], true) ? $sel : $livePick;
                    } else {
                        $sel = ($sel !== '' && isset($opts[$sel])) ? $sel : $testPick;
                    }
                ?>
                <select name="keys[<?= e($key) ?>]" class="input-field mt-1">
                    <?php foreach ($opts as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $sel === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <?php $hasExisting = !empty($existingCreds[$key]); ?>
                <input type="<?= e($meta['type'] ?? 'text') ?>" name="keys[<?= e($key) ?>]" value="" placeholder="<?= ($meta['type'] ?? '') === 'password' ? ($hasExisting ? '••••••' . e($existingCreds['_last4'] ?? '') . ' (leave blank to keep)' : '•••• (leave blank to keep current)') : ($hasExisting ? 'Current: ' . e(substr((string)$existingCreds[$key], 0, 8)) . '…' : '') ?>" class="input-field mt-1 font-mono text-xs" autocomplete="off">
                <?php if ($hasExisting && ($meta['type'] ?? '') !== 'password'): ?>
                <p class="text-[10px] text-gray-600 mt-1">Current value saved in encrypted credentials.</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5">Save <?= e(ucfirst($keyEnv)) ?> Keys</button>
            <a href="admin_gateway_detail.php?id=<?= (int)$gatewayId ?>&amp;tab=test" class="inline-block ml-2 text-sm text-sky-400 hover:underline">Next: Test Connection →</a>
        </form>
        <?php if ($credStatus['test'] || $credStatus['live']): ?>
        <p class="text-[11px] text-gray-600 mt-3">
            Saved: <?php if ($credStatus['test']): ?><span class="text-sky-400">Test ***<?= e($credStatus['test_last4']) ?></span><?php endif; ?>
            <?php if ($credStatus['live']): ?><span class="text-emerald-400 ml-2">Live ***<?= e($credStatus['live_last4']) ?></span><?php endif; ?>
        </p>
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
    <div class="glass rounded-xl p-6 border border-emerald-500/20 text-sm text-gray-300 mb-4">
        <p class="font-semibold text-emerald-300 mb-1">Go-live order: UPI → Card → Net Banking</p>
        <p class="text-xs text-gray-500">1) Paste Test/Live keys → 2) Turn methods ON here → 3) Merchant toggles on Payment Methods. Checkout stays the same page — no new app. Soft “waiting for keys” text clears when this partner’s keys are live.</p>
    </div>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Payment Methods</h3>
        <p class="text-xs text-gray-500 mb-4">Enable/disable methods for <?= e($gateway['gateway_name']) ?>. Only partner-ON methods can appear at live checkout. Priority: lower number = prefer first (UPI should stay near 10).</p>
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
                    <?php if ($methodKey === 'upi'): ?><span class="text-[10px] text-emerald-500">start here</span><?php endif; ?>
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

    <?php     elseif ($activeTab === 'commercial'):
        if (function_exists('ensureMissingColumns')) {
            ensureMissingColumns();
        }
        if (!function_exists('getPartnerBaseMdr')) {
            require_once __DIR__ . '/includes/split_settlement.php';
        }
        if (function_exists('ensureSplitSettlementTable')) {
            ensureSplitSettlementTable();
        }
        if (function_exists('ensurePartnerCommercialSeeded')) {
            ensurePartnerCommercialSeeded($partnerKey, 'admin');
        }
        $pricingError = null;
        $defaultP = 0.0;
        $methodMdrs = [];
        $commercialRow = null;
        try {
            $defaultP = getPartnerBaseMdr($partnerKey);
            $methodMdrs = getAllPartnerMethodMdrs($partnerKey);
            $stc = getDB()->prepare('SELECT * FROM partner_commercial WHERE partner_key=?');
            $stc->execute([$partnerKey]);
            $commercialRow = $stc->fetch();
        } catch (Throwable $e) {
            $pricingError = $e->getMessage();
            if (function_exists('logPlatformError')) {
                logPlatformError('pricing_tab_load', $e->getMessage(), ['partner_key' => $partnerKey, 'file' => __FILE__, 'line' => $e->getLine()]);
            }
        }
        $routeCfg = getPartnerRouteConfig($partnerKey);
        $routeReadiness = getRouteSplitReadinessChecklist($partnerKey);
        $routeMarket = getRouteSplitMarketMatrix();
        $routeOwnerLive = routeSplitLiveEnabled();
    ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Commercial — <?= e($gateway['gateway_name']) ?></h3>
        <div class="rounded-lg border border-emerald-500/25 bg-emerald-500/5 p-3 mb-4 text-xs text-gray-300 space-y-1">
            <p class="font-medium text-emerald-300">Revenue model: commission on successful transactions</p>
            <p class="text-gray-500">UniWeb does not sell a white-label software package. You set partner cost (P) and merchant schedule (M); UniWeb earns the platform margin on live captures.</p>
            <?php
            $exGross = 100.0;
            $exP = (float)$defaultP;
            $exM = max($exP, defined('DEFAULT_MDR_PERCENT') ? (float)DEFAULT_MDR_PERCENT : 2.0);
            $exPartner = round($exGross * $exP / 100, 2);
            $exAdmin = round($exGross * max(0, $exM - $exP) / 100, 2);
            $exMerch = round($exGross - ($exGross * $exM / 100), 2);
            ?>
            <p class="text-sky-300/90 pt-1">Example on ₹100 (current P <?= e(number_format($exP, 2)) ?>%, sample M <?= e(number_format($exM, 2)) ?>%): Admin cut <?= formatMoney($exAdmin) ?> · Partner cut <?= formatMoney($exPartner) ?> · Merchant baaki <?= formatMoney($exMerch) ?>. Same math posts to ledger on success.</p>
        </div>
        <p class="text-xs text-gray-500 mb-4">Save Partner MDR below. Route / Easy Split SDK stays <strong class="text-amber-400">parked</strong> until Owner + keys — commission still applies via standard settle on this engine.</p>

        <?php if ($pricingError): ?>
        <div class="rounded-lg border border-amber-700/50 bg-amber-900/20 p-3 mb-4 text-sm text-amber-300">
            Could not load existing commercial terms. You can still save new values below.
        </div>
        <?php elseif (!$commercialRow): ?>
        <div class="rounded-lg border border-gray-700 bg-gray-800/30 p-3 mb-4 text-sm text-gray-400">
            No commercial terms saved yet for <?= e($gateway['gateway_name']) ?>. Set default Partner MDR (P) and settlement mode, then Save.
        </div>
        <?php endif; ?>

        <!-- Section A: Partner Commercial (MDR) -->
        <div class="rounded-lg border border-gray-800 p-4 mb-6">
            <h4 class="text-sm font-semibold mb-1">Section A — Partner MDR (P)</h4>
            <p class="text-xs text-gray-600 mb-3">P = bank/PG cost. M = merchant MDR (merchant schedule). UniWeb commission ≈ M − P when both are set. If per-method P is 0, the default below applies.</p>

            <form method="POST" class="flex flex-wrap items-end gap-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_commercial">
                <div>
                    <label class="text-gray-500 text-xs block mb-1">Default Partner MDR (P) %</label>
                    <input type="number" name="base_mdr_percent" value="<?= e(number_format($defaultP, 4)) ?>" class="input-field w-32" step="0.01" min="0" max="100" title="Partner cost percent">
                </div>
                <div>
                    <label class="text-gray-500 text-xs block mb-1">Settlement mode</label>
                    <select name="settlement_mode" class="input-field w-48">
                        <option value="standard_settle_mode" <?= ($commercialRow['settlement_mode'] ?? '') === 'standard_settle_mode' ? 'selected' : '' ?>>Standard settle (commission on capture)</option>
                        <option value="route_mode" <?= ($commercialRow['settlement_mode'] ?? '') === 'route_mode' ? 'selected' : '' ?>>Route mode (scaffold)</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Save commercial</button>
            </form>

            <h5 class="text-xs font-semibold text-gray-500 uppercase mb-2">Per-method Partner MDR (P)</h5>
            <div class="space-y-2">
                <?php foreach ($methodLabels as $methodKey => $label): ?>
                <form method="POST" class="flex items-center gap-3 rounded-lg border border-gray-800 px-4 py-2.5">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_method_mdr">
                    <input type="hidden" name="method" value="<?= e($methodKey) ?>">
                    <span class="text-sm text-gray-300 min-w-[140px]"><?= e($label) ?></span>
                    <input type="number" name="base_mdr_percent" value="<?= e(number_format($methodMdrs[$methodKey] ?? 0, 2)) ?>" class="input-field w-28 text-sm" step="0.01" min="0" max="100">
                    <span class="text-xs text-gray-600">%</span>
                    <button type="submit" class="text-xs text-brand-400 hover:underline ml-auto">Save</button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section B: Route / Split (Phase 11) -->
        <div class="rounded-lg border border-violet-500/30 bg-violet-500/5 p-4 mb-6">
            <h4 class="text-sm font-semibold text-violet-300 mb-1">Route / Split — programme config (not live split by default)</h4>
            <p class="text-xs text-gray-500 mb-3">UniWeb <strong class="text-gray-300">today</strong>: standard settlement + M/P commission. Partner Route / Easy Split transfer API runs only when Platform switch ON, status below, linked IDs, and SDK capability.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[11px] min-w-[720px]">
                    <thead class="text-gray-500 uppercase">
                        <tr>
                            <th class="text-left py-2 pr-2">Feature</th>
                            <th class="text-left py-2 px-2">Razorpay Route</th>
                            <th class="text-left py-2 px-2">Cashfree Easy Split</th>
                            <th class="text-left py-2 px-2">UniWeb today</th>
                            <th class="text-left py-2 pl-2">UniWeb future</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800 text-gray-400">
                        <?php foreach ($routeMarket as $row): ?>
                        <tr>
                            <td class="py-2 pr-2 font-medium text-gray-300"><?= e($row['feature']) ?></td>
                            <td class="py-2 px-2"><?= e($row['razorpay']) ?></td>
                            <td class="py-2 px-2"><?= e($row['cashfree']) ?></td>
                            <td class="py-2 px-2 text-emerald-300/90"><?= e($row['uniweb_today']) ?></td>
                            <td class="py-2 pl-2 text-amber-300/80"><?= e($row['uniweb_future']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-[10px] text-gray-600 mt-3">PayU Split follows same pattern as Razorpay/Cashfree — multi-payee at settlement. UniWeb does not claim marketplace split live until Owner enables Phase 11 + SDK.</p>
        </div>

        <div class="rounded-lg border border-gray-800 p-4 mb-6">
            <h4 class="text-sm font-semibold mb-2">Readiness — <?= e($gateway['gateway_name']) ?></h4>
            <p class="text-xs text-gray-600 mb-3"><?= e(routeSplitActivationMessage($partnerKey)) ?></p>
            <ul class="text-xs space-y-1 mb-3">
                <?php foreach ($routeReadiness['items'] as $item): ?>
                <li class="<?= !empty($item['ok']) ? 'text-emerald-400' : 'text-amber-400' ?>">
                    <?= !empty($item['ok']) ? '●' : '○' ?> <?= e($item['label']) ?>
                    <?php if (!empty($item['note'])): ?><span class="text-gray-600"> — <?= e($item['note']) ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-[10px] text-gray-600">Platform switch: <strong class="<?= $routeOwnerLive ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $routeOwnerLive ? 'ON' : 'OFF' ?></strong> · <a href="gateway_settings.php#live-money-switches" class="text-sky-400 underline">Platform Settings → Live Money Switches</a></p>
        </div>

        <div class="rounded-lg border border-gray-800 p-4">
            <h4 class="text-sm font-semibold mb-1">Section B — Route / Split config</h4>
            <p class="text-xs text-gray-600 mb-3">Prepare partner programme fields. No provider API calls / live marketplace split until Platform switch ON + transfer SDK.</p>
            <?php $routeStatusHelp = function_exists('routeSplitStatusDescriptions') ? routeSplitStatusDescriptions() : []; ?>
            <?php if ($routeStatusHelp !== []): ?>
            <ul class="text-[11px] text-gray-500 space-y-1 mb-4 list-disc list-inside">
                <?php foreach ($routeStatusHelp as $key => $desc): ?>
                <li><strong class="text-gray-400"><?= e($key) ?>:</strong> <?= e($desc) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_route_config">

                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="route_enabled" value="1" <?= $routeCfg['route_enabled'] ? 'checked' : '' ?> class="rounded">
                        <span>Route enabled</span>
                    </label>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-gray-500 text-xs block mb-1">Route mode</label>
                        <select name="route_mode" class="input-field w-full text-sm">
                            <?php foreach (['off', 'internal_only', 'partner_api'] as $rm): ?>
                            <option value="<?= $rm ?>" <?= $routeCfg['route_mode'] === $rm ? 'selected' : '' ?>><?= e($rm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-gray-500 text-xs block mb-1">Route provider</label>
                        <select name="route_provider" class="input-field w-full text-sm">
                            <?php foreach (['none', 'razorpay_route', 'cashfree_vendor', 'other'] as $rp): ?>
                            <option value="<?= $rp ?>" <?= $routeCfg['route_provider'] === $rp ? 'selected' : '' ?>><?= e($rp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-gray-500 text-xs block mb-1">Linked account hint</label>
                        <input type="text" name="route_linked_account_hint" value="<?= e($routeCfg['route_linked_account_hint']) ?>" placeholder="e.g. razorpay_linked_account_id" class="input-field w-full text-sm font-mono">
                    </div>
                    <div>
                        <label class="text-gray-500 text-xs block mb-1">Split on</label>
                        <select name="route_split_on" class="input-field w-full text-sm">
                            <?php foreach (['capture', 'settlement', 'manual'] as $so): ?>
                            <option value="<?= $so ?>" <?= $routeCfg['route_split_on'] === $so ? 'selected' : '' ?>><?= e($so) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-gray-500 text-xs block mb-1">Route status</label>
                    <select name="route_status" class="input-field w-48 text-sm">
                        <?php foreach (['scaffold', 'ready_for_api', 'live'] as $rs): ?>
                        <?php
                        $liveLocked = ($rs === 'live' && !$routeOwnerLive);
                        $liveLabel = match ($rs) {
                            'scaffold' => 'scaffold — save only',
                            'ready_for_api' => 'ready_for_api — keys/hints; transfers off',
                            'live' => $routeOwnerLive ? 'live intent — SDK still required' : 'live (locked — enable Platform switch first)',
                            default => $rs,
                        };
                        ?>
                        <option value="<?= $rs ?>" <?= $routeCfg['route_status'] === $rs ? 'selected' : '' ?> <?= $liveLocked ? 'disabled' : '' ?>><?= e($liveLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[10px] text-gray-600 mt-1">Current: <strong class="text-gray-400"><?= e(routeSplitStatusLabel($routeCfg['route_status'])) ?></strong> · canUsePartnerRoute(): <strong class="<?= canUsePartnerRoute($partnerKey) ? 'text-emerald-400' : 'text-gray-600' ?>"><?= canUsePartnerRoute($partnerKey) ? 'true' : 'false' ?></strong></p>
                </div>

                <button type="submit" class="btn-primary px-4 py-2 text-sm">Save route config</button>
            </form>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-800 text-xs text-gray-500">
            <p><strong class="text-gray-400">P</strong> = Partner MDR (Section A) · <strong class="text-gray-400">M</strong> = Merchant MDR · <strong class="text-gray-400">UniWeb commission</strong> ≈ M − P on successful captures</p>
            <p class="mt-1">Settlement today: <strong class="text-emerald-400">standard_settle_mode</strong> (T+0/T+1/T+2). Live Route/Easy Split transfers run only when Platform switch ON, status live, and SDK gate open.</p>
            <p class="mt-1"><a href="admin_settlements.php" class="text-sky-400 underline">Settlements → partner transfer queue</a></p>
        </div>
    </div>

    <?php elseif ($activeTab === 'webhooks'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Webhook Configuration</h3>
        <p class="text-xs text-gray-500 mb-4">Configure this URL at the partner's dashboard. UniWeb verifies signatures and processes events idempotently.</p>
        <?php $webhookUrl = $webhookUrl ?? ($gateway['webhook_url'] ?: ($partner['webhook'] ?? '')); ?>
        <?php if ($webhookUrl): ?>
        <div class="bg-dark-900/50 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-500">Webhook URL</p>
            <p class="text-xs font-mono text-sky-400 break-all mt-1"><?= e($webhookUrl) ?></p>
        </div>
        <button type="button" data-copy-url="<?= e($webhookUrl) ?>" onclick="var u=this.getAttribute('data-copy-url')||''; if(u){navigator.clipboard.writeText(u); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy URL',2000);}" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Copy URL</button>
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

    <?php elseif ($activeTab === 'golive'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Go-live</h3>
        <p class="text-xs text-gray-500 mb-4">Checklist before this partner appears on the public homepage. Complete required items, then turn Go Live ON. Live Route API stays locked until a later ticket.</p>
        <ul class="space-y-2 mb-5">
            <?php foreach (($goLiveChecklist['items'] ?? []) as $item): ?>
            <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-800 px-3 py-2.5 text-sm">
                <span class="<?= !empty($item['ok']) ? 'text-emerald-400' : 'text-amber-400' ?>">
                    <?= !empty($item['ok']) ? '● Ready' : '○ Missing' ?>
                    <span class="text-gray-200 ml-1"><?= e((string)$item['label']) ?></span>
                    <?php if (empty($item['required'])): ?><span class="text-gray-600 text-xs ml-1">(optional)</span><?php endif; ?>
                </span>
                <?php if (empty($item['ok']) && !empty($item['tab'])): ?>
                <a href="admin_gateway_detail.php?id=<?= (int)$gatewayId ?>&tab=<?= e((string)$item['tab']) ?>" class="text-xs text-sky-400">Open <?= e((string)$item['tab']) ?> →</a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($webhookUrl): ?>
        <div class="bg-dark-900/50 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-500">Webhook URL</p>
            <p class="text-xs font-mono text-sky-400 break-all mt-1"><?= e($webhookUrl) ?></p>
            <button type="button" data-copy-url="<?= e($webhookUrl) ?>" onclick="var u=this.getAttribute('data-copy-url')||''; if(u){navigator.clipboard.writeText(u); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy URL',2000);}" class="mt-2 text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Copy URL</button>
        </div>
        <?php endif; ?>
        <div class="flex flex-wrap items-center gap-4">
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle_go_live">
                <?php if ($isGoLive): ?>
                <input type="hidden" name="go_live" value="0">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/30" onclick="return confirm('Remove from public website?')">● Go Live ON — Click to turn OFF</button>
                <?php elseif (!empty($goLiveChecklist['ready'])): ?>
                <input type="hidden" name="go_live" value="1">
                <button type="submit" class="text-xs px-4 py-2.5 rounded-lg bg-gray-700/50 text-gray-400 border border-gray-600" onclick="return confirm('Show this partner on public website?')">○ Go Live OFF — Click to turn ON</button>
                <?php else: ?>
                <button type="button" disabled class="text-xs px-4 py-2.5 rounded-lg bg-gray-800 text-gray-500 border border-gray-700 cursor-not-allowed">○ Go Live OFF — complete required items first</button>
                <?php endif; ?>
            </form>
            <div class="text-xs text-gray-500 space-y-0.5">
                <?php if ($isGoLive && !empty($gateway['public_go_live_at'])): ?>
                <div class="text-gray-600">Go Live since: <?= e((string)$gateway['public_go_live_at']) ?> by <?= e((string)($gateway['public_go_live_by'] ?? '')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php elseif ($activeTab === 'test'): ?>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-1">Connection Test</h3>
        <p class="text-xs text-gray-500 mb-2">Uses the credentials currently saved for this partner (prefer Test keys first, then Live). No new vault — same encrypted Partner Registry store.</p>
        <p class="text-xs text-gray-600 mb-4">
            Saved now:
            <?php if ($credStatus['test']): ?><span class="text-sky-400">Test ***<?= e($credStatus['test_last4']) ?></span><?php else: ?><span class="text-amber-400">Test missing</span><?php endif; ?>
            ·
            <?php if ($credStatus['live']): ?><span class="text-emerald-400">Live ***<?= e($credStatus['live_last4']) ?></span><?php else: ?><span class="text-gray-500">Live not set</span><?php endif; ?>
            · <a href="admin_gateway_detail.php?id=<?= (int)$gatewayId ?>&amp;tab=keys&amp;env=test" class="text-sky-400 hover:underline">Edit keys</a>
        </p>
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
