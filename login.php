<?php
require_once __DIR__ . '/config.php';
if (isLoggedIn()) {
    $m = getMerchant();
    redirect($m && merchantProfileComplete($m) ? 'dashboard.php' : 'merchant_setup.php');
}

if (isset($_GET['cancel_otp'])) {
    unset($_SESSION['pending_merchant_id'], $_SESSION['pending_otp_identifier'], $_SESSION['login_otp_wa_url']);
    flash('info', 'OTP step cancelled. Sign in with email and password.');
    redirect('login.php');
}
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['pending_2fa_merchant_id']);
    flash('info', '2FA step cancelled. Sign in again.');
    redirect('login.php');
}

$error = '';
$otpStep = false;
$totpStep = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { $error = __('err_invalid_login'); }
    elseif (isset($_POST['totp_code'])) {
        ensureMerchant2FA();
        $pendingId = (int)($_SESSION['pending_2fa_merchant_id'] ?? 0);
        $stmt = getDB()->prepare('SELECT * FROM merchants WHERE id = ?');
        $stmt->execute([$pendingId]);
        $m = $stmt->fetch();
        if ($pendingId && $m && !empty($m['totp_enabled']) && !empty($m['totp_secret']) && totpVerify($m['totp_secret'], $_POST['totp_code'] ?? '')) {
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['pending_2fa_merchant_id'], $_SESSION['merchant_team_id'], $_SESSION['merchant_team_role']);
            $_SESSION['merchant_id'] = $m['id'];
            $_SESSION['merchant_code'] = $m['merchant_code'] ?? '';
            initializePortalSession();
            flash('success', __('welcome') . ', ' . ($m['name'] ?? 'Merchant') . '!');
            redirect(merchantProfileComplete($m) ? 'dashboard.php' : 'merchant_setup.php');
        } else {
            $error = 'Invalid authenticator code. Please try again.';
            $totpStep = true;
        }
    }
    elseif (isset($_POST['otp_code'])) {
        $pendingId = $_SESSION['pending_merchant_id'] ?? 0;
        $identifier = $_SESSION['pending_otp_identifier'] ?? '';
        if ($pendingId && verifyOTP($identifier, $_POST['otp_code'], 'login')) {
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['merchant_team_id'], $_SESSION['merchant_team_role']);
            $_SESSION['merchant_id'] = $pendingId;
            unset($_SESSION['pending_merchant_id'], $_SESSION['pending_otp_identifier']);
            $stmt = getDB()->prepare('SELECT * FROM merchants WHERE id = ?');
            $stmt->execute([$pendingId]);
            $m = $stmt->fetch();
            $_SESSION['merchant_code'] = $m['merchant_code'] ?? '';
            initializePortalSession();
            flash('success', __('welcome') . ', ' . ($m['name'] ?? 'Merchant') . '!');
            redirect(merchantProfileComplete($m ?: []) ? 'dashboard.php' : 'merchant_setup.php');
        } else { $error = __('err_invalid_otp'); $otpStep = true; }
    } elseif (checkVelocityBlock('login_fail')['blocked']) {
        $v = checkVelocityBlock('login_fail');
        $error = velocityBlockMessage('login_fail') . ' (retry in ~' . $v['retry_after_minutes'] . ' min)';
    } else {
        $identifier = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $m = null;
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = getDB()->prepare('SELECT * FROM merchants WHERE email = ? AND status = ?');
            $stmt->execute([$identifier, 'active']);
            $m = $stmt->fetch();
        } else {
            $digits = preg_replace('/\D/', '', $identifier);
            if (strlen($digits) === 10) {
                $digits = '91' . $digits;
            }
            $phone = '+' . ltrim($digits, '+');
            $stmt = getDB()->prepare('SELECT * FROM merchants WHERE phone = ? AND status = ?');
            $stmt->execute([$phone, 'active']);
            $m = $stmt->fetch();
            if (!$m && strlen($digits) >= 10) {
                $last10 = substr($digits, -10);
                $stmt = getDB()->prepare('SELECT * FROM merchants WHERE phone LIKE ? AND status = ?');
                $stmt->execute(['%' . $last10, 'active']);
                $m = $stmt->fetch();
            }
        }
        if ($m && password_verify($password, $m['password'])) {
            if (strcasecmp((string)$m['email'], 'demo@uniweb.co.in') === 0) {
                try {
                    ensureDemoMerchant();
                    $stmt = getDB()->prepare('SELECT * FROM merchants WHERE id = ?');
                    $stmt->execute([(int)$m['id']]);
                    $refreshed = $stmt->fetch();
                    if ($refreshed) {
                        $m = $refreshed;
                    }
                } catch (Throwable $e) {
                    logPlatformError('warning', 'Demo merchant refresh failed: ' . $e->getMessage());
                }
            }
            $isDemoLogin = strcasecmp((string)$m['email'], 'demo@uniweb.co.in') === 0;
            if ($isDemoLogin) {
                // Demo store always opens in Test Mode for Instant Pay / instant bank transfer demos
                $_SESSION['dashboard_view_mode'] = 'test';
            }
            ensureMerchant2FA();
            if (!$isDemoLogin && !empty($m['totp_enabled']) && !empty($m['totp_secret'])) {
                unset($_SESSION['admin_id'], $_SESSION['admin_name']);
                $_SESSION['pending_2fa_merchant_id'] = $m['id'];
                $totpStep = true;
            }
            // Demo store + broken WhatsApp Meta: password login (OTP must not block bank/PG demos)
            elseif (!$isDemoLogin && isOTPEnabled()) {
                $otp = generateOTP($m['email'], 'login');
                $otpDelivery = sendLoginOtpViaWhatsAppAndEmail($m, $otp);
                if (!empty($otpDelivery['whatsapp_sent']) || !empty($otpDelivery['email_sent'])) {
                    $_SESSION['pending_merchant_id'] = $m['id'];
                    $_SESSION['pending_otp_identifier'] = $m['email'];
                    if (!empty($otpDelivery['whatsapp_url'])) {
                        $_SESSION['login_otp_wa_url'] = $otpDelivery['whatsapp_url'];
                    }
                    $otpStep = true;
                } else {
                    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['pending_merchant_id'], $_SESSION['pending_otp_identifier']);
                    $_SESSION['merchant_id'] = $m['id'];
                    $_SESSION['merchant_code'] = $m['merchant_code'];
                    initializePortalSession();
                    flash('success', __('welcome') . ', ' . $m['name'] . '! (Password login — WhatsApp OTP not available yet)');
                    redirect(merchantProfileComplete($m) ? 'dashboard.php' : 'merchant_setup.php');
                }
            } else {
                unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['merchant_team_id'], $_SESSION['merchant_team_role']);
                $_SESSION['merchant_id'] = $m['id'];
                $_SESSION['merchant_code'] = $m['merchant_code'];
                initializePortalSession();
                flash('success', __('welcome') . ', ' . $m['name'] . '!');
                redirect(merchantProfileComplete($m) ? 'dashboard.php' : 'merchant_setup.php');
            }
        } else {
            $team = authenticateMerchantTeamLogin($identifier, $password);
            if ($team) {
                $mst = getDB()->prepare("SELECT * FROM merchants WHERE id=? AND status='active'");
                $mst->execute([(int)$team['merchant_id']]);
                $parent = $mst->fetch();
                if ($parent) {
                    unset($_SESSION['admin_id'], $_SESSION['admin_name']);
                    $_SESSION['merchant_id'] = (int)$parent['id'];
                    $_SESSION['merchant_code'] = $parent['merchant_code'] ?? '';
                    $_SESSION['merchant_team_id'] = (int)$team['id'];
                    $_SESSION['merchant_team_role'] = $team['role'];
                    initializePortalSession();
                    flash('success', 'Welcome, ' . ($team['name'] ?? 'Team member') . ' (' . merchantTeamRoleLabel((string)$team['role']) . ')');
                    redirect(merchantProfileComplete($parent) ? 'dashboard.php' : 'merchant_setup.php');
                }
            }
            $error = __('err_invalid_login');
            recordVelocityEvent('login_fail', $identifier);
        }
    }
}
if (isset($_SESSION['pending_merchant_id'])) $otpStep = true;
if (isset($_SESSION['pending_2fa_merchant_id'])) $totpStep = true;
$otpWaUrl = $_SESSION['login_otp_wa_url'] ?? null;
unset($_SESSION['login_otp_wa_url']);
$pageTitle = __('login_title');
$hideNav = true;
$footerVariant = 'auth';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="relative w-full max-w-md">

        <div class="text-center mb-8">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="text-2xl font-bold"><?= __('login_title') ?></h1>
            <p class="text-gray-500 text-sm mt-2"><?= __('login_sub') ?></p>
        </div>
        <div class="glass rounded-2xl p-8">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
            <?php if ($totpStep): ?>
            <p class="text-xs text-gray-500 text-center mb-4">🔐 Two-Factor Authentication is ON. Enter the 6-digit code from your authenticator app.</p>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div><label class="block text-sm text-gray-400 mb-1.5">Authenticator Code</label><input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" class="input-field text-center text-2xl tracking-widest" placeholder="000000" autofocus></div>
                <button type="submit" class="w-full btn-primary py-3">Verify &amp; Login</button>
                <p class="text-center text-xs mt-4"><a href="login.php?cancel_2fa=1" class="text-gray-500 hover:text-white">← Back to password login</a></p>
            </form>
            <?php elseif ($otpStep): ?>
            <?php if (!empty($otpWaUrl)): ?>
            <script>window.open(<?= json_encode($otpWaUrl) ?>, '_blank');</script>
            <p class="text-xs text-emerald-400 text-center mb-4">OTP sent via WhatsApp (and email if on file). WhatsApp opened — check your chat.</p>
            <?php else: ?>
            <p class="text-xs text-gray-500 text-center mb-4">OTP sent to your registered WhatsApp / email.</p>
            <?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-sm text-gray-400 text-center"><?= __('otp_enter') ?></p>
                <div><label class="block text-sm text-gray-400 mb-1.5"><?= __('otp_code') ?></label><input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field text-center text-2xl tracking-widest" placeholder="000000"></div>
                <button type="submit" class="w-full btn-primary py-3"><?= __('otp_verify') ?></button>
                <p class="text-center text-xs mt-4"><a href="login.php?cancel_otp=1" class="text-gray-500 hover:text-white">← Back to password login</a></p>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div><label class="block text-sm text-gray-400 mb-1.5"><?= __('email_or_mobile') ?></label><input type="text" name="email" required class="input-field" placeholder="<?= e(__('email_or_mobile_ph')) ?>" value="<?= e($_POST['email']??'') ?>"></div>
                <div><label class="block text-sm text-gray-400 mb-1.5"><?= __('password') ?></label><input type="password" name="password" required class="input-field" placeholder="••••••••"></div>
                <div class="flex justify-end"><a href="forgot_password.php" class="text-sm text-brand-400 hover:text-brand-300"><?= __('forgot_password') ?></a></div>
                <button type="submit" class="w-full btn-primary py-3"><?= __('login_btn') ?></button>
            </form>
            <?php endif; ?>
            <p class="text-center text-sm text-gray-500 mt-6"><?= __('no_account') ?> <a href="merchant_register.php" class="text-brand-400"><?= __('create_account') ?></a></p>
            <?php if (!isOTPEnabled()): ?>
            <p class="text-center text-[11px] text-gray-600 mt-3">Password login active · WhatsApp OTP when Meta template is approved</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
