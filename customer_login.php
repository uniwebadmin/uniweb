<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';

if (isCustomerLoggedIn()) {
    redirect('customer_portal.php');
}

if (isset($_GET['change'])) {
    unset($_SESSION['customer_pending_phone'], $_SESSION['customer_demo_otp']);
    redirect('customer_login.php');
}

$error = '';
$notice = '';
$otpStep = !empty($_SESSION['customer_pending_phone']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } elseif (isset($_POST['otp_code'])) {
        $phone = (string)($_SESSION['customer_pending_phone'] ?? '');
        if ($phone === '') {
            redirect('customer_login.php');
        }
        $res = verifyCustomerOtp($phone, (string)$_POST['otp_code']);
        if ($res['ok']) {
            unset($_SESSION['customer_pending_phone'], $_SESSION['customer_demo_otp']);
            flash('success', 'Welcome! You are logged in.');
            redirect('customer_portal.php');
        }
        $error = $res['message'];
        $otpStep = true;
    } else {
        $phone = customerNormalizePhone((string)($_POST['mobile'] ?? ''));
        if ($phone === '') {
            $error = 'Please enter a valid 10-digit Indian mobile number.';
        } else {
            $res = requestCustomerOtp($phone);
            if ($res['ok']) {
                $_SESSION['customer_pending_phone'] = $phone;
                unset($_SESSION['customer_demo_otp']);
                $notice = $res['message'];
                $otpStep = true;
            } else {
                $error = $res['message'];
            }
        }
    }
}

$pendingPhone = (string)($_SESSION['customer_pending_phone'] ?? '');
$pageTitle = 'Customer Login';
$hideNav = true;
$hideFooter = true;
$footerVariant = 'auth';
$authPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="ap-wrap">
    <aside class="ap-visual" aria-hidden="true">
        <div>
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h2 class="ap-display text-3xl font-bold mt-10 leading-tight">Payments you made,<br>in one calm place.</h2>
            <p class="mt-4 text-white/80 text-sm max-w-sm leading-relaxed">Enter the mobile number used at checkout. We send a one-time OTP — no password, no account setup.</p>
        </div>
        <div class="relative z-10 space-y-3 text-sm text-white/85">
            <p>✓ Full history across every UniWeb merchant</p>
            <p>✓ Clear status + reason for each payment</p>
            <p>✓ Raise a grievance on any transaction</p>
        </div>
    </aside>
    <div class="ap-panel">
        <div class="ap-card">
            <div class="sm:hidden mb-4"><?php $logoHref = 'index.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?></div>
            <p class="ap-eyebrow">Customer portal</p>
            <h1 class="ap-display"><?= $otpStep ? 'Enter OTP' : 'Sign in with mobile' ?></h1>
            <p class="ap-sub"><?= $otpStep
                ? 'We sent a 6-digit code to your phone. Valid for 10 minutes.'
                : 'Use the same 10-digit number you paid with. No password needed.' ?></p>

            <?php if ($error): ?><div class="ap-alert ap-alert-error mt-5"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="ap-alert ap-alert-ok mt-5"><?= e($notice) ?></div><?php endif; ?>

            <?php if ($otpStep): ?>
            <form method="POST" class="space-y-5 mt-6">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-sm text-center text-slate-600">Code sent to <strong>+91 <?= e($pendingPhone) ?></strong></p>
                <div class="ap-field">
                    <label for="otp_code">One-time password</label>
                    <input id="otp_code" type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="ap-input ap-otp" placeholder="••••••" autofocus>
                </div>
                <button type="submit" class="ap-btn">Verify &amp; continue</button>
                <p class="text-center text-xs"><a href="customer_login.php?change=1" class="text-slate-500 hover:text-teal-700">← Change mobile number</a></p>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5 mt-6">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="ap-field">
                    <label for="mobile">Mobile number</label>
                    <div class="ap-phone" role="group" aria-label="Indian mobile number">
                        <span class="ap-phone-cc" aria-hidden="true">+91</span>
                        <input id="mobile" type="tel" name="mobile" required maxlength="10" pattern="[6-9][0-9]{9}" inputmode="numeric" autocomplete="tel-national" class="ap-phone-input" placeholder="98765 43210" value="<?= e($_POST['mobile'] ?? '') ?>" autofocus>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">India (+91) · enter your 10-digit mobile. We send an OTP by WhatsApp or SMS.</p>
                </div>
                <button type="submit" class="ap-btn">Send OTP →</button>
            </form>
            <?php endif; ?>
            <p class="ap-foot">Need help? <a href="contact.php" class="ap-link">Contact support</a></p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
