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
$demoOtp = $_SESSION['customer_demo_otp'] ?? null;

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
                if (!empty($res['demo_otp'])) {
                    $_SESSION['customer_demo_otp'] = $res['demo_otp'];
                    $demoOtp = $res['demo_otp'];
                } else {
                    unset($_SESSION['customer_demo_otp']);
                    $demoOtp = null;
                }
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
$customerPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' customer-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="cp-shell">
    <div class="cp-auth-wrap">
        <aside class="cp-auth-visual" aria-hidden="true">
            <div>
                <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
                <h2 class="cp-display text-3xl font-bold mt-10 leading-tight">Payments you made,<br>in one calm place.</h2>
                <p class="mt-4 text-white/80 text-sm max-w-sm leading-relaxed">Enter the mobile number used at checkout. We send a one-time OTP — no password, no account setup.</p>
            </div>
            <div class="relative z-10 space-y-3 text-sm text-white/85">
                <p>✓ Full history across every UniWeb merchant</p>
                <p>✓ Clear status + reason for each payment</p>
                <p>✓ Raise a grievance on any transaction</p>
            </div>
        </aside>

        <div class="cp-auth-panel">
            <div class="cp-auth-card">
                <div class="sm:hidden mb-4">
                    <?php $logoHref = 'index.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
                </div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Customer portal</p>
                <h1 class="cp-display"><?= $otpStep ? 'Enter OTP' : 'Sign in with mobile' ?></h1>
                <p class="cp-muted mt-2"><?= $otpStep
                    ? 'We sent a 6-digit code to your phone. Valid for 10 minutes.'
                    : 'Use the same 10-digit number you paid with. No password needed.' ?></p>

                <?php if ($error): ?><div class="cp-alert cp-alert-error mt-5"><?= e($error) ?></div><?php endif; ?>
                <?php if ($notice): ?><div class="cp-alert cp-alert-ok mt-5"><?= e($notice) ?></div><?php endif; ?>

                <?php if ($otpStep): ?>
                <?php if (!empty($demoOtp)): ?>
                <div class="cp-alert cp-alert-demo mt-5">
                    Demo mode (SMS/WhatsApp not configured). Your OTP is
                    <strong class="font-mono text-lg tracking-widest block mt-1"><?= e($demoOtp) ?></strong>
                </div>
                <?php endif; ?>
                <form method="POST" class="space-y-5 mt-6">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <p class="text-sm text-center text-slate-600">Code sent to <strong>+91 <?= e($pendingPhone) ?></strong></p>
                    <div class="cp-field">
                        <label for="otp_code">One-time password</label>
                        <input id="otp_code" type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="cp-input cp-otp" placeholder="••••••" autofocus>
                    </div>
                    <button type="submit" class="cp-btn cp-btn-primary w-full py-3">Verify &amp; continue</button>
                    <p class="text-center text-xs"><a href="customer_login.php?change=1" class="text-slate-500 hover:text-teal-700">← Change mobile number</a></p>
                </form>
                <?php else: ?>
                <form method="POST" class="space-y-5 mt-6">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="cp-field">
                        <label for="mobile">Mobile number</label>
                        <div class="flex gap-2">
                            <span class="cp-input w-16 text-center text-slate-500 shrink-0 flex items-center justify-center">+91</span>
                            <input id="mobile" type="tel" name="mobile" required maxlength="10" pattern="[6-9][0-9]{9}" inputmode="numeric" class="cp-input" placeholder="10-digit mobile" value="<?= e($_POST['mobile'] ?? '') ?>" autofocus>
                        </div>
                        <p class="text-xs text-slate-500 mt-1.5">We'll send an OTP by WhatsApp or SMS.</p>
                    </div>
                    <button type="submit" class="cp-btn cp-btn-primary w-full py-3">Send OTP →</button>
                </form>
                <?php endif; ?>

                <p class="text-center text-sm text-slate-500 mt-8">Merchant? <a href="login.php" class="text-teal-700 font-semibold hover:underline">Merchant login</a></p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
