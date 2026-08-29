<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/includes/release_helpers.php')) {
    require_once __DIR__ . '/includes/release_helpers.php';
}
require_once __DIR__ . '/includes/risk.php';
if (isLoggedIn()) redirect('dashboard.php');
ensureSignupVerificationSchema();

// Pre-filled onboarding invite
$inviteData = null;
$inviteToken = trim((string)($_GET['invite'] ?? ''));
if ($inviteToken !== '' && preg_match('/^[a-f0-9]{20,64}$/', $inviteToken)) {
    try {
        $st = getDB()->prepare("SELECT * FROM onboarding_invites WHERE token=? AND used_by IS NULL AND expires_at > NOW() LIMIT 1");
        $st->execute([$inviteToken]);
        $inviteData = $st->fetch() ?: null;
    } catch (Throwable $e) { $inviteData = null; }
}

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
                if ($inviteData === null && !empty($pending['invite_token'])) {
                    try {
                        $invSt = $db->prepare('SELECT * FROM onboarding_invites WHERE token=? AND used_by IS NULL AND expires_at > NOW() LIMIT 1');
                        $invSt->execute([(string)$pending['invite_token']]);
                        $inviteData = $invSt->fetch() ?: null;
                    } catch (Throwable $e) {
                        $inviteData = null;
                    }
                }
                $business = trim((string)($pending['business_name'] ?? ''));
                if ($business === '' && is_array($inviteData)) {
                    $business = trim((string)($inviteData['business_name'] ?? ''));
                }
                if ($business === '') {
                    $nameTrim = trim((string)($pending['name'] ?? ''));
                    if ($nameTrim !== '' && strcasecmp($nameTrim, 'Merchant') !== 0) {
                        $business = $nameTrim;
                    } elseif (!empty($pending['email']) && str_contains((string)$pending['email'], '@')) {
                        $local = strstr((string)$pending['email'], '@', true) ?: '';
                        $localLabel = ucfirst(trim(preg_replace('/[^a-zA-Z0-9]/', ' ', $local)));
                        $business = ($localLabel !== '' && strcasecmp($localLabel, 'Merchant') !== 0)
                            ? ($localLabel . ' Business')
                            : 'My Business';
                    } else {
                        $business = 'My Business';
                    }
                }
                $business = mb_substr($business, 0, 120);
                $bType = (is_array($inviteData) ? ($inviteData['business_type'] ?? null) : null) ?? 'retail';
                $bEntity = (is_array($inviteData) ? ($inviteData['business_entity_type'] ?? null) : null) ?? 'individual';
                $db->prepare('INSERT INTO merchants (merchant_code,name,email,phone,password,business_name,business_type,business_entity_type,pan_number,address,country,state,district,city,pincode,api_key,api_secret,upi_id,email_verified_at,phone_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        $code, $name, $email, $phone, $pending['password_hash'],
                        $business, $bType, $bEntity, null, '',
                        'India', '', '', '', '',
                        null, null,
                        'merchant' . strtolower(substr($code, 2)) . '@uniweb',
                        $signupMode === 'email' ? date('Y-m-d H:i:s') : null,
                        $signupMode === 'mobile' ? date('Y-m-d H:i:s') : null,
                    ]);
                $id = (int)$db->lastInsertId();
                if (function_exists('linkUserToMerchant')) {
                    try { linkUserToMerchant($email, $phone, $id, 'owner'); } catch (Throwable $e) {}
                }
                if ($pending['signup_mode'] === 'email') {
                    // Synthetic unique phone so email-only signups do not collide on phone UNIQUE.
                    // Pattern +9199 + zero-padded id (e.g. id 7 → +919900000007) — NOT a real mobile.
                    $uniquePhone = '+9199' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
                    $db->prepare('UPDATE merchants SET phone=? WHERE id=?')->execute([$uniquePhone, $id]);
                }
                recordVelocityEvent('merchant_signup', 'merchant:' . $id);
                try {
                    $db->prepare('UPDATE merchants SET account_mode=?, provision_profile=?, enabled_methods=?, collection_mode=?, auto_provisioned=1 WHERE id=?')
                        ->execute(['test', 'auto_p2m', json_encode(['upi_p2m']), 'direct_upi', $id]);
                } catch (Throwable $e) {
                    try {
                        $db->prepare('UPDATE merchants SET account_mode=?, enabled_methods=? WHERE id=?')
                            ->execute(['test', json_encode(['upi_p2m']), $id]);
                    } catch (Throwable $e2) { /* ok */ }
                }
                if (function_exists('bootstrapMerchantApiCredentialsOnSignup')) {
                    $signupCred = bootstrapMerchantApiCredentialsOnSignup($id);
                    if (!empty($signupCred['key']) && !empty($signupCred['secret'])) {
                        $_SESSION['new_api_credential'] = [
                            'key' => $signupCred['key'],
                            'secret' => $signupCred['secret'],
                            'mode' => $signupCred['mode'] ?? 'test',
                        ];
                    }
                }

                if (function_exists('bootstrapMerchantMethodAutomation') === false) {
                    require_once __DIR__ . '/includes/method_requests.php';
                }
                if (function_exists('bootstrapMerchantMethodAutomation')) {
                    bootstrapMerchantMethodAutomation($id, 'Auto-queued on merchant signup');
                }

                updateMerchantRiskScore($id);
                notifyMerchant($id, __('notif_welcome_title'), __('notif_welcome_body'), 'welcome');

                // Mark invite as used
                if ($inviteData && !empty($inviteData['token'])) {
                    try { $db->prepare("UPDATE onboarding_invites SET used_by=? WHERE token=?")->execute([$id, $inviteData['token']]); } catch (Throwable $e) {}
                }

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

        // Use invite data if available
        $invName = $inviteData['name'] ?? '';
        $invEmail = $inviteData['email'] ?? '';
        $invPhone = $inviteData['phone'] ?? '';
        $invBusiness = $inviteData['business_name'] ?? '';

        $v = checkVelocityBlock('merchant_signup');
        if (!empty($v['blocked'])) {
            $errors[] = velocityBlockMessage('merchant_signup');
        }

        if (empty($errors) && $signupMode === 'email') {
            $email = strtolower(trim($_POST['email'] ?? $invEmail));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('err_valid_email');
            }
            $local = strstr($email, '@', true) ?: 'merchant';
            $name = $invName !== '' ? $invName : ucfirst(preg_replace('/[^a-zA-Z0-9]/', ' ', $local));
            $phone = $invPhone !== '' ? '+91' . preg_replace('/\D/', '', $invPhone) : '+910000000000';
        } else {
            $phoneCode = trim($_POST['phone_code'] ?? '+91');
            $phoneNum = preg_replace('/\D/', '', $_POST['phone'] ?? $invPhone);
            $phone = $phoneCode . $phoneNum;
            if ($phoneCode === '+91' && !preg_match('/^[6-9]\d{9}$/', $phoneNum)) {
                $errors[] = __('err_valid_mobile');
            } elseif (strlen($phoneNum) < 6 || strlen($phoneNum) > 15) {
                $errors[] = __('err_valid_mobile_generic');
            }
            $email = $invEmail !== '' ? $invEmail : 'm' . $phoneNum . '@signup.uniweb.co.in';
            $name = $invName !== '' ? $invName : 'Merchant';
        }

        if (function_exists('validateStrongPassword')) {
            $policyError = validateStrongPassword($password, 10);
            if ($policyError) {
                $errors[] = $policyError;
            }
        } elseif (strlen($password) < 8) {
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
                    'business_name' => trim((string)($invBusiness !== '' ? $invBusiness : '')),
                    'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                    'signup_mode' => $signupMode,
                    'otp_identifier' => $otpTarget,
                    'demo_otp' => $delivery['demo_otp'],
                    'invite_token' => ($inviteToken !== '' && $inviteData) ? $inviteToken : null,
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

<div class="ap-wrap">
    <?php require __DIR__ . '/includes/auth_theme_toggle.php'; ?>
    <div class="ap-panel">
        <div class="ap-card">
            <div class="ap-logo">
                <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo_safe.php'; ?>
            </div>
            <p class="ap-title"><?= $showOtpStep ? __('signup_otp_title') : __('signup_title') ?></p>
            <p class="ap-sub"><?= $showOtpStep ? __('signup_otp_sub') : __('signup_sub') ?></p>

            <?php if ($errors): ?>
            <div class="ap-alert ap-alert-error">
                <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($showOtpStep && $pending): ?>
            <p class="text-sm mb-4" style="color:var(--ap-muted);">
                <?= $pending['signup_mode'] === 'email' ? __('email_id') : __('mobile_number') ?>:
                <span style="color:var(--ap-ink);font-weight:500;"><?= e($pending['signup_mode'] === 'email' ? $pending['email'] : $pending['phone']) ?></span>
            </p>
            <form method="POST" class="ap-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="step" value="verify">
                <div class="ap-field">
                    <label class="ap-label"><?= __('otp_code_label') ?> *</label>
                    <input type="text" name="otp_code" required maxlength="6" inputmode="numeric" autofocus class="ap-input ap-otp" placeholder="••••••">
                </div>
                <button type="submit" class="ap-btn"><?= __('verify_and_create_account_btn') ?></button>
            </form>
            <div class="ap-row" style="margin-top:1rem;">
                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="step" value="resend_otp"><button type="submit" class="ap-link"><?= __('resend_otp') ?></button></form>
                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="step" value="change_details"><button type="submit" class="ap-text-link"><?= __('change_details') ?></button></form>
            </div>
            <?php else: ?>

            <div class="ap-mode-tab">
                <a href="?mode=email" class="<?= $signupMode === 'email' ? 'active' : '' ?>"><?= __('signup_via_email') ?></a>
                <a href="?mode=mobile" class="<?= $signupMode === 'mobile' ? 'active' : '' ?>"><?= __('signup_via_mobile') ?></a>
            </div>

            <form method="POST" class="ap-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="signup_mode" value="<?= e($signupMode) ?>">

                <?php if ($signupMode === 'email'): ?>
                <div class="ap-field">
                    <label class="ap-label"><?= __('email_id') ?> *</label>
                    <input type="email" name="email" required class="ap-input" placeholder="you@business.com" value="<?= e($_POST['email'] ?? $inviteData['email'] ?? '') ?>">
                </div>
                <?php else: ?>
                <div class="grid grid-cols-3 gap-3">
                    <div class="ap-field">
                        <label class="ap-label"><?= __('country_code') ?></label>
                        <select name="phone_code" class="ap-input">
                            <option value="+91" <?= ($_POST['phone_code'] ?? '+91') === '+91' ? 'selected' : '' ?>>+91</option>
                            <option value="+977">+977</option>
                            <option value="+880">+880</option>
                        </select>
                    </div>
                    <div class="ap-field col-span-2">
                        <label class="ap-label"><?= __('mobile_number') ?> *</label>
                        <input type="tel" name="phone" required class="ap-input" placeholder="9876543210" value="<?= e($_POST['phone'] ?? $inviteData['phone'] ?? '') ?>">
                    </div>
                </div>
                <?php endif; ?>

                <div class="ap-field">
                    <label class="ap-label"><?= __('password') ?> *</label>
                    <input type="password" name="password" required minlength="10" class="ap-input" placeholder="<?= e(__('password_min_placeholder')) ?>">
                    <p class="ap-hint text-xs text-gray-500 mt-1">Min 10 characters with upper, lower, number &amp; special character.</p>
                </div>
                <div class="ap-field">
                    <label class="ap-label"><?= __('confirm_password') ?> *</label>
                    <input type="password" name="confirm_password" required class="ap-input" placeholder="<?= e(__('password_confirm_placeholder')) ?>">
                </div>

                <p class="ap-hint"><?= __('signup_portal_note') ?></p>

                <button type="submit" class="ap-btn"><?= __('create_account_btn') ?></button>

                <p class="ap-hint text-center leading-relaxed">
                    <?= __('signup_terms') ?>
                    <a href="terms.php" target="_blank"><?= __('terms') ?></a>
                    and
                    <a href="privacy.php" target="_blank"><?= __('privacy_policy') ?></a>,
                    <a href="refund_policy.php" target="_blank">Refund Policy</a>.
                </p>
            </form>
            <?php endif; ?>

            <p class="ap-foot"><?= __('already_have_account') ?> <a href="login.php" class="ap-link"><?= __('login') ?></a></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
