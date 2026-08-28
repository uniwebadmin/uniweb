<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/includes/release_helpers.php')) {
    require_once __DIR__ . '/includes/release_helpers.php';
}
require_once __DIR__ . '/includes/page_ux.php';
$merchant = requireMerchantAccount();

$errors = [];
$categories = getBusinessCategories();
$entities = getBusinessEntityTypes();
$collectionModes = getMerchantFacingCollectionModes($merchant);
$methodCatalog = getPaymentMethodCatalog();
$defaultMethods = ['upi_p2m', 'debit_card', 'credit_card', 'netbanking'];
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? 'complete');
    if ($action === 'save_draft') {
        $result = saveMerchantOnboardingDraft((int)$merchant['id'], $_POST);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('merchant_setup.php');
    }

    $name = trim($_POST['name'] ?? '');
    $business = trim($_POST['business_name'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $state = trim($_POST['state'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $entity = $_POST['business_entity_type'] ?? 'individual';
    $category = $_POST['business_type'] ?? 'retail';
    $pan = strtoupper(trim($_POST['pan_number'] ?? ''));
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
    // If masked value submitted, treat as unchanged
    if ($pan && str_starts_with($pan, '*')) $pan = '';
    if ($gstin && str_starts_with($gstin, '*')) $gstin = '';
    $collectionMode = $_POST['collection_mode'] ?? getSetting('default_collection_mode', 'direct_upi');
    $allowedModes = array_keys(getMerchantFacingCollectionModes($merchant));
    if (!in_array($collectionMode, $allowedModes, true)) {
        $collectionMode = 'direct_upi';
    }
    $enabledMethods = array_values(array_intersect(
        array_keys(getPaymentMethodCatalog()),
        array_map('strval', $_POST['enabled_methods'] ?? ['upi_p2m', 'debit_card'])
    ));
    if (empty($enabledMethods)) {
        $enabledMethods = ['upi_p2m'];
    }

    if (!$name || !$business || !$country || !$state || !$district || !$city || !$pincode) {
        $errors[] = __('err_setup_required');
    }
    if ($pan && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
        $errors[] = __('err_invalid_pan');
    }
    if ($gstin && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$/', $gstin)) {
        $errors[] = 'Invalid GSTIN format. Example: 27ABCDE1234F1Z5';
    }
    // Point 4: PAN/GSTIN duplicate check (skip for own merchant record)
    if ($pan && empty($errors) && function_exists('checkPanGstinDuplicate')) {
        try {
            $dupCheck = checkPanGstinDuplicate($pan, $gstin !== '' ? $gstin : null);
            if (!$dupCheck['allowed'] && (int)($dupCheck['existing_merchant_id'] ?? 0) !== (int)$merchant['id']) {
                $errors[] = $dupCheck['reason'];
            }
        } catch (Throwable $e) { /* safe fallback — allow */ }
    }
    if (!isset($entities[$entity])) {
        $errors[] = __('err_invalid_entity');
    }

    if (empty($errors)) {
        $alreadyCompleted = (string)($merchant['provision_profile'] ?? '') === 'signup_custom';
        $db->prepare('UPDATE merchants SET name=?, business_name=?, business_type=?, business_entity_type=?, pan_number=?, address=?, country=?, state=?, district=?, city=?, pincode=? WHERE id=?')
            ->execute([$name, $business, $category, $entity, $pan ? sensitiveEncrypt($pan) : null, $address !== '' ? sensitiveEncrypt($address) : null, $country, $state, $district, $city, $pincode, $merchant['id']]);
        // Try to save gstin column (may not exist on older DBs)
        if ($gstin !== '') {
            try { $db->prepare('UPDATE merchants SET gstin=? WHERE id=?')->execute([sensitiveEncrypt($gstin), $merchant['id']]); } catch (Throwable $e) {}
        }

        // Link user to merchant in user_merchant_roles
        if (function_exists('linkUserToMerchant')) {
            try { linkUserToMerchant((string)$merchant['email'], (string)($merchant['phone'] ?? ''), (int)$merchant['id'], 'owner'); } catch (Throwable $e) {}
        }

        applyMerchantSignupPreferences((int)$merchant['id'], $collectionMode, $enabledMethods);

        if (!$alreadyCompleted) {
            try {
                $db->prepare("UPDATE merchants SET kyc_status='submitted' WHERE id=? AND kyc_status IN ('pending','')")->execute([(int)$merchant['id']]);
            } catch (Throwable $e) { /* ok */ }

            try {
                $db->prepare('UPDATE merchants SET provision_profile=? WHERE id=?')->execute(['signup_custom', $merchant['id']]);
            } catch (Throwable $e) { /* ok */ }

            notifyAdminNewMerchantSignup((int)$merchant['id']);
            notifyMerchant((int)$merchant['id'], __('setup_title'), __('notif_profile_saved'), 'profile_saved');
            notifyMerchant((int)$merchant['id'], 'Payment Pack Ready', 'Your test payment links are ready. Open Payment Pack to try ₹1 test payments.', 'payment_pack_ready');
        }

        clearMerchantOnboardingDraft((int)$merchant['id']);
        flash('success', $alreadyCompleted ? 'Profile updated. Your launch setup remains unchanged.' : __('flash_profile_saved') . ' Payment Pack created — check your dashboard.');
        redirect('dashboard.php');
    }
}

$merchant = getMerchant();
$draft = getMerchantOnboardingDraft((int)$merchant['id']);
$formData = !empty($_POST) ? $_POST : $draft;
$enabledMethods = getMerchantEnabledMethods($merchant);
if (empty($enabledMethods)) {
    $enabledMethods = $defaultMethods;
}
$addressValues = [
    'address' => (isset($formData['address']) && $formData['address'] !== '' && !(function_exists('isSensitiveEncrypted') && isSensitiveEncrypted((string)$formData['address']))) ? (string)$formData['address'] : sensitiveUiPlain($merchant['address'] ?? ''),
    'country' => $formData['country'] ?? ($merchant['country'] ?? 'India'),
    'state' => $formData['state'] ?? ($merchant['state'] ?? ''),
    'district' => $formData['district'] ?? ($merchant['district'] ?? ''),
    'city' => $formData['city'] ?? ($merchant['city'] ?? ''),
    'pincode' => $formData['pincode'] ?? ($merchant['pincode'] ?? ''),
];

$pageTitle = __('setup_title');
$hideNav = true;
$footerVariant = 'auth';
$signupApiCredential = $_SESSION['new_api_credential'] ?? null;
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl mx-auto space-y-6">
<?php if ($signupApiCredential): unset($_SESSION['new_api_credential']); ?>
<div class="glass rounded-xl p-5 border border-emerald-500/40 bg-emerald-500/5">
    <h2 class="font-semibold text-emerald-300 mb-2">Your Test API key — copy now</h2>
    <p class="text-xs text-gray-500 mb-3">Secret is shown once. You can regenerate anytime from API Settings.</p>
    <label class="text-xs text-gray-500">API Key</label>
    <input readonly class="input-field font-mono text-xs mt-1 mb-3" value="<?= e($signupApiCredential['key'] ?? '') ?>">
    <label class="text-xs text-gray-500">API Secret</label>
    <input readonly class="input-field font-mono text-xs mt-1 mb-3" value="<?= e($signupApiCredential['secret'] ?? '') ?>">
    <a href="api_settings.php" class="text-xs text-sky-400 hover:underline">Open API Settings →</a>
</div>
<?php endif; ?>

    <div class="mb-2">
        <h1 class="text-xl font-bold"><?= __('setup_title') ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= __('setup_sub') ?></p>
    </div>

    <?php if ($errors): ?>
    <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg">
        <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-xl p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <p class="text-xs text-brand-400 font-medium uppercase tracking-wide"><?= __('personal_business') ?></p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="text-sm text-gray-400"><?= __('full_name') ?> *</label>
                <input type="text" name="name" required class="input-field mt-1" value="<?= e($formData['name'] ?? $merchant['name'] ?? '') ?>">
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm text-gray-400"><?= __('shop_name') ?> *</label>
                <input type="text" name="business_name" required class="input-field mt-1" value="<?= e($formData['business_name'] ?? (($merchant['business_name'] ?? '') === 'My Business' ? '' : ($merchant['business_name'] ?? ''))) ?>">
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm text-gray-400"><?= __('account_type') ?> *</label>
                <select name="business_entity_type" required class="input-field mt-1">
                    <?php foreach ($entities as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($formData['business_entity_type'] ?? $merchant['business_entity_type'] ?? 'individual') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400"><?= __('business_category') ?></label>
                <select name="business_type" class="input-field mt-1">
                    <?php foreach ($categories as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($formData['business_type'] ?? $merchant['business_type'] ?? 'retail') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400"><?= __('pan_optional') ?></label>
                <input type="text" name="pan_number" maxlength="10" class="input-field mt-1 uppercase" value="<?= e((isset($formData['pan_number']) && $formData['pan_number'] !== '' && !str_starts_with((string)$formData['pan_number'], '*') && !(function_exists('isSensitiveEncrypted') && isSensitiveEncrypted((string)$formData['pan_number']))) ? (string)$formData['pan_number'] : sensitiveUiPlain($merchant['pan_number'] ?? '')) ?>" placeholder="ABCDE1234F" autocomplete="off">
            </div>
            <div>
                <label class="text-sm text-gray-400">GSTIN (optional)</label>
                <input type="text" name="gstin" maxlength="15" class="input-field mt-1 uppercase" value="<?= e((isset($formData['gstin']) && $formData['gstin'] !== '' && !str_starts_with((string)$formData['gstin'], '*') && !(function_exists('isSensitiveEncrypted') && isSensitiveEncrypted((string)$formData['gstin']))) ? (string)$formData['gstin'] : sensitiveUiPlain($merchant['gstin'] ?? '')) ?>" placeholder="27ABCDE1234F1Z5" autocomplete="off">
            </div>
        </div>

        <?php
        $addressPrefix = 'setup';
        $addressTitle = __('business_address');
        require __DIR__ . '/includes/address_form.php';
        ?>

        <p class="text-xs text-brand-400 font-medium uppercase tracking-wide pt-2"><?= __('payment_setup') ?></p>
        <p class="text-xs text-gray-500"><?= __('payment_setup_note') ?></p>
        <div class="space-y-5 bg-dark-900/40 rounded-xl p-4 sm:p-5 border border-gray-800">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label class="text-sm font-semibold text-white"><?= __('collection_mode') ?> *</label>
                    <span class="text-[10px] uppercase tracking-wider text-brand-400">Choose your primary flow</span>
                </div>
                <p class="text-xs text-gray-500 mt-1">Your available payment setup follows partner approval and account verification.</p>
                <select name="collection_mode" required class="input-field mt-3">
                    <?php foreach ($collectionModes as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= ($formData['collection_mode'] ?? getMerchantCollectionMode($merchant)) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pt-4 border-t border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <label class="text-sm font-semibold text-white"><?= __('payment_methods') ?></label>
                    <span class="text-xs text-gray-500">Select what you need</span>
                </div>
                <div class="grid sm:grid-cols-2 gap-2 max-h-52 overflow-y-auto pr-1">
                    <?php
                    $postedMethods = $formData['enabled_methods'] ?? $enabledMethods;
                    foreach ($methodCatalog as $mk => $cat):
                    ?>
                    <label class="flex items-center gap-3 text-sm cursor-pointer rounded-lg border border-gray-800 px-3 py-2.5 hover:border-brand-500/40 hover:bg-brand-500/5 transition">
                        <input type="checkbox" name="enabled_methods[]" value="<?= e($mk) ?>" <?= in_array($mk, $postedMethods, true) ? 'checked' : '' ?> class="rounded border-gray-600 accent-emerald-500">
                        <span class="text-gray-200"><?= e(($cat['icon'] ?? '') . ' ' . $cat['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
            <button type="submit" name="action" value="complete" class="btn-primary px-6 py-3"><?= __('save_dashboard') ?></button>
            <button type="submit" name="action" value="save_draft" formnovalidate class="px-5 py-3 rounded-xl border border-sky-500/40 text-sm text-sky-300 hover:bg-sky-500/10">Save &amp; Resume Later</button>
            <?php if (merchantProfileComplete($merchant)): ?>
            <a href="dashboard.php" class="text-sm text-gray-400 hover:text-white px-4 py-3"><?= __('skip_for_now') ?></a>
            <?php endif; ?>
        </div>

        <p class="text-xs text-gray-600 text-center pt-4 border-t border-gray-800">
            <?= __('login_credential') ?>: <span class="text-gray-400"><?= e(sensitiveUiPlain($merchant['email'] ?? '') ?: (string)($merchant['email'] ?? '')) ?></span> · <span class="text-gray-400"><?= e(sensitiveUiPlain($merchant['phone'] ?? '') ?: (string)($merchant['phone'] ?? '')) ?></span>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
