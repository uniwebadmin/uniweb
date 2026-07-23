<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

$wallet = ensureMerchantWalletReady($merchantId);
$pageTitle = __('settings_title');
require_once __DIR__ . '/header.php';

$globalCards = [
    ['my_account.php', 'Profile Details', 'Name, business, PAN, GST, address + OTP-gated email/mobile', 'View details', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14'],
    ['kyc.php', 'KYC Verification', 'Upload documents for Live mode approval', 'Manage KYC', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
    ['add_bank.php', 'Bank Account', 'Primary settlement bank / IFSC', 'Manage bank', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
    ['merchant_notify_settings.php', 'Notifications', 'Email & webhook alerts for payments, settlements, refunds', 'Configure', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
    ['merchant_2fa.php', 'Two-Factor Authentication', 'Optional — authenticator app login (admin/staff MFA is mandatory)', 'Manage 2FA', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v4h8z'],
    ['merchant_agreement.php', 'Merchant Agreement', 'Review and record your authenticated contract', 'Review agreement', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ['api_settings.php', 'API Keys & Webhooks', 'Test/Live keys and webhook URL', 'Open API', 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
    ['security.php', 'Security', 'Password and account protection', 'Open', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
];

$productCards = [
    ['collection_settings.php', 'Payment Methods', 'Collection mode and enabled methods', 'Configure', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
    ['merchant_settlement_settings.php', 'Settlement Preferences', 'Scheduled batch or manual payout', 'Change', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['merchant_payout.php', 'Payouts', 'Enable request, beneficiaries, gated drafts (partner keys pending)', 'Open payouts', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
    ['merchant_website.php', 'Website & App', 'URLs for gateway / KYC review', 'Update', 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
    ['refunds.php', 'Refunds', 'Process customer refunds with reason codes', 'Open', 'M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z'],
];
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <p class="text-sm text-gray-400"><?= __('settings_sub') ?></p>
        <p class="text-xs text-gray-500 mt-2 flex flex-wrap items-center gap-2">
            <?= accountModeBadge($merchant) ?>
            <span class="font-mono text-sky-400">MID: <?= e($merchant['merchant_code'] ?? '') ?></span>
            <span class="text-gray-600">·</span>
            <span>KYC: <?= statusBadge($merchant['kyc_status'] ?? 'pending') ?></span>
        </p>
    </div>
    <div class="flex items-center gap-3 flex-wrap">
        <?= renderMerchantModeToggle($merchant, 'header') ?>
    </div>
</div>

<div class="glass rounded-xl p-4 mb-8 border border-gray-800 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-xs text-gray-500 uppercase tracking-wide">Wallet</p>
        <p class="text-lg font-semibold text-sky-400"><?= walletMoney($wallet['available'], $wallet['is_test'] ?? true) ?> <span class="text-xs font-normal text-gray-500">available</span></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="settlements.php" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Settlements</a>
        <a href="wallet.php" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Wallet</a>
    </div>
</div>

<h2 class="text-sm font-semibold text-gray-400 mb-3">Global Settings</h2>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-8">
    <?php foreach ($globalCards as [$url, $title, $desc, $cta, $icon]): ?>
    <a href="<?= e($url) ?>" class="glass rounded-xl p-5 border border-gray-800 hover:border-brand-500/40 transition block group">
        <span class="dash-quick-icon mb-3 inline-flex">
            <svg class="text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="<?= $icon ?>"/></svg>
        </span>
        <h3 class="font-semibold text-white text-sm"><?= e($title) ?></h3>
        <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?= e($desc) ?></p>
        <span class="inline-block mt-3 text-xs text-brand-400 font-medium"><?= e($cta) ?> →</span>
    </a>
    <?php endforeach; ?>
</div>

<h2 class="text-sm font-semibold text-gray-400 mb-3">Product Configuration</h2>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-8">
    <?php foreach ($productCards as [$url, $title, $desc, $cta, $icon]): ?>
    <a href="<?= e($url) ?>" class="glass rounded-xl p-5 border border-gray-800 hover:border-sky-500/40 transition block group">
        <span class="dash-quick-icon mb-3 inline-flex">
            <svg class="text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="<?= $icon ?>"/></svg>
        </span>
        <h3 class="font-semibold text-white text-sm"><?= e($title) ?></h3>
        <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?= e($desc) ?></p>
        <span class="inline-block mt-3 text-xs text-sky-400 font-medium"><?= e($cta) ?> →</span>
    </a>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
