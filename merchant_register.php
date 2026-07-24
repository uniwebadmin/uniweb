<?php
require_once __DIR__ . '/config.php';
if (isLoggedIn()) redirect('dashboard.php');

$errors = [];
$signupMode = ($_POST['signup_mode'] ?? $_GET['mode'] ?? 'email') === 'mobile' ? 'mobile' : 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $signupMode = ($_POST['signup_mode'] ?? 'email') === 'mobile' ? 'mobile' : 'email';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $email = '';
    $phone = '';
    $name = 'Merchant';

    if (function_exists('checkVelocityBlock')) {
        $v = checkVelocityBlock('merchant_signup');
        if (!empty($v['blocked'])) {
            $errors[] = function_exists('velocityBlockMessage')
                ? velocityBlockMessage('merchant_signup')
                : 'Too many signup attempts. Please try again later.';
        }
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
            $code = 'UW' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $business = 'My Business';
            $db->prepare('INSERT INTO merchants (merchant_code,name,email,phone,password,business_name,business_type,business_entity_type,pan_number,address,country,state,district,city,pincode,api_key,api_secret,upi_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $code, $name, $email, $phone, password_hash($password, PASSWORD_BCRYPT),
                    $business, 'retail', 'individual', null, '',
                    'India', '', '', '', '',
                    'uk_' . bin2hex(random_bytes(16)), 'us_' . bin2hex(random_bytes(24)),
                    'merchant' . strtolower(substr($code, 2)) . '@uniweb',
                ]);
            $id = (int)$db->lastInsertId();
            if ($signupMode === 'email') {
                // Synthetic unique phone so email-only signups do not collide on phone UNIQUE.
                // Pattern +9199 + zero-padded id (e.g. id 7 → +919900000007) — NOT a real mobile.
                $uniquePhone = '+9199' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
                $db->prepare('UPDATE merchants SET phone=? WHERE id=?')->execute([$uniquePhone, $id]);
            }
            if (function_exists('recordVelocityEvent')) {
                recordVelocityEvent('merchant_signup', 'merchant:' . $id);
            }
            try {
                $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=?, account_mode=?, provision_profile=? WHERE id=?')
                    ->execute(['test_' . bin2hex(random_bytes(16)), 'testsec_' . bin2hex(random_bytes(24)), 'test', 'minimal', $id]);
            } catch (Throwable $e) {
                try {
                    $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=?, account_mode=? WHERE id=?')
                        ->execute(['test_' . bin2hex(random_bytes(16)), 'testsec_' . bin2hex(random_bytes(24)), 'test', $id]);
                } catch (Throwable $e2) { /* ok */ }
            }

            createNotification($id, __('notif_welcome_title'), __('notif_welcome_body'));

            $_SESSION['merchant_id'] = $id;
            $_SESSION['merchant_code'] = $code;

            flash('success', __('flash_account_created'));
            redirect('merchant_setup.php');
        }
    }
}

$pageTitle = __('signup_title');
$hideNav = true;
$footerVariant = 'auth';
require_once __DIR__ . '/header.php';
?>

<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="relative w-full max-w-md">

        <div class="text-center mb-6">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="text-2xl font-bold"><?= __('signup_title') ?></h1>
            <p class="text-gray-500 text-sm mt-2"><?= __('signup_sub') ?></p>
        </div>

        <div class="glass rounded-2xl p-8">
            <?php if ($errors): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6">
                <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>

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

                <button type="submit" class="w-full btn-primary py-3"><?= __('create_account_btn') ?></button>

                <p class="text-xs text-gray-600 text-center leading-relaxed">
                    <?= __('signup_terms') ?>
                    <a href="terms.php" target="_blank" class="text-brand-400 hover:underline"><?= __('terms') ?></a>
                    and
                    <a href="privacy.php" target="_blank" class="text-brand-400 hover:underline"><?= __('privacy_policy') ?></a>,
                    <a href="refund_policy.php" target="_blank" class="text-brand-400 hover:underline">Refund Policy</a>.
                </p>
            </form>

            <p class="text-center text-sm text-gray-500 mt-6"><?= __('already_have_account') ?> <a href="login.php" class="text-brand-400"><?= __('login') ?></a></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
