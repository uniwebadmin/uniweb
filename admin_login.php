<?php
require_once __DIR__ . '/config.php';
ensureAdminAuthSecurity();
ensureAdminMfaColumns();
if (isAdminLoggedIn()) {
    redirect(isSuperAdmin() ? 'admin_dashboard.php' : 'staff_dashboard.php');
}

$error = '';
$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
$mfaPending = !empty($_SESSION['pending_admin_id']);
$mfaSetup = !empty($_SESSION['pending_admin_mfa_setup']);

if (isset($_GET['cancel']) && verifyCsrf($_GET['token'] ?? '')) {
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_portal'], $_SESSION['pending_admin_name'], $_SESSION['pending_admin_auth_version'], $_SESSION['pending_admin_mfa_setup'], $_SESSION['pending_admin_totp_secret']);
    redirect('admin_login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $db = getDB();
    $action = (string)($_POST['action'] ?? 'password');

    if ($action === 'password') {
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $db->prepare("DELETE FROM admin_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")->execute();
        $attempts = $db->prepare("SELECT COUNT(*) FROM admin_login_attempts
            WHERE succeeded=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND (username=? OR ip_address=?)");
        $attempts->execute([$username, $ipAddress]);
        $locked = (int)$attempts->fetchColumn() >= 5;
        if ($locked) {
            $error = 'Too many login attempts. Try again after 15 minutes or reset your password.';
        } else {
            $stmt = $db->prepare('SELECT * FROM admins WHERE LOWER(username) = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            $valid = $admin
                && (int)($admin['is_active'] ?? 1) === 1
                && password_verify($password, (string)$admin['password']);
            $db->prepare('INSERT INTO admin_login_attempts (username, ip_address, succeeded) VALUES (?,?,?)')
                ->execute([$username, $ipAddress, $valid ? 1 : 0]);
            if ($valid) {
                if (!in_array(adminRole($admin), ['super', 'ceo'], true)) {
                    $error = 'Staff accounts must use the Operations Portal login.';
                } else {
                    beginAdminPasswordChallenge($admin, 'admin');
                    if (!adminHasMfaEnabled($admin)) {
                        $_SESSION['pending_admin_mfa_setup'] = 1;
                        $_SESSION['pending_admin_totp_secret'] = totpGenerateSecret();
                        redirect('admin_login.php');
                    }
                    redirect('admin_login.php');
                }
            } else {
                usleep(350000);
                $error = 'Invalid credentials.';
            }
        }
    } elseif ($action === 'mfa_verify' && $mfaPending) {
        $st = $db->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
        $st->execute([(int)$_SESSION['pending_admin_id']]);
        $admin = $st->fetch();
        if (!$admin || !adminHasMfaEnabled($admin) || !totpVerify((string)$admin['totp_secret'], (string)($_POST['totp_code'] ?? ''))) {
            usleep(350000);
            $error = 'Invalid authenticator code.';
        } else {
            completeAdminLoginSession($admin);
            $db->prepare('UPDATE admins SET last_login_at=NOW(), last_login_ip=? WHERE id=?')
                ->execute([$ipAddress, (int)$admin['id']]);
            $db->prepare('DELETE FROM admin_login_attempts WHERE username=? OR ip_address=?')
                ->execute([strtolower((string)$admin['username']), $ipAddress]);
            flash('success', 'Welcome, ' . $admin['name']);
            if (isSuperAdmin()) {
                $_SESSION['force_auto_audit'] = true;
            }
            redirect('admin_dashboard.php');
        }
    } elseif ($action === 'mfa_setup' && $mfaPending && $mfaSetup) {
        $secret = (string)($_SESSION['pending_admin_totp_secret'] ?? '');
        $st = $db->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
        $st->execute([(int)$_SESSION['pending_admin_id']]);
        $admin = $st->fetch();
        if (!$admin || $secret === '' || !totpVerify($secret, (string)($_POST['totp_code'] ?? ''))) {
            $error = 'Authenticator setup failed. Check the 6-digit code and retry.';
        } else {
            $db->prepare('UPDATE admins SET totp_secret=?, totp_enabled=1, mfa_enforced_at=NOW() WHERE id=?')
                ->execute([$secret, (int)$admin['id']]);
            $admin['totp_secret'] = $secret;
            $admin['totp_enabled'] = 1;
            completeAdminLoginSession($admin);
            recordImmutableAudit('admin_mfa_enrolled', null, 'admin', (string)$admin['id'], 'Mandatory MFA enrolled');
            flash('success', 'MFA enabled. Welcome, ' . $admin['name']);
            redirect('admin_dashboard.php');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security token expired. Refresh and try again.';
}

$mfaPending = !empty($_SESSION['pending_admin_id']);
$mfaSetup = !empty($_SESSION['pending_admin_mfa_setup']);
$setupSecret = (string)($_SESSION['pending_admin_totp_secret'] ?? '');
$setupUrl = ($mfaSetup && $setupSecret !== '')
    ? totpAuthUrl($setupSecret, (string)($_SESSION['pending_admin_name'] ?? 'admin'), 'UniWeb Admin')
    : '';

$pageTitle = 'Admin Login';
$hideNav = true;
$hideFooter = true;
$authPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell auth-portal--admin');
require_once __DIR__ . '/header.php';
?>
<div class="ap-wrap">
    <aside class="ap-visual" aria-hidden="true">
        <div>
            <div class="w-14 h-14 rounded-2xl bg-white/15 border border-white/25 flex items-center justify-center font-bold text-xl">UW</div>
            <h2 class="ap-display text-3xl font-bold mt-10 leading-tight">Master control.<br>Mandatory MFA.</h2>
            <p class="mt-4 text-white/80 text-sm max-w-sm leading-relaxed">Super Admin / CEO portal for merchants, gateways, settlements, and compliance.</p>
        </div>
        <div class="relative z-10 space-y-3 text-sm text-white/85">
            <p>✓ First login enrolls authenticator — no lockout</p>
            <p>✓ Staff must use Operations Portal</p>
            <p>✓ Session timeout &amp; activity audit</p>
        </div>
    </aside>
    <div class="ap-panel">
        <div class="ap-card">
            <p class="ap-eyebrow">Master admin</p>
            <h1 class="ap-display"><?= $mfaSetup ? 'Enroll authenticator' : ($mfaPending ? 'Authenticator challenge' : 'Admin sign in') ?></h1>
            <p class="ap-sub">Policy: MFA is mandatory for admin &amp; staff. First login shows a setup prompt — you are not locked out.</p>
            <?php if ($error): ?><div class="ap-alert ap-alert-error mt-5"><?= e($error) ?></div><?php endif; ?>
            <?php if ($mfaSetup && $setupSecret !== ''): ?>
            <p class="ap-sub mt-4 mb-3">Scan this secret in Google Authenticator / Authy, then enter the 6-digit code.</p>
            <p class="ap-mono mb-3"><?= e($setupSecret) ?></p>
            <p class="text-[11px] text-slate-500 mb-4 break-all"><?= e($setupUrl) ?></p>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_setup">
                <div class="ap-field"><label>Authenticator Code</label><input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" class="ap-input ap-otp" autofocus></div>
                <button type="submit" class="ap-btn">Enable MFA &amp; Continue</button>
            </form>
            <?php elseif ($mfaPending): ?>
            <form method="POST" class="space-y-5 mt-6">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_verify">
                <div class="ap-field"><label>Authenticator Code</label><input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" class="ap-input ap-otp" autofocus></div>
                <button type="submit" class="ap-btn">Verify &amp; Login</button>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5 mt-6">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="password">
                <div class="ap-field"><label>Admin ID</label><input type="text" name="username" required autocomplete="username" class="ap-input" placeholder="admin"></div>
                <div class="ap-field"><label>Master Password</label><input type="password" name="password" required autocomplete="current-password" class="ap-input"></div>
                <div class="flex justify-end"><a href="admin_forgot_password.php" class="text-xs ap-link">Forgot password?</a></div>
                <button type="submit" class="ap-btn">Continue</button>
            </form>
            <?php endif; ?>
            <p class="ap-foot">
                <a href="staff_login.php" class="ap-link">Employee / Operations login →</a> ·
                <a href="index.php" class="text-slate-500 hover:text-slate-800">Website</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
