<?php

require_once __DIR__ . '/config.php';

requireLogin();
$merchant = getMerchant();
ensureMerchantWebsiteEngine();
$db = getDB();

$categories = getBusinessCategories();

$entities = getBusinessEntityTypes();



if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {

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

    <div class="glass rounded-xl p-6">

        <h2 class="font-semibold mb-4">Edit Profile</h2>

        <form method="POST" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

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

                <div><label class="text-sm text-gray-400">GSTIN</label><input type="text" name="gstin" class="input-field mt-1" value="<?= e($merchant['gstin']??'') ?>"></div>

                <div class="col-span-2"><label class="text-sm text-gray-400">CIN / LLPIN</label><input type="text" name="cin_llpin" class="input-field mt-1" value="<?= e($merchant['cin_llpin']??'') ?>" placeholder="For Pvt Ltd / LLP / OPC"></div>

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

