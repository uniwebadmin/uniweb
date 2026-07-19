<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensurePaymentPackSchema();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $mode = $_POST['collection_mode'] ?? 'direct_upi';
    $modes = array_keys(getCollectionModes());
    if (!in_array($mode, $modes, true)) $mode = 'direct_upi';
    $enabled = array_values(array_intersect(
        array_keys(getPaymentMethodCatalog()),
        array_map('strval', $_POST['enabled_methods'] ?? [])
    ));
    if (empty($enabled)) $enabled = ['upi_p2m'];
    try {
        $db->prepare('UPDATE merchants SET collection_mode=?, enabled_methods=?, payu_child_key=?, razorpay_linked_account_id=?, cashfree_vendor_id=? WHERE id=?')
            ->execute([
                $mode,
                json_encode($enabled),
                trim($_POST['payu_child_key'] ?? '') ?: null,
                trim($_POST['razorpay_linked_account_id'] ?? '') ?: null,
                trim($_POST['cashfree_vendor_id'] ?? '') ?: null,
                $merchant['id'],
            ]);
    } catch (Throwable $e) {
        try {
            $db->prepare('UPDATE merchants SET collection_mode=?, payu_child_key=?, razorpay_linked_account_id=?, cashfree_vendor_id=? WHERE id=?')
                ->execute([
                    $mode,
                    trim($_POST['payu_child_key'] ?? '') ?: null,
                    trim($_POST['razorpay_linked_account_id'] ?? '') ?: null,
                    trim($_POST['cashfree_vendor_id'] ?? '') ?: null,
                    $merchant['id'],
                ]);
        } catch (Throwable $e2) {
            flash('error', 'Could not save collection settings. Please try again.');
            redirect('collection_settings.php');
        }
    }
    if ($mode === 'axis_va' && empty($merchant['axis_va_number'])) {
        ensureAxisVirtualAccount((int)$merchant['id']);
    }
    generateMerchantPaymentPack((int)$merchant['id'], 1.0);
    flash('success', 'Collection settings saved. Payment pack updated.');
    redirect('collection_settings.php');
}

$merchant = getMerchant();
$axisVa = null;
if (getMerchantCollectionMode($merchant) === 'axis_va') {
    $axisVa = ensureAxisVirtualAccount((int)$merchant['id']);
    $merchant = getMerchant();
}
$modes = getMerchantFacingCollectionModes($merchant);
$enabledMethods = getMerchantEnabledMethods($merchant);
$methodCatalog = getPaymentMethodCatalog();
$pageTitle = 'Collection Settings';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-2">B2B Collection Mode</h2>
        <p class="text-xs text-gray-500 mb-6">Choose how payments are collected. <strong>Direct UPI</strong> = zero liability (P2M). <strong>PayU Split</strong> = auto commission cut.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                <label class="text-sm text-gray-400">Collection Mode *</label>
                <select name="collection_mode" class="input-field mt-1">
                    <?php foreach ($modes as $k => $label): ?>
                    <option value="<?= $k ?>" <?= getMerchantCollectionMode($merchant) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                </div>
                <button type="submit" class="btn-primary px-5 py-2.5 font-semibold whitespace-nowrap">✓ Save</button>
            </div>
            <div>
                <label class="text-sm text-gray-400 block mb-2">Payment Methods</label>
                <div class="grid sm:grid-cols-2 gap-2 bg-dark-900/50 rounded-xl p-4 border border-gray-800 max-h-48 overflow-y-auto">
                    <?php foreach ($methodCatalog as $mk => $cat): ?>
                    <label class="flex items-center gap-2 text-xs cursor-pointer">
                        <input type="checkbox" name="enabled_methods[]" value="<?= e($mk) ?>" <?= in_array($mk, $enabledMethods, true) ? 'checked' : '' ?> class="rounded border-gray-600">
                        <span><?= e(($cat['icon'] ?? '') . ' ' . $cat['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div><label class="text-sm text-gray-400">PayU Child Merchant Key (for split)</label>
                <input type="text" name="payu_child_key" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['payu_child_key'] ?? '') ?>" placeholder="Optional — same as parent for test"></div>
            <div><label class="text-sm text-gray-400">Razorpay Linked Account ID (Route)</label>
                <input type="text" name="razorpay_linked_account_id" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['razorpay_linked_account_id'] ?? '') ?>"></div>
            <div><label class="text-sm text-gray-400">Cashfree Vendor ID (Easy Split)</label>
                <input type="text" name="cashfree_vendor_id" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['cashfree_vendor_id'] ?? '') ?>"></div>
            <button type="submit" class="btn-primary px-6 py-2.5">✓ Save Collection Settings</button>
        </form>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-3">Direct UPI (P2M)</h3>
        <p class="text-sm text-gray-400">Your UPI ID: <span class="font-mono text-sky-400"><?= e($merchant['upi_id']) ?></span></p>
        <p class="text-xs text-gray-500 mt-2">Customers pay directly to your VPA — money does not pass through UniWeb.</p>
    </div>

    <?php if (!empty($merchant['axis_va_number']) || $axisVa): ?>
    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-3">Axis Virtual Account</h3>
        <div class="text-sm space-y-1 font-mono">
            <p>VA: <?= e($merchant['axis_va_number'] ?? $axisVa['va_number'] ?? '') ?></p>
            <p>IFSC: <?= e($merchant['axis_va_ifsc'] ?? $axisVa['ifsc'] ?? '') ?></p>
            <?php if (!empty($merchant['axis_va_upi'])): ?><p>UPI: <?= e($merchant['axis_va_upi']) ?></p><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-6 text-sm">
        <h3 class="font-semibold mb-2">Commission Preview</h3>
        <?php $demo = calculateSplitBreakdown(1000, $merchant); ?>
        <p class="text-gray-400">On ₹1,000 payment: Merchant <?= formatMoney($demo['merchant_net']) ?> · Platform <?= formatMoney($demo['platform_fee']) ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
