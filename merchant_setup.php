<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
if (!$merchant) {
    session_destroy();
    redirect('login.php');
}

$errors = [];
$categories = getBusinessCategories();
$entities = getBusinessEntityTypes();
$collectionModes = getCollectionModes();
$methodCatalog = getPaymentMethodCatalog();
$defaultMethods = ['upi_p2m', 'debit_card', 'credit_card', 'netbanking'];
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
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
    $collectionMode = $_POST['collection_mode'] ?? getSetting('default_collection_mode', 'direct_upi');
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
    if (!isset($entities[$entity])) {
        $errors[] = __('err_invalid_entity');
    }

    if (empty($errors)) {
        $db->prepare('UPDATE merchants SET name=?, business_name=?, business_type=?, business_entity_type=?, pan_number=?, address=?, country=?, state=?, district=?, city=?, pincode=? WHERE id=?')
            ->execute([$name, $business, $category, $entity, $pan ?: null, $address, $country, $state, $district, $city, $pincode, $merchant['id']]);

        applyMerchantSignupPreferences((int)$merchant['id'], $collectionMode, $enabledMethods);

        try {
            $db->prepare("UPDATE merchants SET kyc_status='submitted' WHERE id=? AND kyc_status IN ('pending','')")->execute([(int)$merchant['id']]);
        } catch (Throwable $e) { /* ok */ }

        try {
            $db->prepare('UPDATE merchants SET provision_profile=? WHERE id=?')->execute(['signup_custom', $merchant['id']]);
        } catch (Throwable $e) { /* ok */ }

        notifyAdminNewMerchantSignup((int)$merchant['id']);

        createNotification((int)$merchant['id'], __('setup_title'), __('notif_profile_saved'));
        createNotification((int)$merchant['id'], 'Payment Pack Ready', 'Your test payment links are ready. Open Payment Pack to try ₹1 test payments.');

        flash('success', __('flash_profile_saved') . ' Payment Pack created — check your dashboard.');
        redirect('dashboard.php');
    }
}

$merchant = getMerchant();
$enabledMethods = getMerchantEnabledMethods($merchant);
if (empty($enabledMethods)) {
    $enabledMethods = $defaultMethods;
}
$addressValues = [
    'address' => $_POST['address'] ?? ($merchant['address'] ?? ''),
    'country' => $_POST['country'] ?? ($merchant['country'] ?? 'India'),
    'state' => $_POST['state'] ?? ($merchant['state'] ?? ''),
    'district' => $_POST['district'] ?? ($merchant['district'] ?? ''),
    'city' => $_POST['city'] ?? ($merchant['city'] ?? ''),
    'pincode' => $_POST['pincode'] ?? ($merchant['pincode'] ?? ''),
];

$pageTitle = __('setup_title');
$hideNav = true;
$footerVariant = 'auth';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-2xl mx-auto space-y-6">

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
                <input type="text" name="name" required class="input-field mt-1" value="<?= e($_POST['name'] ?? $merchant['name'] ?? '') ?>">
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm text-gray-400"><?= __('shop_name') ?> *</label>
                <input type="text" name="business_name" required class="input-field mt-1" value="<?= e($_POST['business_name'] ?? (($merchant['business_name'] ?? '') === 'My Business' ? '' : ($merchant['business_name'] ?? ''))) ?>">
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm text-gray-400"><?= __('account_type') ?> *</label>
                <select name="business_entity_type" required class="input-field mt-1">
                    <?php foreach ($entities as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($_POST['business_entity_type'] ?? $merchant['business_entity_type'] ?? 'individual') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400"><?= __('business_category') ?></label>
                <select name="business_type" class="input-field mt-1">
                    <?php foreach ($categories as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($_POST['business_type'] ?? $merchant['business_type'] ?? 'retail') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400"><?= __('pan_optional') ?></label>
                <input type="text" name="pan_number" maxlength="10" class="input-field mt-1 uppercase" value="<?= e($_POST['pan_number'] ?? $merchant['pan_number'] ?? '') ?>">
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
                    <option value="<?= e($k) ?>" <?= ($_POST['collection_mode'] ?? getMerchantCollectionMode($merchant)) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
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
                    $postedMethods = $_POST['enabled_methods'] ?? $enabledMethods;
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
            <button type="submit" class="btn-primary px-6 py-3"><?= __('save_dashboard') ?></button>
            <?php if (merchantProfileComplete($merchant)): ?>
            <a href="dashboard.php" class="text-sm text-gray-400 hover:text-white px-4 py-3"><?= __('skip_for_now') ?></a>
            <?php endif; ?>
        </div>

        <p class="text-xs text-gray-600 text-center pt-4 border-t border-gray-800">
            <?= __('login_credential') ?>: <span class="text-gray-400"><?= e($merchant['email']) ?></span> · <span class="text-gray-400"><?= e($merchant['phone']) ?></span>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
