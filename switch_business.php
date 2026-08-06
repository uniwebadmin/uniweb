<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
if (!$merchant) {
    redirect('login.php');
}

ensureMultiMerchantTables();

// Handle switch
$switchTo = (int)($_GET['id'] ?? 0);
if ($switchTo > 0 && verifyCsrf($_GET['token'] ?? '')) {
    if (switchActiveMerchant($switchTo)) {
        $newMerchant = getMerchant();
        flash('success', 'Switched to: ' . (string)($newMerchant['business_name'] ?? 'Business'));
        redirect('dashboard.php');
    } else {
        flash('error', 'You do not have access to that business.');
        redirect('switch_business.php');
    }
}

// Handle add business
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_business') {
        $pan = strtoupper(trim($_POST['pan_number'] ?? ''));
        $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
        $businessName = trim($_POST['business_name'] ?? '');
        $entityType = trim($_POST['business_entity_type'] ?? 'individual');

        $errors = [];
        if (!$pan || !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            $errors[] = 'Valid PAN is required to add a business.';
        }
        if (!$businessName) {
            $errors[] = 'Business name is required.';
        }
        if ($gstin && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$/', $gstin)) {
            $errors[] = 'Invalid GSTIN format.';
        }

        if (empty($errors)) {
            $result = createAdditionalBusiness($merchant, $pan, $gstin !== '' ? $gstin : null, $businessName, $entityType);
            if ($result['ok']) {
                $_SESSION['merchant_id'] = $result['merchant_id'];
                flash('success', 'New business created! Complete your setup below.');
                redirect('merchant_setup.php');
            } else {
                flash('error', $result['error']);
            }
        } else {
            flash('error', implode(' ', $errors));
        }
        redirect('switch_business.php');
    }
}

$userMerchants = getUserMerchants((string)$merchant['email'], (string)($merchant['phone'] ?? ''));
$entities = getBusinessEntityTypes();

$pageTitle = 'Switch Business';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-bold">Your Businesses</h1>
        <p class="text-sm text-gray-500 mt-1">Switch between businesses or add a new one with the same PAN and a different GSTIN.</p>
    </div>

    <?php if (count($userMerchants) > 0): ?>
    <div class="glass rounded-xl divide-y divide-gray-800">
        <?php foreach ($userMerchants as $um):
            $isActive = (int)$um['id'] === (int)$merchant['id'];
        ?>
        <div class="flex items-center justify-between p-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold <?= $isActive ? 'text-emerald-400' : 'text-gray-200' ?>">
                    <?= e($um['business_name'] ?? 'Unnamed') ?>
                    <?php if ($isActive): ?><span class="text-xs text-emerald-400 ml-2">(current)</span><?php endif; ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    <?= e($um['merchant_code'] ?? '') ?> ·
                    <?= e(ucfirst((string)($um['kyc_status'] ?? 'pending'))) ?> ·
                    <?= e(ucfirst((string)($um['account_mode'] ?? 'test'))) ?>
                </p>
            </div>
            <?php if (!$isActive): ?>
            <a href="switch_business.php?id=<?= (int)$um['id'] ?>&token=<?= e(csrfToken()) ?>"
               class="btn-primary text-xs px-3 py-2 rounded-lg whitespace-nowrap">Switch</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <details class="glass rounded-xl p-6">
        <summary class="cursor-pointer font-semibold text-sm">+ Add New Business</summary>
        <p class="text-xs text-gray-500 mt-2 mb-4">Use the same PAN with a different GSTIN to manage multiple businesses from one login.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_business">
            <div>
                <label class="text-sm text-gray-400">Business Name *</label>
                <input type="text" name="business_name" required class="input-field mt-1" placeholder="My Second Shop">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">PAN Number *</label>
                    <input type="text" name="pan_number" required maxlength="10" class="input-field mt-1 uppercase" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()" placeholder="ABCDE1234F">
                </div>
                <div>
                    <label class="text-sm text-gray-400">GSTIN</label>
                    <input type="text" name="gstin" maxlength="15" class="input-field mt-1 uppercase" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()" placeholder="27ABCDE1234F1Z5">
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-400">Entity Type</label>
                <select name="business_entity_type" class="input-field mt-1">
                    <?php foreach ($entities as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Create Business</button>
        </form>
    </details>

    <p class="text-xs text-gray-600 text-center">
        <a href="dashboard.php" class="text-brand-400 hover:underline">← Back to Dashboard</a>
    </p>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
