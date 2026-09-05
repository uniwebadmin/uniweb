<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getMerchantMdr')) {
    require_once __DIR__ . '/includes/split_settlement.php';
}
if (!function_exists('getMerchantPrimaryVaNumber')) {
    require_once __DIR__ . '/includes/va_manager.php';
}
requireStaffAccess(['super', 'ceo', 'regional_manager', 'ops', 'kyc']);
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
requireMerchantAccess($id);
$stmt = $db->prepare("SELECT * FROM merchants WHERE id = ? AND status != 'deleted'");
$stmt->execute([$id]);
$merchant = $stmt->fetch();
if (!$merchant) {
    flash('error', 'Merchant not found.');
    redirect('manage_merchant.php');
}

$categories = getBusinessCategories();
$entities = getBusinessEntityTypes();
$plans = getSubscriptionPlans();
$editEntityType = (string)($merchant['business_entity_type'] ?? 'sole_proprietorship');
$editTaxFields = entityProfileTaxFields($editEntityType);

if (isset($_GET['action']) && in_array($_GET['action'], ['verify_website', 'reject_website'], true) && verifyCsrf($_GET['token'] ?? '')) {
    ensureMerchantWebsiteEngine();
    adminSetMerchantWebsiteStatus($id, $_GET['action'] === 'verify_website' ? 'verified' : 'rejected');
    logStaffActivity($_GET['action'] === 'verify_website' ? 'website_verified' : 'website_rejected', 'Merchant #' . $id, $id, 'merchant', (string)$id);
    flash('success', $_GET['action'] === 'verify_website' ? 'Website marked verified.' : 'Website marked rejected.');
    redirect('admin_edit_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'provision_axis_va' && verifyCsrf($_GET['token'] ?? '')) {
    flash('info', 'Open Virtual Accounts below to create an Axis VA. Live Axis API needs keys in Partner Registry first.');
    redirect('admin_virtual_accounts.php?merchant_id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'auto_provision' && verifyCsrf($_GET['token'] ?? '')) {
    $result = autoProvisionMerchant($id, (int)($_SESSION['admin_id'] ?? 0));
    logStaffActivity('auto_provision', $result['ok'] ? 'Auto-provisioned merchant #' . $id : 'Auto-provision failed: ' . ($result['message'] ?? ''), $id, 'merchant', (string)$id);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('admin_edit_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'regen_key' && verifyCsrf($_GET['token'] ?? '')) {
    $mode = ($_GET['mode'] ?? 'live') === 'test' ? 'test' : 'live';
    $result = regenerateMerchantApiKey($id, $mode, (int)($_SESSION['admin_id'] ?? 0));
    logStaffActivity('regen_api_key', ucfirst($mode) . ' key regenerated for merchant #' . $id, $id, 'merchant', (string)$id);
    if (!empty($result['ok'])) {
        $_SESSION['admin_new_api_credential'] = [
            'key' => $result['key'],
            'secret' => $result['secret'],
            'mode' => $result['mode'],
        ];
    }
    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? ucfirst($mode) . ' API credential regenerated — copy the one-time key below. Merchant notified by email.'
        : ($result['error'] ?? 'Failed.'));
    redirect('admin_edit_merchant.php?id=' . $id . '#api-keys');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $adminAction = (string)($_POST['admin_action'] ?? '');

    if ($adminAction === 'toggle_method') {
        $methodKey = trim((string)($_POST['method_key'] ?? ''));
        $enabled = ($_POST['enabled'] ?? '') === '1';
        if ($methodKey !== '') {
            $result = toggleMerchantPaymentMethod($id, $methodKey, $enabled, 'admin');
            logStaffActivity('toggle_method', $methodKey . ' ' . ($enabled ? 'enabled' : 'disabled') . ' for merchant #' . $id, $id, 'merchant', (string)$id);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $methodKey . ' ' . ($enabled ? 'enabled' : 'disabled') : ($result['error'] ?? 'Error'));
        }
        redirect('admin_edit_merchant.php?id=' . $id . '#payment-methods');
    }

    if ($adminAction === 'bulk_methods') {
        $enabledKeys = array_map('strval', $_POST['methods'] ?? []);
        $result = setMerchantPaymentMethods($id, $enabledKeys, 'admin');
        logStaffActivity('bulk_methods', 'Updated payment methods for merchant #' . $id . ': ' . implode(', ', $enabledKeys), $id, 'merchant', (string)$id);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Payment methods updated.' : ($result['error'] ?? 'Error'));
        redirect('admin_edit_merchant.php?id=' . $id . '#payment-methods');
    }

    $panEncrypted = sensitiveUiSave($_POST['pan_number'] ?? '', (string)($merchant['pan_number'] ?? ''));
    $panCheck = sensitiveUiPlain($panEncrypted);
    if ($panCheck !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $panCheck)) {
        flash('error', 'Invalid PAN format.');
        redirect('admin_edit_merchant.php?id=' . $id);
    }
    $gstinEncrypted = sensitiveUiSave($_POST['gstin'] ?? '', (string)($merchant['gstin'] ?? ''));
    $cinEncrypted = sensitiveUiSave($_POST['cin_llpin'] ?? '', (string)($merchant['cin_llpin'] ?? ''));
    $accountMode = $_POST['account_mode'] ?? 'test';
    $kycStatus = $_POST['kyc_status'] ?? 'pending';
    if (!in_array($accountMode, ['test', 'live'], true)) $accountMode = 'test';
    if ($accountMode === 'live' && $kycStatus !== 'verified') {
        flash('error', 'Live mode requires verified KYC. Complete KYC review first — status was not changed.');
        redirect('admin_edit_merchant.php?id=' . $id);
    }
    if ($accountMode === 'live' && !merchantLiveGateSatisfied($id)) {
        $gate = merchantLiveGateReport($id);
        flash('error', 'Live gates incomplete: ' . implode(', ', $gate['missing'] ?? []));
        redirect('admin_edit_merchant.php?id=' . $id);
    }

    // Payment methods now managed via ON/OFF toggles (sidebar) — keep existing JSON
    $enabledJson = $merchant['enabled_methods'] ?? '["upi_p2m"]';

    $db->prepare('UPDATE merchants SET name=?, business_name=?, email=?, phone=?, business_type=?, business_entity_type=?, pan_number=?, gstin=?, cin_llpin=?, kyc_status=?, account_mode=?, subscription_plan=?, monthly_fee=?, status=?, collection_mode=?, payu_child_key=?, razorpay_linked_account_id=?, cashfree_vendor_id=?, enabled_methods=?, website_url=?, android_app_url=?, ios_app_url=? WHERE id=?')
        ->execute([
            trim($_POST['name']),
            trim($_POST['business_name']),
            trim($_POST['email']),
            trim($_POST['phone']),
            $_POST['business_type'],
            $_POST['business_entity_type'],
            $panEncrypted,
            $gstinEncrypted,
            $cinEncrypted,
            $kycStatus,
            $accountMode,
            $_POST['subscription_plan'] ?? 'starter',
            (float)($_POST['monthly_fee'] ?? 0),
            $_POST['status'] ?? 'active',
            $_POST['collection_mode'] ?? 'direct_upi',
            trim($_POST['payu_child_key'] ?? '') ?: null,
            trim($_POST['razorpay_linked_account_id'] ?? '') ?: null,
            trim($_POST['cashfree_vendor_id'] ?? '') ?: null,
            $enabledJson,
            normalizeWebsiteUrl(trim($_POST['website_url'] ?? '')) ?: null,
            normalizeAppStoreUrl(trim($_POST['android_app_url'] ?? '')) ?: null,
            normalizeAppStoreUrl(trim($_POST['ios_app_url'] ?? '')) ?: null,
            $id,
        ]);

    // C3: Update PII search hashes when new values are saved
    if (function_exists('pii_hash')) {
        try {
            $panInput = strtoupper(trim($_POST['pan_number'] ?? ''));
            $gstinInput = strtoupper(trim($_POST['gstin'] ?? ''));
            $cinInput = strtoupper(trim($_POST['cin_llpin'] ?? ''));
            $updates = [];
            $params = [];
            if ($panInput && !str_starts_with($panInput, '*')) {
                $updates[] = 'pan_hash=?';
                $params[] = pii_hash(preg_replace('/\s+/', '', $panInput));
            }
            if ($gstinInput && !str_starts_with($gstinInput, '*')) {
                $updates[] = 'gstin_hash=?';
                $params[] = pii_hash(preg_replace('/\s+/', '', $gstinInput));
            }
            if ($cinInput && !str_starts_with($cinInput, '*')) {
                $updates[] = 'cin_hash=?';
                $params[] = pii_hash(preg_replace('/\s+/', '', $cinInput));
            }
            if ($updates) {
                $params[] = $id;
                $db->prepare('UPDATE merchants SET ' . implode(',', $updates) . ' WHERE id=?')->execute($params);
            }
        } catch (Throwable $e) { /* hash columns may not exist yet */ }
    }

    if ($accountMode === 'live' && ($merchant['account_mode'] ?? 'test') !== 'live') {
        createNotification($id, 'Account Live!', 'Admin activated your LIVE mode. You can now accept real payments.');
    }

    // Canonical merchant MDR (M) — also mirrors merchants.commission_rate for legacy reads
    $mdrInput = (float)($_POST['mdr_percent'] ?? 0);
    if ($mdrInput <= 0) {
        $mdrInput = getMerchantMdr($id);
    }
    $partnerKeyForMdr = trim($_POST['partner_key_for_mdr'] ?? '') ?: null;
    $currentMdr = getMerchantMdr($id);
    if (abs($mdrInput - $currentMdr) > 0.0001) {
        $mdrResult = setMerchantMdr($id, $mdrInput, $partnerKeyForMdr, 'admin:' . ($admin['username'] ?? 'admin'));
        if (!$mdrResult['ok']) {
            flash('error', 'MDR not saved: ' . ($mdrResult['error'] ?? 'Unknown error'));
        }
    } else {
        syncMerchantCommissionRateMirror($id, $mdrInput);
    }

    logStaffActivity('merchant_edited', 'Profile updated — mode ' . $accountMode . ', KYC ' . $kycStatus, $id);
    flash('success', 'Merchant profile updated.');
    redirect('admin_edit_merchant.php?id=' . $id);
}

ensureMerchantApiKeys($id);
$apiCredentialSummaries = getMerchantApiCredentialSummaries($id);
$adminNewCredential = $_SESSION['admin_new_api_credential'] ?? null;
unset($_SESSION['admin_new_api_credential']);

$pageTitle = 'Edit Merchant — ' . $merchant['merchant_code'];
require_once __DIR__ . '/header.php';
$kycProgress = getMerchantKycProgress($merchant);
$methodPreview = merchantMethodPreview($merchant);
$packLinks = getMerchantPackLinks($id, $merchant['provision_pack_id'] ?? null);
$enabledMethods = getMerchantEnabledMethods($merchant);
$methodCatalog = getPaymentMethodCatalog();
?>

<div class="mb-4 flex flex-wrap gap-2 sm:gap-3 items-center">
    <a href="manage_merchant.php" class="text-sm text-gray-400 hover:text-white">← Back to Merchants</a>
    <a href="admin_view_merchant.php?id=<?= $id ?>" class="text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-3 sm:px-4 py-2 rounded-lg font-semibold">Merchant View</a>
    <a href="?id=<?= $id ?>&action=auto_provision&token=<?= csrfToken() ?>" class="text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-3 sm:px-4 py-2 rounded-lg font-semibold" onclick="return confirm('Auto Setup: profile + gateway submit + all method links (₹1)?')">Auto Setup Merchant</a>
    <a href="admin_partner_requests.php" class="text-sm text-sky-400">Partner Emails →</a>
</div>

<div class="grid lg:grid-cols-3 gap-4 sm:gap-6">
    <div class="lg:col-span-2 glass rounded-xl p-4 sm:p-6 min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
            <h2 class="font-semibold text-lg break-words"><?= e($merchant['business_name']) ?></h2>
            <?= accountModeBadge($merchant) ?>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <p class="text-xs text-brand-400 font-medium uppercase tracking-wide">Personal & Business</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Full Name</label><input type="text" name="name" required autocomplete="name" class="input-field mt-1 w-full" value="<?= e($merchant['name']) ?>"></div>
                <div><label class="text-sm text-gray-400">Business Name</label><input type="text" name="business_name" required class="input-field mt-1 w-full" value="<?= e($merchant['business_name']) ?>"></div>
                <div><label class="text-sm text-gray-400">Email</label><input type="email" name="email" required autocomplete="email" class="input-field mt-1 w-full" value="<?= e($merchant['email']) ?>"></div>
                <div><label class="text-sm text-gray-400">Phone</label><input type="tel" name="phone" required maxlength="15" inputmode="numeric" autocomplete="tel" class="input-field mt-1 w-full ap-phone" value="<?= e($merchant['phone']) ?>"></div>
            </div>
            <p class="text-xs text-brand-400 font-medium uppercase tracking-wide pt-2">Legal Entity & Tax (Admin Control)</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Legal Entity Type *</label>
                    <select name="business_entity_type" required class="input-field mt-1">
                        <?php foreach ($entities as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($merchant['business_entity_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-600 mt-1">Change here if merchant selected wrong type at signup.</p>
                </div>
                <div><label class="text-sm text-gray-400">Business Category</label>
                    <select name="business_type" class="input-field mt-1">
                        <?php foreach ($categories as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $merchant['business_type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="text-sm text-gray-400">PAN</label><input type="text" name="pan_number" maxlength="10" class="input-field mt-1 uppercase" value="<?= e(sensitiveUiPlain($merchant['pan_number'] ?? '')) ?>" placeholder="ABCDE1234F" autocomplete="off"></div>
                <?php if (!empty($editTaxFields['gst'])): ?>
                <div><label class="text-sm text-gray-400">GSTIN</label><input type="text" name="gstin" class="input-field mt-1" value="<?= e(sensitiveUiPlain($merchant['gstin'] ?? '')) ?>" placeholder="GSTIN" autocomplete="off"></div>
                <?php else: ?>
                <input type="hidden" name="gstin" value="">
                <?php endif; ?>
                <?php if (!empty($editTaxFields['cin'])): ?>
                <div><label class="text-sm text-gray-400">CIN / LLPIN</label><input type="text" name="cin_llpin" class="input-field mt-1" value="<?= e(sensitiveUiPlain($merchant['cin_llpin'] ?? '')) ?>" placeholder="CIN / LLPIN" autocomplete="off"></div>
                <?php else: ?>
                <input type="hidden" name="cin_llpin" value="">
                <?php endif; ?>
                <p class="sm:col-span-2 text-xs text-gray-500">Stored encrypted. Shown decrypted here for admin review (B-03). GSTIN / CIN follow entity type.</p>
            </div>
            <p class="text-xs text-brand-400 font-medium uppercase tracking-wide pt-2">Website & App</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Website URL</label>
                    <div class="flex flex-wrap items-center gap-2 mt-1">
                        <input type="url" name="website_url" class="input-field flex-1" value="<?= e($merchant['website_url'] ?? '') ?>" placeholder="https://example.com">
                        <?= merchantWebsiteStatusBadge($merchant) ?>
                    </div>
                </div>
                <div><label class="text-sm text-gray-400">Android App URL</label><input type="url" name="android_app_url" class="input-field mt-1" value="<?= e($merchant['android_app_url'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">iOS App URL</label><input type="url" name="ios_app_url" class="input-field mt-1" value="<?= e($merchant['ios_app_url'] ?? '') ?>"></div>
            </div>
            <p class="text-xs text-brand-400 font-medium uppercase tracking-wide pt-2" id="kyc">Account Mode & Billing</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">Account Mode</label>
                    <select name="account_mode" class="input-field mt-1 w-full">
                        <option value="test" <?= merchantAccountMode($merchant) === 'test' ? 'selected' : '' ?>>Test Mode (Sandbox)</option>
                        <option value="live" <?= merchantAccountMode($merchant) === 'live' ? 'selected' : '' ?>>Live Mode (Real Payments)</option>
                    </select>
                    <p class="text-[11px] text-amber-400/90 mt-1">Live mode blocked until KYC verified + live gates pass (server-enforced).</p>
                </div>
                <div>
                    <label class="text-sm text-gray-400">KYC Status</label>
                    <select name="kyc_status" class="input-field mt-1">
                        <?php foreach (['pending','submitted','verified','rejected'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($merchant['kyc_status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Subscription Plan</label>
                    <select name="subscription_plan" class="input-field mt-1">
                        <?php foreach ($plans as $k => $p): ?>
                        <option value="<?= $k ?>" <?= ($merchant['subscription_plan'] ?? 'starter') === $k ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="text-sm text-gray-400">Monthly Fee (₹)</label><input type="number" name="monthly_fee" step="0.01" min="0" class="input-field mt-1" value="<?= e((string)($merchant['monthly_fee'] ?? 0)) ?>"></div>
                <?php
                $currentMdr = getMerchantMdr($id);
                $partnerLinks = function_exists('getMerchantPartnerLinks') ? getMerchantPartnerLinks($id) : [];
                $activePartnerKey = '';
                $partnerBaseMdr = 0.0;
                foreach ($partnerLinks as $pLink) {
                    if (in_array(($pLink['kyc_status'] ?? ''), ['live', 'active'], true)) {
                        $activePartnerKey = (string)$pLink['partner_key'];
                        $partnerBaseMdr = function_exists('getPartnerBaseMdr') ? getPartnerBaseMdr($activePartnerKey) : 0.0;
                        break;
                    }
                }
                ?>
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Merchant MDR M (%) <span class="text-gray-600">— single rate for splits &amp; merchant display</span></label>
                    <input type="number" name="mdr_percent" step="0.01" min="0" max="100" required class="input-field mt-1 max-w-xs" value="<?= e((string)$currentMdr) ?>" placeholder="2.00">
                    <?php if ($activePartnerKey): ?>
                    <input type="hidden" name="partner_key_for_mdr" value="<?= e($activePartnerKey) ?>">
                    <p class="text-xs text-gray-600 mt-1">Partner base MDR (P): <strong><?= e(number_format($partnerBaseMdr, 2)) ?>%</strong> — M must be ≥ P. Saved to pricing history and mirrored on merchant profile.</p>
                    <?php else: ?>
                    <p class="text-xs text-gray-600 mt-1">No active partner linked. Default M: <?= e(number_format(DEFAULT_MDR_PERCENT, 2)) ?>% until you set one here.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Collection Mode</label>
                    <select name="collection_mode" class="input-field mt-1">
                        <?php foreach (getCollectionModes() as $k => $label): ?>
                        <option value="<?= $k ?>" <?= getMerchantCollectionMode($merchant) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400 block mb-2">Payment Methods</label>
                    <div class="bg-dark-900/50 rounded-xl p-4 border border-gray-800">
                        <p class="text-xs text-gray-400 mb-2">Manage payment methods via the ON/OFF toggles in the sidebar →</p>
                        <a href="#payment-methods" class="text-sm text-brand-400 hover:text-brand-300">Go to Payment Methods ON/OFF →</a>
                    </div>
                </div>
                <div><label class="text-sm text-gray-400">PayU Child Key</label><input type="text" name="payu_child_key" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['payu_child_key'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">Razorpay Linked Account</label><input type="text" name="razorpay_linked_account_id" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['razorpay_linked_account_id'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">Cashfree Vendor ID</label><input type="text" name="cashfree_vendor_id" class="input-field mt-1 font-mono text-xs" value="<?= e($merchant['cashfree_vendor_id'] ?? '') ?>"></div>
                <div>
                    <label class="text-sm text-gray-400">Account Status</label>
                    <select name="status" class="input-field mt-1">
                        <option value="active" <?= $merchant['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $merchant['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary w-full sm:w-auto px-8 py-2.5">Save Changes</button>
        </form>
    </div>
    <div class="space-y-4 min-w-0">
        <div class="glass rounded-xl p-4 sm:p-5" id="payment-methods">
            <h3 class="font-semibold text-sm mb-3">Payment Methods ON/OFF</h3>
            <p class="text-xs text-gray-500 mb-4">Toggle which payment methods this merchant can use. All OFF by default.</p>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="admin_action" value="bulk_methods">
                <?php
                $pmMethods = getMerchantPaymentMethods($id);
                foreach ($pmMethods as $pm):
                ?>
                <div class="flex items-center justify-between gap-3 bg-dark-900/50 rounded-lg p-3 border border-gray-800">
                    <div class="flex items-center gap-2">
                        <span class="text-base"><?= match($pm['gateway_key']) {
                            'upi_p2m' => '📱',
                            'qr_code' => '🔳',
                            'credit_card' => '💳',
                            'debit_card' => '💳',
                            'net_banking' => '🏦',
                            'wallet' => '👛',
                            'payout' => '💸',
                            'recurring' => '🔄',
                            default => '⚙️',
                        } ?></span>
                        <div>
                            <span class="text-xs font-medium text-gray-200"><?= e($pm['gateway_name']) ?></span>
                            <?php if (!empty($pm['updated_by'])): ?>
                            <span class="text-[10px] text-gray-600 ml-1">by <?= e($pm['updated_by']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <label class="pm-toggle-label">
                        <input type="checkbox" name="methods[]" value="<?= e($pm['gateway_key']) ?>" <?= (int)$pm['is_enabled'] === 1 ? 'checked' : '' ?> class="pm-toggle-checkbox">
                        <span class="pm-toggle-slider"></span>
                    </label>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn-primary w-full text-sm py-2 mt-3">Save Methods</button>
            </form>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5 text-sm" id="website">
            <h3 class="font-semibold mb-2">Website & App</h3>
            <?php if (!empty($merchant['website_url'])): ?>
            <a href="<?= e($merchant['website_url']) ?>" target="_blank" rel="noopener" class="text-sky-400 text-xs break-all hover:underline"><?= e($merchant['website_url']) ?></a>
            <div class="mt-2"><?= merchantWebsiteStatusBadge($merchant) ?></div>
            <div class="flex flex-wrap gap-2 mt-3">
                <a href="?id=<?= $id ?>&action=verify_website&token=<?= csrfToken() ?>" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-400">✓ Verify</a>
                <a href="?id=<?= $id ?>&action=reject_website&token=<?= csrfToken() ?>" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/20 text-red-400">Reject</a>
            </div>
            <?php else: ?>
            <p class="text-xs text-gray-500">Merchant has not added a website yet.</p>
            <?php endif; ?>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5">
            <h3 class="font-semibold text-sm mb-3">KYC Progress</h3>
            <p class="text-2xl font-bold text-brand-400"><?= $kycProgress['uploaded'] ?>/<?= $kycProgress['required'] ?></p>
            <p class="text-xs text-gray-500 mt-1">Documents uploaded</p>
            <?php if (!$kycProgress['complete']): ?>
            <ul class="mt-3 text-xs text-amber-400 space-y-1">
                <?php foreach (array_slice($kycProgress['missing'], 0, 5) as $m): ?>
                <li>• Missing: <?= e($m) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="text-xs text-emerald-400 mt-2">✓ All required docs uploaded</p>
            <?php endif; ?>
            <a href="admin_kyc.php" class="inline-block mt-3 text-xs text-brand-400">Review KYC →</a>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5 text-sm">
            <h3 class="font-semibold mb-2">Wallet</h3>
            <p class="text-xl font-bold"><?= formatMoney(getMerchantWalletBalance($merchant)) ?></p>
            <p class="text-xs text-gray-500 mt-1">Merchant ID: <?= e($merchant['merchant_code']) ?></p>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5 text-sm">
            <h3 class="font-semibold mb-2">Payment Profile</h3>
            <p class="text-xs text-gray-400"><?= e($methodPreview['profile_label']) ?></p>
            <p class="text-xs text-brand-400 mt-2"><?= count($methodPreview['methods']) ?> methods · <?= !empty($merchant['auto_provisioned']) ? 'Auto ✓' : 'Manual' ?></p>
            <?php if (!empty($packLinks)): ?>
            <p class="text-xs text-emerald-400 mt-2"><?= count($packLinks) ?> dedicated links</p>
            <?php endif; ?>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5 text-sm">
            <h3 class="font-semibold mb-2">Collection</h3>
            <p class="text-xs text-gray-400 mb-2"><?= e(collectionModeLabel(getMerchantCollectionMode($merchant))) ?></p>
            <?php
            $merchantVaRows = function_exists('getMerchantVirtualAccounts') ? getMerchantVirtualAccounts($id) : [];
            $primaryVaNumber = getMerchantPrimaryVaNumber($id);
            if ($merchantVaRows !== []): ?>
            <ul class="text-xs font-mono text-sky-400 space-y-1 mb-2">
                <?php foreach ($merchantVaRows as $vaRow): ?>
                <li><?= e((string)($vaRow['va_number'] ?? '')) ?><?= !empty($vaRow['is_primary']) ? ' (primary)' : '' ?> · <?= e(function_exists('vaGatewayDisplayName') ? vaGatewayDisplayName((string)($vaRow['gateway'] ?? '')) : (string)($vaRow['gateway'] ?? '')) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php elseif ($primaryVaNumber !== ''): ?>
            <p class="text-xs font-mono text-sky-400">Primary VA: <?= e($primaryVaNumber) ?></p>
            <?php else: ?>
            <p class="text-xs text-gray-500 mb-1">No virtual account on this merchant yet.</p>
            <?php endif; ?>
            <a href="admin_virtual_accounts.php?merchant_id=<?= $id ?>" class="text-xs text-brand-400 block mt-1"><?= $merchantVaRows !== [] || $primaryVaNumber !== '' ? 'Manage Virtual Accounts →' : 'Virtual Accounts (create) →' ?></a>
        </div>
        <div class="glass rounded-xl p-4 sm:p-5 text-xs text-gray-500 space-y-3" id="api-keys">
            <?php if ($adminNewCredential): ?>
            <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-3 mb-2">
                <p class="font-semibold text-emerald-300 mb-1">Copy <?= e(ucfirst((string)($adminNewCredential['mode'] ?? 'test'))) ?> credential now</p>
                <p class="text-[11px] text-gray-600 mb-2">Shown once — secret is stored as hash only.</p>
                <p class="text-gray-500">Key</p>
                <code class="text-brand-400 break-all block mb-2"><?= e($adminNewCredential['key'] ?? '') ?></code>
                <p class="text-gray-500">Secret</p>
                <code class="text-amber-400 break-all block"><?= e($adminNewCredential['secret'] ?? '') ?></code>
            </div>
            <?php endif; ?>
            <div>
                <p class="text-[10px] uppercase text-gray-600 mb-1">UniWeb API (hashed in api_credentials)</p>
                <p class="text-gray-500">Live prefix:</p>
                <code class="text-brand-400 break-all block"><?= e(($apiCredentialSummaries['live']['key_prefix'] ?? 'Not created') . (isset($apiCredentialSummaries['live']) ? '…' : '')) ?></code>
                <p class="text-gray-500 mt-2">Test prefix:</p>
                <code class="text-amber-400 break-all block"><?= e(($apiCredentialSummaries['test']['key_prefix'] ?? 'Not created') . (isset($apiCredentialSummaries['test']) ? '…' : '')) ?></code>
                <p class="text-[11px] text-gray-600 mt-2">Plaintext keys are not stored on merchants table. Regenerate to issue a new credential.</p>
            </div>
            <div class="flex flex-wrap gap-2 pt-1">
                <a href="?id=<?= $id ?>&action=regen_key&mode=live&token=<?= csrfToken() ?>" class="text-red-400 border border-red-500/30 px-2.5 py-1 rounded" onclick="return confirm('Regenerate LIVE API key for this merchant? Old key stops working immediately. Merchant gets an email.')">↻ Regen Live</a>
                <a href="?id=<?= $id ?>&action=regen_key&mode=test&token=<?= csrfToken() ?>" class="text-amber-400 border border-amber-500/30 px-2.5 py-1 rounded" onclick="return confirm('Regenerate TEST API key for this merchant?')">↻ Regen Test</a>
            </div>
            <a href="admin_website.php" class="text-sky-400 hover:underline">Platform API guide →</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
<style>
.pm-toggle-label{position:relative;display:inline-flex;align-items:center;cursor:pointer;width:44px;height:24px;flex-shrink:0;}
.pm-toggle-checkbox{opacity:0;width:0;height:0;position:absolute;}
.pm-toggle-slider{position:absolute;inset:0;background:#374151;border-radius:9999px;transition:background .2s;}
.pm-toggle-slider::before{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:9999px;transition:transform .2s;}
.pm-toggle-checkbox:checked + .pm-toggle-slider{background:#059669;}
.pm-toggle-checkbox:checked + .pm-toggle-slider::before{transform:translateX(20px);}
</style>
<script>
(function(){
    var id = (location.hash || '').replace(/^#/, '');
    if (id) {
        var el = document.getElementById(id);
        if (el) {
            setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); el.classList.add('ring-2','ring-brand-500/50'); }, 120);
        }
    }
})();

function piiReveal(field, merchantId, btn) {
    btn.disabled = true;
    btn.textContent = '...';
    fetch('admin_pii_reveal.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent('<?= csrfToken() ?>') + '&merchant_id=' + merchantId + '&field=' + field
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.value && data.value !== '—') {
            var input = btn.previousElementSibling;
            if (input) input.value = data.value;
            btn.textContent = 'Revealed';
            btn.classList.add('text-amber-400');
        } else {
            btn.textContent = 'N/A';
        }
    })
    .catch(function() { btn.textContent = 'Error'; })
    .finally(function() { setTimeout(function(){ btn.disabled = false; btn.textContent = 'Reveal'; btn.classList.remove('text-amber-400'); }, 5000); });
}
</script>
