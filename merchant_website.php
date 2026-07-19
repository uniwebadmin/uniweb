<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureMerchantWebsiteEngine();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $result = saveMerchantWebsite(
        (int)$merchant['id'],
        (string)($_POST['website_url'] ?? ''),
        (string)($_POST['android_app_url'] ?? ''),
        (string)($_POST['ios_app_url'] ?? '')
    );
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('merchant_website.php');
}

$merchant = getMerchant();
$pageTitle = 'Website & App';
require_once __DIR__ . '/header.php';
$status = merchantWebsiteStatus($merchant);
?>

<div class="mb-4">
    <a href="merchant_settings.php" class="text-sm text-gray-400 hover:text-white">← Settings</a>
</div>

<div class="max-w-3xl space-y-6">
    <div class="glass rounded-xl p-6 border border-sky-500/20">
        <h2 class="font-semibold text-sky-300 mb-1">Website & App</h2>
        <p class="text-xs text-gray-500 mb-4">Add your business website and mobile app links — required for PayU, Razorpay, and Cashfree live merchant onboarding (like their dashboard Website & App tab).</p>
        <div class="flex flex-wrap gap-2 text-xs text-gray-500">
            <span>Status:</span> <?= merchantWebsiteStatusBadge($merchant) ?>
            <span class="text-gray-600">· Used in gateway KYC submissions</span>
        </div>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold text-white mb-4">Website</h3>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400">Website URL</label>
                <div class="flex flex-wrap items-center gap-2 mt-1">
                    <input type="url" name="website_url" class="input-field flex-1 min-w-[240px]" placeholder="https://yourbusiness.com" value="<?= e($merchant['website_url'] ?? '') ?>">
                    <?= merchantWebsiteStatusBadge($merchant) ?>
                </div>
                <p class="text-[11px] text-gray-600 mt-2">Must show your products/services and contact details. Changing URL resets verification to <strong class="text-gray-400">Verification in Process</strong>.</p>
            </div>

            <div class="border-t border-gray-800 pt-5">
                <h4 class="text-sm font-medium text-gray-300 mb-3">Mobile Apps (optional)</h4>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-400">Android App URL</label>
                        <input type="url" name="android_app_url" class="input-field mt-1" placeholder="https://play.google.com/store/apps/..." value="<?= e($merchant['android_app_url'] ?? '') ?>">
                        <?php if (empty($merchant['android_app_url'])): ?>
                        <p class="text-[11px] text-sky-400 mt-1">Add Android URL</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="text-sm text-gray-400">iOS App URL</label>
                        <input type="url" name="ios_app_url" class="input-field mt-1" placeholder="https://apps.apple.com/app/..." value="<?= e($merchant['ios_app_url'] ?? '') ?>">
                        <?php if (empty($merchant['ios_app_url'])): ?>
                        <p class="text-[11px] text-sky-400 mt-1">Add iOS URL</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="submit" class="btn-primary px-6 py-2.5">Save Website & App</button>
                <?php if (!empty($merchant['website_url'])): ?>
                <a href="<?= e($merchant['website_url']) ?>" target="_blank" rel="noopener" class="px-5 py-2.5 rounded-xl border border-gray-700 text-sm text-gray-300 hover:text-white">Open Website ↗</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="glass rounded-xl p-6 border border-brand-500/20">
        <h3 class="font-semibold text-brand-400 mb-2">Why this is needed</h3>
        <ul class="text-xs text-gray-500 space-y-2 list-disc list-inside">
            <li>PayU / Razorpay / Cashfree ask for your <strong class="text-gray-400">website URL</strong> during live activation</li>
            <li>UniWeb shares this with admin when submitting your gateway application</li>
            <li>Status <strong class="text-sky-300">Verification in Process</strong> until admin reviews (usually 1–2 business days)</li>
            <li>Payment links work without a website — this is for <strong class="text-gray-400">business verification</strong> only</li>
        </ul>
    </div>

    <?php if ($status === 'verified'): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 text-sm text-emerald-300">
        ✓ Your website is verified. You can quote this URL in PayU/Razorpay/Cashfree onboarding emails.
    </div>
    <?php elseif ($status === 'pending'): ?>
    <div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 text-sm text-sky-300">
        Verification in process — our team will review <strong><?= e($merchant['website_url'] ?? '') ?></strong> shortly.
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
