<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Mobile Hub';
$hideNav = true;
$hideFooter = true;
$bodyClass = 'mobile-hub';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen px-4 py-8 pb-12 max-w-lg mx-auto">
    <div class="text-center mb-8">
        <?php $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo.php'; ?>
        <h1 class="text-2xl font-bold mt-4">UniWeb Mobile</h1>
        <p class="text-gray-500 text-sm mt-2">Quick access from your phone — open portals, share screenshots, reply in chat.</p>
    </div>

    <div class="space-y-3 mb-8">
        <a href="admin_login.php" class="mobile-tile block rounded-2xl border border-red-500/30 bg-red-500/10 p-5 active:scale-[0.98] transition">
            <p class="font-semibold text-red-300 text-lg">Admin Console</p>
            <p class="text-xs text-gray-500 mt-1">Full platform control</p>
        </a>
        <a href="staff_login.php" class="mobile-tile block rounded-2xl border border-sky-500/30 bg-sky-500/10 p-5 active:scale-[0.98] transition">
            <p class="font-semibold text-sky-300 text-lg">Staff Portal</p>
            <p class="text-xs text-gray-500 mt-1">Operations · KYC · Support</p>
        </a>
        <a href="login.php" class="mobile-tile block rounded-2xl border border-brand-500/30 bg-brand-500/10 p-5 active:scale-[0.98] transition">
            <p class="font-semibold text-brand-300 text-lg">Merchant Login</p>
            <p class="text-xs text-gray-500 mt-1">Dashboard · Wallet · Payments</p>
        </a>
    </div>

    <div class="glass rounded-2xl p-5 mb-6 text-sm">
        <p class="font-semibold text-gray-200 mb-3">Quick tools (after login)</p>
        <div class="grid grid-cols-2 gap-2">
            <a href="admin_platform_status.php" class="px-3 py-3 rounded-xl bg-dark-900 border border-gray-800 text-center text-xs text-gray-300">Platform Status</a>
            <a href="gateway_settings.php" class="px-3 py-3 rounded-xl bg-dark-900 border border-gray-800 text-center text-xs text-gray-300">Gateway Settings</a>
            <a href="admin_pg_webhooks.php" class="px-3 py-3 rounded-xl bg-dark-900 border border-gray-800 text-center text-xs text-gray-300">PG Webhooks</a>
            <a href="manage_merchant.php" class="px-3 py-3 rounded-xl bg-dark-900 border border-gray-800 text-center text-xs text-gray-300">Merchants</a>
        </div>
        <p class="text-[11px] text-gray-600 mt-3">Login first if a page asks for credentials.</p>
    </div>

    <div class="glass rounded-2xl p-5 mb-6 text-sm space-y-3">
        <p class="font-semibold text-gray-200">Screenshot workflow</p>
        <ol class="text-gray-400 text-xs space-y-2 list-decimal list-inside">
            <li>Open the page you need (links above).</li>
            <li>Take screenshot on Android (Power + Volume Down).</li>
            <li>Send to Cursor chat or WhatsApp yourself.</li>
        </ol>
    </div>

    <div class="glass rounded-2xl p-5 text-sm">
        <p class="font-semibold text-gray-200 mb-2">Add to Home screen</p>
        <p class="text-xs text-gray-500 leading-relaxed">Chrome menu (⋮) → <strong class="text-gray-400">Add to Home screen</strong> → name it <strong class="text-gray-400">UniWeb</strong>. Opens like an app.</p>
    </div>

    <p class="text-center text-xs text-gray-600 mt-8">
        <a href="index.php" class="hover:text-brand-400">← Main website</a>
    </p>
</div>
<style>
.mobile-hub .mobile-tile { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
</style>
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<?php require_once __DIR__ . '/footer.php'; ?>
