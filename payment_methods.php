<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $methodKey = trim((string)($_POST['method_key'] ?? ''));
    $enabled = ($_POST['enabled'] ?? '') === '1';

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
$pageTitle = 'Payment Methods';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-2">Payment Methods</h2>
        <p class="text-xs text-gray-500 mb-6">Toggle ON/OFF which payment methods customers see at checkout. All methods are OFF by default — enable the ones you need.</p>

        <form method="POST" id="bulkForm" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="bulk_save">
            <?php foreach ($methods as $m): ?>
            <div class="flex items-center justify-between gap-4 bg-dark-900/50 rounded-xl p-4 border border-gray-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-brand-600/10 flex items-center justify-center text-lg">
                        <?= match($m['gateway_key']) {
                            'upi_p2m' => '📱',
                            'qr_code' => '🔳',
                            'credit_card' => '💳',
                            'debit_card' => '💳',
                            'net_banking' => '🏦',
                            'wallet' => '👛',
                            'payout' => '💸',
                            'recurring' => '🔄',
                            default => '⚙️',
                        } ?>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-200"><?= e($m['gateway_name']) ?></p>
                        <p class="text-[11px] text-gray-500">
                            <?php
                            $caps = [];
                            if ((int)$m['supports_collection']) $caps[] = 'Collection';
                            if ((int)$m['supports_payout']) $caps[] = 'Payout';
                            if ((int)$m['supports_refund']) $caps[] = 'Refund';
                            if ((int)$m['supports_recurring']) $caps[] = 'Recurring';
                            echo e(implode(' · ', $caps));
                            ?>
                        </p>
                    </div>
                </div>
                <label class="pm-toggle-label">
                    <input type="checkbox" name="methods[]" value="<?= e($m['gateway_key']) ?>" <?= (int)$m['is_enabled'] === 1 ? 'checked' : '' ?> class="pm-toggle-checkbox">
                    <span class="pm-toggle-slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5 w-full">Save Payment Methods</button>
        </form>
    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-xs text-gray-500">
            <strong class="text-gray-400">Note:</strong> Some methods (Cards, Net Banking, Wallet) require a configured payment gateway. If a gateway is not yet live, the method will show at checkout only after partner activation. UPI P2M and QR Code work immediately.
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
