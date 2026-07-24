<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$tickets = getCustomerTickets($phone);
$txns = getCustomerTransactions($phone, 5);

$pageTitle = 'My Profile';
$hideNav = true;
$hideFooter = true;
$customerPortalUi = true;
$cpNavActive = 'profile';
$bodyClass = trim(($bodyClass ?? '') . ' customer-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="cp-shell">
    <header class="cp-topbar">
        <div class="cp-topbar-inner">
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <?php require __DIR__ . '/includes/customer_portal_nav.php'; ?>
            <div class="flex items-center gap-2 sm:gap-3">
                <span class="cp-phone-chip">+91 <?= e($phone) ?></span>
                <a href="customer_logout.php" class="cp-btn cp-btn-ghost text-xs !py-1.5 !px-3">Logout</a>
            </div>
        </div>
    </header>
    <main class="cp-main py-8 space-y-6 flex-1 w-full">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Customer portal</p>
            <h1 class="cp-display text-3xl font-bold mt-2 text-slate-900">Profile</h1>
            <p class="cp-muted mt-2">Your customer account is verified by mobile OTP. No merchant tools are available here.</p>
        </div>
        <section class="cp-panel p-6 space-y-4">
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-semibold">Mobile</p>
                <p class="text-lg font-semibold text-slate-900 mt-1">+91 <?= e($phone) ?></p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
                    <p class="text-slate-500 text-xs">Recent payments shown</p>
                    <p class="text-2xl font-bold text-slate-900 mt-1"><?= count($txns) ?></p>
                    <a href="customer_portal.php#txns" class="text-teal-700 text-xs font-semibold mt-2 inline-block">View history →</a>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
                    <p class="text-slate-500 text-xs">Complaints</p>
                    <p class="text-2xl font-bold text-slate-900 mt-1"><?= count($tickets) ?></p>
                    <a href="customer_ticket.php" class="text-teal-700 text-xs font-semibold mt-2 inline-block">Open complaints →</a>
                </div>
            </div>
            <p class="text-xs text-slate-500">Need help? <a href="contact.php" class="text-teal-700 font-semibold underline">Contact UniWeb support</a></p>
        </section>
    </main>
    <footer class="cp-footer">
        <div class="cp-footer-inner">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
            <span class="flex gap-4"><a href="terms.php">Terms</a><a href="privacy.php">Privacy</a><a href="contact.php">Contact</a></span>
        </div>
    </footer>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
