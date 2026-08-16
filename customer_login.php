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
        if (function_exists('checkVelocityBlock') && checkVelocityBlock('otp_fail')['blocked']) {
            $v = checkVelocityBlock('otp_fail');
            $error = 'Too many incorrect OTP attempts. Please try again in ~' . $v['retry_after_minutes'] . ' minutes.';
            $otpStep = true;
        } else {
            $res = verifyCustomerOtp($phone, (string)$_POST['otp_code']);
            if ($res['ok']) {
                unset($_SESSION['customer_pending_phone'], $_SESSION['customer_demo_otp']);
                flash('success', 'Welcome! You are logged in.');
                redirect('customer_portal.php');
            }
            if (function_exists('recordVelocityEvent')) {
                recordVelocityEvent('otp_fail', $phone);
            }
            $error = $res['message'];
            $otpStep = true;
        }
    } else {
        if (function_exists('checkVelocityBlock') && checkVelocityBlock('login_fail')['blocked']) {
            $v = checkVelocityBlock('login_fail');
            $error = 'Too many attempts from this network. Please try again in ~' . $v['retry_after_minutes'] . ' minutes.';
        } else {
            $phone = customerNormalizePhone((string)($_POST['mobile'] ?? ''));
            if ($phone === '') {
                $error = 'Please enter a valid 10-digit Indian mobile number.';
            } else {
                $res = requestCustomerOtp($phone);
                if ($res['ok']) {
                    $_SESSION['customer_pending_phone'] = $phone;
                    if (($res['channel'] ?? '') === 'demo' && !empty($res['demo_otp'])) {
                        $_SESSION['customer_demo_otp'] = $res['demo_otp'];
                    } else {
                        unset($_SESSION['customer_demo_otp']);
                    }
                    $notice = $res['message'];
                    $otpStep = true;
                } else {
                    if (function_exists('recordVelocityEvent')) {
                        recordVelocityEvent('login_fail', $phone);
                    }
                    $error = $res['message'];
                }
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
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell auth-portal--customer');
require_once __DIR__ . '/header.php';
?>
<div class="ap-wrap">
    <?php require __DIR__ . '/includes/auth_theme_toggle.php'; ?>
    <div class="ap-panel">
        <div class="ap-card">
            <div class="ap-logo">
                <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            </div>
            <p class="ap-title"><?= $otpStep ? 'Enter OTP' : 'Sign in with mobile' ?></p>
            <p class="ap-sub"><?= $otpStep
                ? 'We sent a 6-digit code to your phone. Valid for 10 minutes.'
                : 'Use the same 10-digit number you paid with. No password needed.' ?></p>

            <?php if ($error): ?><div class="ap-alert ap-alert-error"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="ap-alert ap-alert-ok"><?= e($notice) ?></div><?php endif; ?>

            <?php if ($otpStep): ?>
            <form method="POST" class="ap-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-sm text-center" style="color:var(--ap-muted);margin-bottom:.5rem;">Code sent to <strong>+91 <?= e($pendingPhone) ?></strong></p>
                <?php if (!empty($_SESSION['customer_demo_otp'])): ?>
                <div class="ap-alert ap-alert-ok" style="text-align:center;font-size:1.1rem;letter-spacing:.15em;font-weight:700;">
                    <?= e((string)$_SESSION['customer_demo_otp']) ?>
                </div>
                <p class="text-sm text-center" style="color:var(--ap-muted);margin-top:-.5rem;margin-bottom:.5rem;">SMS/WhatsApp gateway not connected yet — your code is shown above.</p>
                <?php endif; ?>
                <div class="ap-field">
                    <label for="otp_code">One-time password</label>
                    <input id="otp_code" type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="ap-input ap-otp" placeholder="••••••" autofocus>
                </div>
                <button type="submit" class="ap-btn">Verify &amp; continue</button>
                <div class="ap-row"><a href="customer_login.php?change=1" class="ap-text-link">← Change mobile number</a></div>
            </form>
            <?php else: ?>
            <form method="POST" class="ap-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="ap-field">
                    <label for="mobile">Mobile number</label>
                    <div class="ap-phone" role="group" aria-label="Indian mobile number">
                        <span class="ap-phone-cc" aria-hidden="true">+91</span>
                        <input id="mobile" type="tel" name="mobile" required maxlength="10" pattern="[6-9][0-9]{9}" inputmode="numeric" autocomplete="tel-national" class="ap-phone-input" placeholder="98765 43210" value="<?= e($_POST['mobile'] ?? '') ?>" autofocus>
                    </div>
                </div>
                <button type="submit" class="ap-btn">Send OTP →</button>
            </form>
            <?php endif; ?>
            <p class="ap-foot">Need help? <a href="contact.php" class="ap-link">Contact support</a></p>
            <p class="ap-foot" style="margin-top:.5rem;"><?= e(customerPortalScopeCopy()) ?></p>
            <p class="ap-foot" style="margin-top:.35rem;font-size:.75rem;opacity:.85">Customer portal only. Merchants use <a href="login.php" class="ap-text-link">merchant login</a>.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
