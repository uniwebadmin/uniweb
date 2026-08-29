<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$phoneDisplay = (function_exists('sensitiveUiPlain') ? (sensitiveUiPlain($phone) ?: $phone) : $phone);
$tickets = getCustomerTickets($phone);
$txns = getCustomerTransactions($phone, 5);

$phoneChangeOtp = $_SESSION['cust_phone_change_otp'] ?? null;
$phoneChangeNew = $_SESSION['cust_phone_change_new'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_phone_change') {
        $newPhone = customerNormalizePhone((string)($_POST['new_phone'] ?? ''));
        if ($newPhone === '') {
            flash('error', 'कृपया सही 10 अंकों का मोबाइल नंबर दर्ज करें।');
            redirect('customer_profile.php');
        }
        if ($newPhone === $phone) {
            flash('error', 'यह नंबर पहले से पंजीबद्ध है।');
            redirect('customer_profile.php');
        }
        $res = requestCustomerOtp($newPhone);
        if (!empty($res['ok'])) {
            $_SESSION['cust_phone_change_new'] = $newPhone;
            $_SESSION['cust_phone_change_otp'] = $res['demo_otp'] ?? null;
            flash('info', $res['message'] . ' Naya number: +91 ' . $newPhone);
        } else {
            flash('error', $res['message']);
        }
        redirect('customer_profile.php');
    }

    if ($action === 'confirm_phone_change') {
        $newPhone = (string)($_SESSION['cust_phone_change_new'] ?? '');
        $otp = (string)($_POST['phone_change_otp'] ?? '');
        if ($newPhone === '' || $otp === '') {
            flash('error', 'OTP दर्ज करें।');
            redirect('customer_profile.php');
        }
        $verify = verifyCustomerOtp($newPhone, $otp);
        if (!empty($verify['ok'])) {
            $oldPhone = $phone;
            $_SESSION['customer_phone'] = $newPhone;
            unset($_SESSION['cust_phone_change_new'], $_SESSION['cust_phone_change_otp']);
            try {
                getDB()->prepare("UPDATE transactions SET customer_phone=? WHERE customer_phone=?")
                    ->execute([$newPhone, $oldPhone]);
            } catch (Throwable $e) { /* ok */ }
            try {
                getDB()->prepare("UPDATE payment_links SET customer_phone=? WHERE customer_phone=?")
                    ->execute([$newPhone, $oldPhone]);
            } catch (Throwable $e) { /* ok */ }
            try {
                getDB()->prepare("UPDATE customer_tickets SET customer_phone=? WHERE customer_phone=?")
                    ->execute([$newPhone, $oldPhone]);
            } catch (Throwable $e) { /* ok */ }
            flash('success', 'Phone number updated successfully. +91 ' . $newPhone);
        } else {
            flash('error', $verify['message']);
        }
        redirect('customer_profile.php');
    }

    if ($action === 'cancel_phone_change') {
        unset($_SESSION['cust_phone_change_new'], $_SESSION['cust_phone_change_otp']);
        flash('info', 'Phone change cancelled.');
        redirect('customer_profile.php');
    }
}

$phoneChangeOtp = $_SESSION['cust_phone_change_otp'] ?? null;
$phoneChangeNew = $_SESSION['cust_phone_change_new'] ?? null;

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
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo_safe.php'; ?>
            <?php require __DIR__ . '/includes/customer_portal_nav.php'; ?>
            <div class="flex items-center gap-2 sm:gap-3">
                <span class="cp-phone-chip">+91 <?= e($phoneDisplay) ?></span>
                <a href="customer_logout.php" class="cp-btn cp-btn-ghost text-xs !py-1.5 !px-3">Logout</a>
            </div>
        </div>
    </header>
    <main class="cp-main py-8 space-y-6 flex-1 w-full">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Customer portal</p>
            <h1 class="cp-display text-3xl font-bold mt-2 text-slate-900">Profile</h1>
            <p class="cp-muted mt-2">Your customer account is verified by mobile OTP. No merchant tools are available here.</p>
            <p class="text-xs text-slate-500 mt-2"><?= e(customerPortalScopeCopy()) ?></p>
        </div>
        <section class="cp-panel p-6 space-y-4">
            <div>
                <p class="text-[10px] uppercase text-slate-500 font-semibold">Mobile</p>
                <p class="text-lg font-semibold text-slate-900 mt-1">+91 <?= e($phoneDisplay) ?></p>
            </div>

            <?php if ($phoneChangeNew): ?>
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 space-y-3">
                <p class="text-sm font-semibold text-amber-800">Phone change verification</p>
                <p class="text-xs text-amber-700">OTP sent to +91 <?= e($phoneChangeNew) ?>. Enter the 6-digit OTP to confirm.</p>
                <?php if ($phoneChangeOtp): ?>
                <p class="text-xs text-amber-600 bg-amber-100 rounded px-2 py-1">Demo OTP: <strong><?= e($phoneChangeOtp) ?></strong></p>
                <?php endif; ?>
                <form method="POST" class="flex gap-2 flex-wrap">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="confirm_phone_change">
                    <input type="text" name="phone_change_otp" maxlength="6" inputmode="numeric" placeholder="6-digit OTP" class="cp-input flex-1 min-w-[120px] text-sm" required>
                    <button type="submit" class="cp-btn cp-btn-primary text-sm">Confirm</button>
                </form>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="cancel_phone_change">
                    <button type="submit" class="cp-btn cp-btn-ghost text-xs">Cancel</button>
                </form>
            </div>
            <?php else: ?>
            <details class="rounded-xl bg-slate-50 border border-slate-100 p-4">
                <summary class="text-sm font-semibold text-slate-700 cursor-pointer">Change mobile number</summary>
                <form method="POST" class="mt-3 flex gap-2 flex-wrap">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="request_phone_change">
                    <input type="text" name="new_phone" maxlength="10" inputmode="numeric" placeholder="New 10-digit mobile" class="cp-input flex-1 min-w-[140px] text-sm" required>
                    <button type="submit" class="cp-btn cp-btn-primary text-sm">Send OTP</button>
                </form>
                <p class="text-xs text-slate-500 mt-2">OTP will be sent to the new number. Your transactions and tickets will be linked to the new number.</p>
            </details>
            <?php endif; ?>

            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
                    <p class="text-slate-500 text-xs">Recent payments shown</p>
                    <p class="text-2xl font-bold text-slate-900 mt-1"><?= count($txns) ?></p>
                    <a href="customer_portal.php#txns" class="text-teal-700 text-xs font-semibold mt-2 inline-block">View history →</a>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-100 p-4">
                    <p class="text-slate-500 text-xs">Complaints</p>
                    <p class="text-2xl font-bold text-slate-900 mt-1"><?= count($tickets) ?></p>
                    <a href="customer_portal.php#complaints" class="text-teal-700 text-xs font-semibold mt-2 inline-block">Open complaints →</a>
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
