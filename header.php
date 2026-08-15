<?php
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); }
if (!defined('APP_NAME')) require_once __DIR__ . '/config.php';
$pageTitle = $pageTitle ?? APP_NAME;
$pageDescription = $pageDescription ?? "UniWeb — India's trusted B2B Fintech Payment Platform. UPI, Cards, Payment Links, QR codes, settlements and API for Indian merchants.";
$pageKeywords = $pageKeywords ?? 'payment gateway India, UPI payment, payment aggregator, payment links, QR code payments, fintech India, merchant onboarding';
$canonicalUrl = $canonicalUrl ?? (APP_URL . '/' . basename((string)($_SERVER['PHP_SELF'] ?? 'index.php')));
$ogImage = $ogImage ?? (APP_URL . '/assets/icons/apple-touch-icon.png');
$bodyClass = $bodyClass ?? '';
$hideNav = $hideNav ?? false;
$hideFooter = $hideFooter ?? false;
$flash = getFlash();
$isPublic = !$hideNav && !isLoggedIn() && !isAdminLoggedIn();
$isAdmin = !$hideNav && isAdminLoggedIn();
$isMerchant = !$hideNav && isLoggedIn() && !$isAdmin;
$isStaffPortal = $isAdmin && isStaffUser();
$isSuperAdminPanel = $isAdmin && isSuperAdmin();
$sessionInfo = portalSessionSecurityInfo();
if (($isMerchant || $isAdmin) && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="UniWeb">
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="icon" href="<?= APP_URL ?>/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="<?= APP_URL ?>/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icons/apple-touch-icon.png">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="keywords" content="<?= e($pageKeywords) ?>">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($pageTitle) ?> — <?= APP_NAME ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($pageTitle) ?> — <?= APP_NAME ?>">
    <meta name="twitter:description" content="<?= e($pageDescription) ?>">
    <meta name="theme-color" content="#059669">
    <?php
    $gscToken = trim((string)(function_exists('getSetting') ? getSetting('google_site_verification', '') : ''));
    if ($gscToken !== ''):
    ?>
    <meta name="google-site-verification" content="<?= e($gscToken) ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uniweb.min.css?v=20260724b">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/theme-light.css?v=20260730c">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/public-pages.css?v=20260724b">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/portal-polish.css?v=20260812a">
    <?php if (!empty($customerPortalUi)): ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/customer-portal.css?v=20260724b">
    <?php endif; ?>
    <?php if (!empty($authPortalUi)): ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth-portal.css?v=20260811a">
    <?php endif; ?>
    <script>
    (function(){
        try {
            // Light mode is the default for website + merchant + admin + staff.
            var t = localStorage.getItem('uniweb_theme');
            if (t !== 'dark') document.documentElement.setAttribute('data-theme', 'light');
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
    </script>
    <style>
    .mode-pill-group{display:inline-flex;align-items:center;border:1px solid #374151;border-radius:9999px;padding:2px;background:#111827}
    .mode-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:9999px;font-size:11px;font-weight:600;text-decoration:none;transition:all .15s}
    .mode-pill-inactive{color:#9ca3af}.mode-pill-inactive:hover{color:#fff;background:#1f2937}
    .mode-pill-active.mode-pill-test{background:#f59e0b;color:#111827}
    .mode-pill-active.mode-pill-live{background:#059669;color:#fff}
    .mode-pill-test.mode-pill:not(.mode-pill-active){color:#fbbf24}
    .mode-pill-live.mode-pill:not(.mode-pill-active){color:#34d399}
    .mode-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
    .mode-dot-amber{background:#fbbf24}.mode-dot-green{background:#34d399}
    .mode-switch{width:42px;height:24px;border-radius:9999px;background:#374151;position:relative;flex-shrink:0}
    .mode-switch-on{background:#f59e0b}
    .mode-switch-live.mode-switch-on{background:#059669}
    .mode-switch-knob{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .15s}
    .mode-switch-on .mode-switch-knob{transform:translateX(18px)}
    .mode-test-stripe{background:linear-gradient(90deg,#f59e0b,#d97706);color:#111827;text-align:center;font-size:12px;font-weight:700;padding:8px 16px}
    .dash-quick-card{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:.5rem;padding:.75rem .5rem;min-height:0;text-align:center}
    .dash-quick-icon{width:2.5rem;height:2.5rem;display:flex;align-items:center;justify-content:center;border-radius:.625rem;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.2);flex-shrink:0}
    .dash-quick-icon svg{width:1.125rem!important;height:1.125rem!important;max-width:1.125rem;max-height:1.125rem;display:block;flex-shrink:0}
    .dash-quick-label{font-size:.75rem;line-height:1.2;color:#d1d5db;font-weight:500}
    /* Responsive fix: any rounded card that clips overflow but contains a wide table should
       scroll horizontally on small screens instead of squishing/cutting off columns. */
    .overflow-hidden:has(table){overflow-x:auto}
    .portal-shell{min-width:0;width:100%;max-width:100%}
    .portal-main{overflow-x:clip}
    @media (max-width:640px){
        table{font-size:.8125rem}
        .stat-card{padding:1rem!important}
    }
    @media print{
        nav,.no-print,button:not(.print-keep),form[method="GET"],#flash-msg,[data-spotlight-open],#uniweb-spotlight,.theme-toggle-btn,#sidebar-toggle,#admin-sidebar-toggle,#profile-menu-btn,#public-menu-btn{display:none!important}
        body{background:#fff!important;color:#000!important}
        .glass{border:1px solid #ccc!important;box-shadow:none!important;background:#fff!important}
        a{color:#000!important;text-decoration:underline}
        .text-brand-400,.text-sky-400,.text-emerald-400{color:#000!important}
    }
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    </style>
</head>
<body class="bg-dark-950 text-gray-100 min-h-screen flex flex-col w-full max-w-full <?= e($bodyClass) ?> <?= $isPublic ? 'public-site' : '' ?>">

<?php if ($flash): ?>
<div id="flash-msg" class="fixed top-4 right-4 z-50 max-w-sm animate-in">
    <div class="px-5 py-3 rounded-xl shadow-2xl text-sm font-medium <?= $flash['type']==='success'?'bg-brand-600 text-white':($flash['type']==='error'?'bg-red-600 text-white':'bg-amber-500 text-dark-900') ?>"><?= e($flash['message']) ?></div>
</div>
<script>setTimeout(()=>document.getElementById('flash-msg')?.remove(),4000)</script>
<?php endif; ?>

<?php if ($isPublic): ?>
<nav class="fixed top-0 w-full z-40 glass border-b border-white/5">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <?php $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <div class="hidden md:flex items-center gap-8 text-sm text-gray-300">
                <a href="tour_videos.php" class="hover:text-brand-400 transition">Tour</a>
                <a href="demo.php" class="hover:text-brand-400 transition">Demo</a>
                <a href="solutions.php" class="hover:text-brand-400 transition">Solutions</a>
                <a href="pricing.php" class="hover:text-brand-400 transition"><?= __('pricing') ?></a>
                <a href="trust.php" class="hover:text-brand-400 transition">Trust</a>
                <a href="about.php" class="hover:text-brand-400 transition"><?= __('about') ?></a>
                <a href="faq.php" class="hover:text-brand-400 transition"><?= __('faq') ?></a>
                <a href="api_docs.php" class="hover:text-brand-400 transition"><?= __('api_docs') ?></a>
                <a href="contact.php" class="hover:text-brand-400 transition"><?= __('contact') ?></a>
            </div>
            <div class="flex items-center gap-2">
                <span class="hidden lg:inline text-[10px] text-gray-500 font-mono" data-ist-clock><?= e(date('d M Y, h:i:s A')) ?> IST</span>
                <button type="button" id="public-menu-btn" class="md:hidden p-2 text-gray-300 hover:text-white" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="login.php" class="text-sm text-gray-300 hover:text-white px-3 py-2 transition hidden sm:inline"><?= __('login') ?></a>
                <a href="merchant_register.php" class="text-sm bg-brand-600 hover:bg-brand-500 text-white px-5 py-2 rounded-lg font-medium transition"><?= __('get_started') ?></a>
            </div>
        </div>
    </div>
    <div id="public-mobile-menu" class="hidden md:hidden border-t border-gray-800 bg-dark-900/95 px-4 py-4 space-y-2 text-sm">
        <a href="tour_videos.php" class="block py-2 text-violet-400 font-medium">▶ Platform Tour</a>
        <a href="demo.php" class="block py-2 text-sky-400 font-medium">⚡ Demo Payment</a>
        <a href="solutions.php" class="block py-2 text-gray-300 hover:text-brand-400">Solutions</a>
        <a href="pricing.php" class="block py-2 text-gray-300 hover:text-brand-400"><?= __('pricing') ?></a>
        <a href="trust.php" class="block py-2 text-gray-300 hover:text-brand-400">Trust &amp; Security</a>
        <a href="about.php" class="block py-2 text-gray-300 hover:text-brand-400"><?= __('about') ?></a>
        <a href="faq.php" class="block py-2 text-gray-300 hover:text-brand-400"><?= __('faq') ?></a>
        <a href="api_docs.php" class="block py-2 text-gray-300 hover:text-brand-400"><?= __('api_docs') ?></a>
        <a href="contact.php" class="block py-2 text-gray-300 hover:text-brand-400"><?= __('contact') ?></a>
        <a href="business_agreement.php" class="block py-2 text-gray-300 hover:text-brand-400">Merchant Agreement</a>
        <a href="login.php" class="block py-2 text-brand-400"><?= __('login') ?></a>
    </div>
</nav>
<script>document.getElementById('public-menu-btn')?.addEventListener('click',()=>document.getElementById('public-mobile-menu')?.classList.toggle('hidden'))</script>
<?php endif; ?>

<?php if ($isMerchant): $merchant = getMerchant(); ?>
<div id="sidebar-overlay" class="overlay fixed inset-0 bg-black/60 z-40 lg:hidden"></div>
<div class="portal-shell flex flex-1 min-h-screen">
    <aside id="sidebar-panel" class="sidebar-shell w-64 bg-dark-900 border-r border-gray-800 fixed inset-y-0 left-0 z-50 lg:z-30 mobile-drawer lg:translate-x-0 lg:!transform-none flex flex-col">
        <div class="p-5 border-b border-gray-800 shrink-0">
            <?php $logoHref = 'dashboard.php'; $logoSize = 'sm'; $merchantPanel = true; $merchantInitial = strtoupper(substr($merchant['business_name'] ?? $merchant['name'] ?? 'M', 0, 1)); require __DIR__ . '/includes/brand_logo.php'; ?>
            <p class="text-sm font-semibold text-white mt-3 truncate"><?= e($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant') ?></p>
            <p class="text-[10px] text-gray-500 font-mono mt-0.5"><?= e($merchant['merchant_code'] ?? '') ?></p>
        </div>
        <nav class="sidebar-nav p-3 space-y-0.5 text-sm flex-1 overflow-y-auto">
            <?php
            $merchantNav = [
                ['id' => 'overview', 'title' => 'Overview', 'items' => [
                    ['dashboard.php',__('dashboard'),'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ]],
                ['id' => 'collect', 'title' => 'Collect / P2M', 'items' => [
                    ['payment_links.php',__('payment_links'),'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244'],
                    ['qr_code.php',__('qr_code'),'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z'],
                    ['payment_methods.php','Payment Methods','M11 3.055A5.001 5.001 0 005.055 9 5.001 5.001 0 0011 14.945 5.001 5.001 0 0016.945 9 5.001 5.001 0 0011 3.055z'],
                    ['orders.php','Orders','M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
                ]],
                ['id' => 'payments', 'title' => 'Payments', 'items' => [
                    ['transactions.php',__('transactions'),'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
                    ['refunds.php','Refunds','M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6'],
                    ['reports.php',__('reports'),'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                    ['disputes.php',__('nav_disputes'),'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
                    ['chargebacks.php','Chargebacks','M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                ]],
                ['id' => 'settlements', 'title' => 'Settlements', 'items' => [
                    ['wallet.php','Settlement Balance','M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
                    ['settlements.php',__('settlements'),'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
                    ['merchant_instant_settlement.php','Instant Settlement','M13 10V3L4 14h7v7l9-11h-7z'],
                    ['merchant_payout.php','Payouts','M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
                    ['merchant_payout_keys.php','Payout API Keys','M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
                    ['merchant_settlement_settings.php','Settlement Settings','M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                    ['beneficiaries.php','Beneficiaries','M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
                    ['merchant_recurring.php','Recurring & Mandates','M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                ]],
                ['id' => 'kyc', 'title' => 'KYC', 'items' => [
                    ['kyc.php',__('kyc'),'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['video_kyc.php','Video KYC','M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'],
                    ['merchant_shop_photos.php','Shop Photos','M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['merchant_payment_pack.php','Payment Pack','M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                ]],
                ['id' => 'team', 'title' => 'Team & Customers', 'items' => [
                    ['merchant_team.php','Team','M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                    ['invoices.php',__('invoices'),'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['agents.php',__('agents'),'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
                    ['merchant_customer_tickets.php','Customer Complaints','M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z'],
                ]],
                ['id' => 'tools', 'title' => 'Tools / Settings', 'items' => [
                    ['checkout_customize.php','Checkout Customize','M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
                    ['api_settings.php',__('api_settings'),'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['merchant_notify_settings.php','Notification Settings','M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
                    ['merchant_agreement.php','Agreement','M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['merchant_settings.php',__('nav_settings'),'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                    ['merchant_2fa.php','2FA Security','M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['notifications.php',__('nav_notifications'),'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
                    ['merchant_website.php','Sales Website','M3 5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-5l-3 3-3-3H5a2 2 0 01-2-2V5z'],
                    ['collection_settings.php',__('nav_collection_mode'),'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
                    ['qr_analytics.php','QR Analytics','M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                    ['merchant_launch.php','Launch Center','M13 10V3L4 14h7v7l9-11h-7z'],
                    ['support.php',__('support'),'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M5.636 5.636l3.536 3.536m0 5.656l-3.536 3.536M12 2.944l7.07 7.07a10 10 0 010 14.142L12 22.93l-7.07-7.07a10 10 0 010-14.142L12 2.944z'],
                ]],
            ];
            $merchantHiddenUrls = [
                'merchant_nbfc.php','merchant_nbfc_loan.php',
            ];
            foreach ($merchantNav as &$group) {
                $group['items'] = array_values(array_filter($group['items'], function($item) use ($merchantHiddenUrls) {
                    $url = $item[0] ?? '';
                    return !in_array($url, $merchantHiddenUrls, true);
                }));
            }
            unset($group);
            $merchantNav = array_values(array_filter($merchantNav, function($group) {
                return !empty($group['items']);
            }));
            $cur = basename($_SERVER['PHP_SELF']);
            foreach ($merchantNav as $group):
                $isOpen = false;
                foreach ($group['items'] as $item) {
                    if (isset($item[0]) && $cur === $item[0]) { $isOpen = true; break; }
                }
            ?>
            <div class="merchant-sidebar-group mb-1" data-group-id="<?= e($group['id']) ?>" data-open="<?= $isOpen ? '1' : '0' ?>">
                <button type="button" class="merchant-group-toggle w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:text-white hover:bg-white/5 transition" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                    <span><?= e($group['title']) ?></span>
                    <svg class="merchant-group-chevron flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:1rem;height:1rem;pointer-events:none;transition:transform .3s;transform:rotate(<?= $isOpen ? '90' : '0' ?>deg);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="merchant-group-panel" style="overflow:hidden;transition:max-height .3s;max-height:<?= $isOpen ? '1200' : '0' ?>px;">
                    <div class="py-1 pl-4 space-y-0.5">
                        <?php foreach ($group['items'] as [$url, $label, $icon]): ?>
                        <a href="<?= $url ?>" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-white hover:bg-white/5 transition <?= $cur===$url?'active':'' ?>">
                            <svg class="w-5 h-5 flex-shrink-0 overflow-visible" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= $icon ?>"/></svg>
                            <?= e($label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </nav>
        <?= renderMerchantModeToggle($merchant, 'sidebar') ?>
        <div class="sidebar-footer p-4">
            <a href="logout.php" class="flex items-center gap-2 text-sm text-red-400 hover:text-red-300 px-3 py-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <?= __('logout') ?>
            </a>
        </div>
    </aside>
    <main class="portal-main flex-1 lg:ml-64 flex flex-col min-w-0 w-full">
        <header class="bg-dark-900/90 backdrop-blur border-b border-gray-800 px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3 sticky top-0 z-20">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" id="sidebar-toggle" class="lg:hidden p-2 text-gray-400 hover:text-white shrink-0" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-lg font-semibold whitespace-nowrap"><?= e($pageTitle) ?></h1>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <span class="hidden xl:inline text-[10px] text-gray-500 font-mono" title="India Standard Time"><span data-ist-clock><?= e(date('d M, h:i:s A')) ?> IST</span> · Session <span data-session-countdown><?= gmdate('i:s', (int)$sessionInfo['remaining']) ?></span></span>
                <?php require __DIR__ . '/includes/global_search_ui.php'; ?>
                <?= renderMerchantModeToggle($merchant, 'header') ?>
                <button type="button" onclick="toggleUniwebTheme()" class="theme-toggle-btn" title="Toggle dark / light mode" aria-label="Toggle theme"><span data-theme-icon>🌙</span></button>
                <span class="text-xs text-gray-500 hidden md:inline font-mono"><?= e($merchant['merchant_code'] ?? '') ?></span>
                <div class="relative" id="profile-menu-wrap">
                    <button type="button" id="profile-menu-btn" class="w-9 h-9 bg-brand-600 rounded-full flex items-center justify-center text-sm font-bold hover:ring-2 hover:ring-brand-400/50 transition" aria-label="Profile menu">
                        <?= strtoupper(substr($merchant['name'] ?? 'M', 0, 1)) ?>
                    </button>
                    <div id="profile-menu" class="hidden absolute right-0 mt-2 w-52 rounded-xl border border-gray-700 bg-dark-900 shadow-2xl py-1 z-50">
                        <div class="px-4 py-2 border-b border-gray-800">
                            <p class="text-sm font-medium truncate"><?= e($merchant['name'] ?? 'Merchant') ?></p>
                            <p class="text-[10px] text-gray-500 font-mono"><?= e($merchant['merchant_code'] ?? '') ?></p>
                            <p class="text-[10px] text-gray-600 mt-0.5"><?= e($merchant['business_name'] ?? '') ?></p>
                        </div>
                        <?= renderMerchantModeToggle($merchant, 'profile') ?>
                        <a href="merchant_settings.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white"><?= __('nav_settings') ?></a>
                        <a href="merchant_team.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white">Team</a>
                        <a href="my_account.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white"><?= __('profile_menu_account') ?></a>
                        <a href="wallet.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white"><?= __('profile_menu_wallet') ?></a>
                        <a href="security.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white"><?= __('nav_security') ?></a>
                        <a href="support.php" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white"><?= __('profile_menu_support') ?></a>
                        <a href="logout.php" class="block px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10"><?= __('logout') ?></a>
                    </div>
                </div>
            </div>
        </header>
        <?= renderMerchantTestStripe($merchant) ?>
        <div class="p-6 flex-1 portal-content-frame">
<?php endif; ?>

<?php if ($isSuperAdminPanel): ?>
<div id="admin-overlay" class="overlay fixed inset-0 bg-black/60 z-40 lg:hidden"></div>
<div class="portal-shell flex flex-1 min-h-screen">
    <aside id="admin-sidebar" class="sidebar-shell w-64 bg-dark-900 border-r border-gray-800 fixed inset-y-0 left-0 z-50 lg:z-30 mobile-drawer lg:translate-x-0 lg:!transform-none flex flex-col">
        <div class="p-5 border-b border-gray-800 shrink-0">
            <span class="font-bold text-red-400 text-lg">Admin Panel</span>
            <p class="text-xs text-gray-500 mt-1"><?= APP_NAME ?> Control</p>
        </div>
        <nav class="sidebar-nav p-3 space-y-0.5 text-sm flex-1 overflow-y-auto">
            <?php
            $adminNav = [
                ['id' => 'dashboard', 'title' => 'Dashboard', 'items' => [
                    ['admin_dashboard.php','Overview'],
                ]],
                ['id' => 'merchants', 'title' => 'Merchants & KYC', 'items' => [
                    ['manage_merchant.php','All Merchants'],
                    ['add_merchant.php','Add Merchant'],
                    ['admin_kyc.php','KYC Review'],
                    ['admin_onboarding_invite.php','Onboarding Invites'],
                    ['admin_website_reviews.php','Website Reviews'],
                ]],
                ['id' => 'partners', 'title' => 'Partners', 'items' => [
                    ['admin_gateway_registry.php','Partner Registry'],
                    ['gateway_settings.php','Platform Settings'],
                    ['admin_method_requests.php','Method Requests'],
                    ['admin_forward_queue.php','KYC Forward Queue'],
                ]],
                ['id' => 'payments', 'title' => 'Transactions & Refunds', 'items' => [
                    ['admin_transactions.php','Transactions'],
                    ['admin_refunds.php','Refunds'],
                    ['admin_disputes.php','Disputes'],
                ]],
                ['id' => 'settlements', 'title' => 'Settlements & Payouts', 'items' => [
                    ['admin_settlements.php','Settlements'],
                    ['admin_bulk_payout.php','Bulk Payout'],
                    ['admin_payout.php','Payout Requests'],
                ]],
                ['id' => 'support', 'title' => 'Support', 'items' => [
                    ['admin_support.php','Support Tickets'],
                ]],
                ['id' => 'ops', 'title' => 'Ops', 'items' => [
                    ['admin_platform_status.php','Platform Status + Cron Jobs'],
                    ['admin_watchdog.php','Link Watchdog'],
                    ['admin_error_log.php','Error Log'],
                ]],
                ['id' => 'staff', 'title' => 'Staff', 'items' => [
                    ['admin_manage_staff.php','Staff / Employees'],
                ]],
                ['id' => 'advanced', 'title' => 'Advanced', 'collapsed' => true, 'items' => [
                    ['admin_sub_merchants.php','Sub Merchants'],
                    ['admin_merchant_health.php','Merchant Health'],
                    ['admin_customer_view.php','Customer Lookup'],
                    ['admin_reason_map.php','Reason Maps'],
                    ['admin_auto_kyc.php','Auto KYC Engine'],
                    ['admin_gateway_submit.php','KYC Submissions'],
                    ['admin_integration_matrix.php','Integration Status Board'],
                    ['admin_gateway_matrix.php','Gateway Routing Matrix'],
                    ['admin_gateway_health.php','Gateway Health'],
                    ['admin_virtual_accounts.php','Virtual Accounts'],
                    ['admin_partner_requests.php','Partner Requests'],
                    ['admin_partner_commercial.php','Partner Commercial'],
                    ['admin_circuit_breaker.php','Circuit Breaker'],
                    ['admin_webhook_reliability.php','Webhook Reliability'],
                    ['admin_chargebacks.php','Chargebacks'],
                    ['admin_financial_reports.php','Financial Reports'],
                    ['admin_pg_webhooks.php','PG Webhooks'],
                    ['admin_reconciliation.php','PG Reconciliation'],
                    ['admin_settlement_settings.php','Settlement Engine'],
                    ['admin_settlement_batches.php','Settlement Batches'],
                    ['admin_bank_reconciliation.php','Bank Reconciliation'],
                    ['admin_bank_holidays.php','Bank Holidays'],
                    ['admin_rolling_reserve.php','Rolling Reserve'],
                    ['admin_customer_tickets.php','Customer Complaints'],
                    ['admin_aml.php','AML Compliance'],
                    ['admin_risk.php','Risk Rules'],
                    ['admin_risk_engine.php','Risk Engine'],
                    ['admin_grievance.php','Grievance Officer'],
                    ['admin_audit_plan.php','Deep Audit Plan'],
                    ['admin_transaction_monitor.php','Transaction Monitor'],
                    ['admin_throughput.php','Throughput Monitor'],
                    ['admin_website.php','Website & API Keys'],
                    ['admin_wallet.php','Platform Bank Account'],
                    ['admin_platform_wallet.php','Platform Fee Ledger'],
                    ['admin_nodal_accounts.php','Nodal Accounts'],
                    ['admin_reports.php','Reports'],
                    ['admin_incidents.php','Incidents'],
                    ['admin_link_audit.php','Link Audit'],
                    ['admin_audit_log.php','Audit Log'],
                    ['admin_ledger_state.php','Ledger State Machine'],
                    ['admin_encrypt_pii.php','Encrypt PII Backfill'],
                    ['admin_staff_activity.php','Staff Activity Log'],
                    ['admin_security.php','Security & Password'],
                    ['admin_security_hardening.php','Security Hardening'],
                ]],
            ];
            $adminHiddenUrls = ['admin_nbfc.php'];
            foreach ($adminNav as &$group) {
                $group['items'] = array_values(array_filter($group['items'], static function ($item) use ($adminHiddenUrls) {
                    return !in_array($item[0] ?? '', $adminHiddenUrls, true);
                }));
            }
            unset($group);
            $cur = basename($_SERVER['PHP_SELF']);
            foreach ($adminNav as $group):
                $isOpen = false;
                foreach ($group['items'] as $item) {
                    if (isset($item[0]) && $cur === $item[0]) { $isOpen = true; break; }
                }
            ?>
            <div class="admin-sidebar-group mb-1" data-group-id="<?= e($group['id']) ?>" data-open="<?= $isOpen ? '1' : '0' ?>"<?= !empty($group['collapsed']) ? ' data-collapsed="1"' : '' ?>>
                <button type="button" class="admin-group-toggle w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:text-white hover:bg-white/5 transition" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                    <span><?= e($group['title']) ?></span>
                    <svg class="admin-group-chevron flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:1rem;height:1rem;pointer-events:none;transition:transform .3s;transform:rotate(<?= $isOpen ? '90' : '0' ?>deg);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="admin-group-panel" style="overflow:hidden;transition:max-height .3s;max-height:<?= $isOpen ? (!empty($group['collapsed']) ? '5000' : '1200') : '0' ?>px;">
                    <div class="py-1 pl-4 space-y-0.5">
                        <?php foreach ($group['items'] as [$url, $label]): ?>
                        <a href="<?= $url ?>" class="block px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-white hover:bg-white/5 transition <?= $cur===$url?'bg-red-500/10 text-red-400':'' ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer p-4">
            <a href="logout.php" class="text-sm text-red-400 hover:text-red-300 px-3 py-2 block">Logout</a>
        </div>
    </aside>
    <main class="portal-main flex-1 lg:ml-64 flex flex-col min-w-0 w-full">
        <header class="bg-dark-900 border-b border-gray-800 px-4 sm:px-6 py-4 sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
            <button type="button" id="admin-sidebar-toggle" class="lg:hidden p-2 text-gray-400 hover:text-white shrink-0" aria-label="Menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="text-lg font-semibold whitespace-nowrap"><?= e($pageTitle) ?></h1>
            </div>
            <div class="flex items-center gap-2 flex-wrap justify-end">
                <span class="hidden xl:inline text-[10px] text-gray-500 font-mono" title="30-minute inactivity timeout"><span data-ist-clock><?= e(date('d M, h:i:s A')) ?> IST</span> · Session <span data-session-countdown><?= gmdate('i:s', (int)$sessionInfo['remaining']) ?></span></span>
                <?php require __DIR__ . '/includes/global_search_ui.php'; ?>
            <?php
                $unresolvedHdr = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;
            ?>
            <?php if (function_exists('getAutoAuditStatusForHeader')):
                $auditHdr = getAutoAuditStatusForHeader();
            ?>
                <a href="admin_watchdog.php?tab=auto" class="text-xs px-2.5 py-1 rounded-full border <?= $auditHdr['audit_ok'] ? 'border-emerald-500/40 text-emerald-400 bg-emerald-500/10' : 'border-amber-500/40 text-amber-400 bg-amber-500/10' ?>" title="Auto-audit every <?= (int)$auditHdr['interval_min'] ?> min">● Audit</a>
            <?php endif; ?>
                <a href="admin_watchdog.php" class="text-xs px-2.5 py-1 rounded-full border border-gray-700 text-gray-400 hover:text-white">Watchdog</a>
                <?php if ($unresolvedHdr > 0): ?>
                <a href="admin_error_log.php" class="text-xs px-2.5 py-1 rounded-full border border-red-500/40 text-red-400 bg-red-500/10"><?= (int)$unresolvedHdr ?> errors</a>
                <?php else: ?>
                <a href="admin_error_log.php" class="text-xs px-2.5 py-1 rounded-full border border-gray-700 text-gray-400 hover:text-white">Error Log</a>
                <?php endif; ?>
                <button type="button" onclick="toggleUniwebTheme()" class="theme-toggle-btn" title="Toggle dark / light mode" aria-label="Toggle theme"><span data-theme-icon>🌙</span></button>
            </div>
        </header>
        <div class="p-4 sm:p-6 flex-1 portal-content-frame">
<?php endif; ?>

<?php if ($isStaffPortal): $staffAdmin = getAdmin(); ?>
<div id="admin-overlay" class="overlay fixed inset-0 bg-black/60 z-40 lg:hidden"></div>
<div class="portal-shell flex flex-1 min-h-screen">
    <aside id="admin-sidebar" class="sidebar-shell w-64 bg-dark-900 border-r border-gray-800 fixed inset-y-0 left-0 z-50 lg:z-30 mobile-drawer lg:translate-x-0 lg:!transform-none flex flex-col">
        <div class="p-5 border-b border-gray-800 shrink-0">
            <span class="font-bold text-sky-400 text-lg">Operations Portal</span>
            <p class="text-xs text-gray-500 mt-1"><?= e(staffRoleLabel(adminRole($staffAdmin))) ?> · <?= e($staffAdmin['name'] ?? 'Staff') ?></p>
        </div>
        <nav class="sidebar-nav p-3 space-y-0.5 text-sm flex-1 overflow-y-auto">
            <?php
            $cur = basename($_SERVER['PHP_SELF']);
            foreach (staffNavForRole(adminRole($staffAdmin)) as [$url,$label]):
            ?>
            <a href="<?= $url ?>" class="block px-3 py-2.5 rounded-lg text-gray-400 hover:text-white hover:bg-white/5 transition <?= $cur===$url?'bg-sky-500/10 text-sky-400':'' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer p-4">
            <a href="logout.php" class="text-sm text-red-400 hover:text-red-300 px-3 py-2 block">Logout</a>
        </div>
    </aside>
    <main class="portal-main flex-1 lg:ml-64 flex flex-col min-w-0 w-full">
        <header class="bg-dark-900 border-b border-gray-800 px-4 sm:px-6 py-4 sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
            <button type="button" id="admin-sidebar-toggle" class="lg:hidden p-2 text-gray-400 hover:text-white shrink-0" aria-label="Menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="text-lg font-semibold whitespace-nowrap"><?= e($pageTitle) ?></h1>
            </div>
            <div class="flex items-center gap-2 flex-wrap justify-end">
                <span class="hidden md:inline text-[10px] text-gray-500 font-mono" title="30-minute inactivity timeout"><span data-ist-clock><?= e(date('d M, h:i:s A')) ?> IST</span> · Session <span data-session-countdown><?= gmdate('i:s', (int)$sessionInfo['remaining']) ?></span></span>
                <?php require __DIR__ . '/includes/global_search_ui.php'; ?>
                <button type="button" onclick="toggleUniwebTheme()" class="theme-toggle-btn" title="Toggle dark / light mode" aria-label="Toggle theme"><span data-theme-icon>🌙</span></button>
            </div>
        </header>
        <div class="p-4 sm:p-6 flex-1 portal-content-frame">
<?php endif; ?>
<script>
function toggleUniwebTheme(){
    const html=document.documentElement;
    const isLight=html.getAttribute('data-theme')==='light';
    if(isLight){html.removeAttribute('data-theme');try{localStorage.setItem('uniweb_theme','dark');}catch(e){}}
    else{html.setAttribute('data-theme','light');try{localStorage.setItem('uniweb_theme','light');}catch(e){}}
    document.querySelectorAll('[data-theme-icon]').forEach(el=>el.textContent=isLight?'🌙':'☀️');
}
(function(){
    document.querySelectorAll('[data-theme-icon]').forEach(el=>{
        el.textContent = document.documentElement.getAttribute('data-theme')==='light' ? '☀️' : '🌙';
    });
    function bind(toggleId,panelId,overlayId){
        const t=document.getElementById(toggleId),p=document.getElementById(panelId),o=document.getElementById(overlayId);
        if(!t||!p)return;
        const close=()=>{p.classList.remove('open');o?.classList.remove('active');document.body.style.overflow='';};
        t.addEventListener('click',()=>{p.classList.toggle('open');o?.classList.toggle('active');document.body.style.overflow=p.classList.contains('open')?'hidden':'';});
        o?.addEventListener('click',close);
        p.querySelectorAll('a').forEach(a=>a.addEventListener('click',close));
    }
    bind('sidebar-toggle','sidebar-panel','sidebar-overlay');
    bind('admin-sidebar-toggle','admin-sidebar','admin-overlay');
    document.querySelectorAll('.admin-group-toggle').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const group=btn.closest('.admin-sidebar-group');
            const panel=group.querySelector('.admin-group-panel');
            const chevron=group.querySelector('.admin-group-chevron');
            const open=group.getAttribute('data-open')==='1';
            if(panel){
                if(open){
                    panel.style.maxHeight=panel.scrollHeight+'px';
                    requestAnimationFrame(()=>{panel.style.maxHeight='0px';});
                }else{
                    panel.style.maxHeight=panel.scrollHeight+'px';
                }
            }
            if(chevron){chevron.style.transform=open?'rotate(0deg)':'rotate(90deg)';}
            btn.setAttribute('aria-expanded',open?'false':'true');
            group.setAttribute('data-open',open?'0':'1');
        });
    });
    document.querySelectorAll('.merchant-group-toggle').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const group=btn.closest('.merchant-sidebar-group');
            const panel=group.querySelector('.merchant-group-panel');
            const chevron=group.querySelector('.merchant-group-chevron');
            const open=group.getAttribute('data-open')==='1';
            if(panel){
                if(open){
                    panel.style.maxHeight=panel.scrollHeight+'px';
                    requestAnimationFrame(()=>{panel.style.maxHeight='0px';});
                }else{
                    panel.style.maxHeight=panel.scrollHeight+'px';
                }
            }
            if(chevron){chevron.style.transform=open?'rotate(0deg)':'rotate(90deg)';}
            btn.setAttribute('aria-expanded',open?'false':'true');
            group.setAttribute('data-open',open?'0':'1');
        });
    });
    const pbtn=document.getElementById('profile-menu-btn'),pmenu=document.getElementById('profile-menu'),pwrap=document.getElementById('profile-menu-wrap');
    if(pbtn&&pmenu){
        pbtn.addEventListener('click',e=>{e.stopPropagation();pmenu.classList.toggle('hidden');});
        document.addEventListener('click',e=>{if(pwrap&&!pwrap.contains(e.target))pmenu.classList.add('hidden');});
    }
    const clocks=document.querySelectorAll('[data-ist-clock]');
    const updateClock=()=>{
        const value=new Intl.DateTimeFormat('en-IN',{
            timeZone:'Asia/Kolkata',day:'2-digit',month:'short',hour:'2-digit',
            minute:'2-digit',second:'2-digit',hour12:true
        }).format(new Date())+' IST';
        clocks.forEach(el=>el.textContent=value);
    };
    updateClock();
    if(clocks.length)setInterval(updateClock,1000);
    const countdowns=document.querySelectorAll('[data-session-countdown]');
    let remaining=<?= (int)$sessionInfo['remaining'] ?>;
    if(countdowns.length&&remaining>0){
        const updateSession=()=>{
            const minutes=Math.floor(remaining/60),seconds=remaining%60;
            countdowns.forEach(el=>el.textContent=String(minutes).padStart(2,'0')+':'+String(seconds).padStart(2,'0'));
            if(remaining<=0){window.location.href='logout.php?reason=timeout';return;}
            remaining--;
        };
        updateSession();
        setInterval(updateSession,1000);
    }
})();
</script>