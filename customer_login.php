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
$footerVariant = 'auth';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="relative w-full max-w-md">
        <div class="text-center mb-8">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="text-2xl font-bold">Customer Login</h1>
            <p class="text-gray-500 text-sm mt-2">See your payment history &amp; raise a complaint. Login with your mobile number — no password needed.</p>
        </div>
        <div class="glass rounded-2xl p-8">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($notice) ?></div><?php endif; ?>

            <?php if ($otpStep): ?>
            <?php if (!empty($demoOtp)): ?>
            <div class="bg-amber-500/10 border border-amber-500/30 text-amber-300 text-sm px-4 py-3 rounded-lg mb-6">
                Demo mode (SMS/WhatsApp not configured). Your OTP is <strong class="font-mono text-lg tracking-widest"><?= e($demoOtp) ?></strong>
            </div>
            <?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-sm text-gray-400 text-center">Enter the 6-digit OTP sent to <strong>+91 <?= e($pendingPhone) ?></strong></p>
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5">OTP</label>
                    <input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="input-field text-center text-2xl tracking-widest" placeholder="000000" autofocus>
                </div>
                <button type="submit" class="w-full btn-primary py-3">Verify &amp; Continue</button>
                <p class="text-center text-xs mt-4"><a href="customer_login.php?change=1" class="text-gray-500 hover:text-white">← Change mobile number</a></p>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5">Mobile Number</label>
                    <div class="flex items-center gap-2">
                        <span class="input-field w-16 text-center text-gray-400 shrink-0">+91</span>
                        <input type="tel" name="mobile" required maxlength="10" pattern="[6-9][0-9]{9}" inputmode="numeric" class="input-field" placeholder="10-digit mobile" value="<?= e($_POST['mobile'] ?? '') ?>" autofocus>
                    </div>
                    <p class="text-xs text-gray-600 mt-1.5">We'll send a one-time password (OTP) to this number.</p>
                </div>
                <button type="submit" class="w-full btn-primary py-3">Send OTP →</button>
            </form>
            <?php endif; ?>

            <p class="text-center text-sm text-gray-500 mt-6">Are you a merchant? <a href="login.php" class="text-brand-400">Merchant login</a></p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
