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
    if (($_POST['action'] ?? '') === 'create_va') {
        ensureMerchantVirtualAccountsTable();
        $merchant = getMerchant();
        if (getMerchantCollectionMode($merchant) !== 'axis_va') {
            flash('error', 'Virtual accounts are available only when your collection mode is Virtual account collection. Contact support to enable it.');
            redirect('collection_settings.php');
        }
        $label = trim((string)($_POST['label'] ?? ''));
        $result = createAdditionalVirtualAccount($merchantId, 'axis', $label);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Virtual account created: ' . ($result['va']['va_number'] ?? '')
            : ($result['error'] ?? 'Could not create virtual account.'));
        redirect('collection_settings.php');
    }
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
$collectionMode = getMerchantCollectionMode($merchant);
$merchantVas = [];
if ($collectionMode === 'axis_va' || getMerchantPrimaryVaNumber((int)$merchant['id']) !== '') {
    ensureMerchantVirtualAccountsTable();
    $merchantVas = getMerchantVirtualAccounts($merchantId);
}
if ($collectionMode === 'axis_va' && empty($merchantVas)) {
    ensureMerchantVirtualAccount((int)$merchant['id']);
    $merchantVas = getMerchantVirtualAccounts($merchantId);
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

    <?php if ($collectionMode === 'axis_va' || !empty($merchantVas)): ?>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold">Virtual Accounts</h3>
                <p class="text-xs text-gray-500 mt-1">Bank collect accounts (Axis). Add separate accounts for branches or projects. Checkout partners (Razorpay, Cashfree, etc.) do not create a VA here — use payment links instead.</p>
            </div>
            <?php if ($collectionMode === 'axis_va'): ?>
            <form method="POST" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create_va">
                <input type="text" name="label" placeholder="Label (e.g. Branch 2)" maxlength="120" class="input-field text-sm !py-2 w-44">
                <button type="submit" class="btn-primary text-sm px-4 py-2 whitespace-nowrap">+ Add Virtual Account</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (empty($merchantVas)): ?>
        <p class="text-sm text-gray-500">No virtual accounts yet. Use the button above to create your first one.</p>
        <?php else: ?>
        <div class="overflow-x-auto -mx-2"><table class="min-w-[520px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase"><tr>
                <th class="px-2 py-2 text-left">Label</th>
                <th class="px-2 py-2 text-left">VA Number</th>
                <th class="px-2 py-2 text-left">IFSC</th>
                <th class="px-2 py-2 text-left">UPI</th>
                <th class="px-2 py-2 text-left">Today</th>
                <th class="px-2 py-2 text-left">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($merchantVas as $va): ?>
                <tr>
                    <td class="px-2 py-2.5"><?= e($va['label'] ?: '—') ?><?= !empty($va['is_primary']) ? ' <span class="text-[10px] text-sky-400">(primary)</span>' : '' ?></td>
                    <td class="px-2 py-2.5 font-mono text-xs"><?= e($va['va_number']) ?></td>
                    <td class="px-2 py-2.5 font-mono text-xs text-gray-400"><?= e($va['ifsc'] ?: '—') ?></td>
                    <td class="px-2 py-2.5 font-mono text-xs text-gray-400"><?= e($va['upi_id'] ?: '—') ?></td>
                    <td class="px-2 py-2.5"><?= (int)$va['txn_count_today'] ?></td>
                    <td class="px-2 py-2.5"><?= ($va['status'] ?? '') === 'active' ? '<span class="text-emerald-400 text-xs">Active</span>' : '<span class="text-amber-400 text-xs">Disabled</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="text-[11px] text-gray-600 mt-3">Need to disable an account? Contact support — Admin can turn accounts off if one has too many failures.</p>
        <?php endif; ?>
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
        <?php
        if (!function_exists('routeSplitMerchantEducation')) {
            require_once __DIR__ . '/includes/route_split_workflow.php';
        }
        $routeEdu = routeSplitMerchantEducation();
        ?>
        <h3 class="font-semibold mb-2"><?= e($routeEdu['title']) ?></h3>
        <p class="text-xs text-gray-500 mb-3"><?= e($routeEdu['today']) ?></p>
        <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside mb-3">
            <?php foreach ($routeEdu['today_flow'] as $step): ?>
            <li><strong class="text-gray-400"><?= e($step['label']) ?>:</strong> <?= e($step['detail']) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="text-xs text-amber-200/90 mb-2"><?= e($routeEdu['parked']) ?></p>
        <p class="text-[11px] text-gray-600"><?= e($routeEdu['merchant_action']) ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
