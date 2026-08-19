<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
require_once __DIR__ . '/includes/method_requests.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
    $action = (string)($_POST['action'] ?? '');
    $methodKey = trim((string)($_POST['method_key'] ?? ''));
    $enabled = ($_POST['enabled'] ?? '') === '1';

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
        <p class="text-xs text-gray-500">Turn ON what customers should see on checkout. Card / Net Banking / Wallet / EMI go live after Admin enables the network. Until then, Test Mode can still use Instant Test Pay.</p>
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
                <p class="text-2xl font-bold text-emerald-400"><?= count(array_filter($methods, fn($m) => (int)$m['is_enabled'] === 1)) ?></p>
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
