<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
if (!function_exists('ensureMerchantVirtualAccount')) {
    require_once __DIR__ . '/includes/va_manager.php';
}
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensurePaymentPackSchema();
ensureMethodRequestSchema();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
    if (($_POST['action'] ?? '') === 'request_method') {
        $res = requestMethodEnable($merchantId, trim($_POST['method_key'] ?? ''), $_POST['note'] ?? '');
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
        redirect('collection_settings.php');
    }
    $mode = $_POST['collection_mode'] ?? 'direct_upi';
    $modes = array_keys(getMerchantFacingCollectionModes($merchant));
    if (!in_array($mode, $modes, true)) $mode = getMerchantCollectionMode($merchant) ?: 'direct_upi';
    // Payment methods ON/OFF live only on payment_methods.php — do not dual-write enabled_methods here (DUP-02).
    // Partner child / linked / vendor IDs are Admin-only (Owner keys pipe). Merchant UI must not set them.
    try {
        $db->prepare('UPDATE merchants SET collection_mode=? WHERE id=?')
            ->execute([
                $mode,
                $merchant['id'],
            ]);
    } catch (Throwable $e) {
        flash('error', 'Could not save collection settings. Please try again.');
        redirect('collection_settings.php');
    }
    if ($mode === 'axis_va' && getMerchantPrimaryVaNumber((int)$merchant['id']) === '') {
        if (!function_exists('ensureMerchantVirtualAccount')) {
            require_once __DIR__ . '/includes/va_manager.php';
        }
        ensureMerchantVirtualAccount((int)$merchant['id']);
    }
    generateMerchantPaymentPack((int)$merchant['id'], 1.0);
    flash('success', 'Collection settings saved. Payment pack updated.');
    redirect('collection_settings.php');
}

$merchant = getMerchant();
$axisVa = null;
if (getMerchantCollectionMode($merchant) === 'axis_va') {
    $axisVa = ensureMerchantVirtualAccount((int)$merchant['id']);
    $merchant = getMerchant();
}
$modes = getMerchantFacingCollectionModes($merchant);
$pageTitle = 'Collection Settings';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-2">B2B Collection Mode</h2>
        <p class="text-xs text-gray-500 mb-6">Choose how UniWeb collects for you. Partners stay with Admin — you do not paste partner keys or Split IDs here.</p>
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
                <div class="bg-dark-900/50 rounded-xl p-4 border border-gray-800">
                    <p class="text-xs text-gray-400 mb-3">Enable or disable methods on <a href="payment_methods.php" class="text-brand-400 hover:underline font-semibold">Payment Methods</a>. Recommended order: <strong class="text-gray-300">UPI → Card → Net Banking</strong>.</p>
                    <a href="payment_methods.php" class="inline-block text-sm bg-brand-600 hover:bg-brand-500 text-white px-4 py-2 rounded-lg font-semibold">Manage Payment Methods →</a>
                </div>
            </div>
            <p class="text-[11px] text-gray-600">Partner Split / Route IDs are not shown here. Admin connects partners once; commission is calculated automatically on success.</p>
            <button type="submit" class="btn-primary px-6 py-2.5">✓ Save Collection Settings</button>
        </form>
    </div>

    <?php
    renderMerchantMethodRequestSection($merchantId, [
        'merchant' => $merchant,
        'heading' => 'Request payment methods',
        'description' => 'Submit a request for Card, Net Banking, or other methods. After Admin (and partner, if required) approves, enable the method under Payment Methods.',
        'form_action' => 'collection_settings.php',
    ]);
    ?>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-3">Direct UPI (P2M)</h3>
        <p class="text-sm text-gray-400">Your UPI ID: <span class="font-mono text-sky-400"><?= e($merchant['upi_id']) ?></span></p>
        <p class="text-xs text-gray-500 mt-2">Customers pay directly to your VPA — money does not pass through UniWeb.</p>
        <a href="qr_upi_print.php" class="inline-block mt-4 text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg font-semibold">Print Instant UPI QR →</a>
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
        <h3 class="font-semibold mb-2">Commission Preview (realtime feel)</h3>
        <?php
        $demo = function_exists('commissionSplitRealtimePreview')
            ? commissionSplitRealtimePreview(100, $merchant)
            : calculateSplitBreakdown(100, $merchant);
        ?>
        <p class="text-gray-400 mb-2">On ₹100 success payment (Admin-saved %):</p>
        <ul class="text-xs text-gray-500 space-y-1">
            <li>Admin cut: <span class="text-amber-400"><?= formatMoney((float)($demo['admin_cut'] ?? $demo['platform_fee'] ?? 0)) ?></span></li>
            <li>Network cut: <span class="text-gray-300"><?= formatMoney((float)($demo['partner_cut'] ?? $demo['partner_fee'] ?? 0)) ?></span></li>
            <li>You receive: <span class="text-emerald-400"><?= formatMoney((float)($demo['merchant_net'] ?? 0)) ?></span></li>
        </ul>
        <p class="text-[11px] text-gray-600 mt-3">Partners are connected by Admin. You only see your net after automatic commission.</p>
    </div>

    <div class="glass rounded-xl p-6 border border-violet-500/20">
        <h3 class="font-semibold mb-2">Settlement vs Route / Split</h3>
        <p class="text-xs text-gray-500 mb-3">Market PGs (Razorpay Route, Cashfree Easy Split) send your share directly from the partner at capture. UniWeb uses <strong class="text-gray-300">standard settlement</strong> today — money settles to your wallet / bank on T+0/T+1/T+2 after commission is cut.</p>
        <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">
            <li><strong class="text-gray-400">Today (live):</strong> Collect → M/P split on ledger → settlement batch → bank transfer</li>
            <li><strong class="text-gray-400">Route / Split (Phase 11):</strong> Parked — Admin prepares partner config only. No marketplace multi-vendor split yet.</li>
        </ul>
        <p class="text-[11px] text-gray-600 mt-3">You do not paste Route or vendor IDs here — Admin manages partner programme in Registry.</p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
