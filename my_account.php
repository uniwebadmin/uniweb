<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/contact_change.php';

requireLogin();
$merchant = getMerchant();
ensureMerchantWebsiteEngine();
$db = getDB();

$categories = getBusinessCategories();
$entities = getBusinessEntityTypes();
$entityType = (string)($merchant['business_entity_type'] ?? 'sole_proprietorship');
$taxFields = entityProfileTaxFields($entityType);

if (isset($_GET['cancel_contact_otp'])) {
    cancelMerchantContactChange();
    flash('info', 'Contact change cancelled. Email and mobile were not updated.');
    redirect('my_account.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
    $action = (string)($_POST['action'] ?? 'profile');

    if ($action === 'request_email_change') {
        $res = requestMerchantEmailChange($merchant, (string)($_POST['new_email'] ?? ''));
        flash($res['ok'] ? 'info' : 'error', $res['message']);
        redirect('my_account.php');
    }

    if ($action === 'request_phone_change') {
        $res = requestMerchantPhoneChange($merchant, (string)($_POST['new_phone'] ?? ''));
        flash($res['ok'] ? 'info' : 'error', $res['message']);
        redirect('my_account.php');
    }

    if ($action === 'verify_contact_change') {
        $res = verifyMerchantContactChange(
            $merchant,
            (string)($_POST['otp_new'] ?? ''),
            (string)($_POST['otp_old'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect('my_account.php');
    }

    // Profile fields only — never accept email/phone from this POST.
    $pan = strtoupper(trim($_POST['pan_number'] ?? ''));

    if ($pan && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
        flash('error', 'Invalid PAN format.');
        redirect('my_account.php');
    }

    $db->prepare('UPDATE merchants SET name=?, business_name=?, business_type=?, business_entity_type=?, gstin=?, pan_number=?, cin_llpin=?, address=?, country=?, state=?, district=?, city=?, pincode=? WHERE id=?')
        ->execute([
            trim($_POST['name']), trim($_POST['business_name']), $_POST['business_type'],
            $_POST['business_entity_type'] ?? 'sole_proprietorship',
            trim($_POST['gstin']), $pan ?: null, trim($_POST['cin_llpin'] ?? '') ?: null,
            trim($_POST['address']), trim($_POST['country'] ?? 'India'), trim($_POST['state']),
            trim($_POST['district'] ?? ''), trim($_POST['city']), trim($_POST['pincode']),
            $merchant['id']
        ]);

    flash('success', 'Profile updated successfully.');
    redirect('my_account.php');
}

$merchant = getMerchant();
$pendingContact = merchantContactChangePending();
if ($pendingContact && (int)$pendingContact['merchant_id'] !== (int)$merchant['id']) {
    cancelMerchantContactChange();
    $pendingContact = null;
}

$pageTitle = 'My Account';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl">

    <div class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs text-gray-500">Account status</p>
            <p class="mt-1 flex flex-wrap items-center gap-2"><?= accountModeBadge($merchant) ?> <?= statusBadge($merchant['kyc_status'] ?? 'pending') ?></p>
        </div>
        <?= renderMerchantModeToggle($merchant, 'header') ?>
    </div>

    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-4">Account Info</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Merchant ID</span>
                <div class="flex items-center gap-2 mt-1">
                    <p class="font-mono text-brand-400" id="merchant-code"><?= e($merchant['merchant_code']) ?></p>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('merchant-code').textContent);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)" class="text-xs bg-brand-600/20 text-brand-400 px-2 py-1 rounded hover:bg-brand-600/30">Copy</button>
                </div>
            </div>
            <div><span class="text-gray-500">UPI ID</span><p class="font-mono text-sm"><?= e($merchant['upi_id']) ?></p></div>
            <div><span class="text-gray-500">Email</span><p><?= e($merchant['email']) ?></p></div>
            <div><span class="text-gray-500">Phone</span><p><?= e($merchant['phone']) ?></p></div>
            <div><span class="text-gray-500">Legal Entity</span><p><?= e(entityTypeLabel($merchant['business_entity_type'] ?? 'sole_proprietorship')) ?></p></div>
            <div><span class="text-gray-500">KYC Status</span><p><?= statusBadge($merchant['kyc_status']) ?></p></div>
            <div><span class="text-gray-500">Commission</span><p><?= $merchant['commission_rate'] ?>% (Cards/NB)</p></div>
            <div><span class="text-gray-500">Category</span><p><?= e(categoryLabel($merchant['business_type'])) ?></p></div>
            <div class="col-span-2"><span class="text-gray-500">Website</span>
                <p class="mt-1 flex flex-wrap items-center gap-2">
                    <?php if (!empty($merchant['website_url'])): ?>
                    <a href="<?= e($merchant['website_url']) ?>" target="_blank" rel="noopener" class="text-sky-400 hover:underline break-all"><?= e($merchant['website_url']) ?></a>
                    <?= merchantWebsiteStatusBadge($merchant) ?>
                    <?php else: ?>
                    <span class="text-gray-500">Not added</span>
                    <a href="merchant_website.php" class="text-xs text-sky-400">Add Website →</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl p-6 mb-6 border border-amber-500/20">
        <h2 class="font-semibold mb-1">Login email &amp; mobile</h2>
        <p class="text-xs text-gray-500 mb-4">Changing email or mobile requires OTP verification. We never update these from a plain profile save.</p>

        <?php if ($pendingContact): ?>
            <?php
            $fieldLabel = $pendingContact['field'] === 'email' ? 'email' : 'mobile';
            $requireOld = !empty($pendingContact['require_old']);
            $demoNew = $pendingContact['demo_otp_new'] ?? null;
            $demoOld = $pendingContact['demo_otp_old'] ?? null;
            ?>
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 mb-4">
                <p class="text-sm text-amber-200/90">Pending <?= e($fieldLabel) ?> change to <span class="font-mono text-white"><?= e((string)$pendingContact['new_value']) ?></span></p>
                <p class="text-xs text-gray-500 mt-1">Enter the OTP<?= $requireOld ? 's' : '' ?> below. Contact is applied only after successful verification.</p>
                <?php if ($demoNew || $demoOld): ?>
                <div class="mt-3 text-sm space-y-1">
                    <?php if ($demoNew): ?>
                    <p class="text-sky-300">Demo OTP (new <?= e($fieldLabel) ?>): <span class="font-mono tracking-widest text-white"><?= e((string)$demoNew) ?></span></p>
                    <?php endif; ?>
                    <?php if ($requireOld && $demoOld): ?>
                    <p class="text-sky-300">Demo OTP (current <?= e($fieldLabel) ?>): <span class="font-mono tracking-widest text-white"><?= e((string)$demoOld) ?></span></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="verify_contact_change">
                <div>
                    <label class="text-sm text-gray-400">OTP sent to new <?= e($fieldLabel) ?></label>
                    <input type="text" name="otp_new" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="input-field mt-1 text-center text-xl tracking-widest" placeholder="000000" autofocus>
                </div>
                <?php if ($requireOld): ?>
                <div>
                    <label class="text-sm text-gray-400">OTP sent to current <?= e($fieldLabel) ?></label>
                    <input type="text" name="otp_old" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="input-field mt-1 text-center text-xl tracking-widest" placeholder="000000">
                </div>
                <?php endif; ?>
                <div class="flex flex-wrap gap-3 items-center">
                    <button type="submit" class="btn-primary px-6 py-2.5">Verify &amp; update <?= e($fieldLabel) ?></button>
                    <a href="my_account.php?cancel_contact_otp=1" class="text-sm text-gray-500 hover:text-white">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <div class="grid sm:grid-cols-2 gap-6">
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="request_email_change">
                    <label class="text-sm text-gray-400">New email</label>
                    <input type="email" name="new_email" required maxlength="190" class="input-field" placeholder="you@business.com" autocomplete="email">
                    <button type="submit" class="btn-primary px-4 py-2 text-sm w-full sm:w-auto">Send email OTP</button>
                </form>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="request_phone_change">
                    <label class="text-sm text-gray-400">New mobile (10 digits)</label>
                    <input type="tel" name="new_phone" required maxlength="10" pattern="[6-9][0-9]{9}" inputmode="numeric" class="input-field" placeholder="9876543210" autocomplete="tel-national">
                    <button type="submit" class="btn-primary px-4 py-2 text-sm w-full sm:w-auto">Send mobile OTP</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Edit Profile</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="profile">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Full Name</label><input type="text" name="name" required class="input-field mt-1" value="<?= e($merchant['name']) ?>"></div>
                <div><label class="text-sm text-gray-400">Business Name</label><input type="text" name="business_name" required class="input-field mt-1" value="<?= e($merchant['business_name']) ?>"></div>
                <div class="col-span-2"><label class="text-sm text-gray-400">Legal Entity Type</label>
                    <select name="business_entity_type" class="input-field mt-1">
                        <?php foreach ($entities as $k=>$v): ?><option value="<?= $k ?>" <?= ($merchant['business_entity_type']??'sole_proprietorship')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="text-sm text-gray-400">Business Category</label>
                    <select name="business_type" class="input-field mt-1"><?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>" <?= $merchant['business_type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                </div>
                <div><label class="text-sm text-gray-400">PAN</label><input type="text" name="pan_number" maxlength="10" class="input-field mt-1 uppercase" value="<?= e($merchant['pan_number']??'') ?>"></div>
                <?php if (!empty($taxFields['gst'])): ?>
                <div><label class="text-sm text-gray-400">GSTIN</label><input type="text" name="gstin" class="input-field mt-1" value="<?= e($merchant['gstin']??'') ?>"></div>
                <?php else: ?>
                <input type="hidden" name="gstin" value="<?= e($merchant['gstin']??'') ?>">
                <?php endif; ?>
                <?php if (!empty($taxFields['cin'])): ?>
                <div class="col-span-2"><label class="text-sm text-gray-400">CIN / LLPIN</label><input type="text" name="cin_llpin" class="input-field mt-1" value="<?= e($merchant['cin_llpin']??'') ?>" placeholder="For Pvt Ltd / LLP / OPC"></div>
                <?php else: ?>
                <input type="hidden" name="cin_llpin" value="<?= e($merchant['cin_llpin']??'') ?>">
                <?php endif; ?>
                <p class="col-span-2 text-xs text-gray-500">KYC documents on the next step match this entity. Individual sees PAN / Aadhaar / bank / photo only — no GST or CIN.</p>
            </div>
            <?php
            $addressPrefix = 'profile';
            $addressTitle = 'Business Address';
            $addressValues = [
                'address' => $merchant['address'] ?? '',
                'country' => $merchant['country'] ?? 'India',
                'state' => $merchant['state'] ?? '',
                'district' => $merchant['district'] ?? '',
                'city' => $merchant['city'] ?? '',
                'pincode' => $merchant['pincode'] ?? '',
            ];
            require __DIR__ . '/includes/address_form.php';
            ?>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Changes</button>
        </form>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
