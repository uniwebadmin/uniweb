<?php
require_once __DIR__ . '/config.php';
if (isLoggedIn()) redirect('dashboard.php');
ensureSignupVerificationSchema();

$errors = [];
$pending = $_SESSION['pending_signup'] ?? null;
$signupMode = $pending['signup_mode'] ?? ((($_POST['signup_mode'] ?? $_GET['mode'] ?? 'email') === 'mobile') ? 'mobile' : 'email');
$showOtpStep = $pending !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $step = (string)($_POST['step'] ?? 'details');

    if ($step === 'change_details') {
        unset($_SESSION['pending_signup']);
        redirect('merchant_register.php?mode=' . ($pending['signup_mode'] ?? 'email'));
    }

    if ($step === 'resend_otp' && $pending) {
        $v = checkVelocityBlock('merchant_signup_otp');
        if (!empty($v['blocked'])) {
            $errors[] = velocityBlockMessage('merchant_signup_otp');
        } else {
            $otp = generateOTP($pending['otp_identifier'], 'merchant_signup');
            $delivery = deliverContactChangeOtp(
                $otp,
                $pending['signup_mode'] === 'email' ? 'email' : 'mobile',
                $pending['signup_mode'] === 'email' ? $pending['email'] : $pending['phone'],
                'account signup'
            );
            $_SESSION['pending_signup'] = $pending;
            flash('success', $pending['signup_mode'] === 'email' ? __('flash_otp_sent_email') : __('flash_otp_sent_mobile'));
        }
        $showOtpStep = true;
    } elseif ($step === 'verify' && $pending) {
        $otpCode = trim((string)($_POST['otp_code'] ?? ''));
        $v = checkVelocityBlock('merchant_signup_otp');
        if (!empty($v['blocked'])) {
            $errors[] = velocityBlockMessage('merchant_signup_otp');
        } elseif (!verifyOTP($pending['otp_identifier'], $otpCode, 'merchant_signup')) {
            recordVelocityEvent('otp_fail', 'merchant_signup:' . $pending['otp_identifier']);
            $errors[] = __('err_invalid_otp');
        } else {
            $db = getDB();
            $email = $pending['email'];
            $phone = $pending['phone'];
            $name = $pending['name'];
            $check = $db->prepare('SELECT id FROM merchants WHERE email=? OR phone=?');
            $check->execute([$email, $phone]);
            if ($check->fetch()) {
                unset($_SESSION['pending_signup']);
                $errors[] = __('err_already_registered');
                $showOtpStep = false;
            } else {
                $code = 'UW' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $business = 'My Business';
                $db->prepare('INSERT INTO merchants (merchant_code,name,email,phone,password,business_name,business_type,business_entity_type,pan_number,address,country,state,district,city,pincode,api_key,api_secret,upi_id,email_verified_at,phone_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        $code, $name, $email, $phone, $pending['password_hash'],
                        $business, 'retail', 'individual', null, '',
                        'India', '', '', '', '',
                        'uk_' . bin2hex(random_bytes(16)), 'us_' . bin2hex(random_bytes(24)),
                        'merchant' . strtolower(substr($code, 2)) . '@uniweb',
                        $signupMode === 'email' ? date('Y-m-d H:i:s') : null,
                        $signupMode === 'mobile' ? date('Y-m-d H:i:s') : null,
                    ]);
                $id = (int)$db->lastInsertId();
                if ($pending['signup_mode'] === 'email') {
                    // Synthetic unique phone so email-only signups do not collide on phone UNIQUE.
                    // Pattern +9199 + zero-padded id (e.g. id 7 → +919900000007) — NOT a real mobile.
                    $uniquePhone = '+9199' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
                    $db->prepare('UPDATE merchants SET phone=? WHERE id=?')->execute([$uniquePhone, $id]);
                }
                recordVelocityEvent('merchant_signup', 'merchant:' . $id);
                try {
                    $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=?, account_mode=?, provision_profile=?, enabled_methods=?, collection_mode=?, auto_provisioned=1 WHERE id=?')
                        ->execute(['test_' . bin2hex(random_bytes(16)), 'testsec_' . bin2hex(random_bytes(24)), 'test', 'auto_p2m', json_encode(['upi_p2m']), 'direct_upi', $id]);
                } catch (Throwable $e) {
                    try {
                        $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=?, account_mode=?, enabled_methods=? WHERE id=?')
                            ->execute(['test_' . bin2hex(random_bytes(16)), 'testsec_' . bin2hex(random_bytes(24)), 'test', json_encode(['upi_p2m']), $id]);
                    } catch (Throwable $e2) { /* ok */ }
                }

                if (function_exists('bootstrapMerchantMethodAutomation') === false) {
                    require_once __DIR__ . '/includes/method_requests.php';
                }
                if (function_exists('bootstrapMerchantMethodAutomation')) {
                    bootstrapMerchantMethodAutomation($id, 'Auto-queued on merchant signup');
                }

                createNotification($id, __('notif_welcome_title'), __('notif_welcome_body'));

                unset($_SESSION['pending_signup']);
                $_SESSION['merchant_id'] = $id;
                $_SESSION['merchant_code'] = $code;

                flash('success', __('flash_account_created'));
                redirect('merchant_setup.php');
            }
        }
    } else {
        $signupMode = ($_POST['signup_mode'] ?? 'email') === 'mobile' ? 'mobile' : 'email';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $email = '';
        $phone = '';
        $name = 'Merchant';

        $v = checkVelocityBlock('merchant_signup');
        if (!empty($v['blocked'])) {
            $errors[] = velocityBlockMessage('merchant_signup');
        }

        if (empty($errors) && $signupMode === 'email') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('err_valid_email');
            }
            $local = strstr($email, '@', true) ?: 'merchant';
            $name = ucfirst(preg_replace('/[^a-zA-Z0-9]/', ' ', $local));
            $phone = '+919900000000';
        } else {
            $phoneCode = trim($_POST['phone_code'] ?? '+91');
            $phoneNum = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            $phone = $phoneCode . $phoneNum;
            if ($phoneCode === '+91' && !preg_match('/^[6-9]\d{9}$/', $phoneNum)) {
                $errors[] = __('err_valid_mobile');
            } elseif (strlen($phoneNum) < 6 || strlen($phoneNum) > 15) {
                $errors[] = __('err_valid_mobile_generic');
            }
            $email = 'm' . $phoneNum . '@signup.uniweb.co.in';
            $name = 'Merchant';
        }

        if (strlen($password) < 8) {
            $errors[] = __('err_password_min');
        }
        if ($password !== $confirm) {
            $errors[] = __('err_password_match');
        }

        if (empty($errors)) {
            $db = getDB();
            $check = $db->prepare('SELECT id FROM merchants WHERE email=? OR phone=?');
            $check->execute([$email, $phone]);
            if ($check->fetch()) {
                $errors[] = __('err_already_registered');
            } else {
                $otpTarget = $signupMode === 'email' ? $email : preg_replace('/\D/', '', $phone);
                $otp = generateOTP($otpTarget, 'merchant_signup');
                $delivery = deliverContactChangeOtp(
                    $otp,
                    $signupMode === 'email' ? 'email' : 'mobile',
                    $signupMode === 'email' ? $email : $phone,
                    'account signup'
                );
                $_SESSION['pending_signup'] = [
                    'email' => $email,
                    'phone' => $phone,
                    'name' => $name,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'signup_mode' => $signupMode,
                    'otp_identifier' => $otpTarget,
                    'demo_otp' => $delivery['demo_otp'],
                ];
                flash('success', $signupMode === 'email' ? __('flash_otp_sent_email') : __('flash_otp_sent_mobile'));
                $showOtpStep = true;
            }
        }
    }
    $pending = $_SESSION['pending_signup'] ?? null;
}

$pageTitle = __('signup_title');
$hideNav = true;
$hideFooter = true;
$footerVariant = 'auth';
$authPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell auth-portal--merchant');
require_once __DIR__ . '/header.php';
?>

<div class="ap-panel min-h-screen">
    <div class="relative w-full max-w-md">

        <div class="text-center mb-6">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="ap-display text-2xl font-bold"><?= $showOtpStep ? __('signup_otp_title') : __('signup_title') ?></h1>
            <p class="ap-sub text-sm mt-2"><?= $showOtpStep ? __('signup_otp_sub') : __('signup_sub') ?></p>
        </div>

        <div class="ap-card max-w-md mx-auto">
            <?php if ($errors): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6">
                <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($showOtpStep && $pending): ?>
            <p class="text-sm text-gray-400 mb-4">
                <?= $pending['signup_mode'] === 'email' ? __('email_id') : __('mobile_number') ?>:
                <span class="text-white font-medium"><?= e($pending['signup_mode'] === 'email' ? $pending['email'] : $pending['phone']) ?></span>
            </p>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="step" value="verify">
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5"><?= __('otp_code_label') ?> *</label>
                    <input type="text" name="otp_code" required maxlength="6" inputmode="numeric" autofocus class="input-field tracking-widest text-center text-lg" placeholder="••••••">
                </div>
                <button type="submit" class="ap-btn"><?= __('verify_and_create_account_btn') ?></button>
            </form>
            <div class="flex items-center justify-between mt-4 text-sm">
                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="step" value="resend_otp"><button type="submit" class="text-brand-400 hover:underline"><?= __('resend_otp') ?></button></form>
                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="step" value="change_details"><button type="submit" class="text-gray-500 hover:underline"><?= __('change_details') ?></button></form>
            </div>
            <?php else: ?>

            <div class="flex rounded-lg bg-dark-900/60 p-1 mb-6 border border-gray-800">
                <a href="?mode=email" class="flex-1 text-center py-2.5 text-sm rounded-md transition <?= $signupMode === 'email' ? 'bg-brand-600 text-white font-semibold' : 'text-gray-400 hover:text-white' ?>"><?= __('signup_via_email') ?></a>
                <a href="?mode=mobile" class="flex-1 text-center py-2.5 text-sm rounded-md transition <?= $signupMode === 'mobile' ? 'bg-brand-600 text-white font-semibold' : 'text-gray-400 hover:text-white' ?>"><?= __('signup_via_mobile') ?></a>
            </div>

            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="signup_mode" value="<?= e($signupMode) ?>">

                <?php if ($signupMode === 'email'): ?>
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5"><?= __('email_id') ?> *</label>
                    <input type="email" name="email" required class="input-field" placeholder="you@business.com" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <?php else: ?>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1.5"><?= __('country_code') ?></label>
                        <select name="phone_code" class="input-field">
                            <option value="+91" <?= ($_POST['phone_code'] ?? '+91') === '+91' ? 'selected' : '' ?>>+91</option>
                            <option value="+977">+977</option>
                            <option value="+880">+880</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm text-gray-400 mb-1.5"><?= __('mobile_number') ?> *</label>
                        <input type="tel" name="phone" required class="input-field" placeholder="9876543210" value="<?= e($_POST['phone'] ?? '') ?>">
                    </div>
                </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm text-gray-400 mb-1.5"><?= __('password') ?> *</label>
                    <input type="password" name="password" required minlength="8" class="input-field" placeholder="<?= e(__('password_min_placeholder')) ?>">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5"><?= __('confirm_password') ?> *</label>
                    <input type="password" name="confirm_password" required class="input-field" placeholder="<?= e(__('password_confirm_placeholder')) ?>">
                </div>

                <p class="text-xs text-gray-600"><?= __('signup_portal_note') ?></p>

                <button type="submit" class="ap-btn"><?= __('create_account_btn') ?></button>

                <p class="text-xs text-gray-600 text-center leading-relaxed">
                    <?= __('signup_terms') ?>
                    <a href="terms.php" target="_blank" class="text-brand-400 hover:underline"><?= __('terms') ?></a>
                    and
                    <a href="privacy.php" target="_blank" class="text-brand-400 hover:underline"><?= __('privacy_policy') ?></a>,
                    <a href="refund_policy.php" target="_blank" class="text-brand-400 hover:underline">Refund Policy</a>.
                </p>
            </form>
            <?php endif; ?>

            <p class="text-center text-sm text-gray-500 mt-6"><?= __('already_have_account') ?> <a href="login.php" class="text-brand-400"><?= __('login') ?></a></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
