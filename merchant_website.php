<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureMerchantWebsiteEngine();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_compliance' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
    $checkUrl = trim($_POST['website_url'] ?? (string)($merchant['website_url'] ?? ''));
    $_SESSION['website_compliance'] = checkWebsiteCompliance($checkUrl);
    redirect('merchant_website.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
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
$compliance = $_SESSION['website_compliance'] ?? null;
unset($_SESSION['website_compliance']);
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
                <button type="submit" name="action" value="run_compliance" class="px-5 py-2.5 rounded-xl border border-sky-500/40 text-sm text-sky-300 hover:bg-sky-500/10">Run compliance check</button>
                <?php if (!empty($merchant['website_url'])): ?>
                <a href="<?= e($merchant['website_url']) ?>" target="_blank" rel="noopener" class="px-5 py-2.5 rounded-xl border border-gray-700 text-sm text-gray-300 hover:text-white">Open Website ↗</a>
                <?php endif; ?>
            </div>
            <p class="text-[11px] text-gray-600">Compliance check scans your homepage for Contact, Privacy, Terms &amp; Refund pages that gateways require. Save the URL first for best results.</p>
        </form>
    </div>

    <?php if ($compliance !== null): ?>
    <div class="glass rounded-xl p-6 border <?= !empty($compliance['required_pass']) ? 'border-emerald-500/30' : 'border-amber-500/30' ?>">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h3 class="font-semibold text-white">Website Compliance Check</h3>
            <?php if (!empty($compliance['fetched'])): ?>
            <span class="text-xs px-2.5 py-1 rounded-full <?= !empty($compliance['required_pass']) ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300' ?>">
                <?= (int)$compliance['score'] ?>/<?= (int)$compliance['max'] ?> checks passed
            </span>
            <?php endif; ?>
        </div>
        <?php if (!empty($compliance['error'])): ?>
        <p class="text-sm text-amber-300 mb-4"><?= e($compliance['error']) ?></p>
        <?php endif; ?>
        <?php if (!empty($compliance['checks'])): ?>
        <ul class="space-y-2">
            <?php foreach ($compliance['checks'] as $c): ?>
            <li class="flex items-start gap-3 text-sm">
                <span class="mt-0.5 <?= $c['pass'] ? 'text-emerald-400' : ($c['required'] ? 'text-red-400' : 'text-gray-500') ?>"><?= $c['pass'] ? '✓' : ($c['required'] ? '✗' : '○') ?></span>
                <span class="flex-1">
                    <span class="<?= $c['pass'] ? 'text-gray-200' : 'text-gray-300' ?>"><?= e($c['label']) ?></span>
                    <?php if ($c['required']): ?><span class="text-[10px] text-gray-600 ml-1">(required)</span><?php endif; ?>
                    <?php if (!$c['pass'] && !empty($c['detail'])): ?><span class="block text-[11px] text-gray-500"><?= e($c['detail']) ?></span><?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($compliance['fetched'])): ?>
        <p class="text-xs mt-4 <?= !empty($compliance['required_pass']) ? 'text-emerald-300' : 'text-amber-300' ?>">
            <?= !empty($compliance['required_pass'])
                ? '✓ All required pages detected — your site looks gateway-ready. Save the URL and submit for verification.'
                : 'Some required pages were not detected. Add the missing pages to your website, then re-run the check. (Automated scan can miss JS-rendered links — admin does a final manual review.)' ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
