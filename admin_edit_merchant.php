<?php
require_once __DIR__ . '/config.php';
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
    flash('success', $_GET['action'] === 'verify_website' ? 'Website marked verified.' : 'Website marked rejected.');
    redirect('admin_edit_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'provision_axis_va' && verifyCsrf($_GET['token'] ?? '')) {
    ensureAxisVirtualAccount($id);
    flash('success', 'Axis Virtual Account provisioned.');
    redirect('admin_edit_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'auto_provision' && verifyCsrf($_GET['token'] ?? '')) {
    $result = autoProvisionMerchant($id, (int)($_SESSION['admin_id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('admin_edit_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'regen_key' && verifyCsrf($_GET['token'] ?? '')) {
    $mode = ($_GET['mode'] ?? 'live') === 'test' ? 'test' : 'live';
    $result = regenerateMerchantApiKey($id, $mode, (int)($_SESSION['admin_id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? ucfirst($mode) . ' API key regenerated. Merchant notified by email + dashboard.'
        : ($result['error'] ?? 'Failed.'));
    redirect('admin_edit_merchant.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $pan = strtoupper(trim($_POST['pan_number'] ?? ''));
    if ($pan && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
        flash('error', 'Invalid PAN format.');
        redirect('admin_edit_merchant.php?id=' . $id);
    }
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

    $enabled = array_values(array_intersect(
        array_keys(getPaymentMethodCatalog()),
        array_map('strval', $_POST['enabled_methods'] ?? [])
    ));
    $enabledJson = json_encode($enabled);

    $db->prepare('UPDATE merchants SET name=?, business_name=?, email=?, phone=?, business_type=?, business_entity_type=?, pan_number=?, gstin=?, cin_llpin=?, commission_rate=?, kyc_status=?, account_mode=?, subscription_plan=?, monthly_fee=?, status=?, collection_mode=?, payu_child_key=?, razorpay_linked_account_id=?, cashfree_vendor_id=?, enabled_methods=?, website_url=?, android_app_url=?, ios_app_url=? WHERE id=?')
        ->execute([
            trim($_POST['name']),
            trim($_POST['business_name']),
            trim($_POST['email']),
            trim($_POST['phone']),
            $_POST['business_type'],
            $_POST['business_entity_type'],
            $pan ?: null,
            trim($_POST['gstin'] ?? '') ?: null,
            trim($_POST['cin_llpin'] ?? '') ?: null,
            (float)($_POST['commission_rate'] ?? 1.5),
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

    if ($accountMode === 'live' && ($merchant['account_mode'] ?? 'test') !== 'live') {
        createNotification($id, 'Account Live!', 'Admin activated your LIVE mode. You can now accept real payments.');
    }
    logStaffActivity('merchant_edited', 'Profile updated — mode ' . $accountMode . ', KYC ' . $kycStatus, $id);
    flash('success', 'Merchant profile updated.');
    redirect('admin_edit_merchant.php?id=' . $id);
}

$pageTitle = 'Edit Merchant — ' . $merchant['merchant_code'];
require_once __DIR__ . '/header.php';
$kycProgress = getMerchantKycProgress($merchant);
$methodPreview = merchantMethodPreview($merchant);
$packLinks = getMerchantPackLinks($id, $merchant['provision_pack_id'] ?? null);
$enabledMethods = getMerchantEnabledMethods($merchant);
$methodCatalog = getPaymentMethodCatalog();
?>

<div class="mb-4 flex flex-wrap gap-3 items-center">
    <a href="manage_merchant.php" class="text-sm text-gray-400 hover:text-white">← Back to Merchants</a>
    <a href="admin_view_merchant.php?id=<?= $id ?>" class="text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg font-semibold">👁 Merchant View</a>
    <a href="?id=<?= $id ?>&action=auto_provision&token=<?= csrfToken() ?>" class="text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg font-semibold" onclick="return confirm('Auto Setup: profile + gateway submit + all method links (₹1)?')">⚡ Auto Setup Merchant</a>
    <a href="admin_partner_requests.php" class="text-sm text-sky-400">Partner Emails →</a>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 glass rounded-xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-semibold text-lg"><?= e($merchant['business_name']) ?></h2>
            <?= accountModeBadge($merchant) ?>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <p class="text-xs text-brand-400 font-medium uppercase tracking-wide">Personal & Business</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Full Name</label><input type="text" name="name" required class="input-field mt-1" value="<?= e($merchant['name']) ?>"></div>
                <div><label class="text-sm text-gray-400">Business Name</label><input type="text" name="business_name" required class="input-field mt-1" value="<?= e($merchant['business_name']) ?>"></div>
                <div><label class="text-sm text-gray-400">Email</label><input type="email" name="email" required class="input-field mt-1" value="<?= e($merchant['email']) ?>"></div>
                <div><label class="text-sm text-gray-400">Phone</label><input type="text" name="phone" required class="input-field mt-1" value="<?= e($merchant['phone']) ?>"></div>
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
                <div><label class="text-sm text-gray-400">PAN</label><input type="text" name="pan_number" maxlength="10" class="input-field mt-1 uppercase" value="<?= e($merchant['pan_number'] ?? '') ?>"></div>
                <?php if (!empty($editTaxFields['gst'])): ?>
                <div><label class="text-sm text-gray-400">GSTIN</label><input type="text" name="gstin" class="input-field mt-1" value="<?= e($merchant['gstin'] ?? '') ?>"></div>
                <?php else: ?>
                <input type="hidden" name="gstin" value="<?= e($merchant['gstin'] ?? '') ?>">
                <?php endif; ?>
                <?php if (!empty($editTaxFields['cin'])): ?>
                <div><label class="text-sm text-gray-400">CIN / LLPIN</label><input type="text" name="cin_llpin" class="input-field mt-1" value="<?= e($merchant['cin_llpin'] ?? '') ?>"></div>
                <?php else: ?>
                <input type="hidden" name="cin_llpin" value="<?= e($merchant['cin_llpin'] ?? '') ?>">
                <?php endif; ?>
                <p class="sm:col-span-2 text-xs text-gray-500">GSTIN / CIN fields follow the selected entity type (Individual hides GST and CIN).</p>
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
                    <select name="account_mode" class="input-field mt-1">
                        <option value="test" <?= merchantAccountMode($merchant) === 'test' ? 'selected' : '' ?>>Test Mode (Sandbox)</option>
                        <option value="live" <?= merchantAccountMode($merchant) === 'live' ? 'selected' : '' ?>>Live Mode (Real Payments)</option>
                    </select>
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
                <div><label class="text-sm text-gray-400">Commission Rate (%)</label><input type="number" name="commission_rate" step="0.01" min="0" class="input-field mt-1" value="<?= e((string)($merchant['commission_rate'] ?? 1.5)) ?>"></div>
                <div>
                    <label class="text-sm text-gray-400">Collection Mode</label>
                    <select name="collection_mode" class="input-field mt-1">
                        <?php foreach (getCollectionModes() as $k => $label): ?>
                        <option value="<?= $k ?>" <?= getMerchantCollectionMode($merchant) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <label class="text-sm text-gray-400">Payment Methods (enabled for merchant)</label>
                        <div class="flex gap-2 text-xs">
                            <button type="button" id="enable-all-methods" class="px-2.5 py-1 rounded-lg border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10">Enable all</button>
                            <button type="button" id="disable-all-methods" class="px-2.5 py-1 rounded-lg border border-gray-700 text-gray-400 hover:bg-white/5">Clear</button>
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2 bg-dark-900/50 rounded-xl p-4 border border-gray-800 max-h-48 overflow-y-auto" id="enabled-methods-box">
                        <?php foreach ($methodCatalog as $mk => $cat): ?>
                        <label class="flex items-center gap-2 text-xs cursor-pointer">
                            <input type="checkbox" name="enabled_methods[]" value="<?= e($mk) ?>" <?= in_array($mk, $enabledMethods, true) ? 'checked' : '' ?> class="rounded border-gray-600 method-toggle">
                            <span><?= e(($cat['icon'] ?? '') . ' ' . $cat['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
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
            <button type="submit" class="btn-primary px-8 py-2.5">Save Changes</button>
        </form>
    </div>
    <div class="space-y-4">
        <div class="glass rounded-xl p-5 text-sm" id="website">
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
        <div class="glass rounded-xl p-5">
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
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-2">Wallet</h3>
            <p class="text-xl font-bold"><?= formatMoney(getMerchantWalletBalance($merchant)) ?></p>
            <p class="text-xs text-gray-500 mt-1">Merchant ID: <?= e($merchant['merchant_code']) ?></p>
        </div>
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-2">Payment Profile</h3>
            <p class="text-xs text-gray-400"><?= e($methodPreview['profile_label']) ?></p>
            <p class="text-xs text-brand-400 mt-2"><?= count($methodPreview['methods']) ?> methods · <?= !empty($merchant['auto_provisioned']) ? 'Auto ✓' : 'Manual' ?></p>
            <?php if (!empty($packLinks)): ?>
            <p class="text-xs text-emerald-400 mt-2"><?= count($packLinks) ?> dedicated links</p>
            <?php endif; ?>
        </div>
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-2">Collection</h3>
            <p class="text-xs text-gray-400 mb-2"><?= e(collectionModeLabel(getMerchantCollectionMode($merchant))) ?></p>
            <?php if (!empty($merchant['axis_va_number'])): ?>
            <p class="text-xs font-mono text-sky-400">VA: <?= e($merchant['axis_va_number']) ?></p>
            <?php else: ?>
            <a href="admin_edit_merchant.php?id=<?= $id ?>&action=provision_axis_va&token=<?= csrfToken() ?>" class="text-xs text-brand-400">Provision Axis VA →</a>
            <?php endif; ?>
        </div>
        <div class="glass rounded-xl p-5 text-xs text-gray-500 space-y-3" id="api-keys">
            <div>
                <p class="text-[10px] uppercase text-gray-600 mb-1">UniWeb API (give to merchant)</p>
                <p class="text-gray-500">Live key:</p>
                <code class="text-brand-400 break-all block"><?= e($merchant['api_key'] ?? '—') ?></code>
                <p class="text-gray-500 mt-2">Test key:</p>
                <code class="text-amber-400 break-all block"><?= e($merchant['test_api_key'] ?? '—') ?></code>
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
<script>
(function(){
    var id = (location.hash || '').replace(/^#/, '');
    if (id) {
        var el = document.getElementById(id);
        if (el) {
            setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); el.classList.add('ring-2','ring-brand-500/50'); }, 120);
        }
    }
    function setAllMethods(on) {
        document.querySelectorAll('#enabled-methods-box .method-toggle').forEach(function(cb){ cb.checked = !!on; });
    }
    document.getElementById('enable-all-methods')?.addEventListener('click', function(){ setAllMethods(true); });
    document.getElementById('disable-all-methods')?.addEventListener('click', function(){ setAllMethods(false); });
})();
</script>
