<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
require_once __DIR__ . '/includes/method_requests.php';
if (!function_exists('ensurePartnerControlTables')) {
    require_once __DIR__ . '/includes/partner_control.php';
}
if (!function_exists('partnerAllowsAlreadyLiveLink') && is_file(__DIR__ . '/includes/partner_registry_v2.php')) {
    require_once __DIR__ . '/includes/partner_registry_v2.php';
}
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
    $action = (string)($_POST['action'] ?? '');
    $methodKey = trim((string)($_POST['method_key'] ?? ''));
    $enabled = ($_POST['enabled'] ?? '') === '1';

    if ($action === 'already_live_link' && function_exists('saveMerchantAlreadyLiveLink')) {
        $pk = strtolower(trim((string)($_POST['partner_key'] ?? '')));
        $result = saveMerchantAlreadyLiveLink($merchantId, $pk, [
            'partner_mid' => (string)($_POST['partner_mid'] ?? ''),
            'env' => (string)($_POST['env'] ?? 'test'),
            'keys' => function_exists('merchantAlreadyLivePostedKeys') ? merchantAlreadyLivePostedKeys($pk, $_POST) : [],
            'actor_role' => 'merchant',
            'actor_id' => $merchantId,
            'actor_email' => (string)($merchant['email'] ?? ''),
            'owner_override' => false,
        ]);
        $msg = !empty($result['ok'])
            ? ('Link saved. Status: ' . strtoupper((string)($result['credential_status'] ?? '')) . (!empty($result['last4']) ? ' · last4 ***' . $result['last4'] : ''))
            : (string)($result['error'] ?? 'Could not save link.');
        if (!empty($result['ok']) && !empty($result['message'])) {
            $msg .= ' — ' . $result['message'];
        }
        flash(!empty($result['ok']) ? (($result['credential_status'] ?? '') === 'valid' ? 'success' : 'warning') : 'error', $msg);
        redirect('payment_methods.php#already-live');
    }

    if ($action === 'already_live_checkout' && function_exists('setMerchantAlreadyLiveCheckoutEnabled')) {
        $pk = strtolower(trim((string)($_POST['partner_key'] ?? '')));
        $on = ((string)($_POST['checkout_on'] ?? '')) === '1';
        $result = setMerchantAlreadyLiveCheckoutEnabled($merchantId, $pk, $on, ['actor_role' => 'merchant', 'actor_id' => $merchantId]);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? ($on ? 'Enabled for checkout.' : 'Checkout flag turned OFF.') : ($result['error'] ?? 'Failed'));
        redirect('payment_methods.php#already-live');
    }

    if ($action === 'request_method' && $methodKey !== '') {
        $res = requestMethodEnable($merchantId, $methodKey, trim((string)($_POST['note'] ?? '')));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Could not submit request.'));
        redirect('payment_methods.php');
    }

    if ($action === 'toggle' && $methodKey !== '') {
        $result = toggleMerchantPaymentMethod($merchantId, $methodKey, $enabled, 'merchant');
        if ($result['ok']) {
            flash('success', $methodKey . ' ' . ($enabled ? 'enabled' : 'disabled'));
        } else {
            flash('error', $result['error'] ?? 'Could not update method.');
        }
        redirect('payment_methods.php');
    }

    if ($action === 'bulk_save') {
        $enabledKeys = array_map('strval', $_POST['methods'] ?? []);
        $result = setMerchantPaymentMethods($merchantId, $enabledKeys, 'merchant');
        if ($result['ok']) {
            flash('success', 'Payment methods updated.');
        } else {
            flash('error', $result['error'] ?? 'Could not save methods.');
        }
        redirect('payment_methods.php');
    }
}

$methods = getMerchantPaymentMethods($merchantId);
$payuReady = function_exists('isGatewayConfigured') && isGatewayConfigured('payu');
$rzpReady = function_exists('isGatewayConfigured') && isGatewayConfigured('razorpay');
$cfReady = function_exists('isGatewayConfigured') && isGatewayConfigured('cashfree');
$pageTitle = 'Payment Methods';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6 border border-emerald-500/20 text-sm text-gray-300">
        <p class="font-semibold text-emerald-300 mb-1">Collect order: UPI first, then Card, then Net Banking</p>
        <p class="text-xs text-gray-500">Turn ON what customers should see on checkout. Card / Net Banking / Wallet / EMI go live after Admin enables the network. Until then, Test Mode can still use UniWeb Test Pay.</p>
        <p class="text-[11px] text-gray-400 mt-2">Payouts and Recurring / AutoPay are not checkout buttons. Use <a href="merchant_payout.php" class="text-sky-400 hover:underline">Payouts</a> (send money) and <a href="merchant_recurring.php" class="text-sky-400 hover:underline">Recurring / AutoPay</a> (mandates).</p>
        <p class="text-[11px] text-gray-600 mt-2">Toggle OFF at checkout but ON here? That was alias mismatch (<code class="text-gray-500">upi</code> vs <code class="text-gray-500">upi_p2m</code>) — now auto-normalized on every save.</p>
    </div>

    <?php if (!$payuReady && !$rzpReady && !$cfReady): ?>
    <div class="glass rounded-xl p-4 border border-amber-500/20 text-xs text-amber-200/90">
        Card / Net Banking are waiting on Admin. <strong class="text-amber-100">UPI</strong> can still collect when you turn it ON.
    </div>
    <?php else: ?>
    <div class="glass rounded-xl p-4 border border-sky-500/20 text-xs text-gray-400">
        Card / Net Banking network is ready on the platform. Turn methods ON below so they appear on your checkout.
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg">Payment Methods</h2>
                <p class="text-xs text-gray-500 mt-1">Toggle ON/OFF which payment methods customers see at checkout. List is UPI → Card → Net Banking.</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-emerald-400"><?= count(array_filter($methods, static fn($m) => (int)$m['is_enabled'] === 1 && (!function_exists('isMerchantOpsMethodKey') || !isMerchantOpsMethodKey((string)$m['gateway_key'])))) ?></p>
                <p class="text-[10px] text-gray-500 uppercase">Enabled</p>
            </div>
        </div>

        <?php if (empty($methods)): ?>
        <div class="bg-dark-900/50 rounded-xl p-8 text-center border border-gray-800">
            <p class="text-sm text-gray-400 mb-2">No payment methods available yet.</p>
            <p class="text-xs text-gray-600">Ask Admin to unlock methods for your shop. Then they appear here for you to toggle ON/OFF.</p>
        </div>
        <?php else: ?>
        <form method="POST" id="bulkForm" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="bulk_save">
            <?php foreach ($methods as $m):
                if (function_exists('isMerchantOpsMethodKey') && isMerchantOpsMethodKey((string)$m['gateway_key'])) {
                    continue;
                }
                $canTurnOn = merchantCanToggleMethodOn($merchantId, (string)$m['gateway_key'], 'merchant');
                $isOn = (int)$m['is_enabled'] === 1;
            ?>
            <div class="flex items-center justify-between gap-4 bg-dark-900/50 rounded-xl p-4 border border-gray-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-brand-600/10 flex items-center justify-center text-lg">
                        <?= match($m['gateway_key']) {
                            'upi_p2m' => '📱',
                            'qr_code' => '🔳',
                            'credit_card' => '💳',
                            'debit_card' => '💳',
                            'net_banking', 'netbanking' => '🏦',
                            'wallet' => '👛',
                            'emi' => '📅',
                            'payout' => '💸',
                            'recurring' => '🔄',
                            default => '⚙️',
                        } ?>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-200"><?= e(merchantPaymentMethodLabel((string)$m['gateway_key'], (string)$m['gateway_name'])) ?></p>
                        <p class="text-[11px] text-gray-500">
                            <?php
                            $caps = [];
                            if ((int)$m['supports_collection']) $caps[] = 'Collection';
                            if ((int)$m['supports_payout']) $caps[] = 'Payout';
                            if ((int)$m['supports_refund']) $caps[] = 'Refund';
                            if ((int)$m['supports_recurring']) $caps[] = 'Recurring';
                            echo e(implode(' · ', $caps));
                            if (in_array($m['gateway_key'], ['upi_p2m', 'qr_code'], true)) {
                                echo ' · <span class="text-emerald-500">Start here</span>';
                            } elseif (in_array($m['gateway_key'], ['credit_card', 'debit_card', 'net_banking', 'netbanking', 'wallet', 'emi'], true) && !$canTurnOn && !$isOn) {
                                echo ' · <span class="text-amber-500">Request enable below</span>';
                            } elseif (in_array($m['gateway_key'], ['credit_card', 'debit_card', 'net_banking', 'netbanking', 'wallet', 'emi'], true) && !$payuReady && !$rzpReady && !$cfReady) {
                                echo ' · <span class="text-amber-500">Waiting on Admin</span>';
                            }
                            ?>
                        </p>
                    </div>
                </div>
                <label class="pm-toggle-label <?= (!$canTurnOn && !$isOn) ? 'opacity-40 cursor-not-allowed' : '' ?>">
                    <input type="checkbox" name="methods[]" value="<?= e($m['gateway_key']) ?>" <?= $isOn ? 'checked' : '' ?> <?= (!$canTurnOn && !$isOn) ? 'disabled' : '' ?> class="pm-toggle-checkbox">
                    <span class="pm-toggle-slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5 w-full">Save Payment Methods</button>
        </form>
        <?php endif; ?>
    </div>

    <?php
    $alreadyLivePartners = function_exists('listAlreadyLiveLinkablePartners') ? listAlreadyLiveLinkablePartners() : [];
    $alreadyLiveLinks = function_exists('getMerchantPartnerLinks') ? getMerchantPartnerLinks($merchantId) : [];
    $alreadyLiveByKey = [];
    foreach ($alreadyLiveLinks as $lr) {
        $alreadyLiveByKey[strtolower((string)$lr['partner_key'])] = $lr;
    }
    ?>
    <div class="glass rounded-xl p-6 border border-gray-800" id="already-live">
        <h2 class="font-semibold text-lg">Already-live partner account</h2>
        <p class="text-xs text-gray-500 mt-1 mb-4">Use this only if you already have a merchant account on the partner. UniWeb does not create a new partner account on this path. Secrets are stored encrypted; last4 only is shown.</p>
        <?php if ($alreadyLivePartners === []): ?>
        <p class="text-sm text-gray-500">No partner has already-live link enabled. Ask Admin to turn on <strong class="text-gray-400">Allow already-live merchant link</strong> for that collect partner.</p>
        <?php else: ?>
        <div class="space-y-3 mb-5">
            <?php foreach ($alreadyLivePartners as $ap):
                $ak = strtolower((string)$ap['gateway_key']);
                $link = $alreadyLiveByKey[$ak] ?? null;
                $state = function_exists('merchantAlreadyLivePublicState') ? merchantAlreadyLivePublicState($link) : 'not_linked';
                $canEnable = $link && (strtolower((string)($link['credential_status'] ?? '')) === 'valid' || (int)($link['owner_override'] ?? 0) === 1);
            ?>
            <div class="bg-dark-900/50 rounded-xl p-4 border border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-gray-200"><?= e($ap['gateway_name']) ?></p>
                        <p class="text-[11px] font-mono text-gray-500"><?= e($ak) ?><?= !empty($link['last4']) ? ' · last4 ***' . e($link['last4']) : '' ?></p>
                    </div>
                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= $state === 'enabled_checkout' ? 'bg-emerald-500/20 text-emerald-400' : ($state === 'linked' ? 'bg-sky-500/20 text-sky-300' : ($state === 'keys_invalid' ? 'bg-red-500/15 text-red-300' : 'bg-gray-700/50 text-gray-400')) ?>"><?= e(function_exists('merchantAlreadyLiveStateLabel') ? merchantAlreadyLiveStateLabel($state) : $state) ?></span>
                </div>
                <?php if ($canEnable): ?>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="already_live_checkout">
                    <input type="hidden" name="partner_key" value="<?= e($ak) ?>">
                    <?php if ((int)($link['checkout_enabled'] ?? 0) === 1): ?>
                    <input type="hidden" name="checkout_on" value="0">
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300">Turn OFF checkout flag</button>
                    <?php else: ?>
                    <input type="hidden" name="checkout_on" value="1">
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-400 border border-emerald-500/30">Enable for checkout</button>
                    <?php endif; ?>
                </form>
                <?php elseif ($link && $state === 'keys_invalid'): ?>
                <p class="text-[11px] text-amber-400/90 mt-2">Enable for checkout is not available until keys are Valid.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" class="space-y-3 border-t border-gray-800 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="already_live_link">
            <p class="text-xs font-medium text-gray-300">I already have an account on this partner</p>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-gray-500">Partner</label>
                    <select name="partner_key" required class="input-field mt-1 text-sm">
                        <?php foreach ($alreadyLivePartners as $ap): ?>
                        <option value="<?= e($ap['gateway_key']) ?>"><?= e($ap['gateway_name']) ?> (<?= e($ap['gateway_key']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-500">Partner merchant ID / MID (optional)</label>
                    <input type="text" name="partner_mid" maxlength="120" class="input-field mt-1 font-mono text-xs" autocomplete="off">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Key ID / API key</label>
                    <input type="password" name="already_live_key" required class="input-field mt-1 font-mono text-xs" autocomplete="new-password">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Secret</label>
                    <input type="password" name="already_live_secret" required class="input-field mt-1 font-mono text-xs" autocomplete="new-password">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Environment</label>
                    <select name="env" class="input-field mt-1 text-sm">
                        <option value="test">Test / sandbox</option>
                        <option value="live">Live</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Save encrypted keys and verify</button>
            <p class="text-[11px] text-gray-600">Where a live Test Connection exists, status becomes Valid or Invalid from the partner. Other partners store keys encrypted and stay Invalid until a probe exists or Admin override.</p>
        </form>
        <?php endif; ?>
    </div>

    <?php
    renderMerchantMethodRequestSection($merchantId, [
        'merchant' => $merchant,
        'heading' => 'Request additional payment methods',
        'description' => 'Admin reviews each request. After approval, turn the method ON using the toggles above.',
        'form_action' => 'payment_methods.php',
    ]);
    ?>

    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-xs text-gray-500">
            <strong class="text-gray-400">How it works:</strong> You turn methods ON/OFF here. Admin connects the payment network once. Checkout shows enabled methods in UPI → Card → Net Banking order.
        </p>
    </div>
</div>
<style>
.pm-toggle-label{position:relative;display:inline-flex;align-items:center;cursor:pointer;width:44px;height:24px;flex-shrink:0;}
.pm-toggle-checkbox{opacity:0;width:0;height:0;position:absolute;}
.pm-toggle-slider{position:absolute;inset:0;background:#374151;border-radius:9999px;transition:background .2s;}
.pm-toggle-slider::before{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:9999px;transition:transform .2s;}
.pm-toggle-checkbox:checked + .pm-toggle-slider{background:#059669;}
.pm-toggle-checkbox:checked + .pm-toggle-slider::before{transform:translateX(20px);}
</style>
<?php require_once __DIR__ . '/footer.php';
