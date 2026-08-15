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
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/uniweb.min.css?v=20260815a">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/theme-light.css?v=20260815a">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/public-pages.css?v=20260815a">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/portal-polish.css?v=20260815a">
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
    .overflow-hidden:has(table){overflow-x:auto;-webkit-overflow-scrolling:touch}
    .portal-shell{min-width:0;width:100%;max-width:100%}
    .portal-main{overflow-x:auto}
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
            if (!function_exists('uniwebMerchantNavGroups')) {
                require_once __DIR__ . '/includes/sidebar_nav.php';
            }
            $merchantNav = uniwebMerchantNavGroups();
            $merchantHiddenUrls = uniwebMerchantHiddenUrls();
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
                $isOpen = true;
            ?>
            <div class="merchant-sidebar-group mb-1" data-group-id="<?= e($group['id']) ?>" data-open="<?= $isOpen ? '1' : '0' ?>">
                <button type="button" class="merchant-group-toggle w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:text-white hover:bg-white/5 transition" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                    <span><?= e($group['title']) ?></span>
                    <svg class="merchant-group-chevron flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:1rem;height:1rem;pointer-events:none;transition:transform .3s;transform:rotate(<?= $isOpen ? '90' : '0' ?>deg);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="merchant-group-panel" style="overflow:hidden;transition:max-height .3s;max-height:<?= $isOpen ? '2000' : '0' ?>px;">
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
            if (!function_exists('uniwebAdminNavGroups')) {
                require_once __DIR__ . '/includes/sidebar_nav.php';
            }
            $adminNav = uniwebAdminNavGroups();
            $adminHiddenUrls = uniwebAdminHiddenUrls();
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