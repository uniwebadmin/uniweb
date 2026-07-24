<?php
require_once __DIR__ . '/config.php';
ensureAdminAuthSecurity();
ensureAdminMfaColumns();
if (isAdminLoggedIn()) {
    redirect(isSuperAdmin() ? 'admin_dashboard.php' : 'staff_dashboard.php');
}

$error = '';
$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
$mfaPending = !empty($_SESSION['pending_admin_id']) && (($_SESSION['pending_admin_portal'] ?? '') === 'staff');
$mfaSetup = $mfaPending && !empty($_SESSION['pending_admin_mfa_setup']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    ensureStaffRoles();
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
            $error = 'Too many login attempts. Try again after 15 minutes.';
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
                $role = adminRole($admin);
                if (in_array($role, ['super', 'ceo'], true)) {
                    $error = 'Admin accounts must use the Master Admin Portal login.';
                } else {
                    beginAdminPasswordChallenge($admin, 'staff');
                    if (!adminHasMfaEnabled($admin)) {
                        $_SESSION['pending_admin_mfa_setup'] = 1;
                        $_SESSION['pending_admin_totp_secret'] = totpGenerateSecret();
                    }
                    redirect('staff_login.php');
                }
            } else {
                usleep(350000);
                $error = 'Invalid credentials or inactive account.';
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
            flash('success', 'Welcome, ' . $admin['name']);
            redirect('staff_dashboard.php');
        }
    } elseif ($action === 'mfa_setup' && $mfaPending && $mfaSetup) {
        $secret = (string)($_SESSION['pending_admin_totp_secret'] ?? '');
        $st = $db->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
        $st->execute([(int)$_SESSION['pending_admin_id']]);
        $admin = $st->fetch();
        if (!$admin || $secret === '' || !totpVerify($secret, (string)($_POST['totp_code'] ?? ''))) {
            $error = 'Authenticator setup failed. Retry with a fresh code.';
        } else {
            $db->prepare('UPDATE admins SET totp_secret=?, totp_enabled=1, mfa_enforced_at=NOW() WHERE id=?')
                ->execute([$secret, (int)$admin['id']]);
            $admin['totp_secret'] = $secret;
            $admin['totp_enabled'] = 1;
            completeAdminLoginSession($admin);
            flash('success', 'MFA enabled. Welcome, ' . $admin['name']);
            redirect('staff_dashboard.php');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security token expired. Refresh and try again.';
}

$mfaPending = !empty($_SESSION['pending_admin_id']) && (($_SESSION['pending_admin_portal'] ?? '') === 'staff');
$mfaSetup = $mfaPending && !empty($_SESSION['pending_admin_mfa_setup']);
$setupSecret = (string)($_SESSION['pending_admin_totp_secret'] ?? '');

$pageTitle = 'Staff Login';
$hideNav = true;
$hideFooter = true;
$authPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell auth-portal--staff');
require_once __DIR__ . '/header.php';
?>
<div class="ap-wrap">
    <aside class="ap-visual" aria-hidden="true">
        <div>
            <div class="w-14 h-14 rounded-2xl bg-white/15 border border-white/25 flex items-center justify-center font-bold text-xl">UW</div>
            <h2 class="ap-display text-3xl font-bold mt-10 leading-tight">Operations.<br>KYC. Support. Settle.</h2>
            <p class="mt-4 text-white/80 text-sm max-w-sm leading-relaxed">Employee portal for refunds, disputes, customer complaints, and merchant ops.</p>
        </div>
        <div class="relative z-10 space-y-3 text-sm text-white/85">
            <p>✓ Staff MFA is mandatory</p>
            <p>✓ Role-gated tools via requireStaffAccess()</p>
            <p>✓ Super Admin uses Master Admin login</p>
        </div>
    </aside>
    <div class="ap-panel">
        <div class="ap-card">
            <p class="ap-eyebrow">Operations portal</p>
            <h1 class="ap-display"><?= $mfaSetup ? 'Mandatory MFA enrollment' : ($mfaPending ? 'Authenticator challenge' : 'Staff sign in') ?></h1>
            <p class="ap-sub">Policy: Staff MFA is mandatory. First login enrolls authenticator — no lockout without a setup prompt.</p>
            <?php if ($error): ?><div class="ap-alert ap-alert-error mt-5"><?= e($error) ?></div><?php endif; ?>
            <?php if ($mfaSetup && $setupSecret !== ''): ?>
            <p class="ap-sub mt-4 mb-3">Scan / enter this secret in your authenticator app:</p>
            <p class="ap-mono mb-4"><?= e($setupSecret) ?></p>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_setup">
                <div class="ap-field"><?= uxFormLabel(uxFieldId('totp_setup'), 'Authenticator Code') ?><input type="text" name="totp_code" id="<?= e(uxFieldId('totp_setup')) ?>" required maxlength="6" pattern="[0-9]{6}" class="ap-input ap-otp" autofocus inputmode="numeric" autocomplete="one-time-code"></div>
                <button type="submit" class="ap-btn">Enable MFA &amp; Continue</button>
            </form>
            <?php elseif ($mfaPending): ?>
            <form method="POST" class="space-y-5 mt-6">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_verify">
                <div class="ap-field"><?= uxFormLabel(uxFieldId('totp_verify'), 'Authenticator Code') ?><input type="text" name="totp_code" id="<?= e(uxFieldId('totp_verify')) ?>" required maxlength="6" pattern="[0-9]{6}" class="ap-input ap-otp" autofocus inputmode="numeric" autocomplete="one-time-code"></div>
                <button type="submit" class="ap-btn">Verify &amp; Login</button>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5 mt-6" aria-label="Staff sign in">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="password">
                <div class="ap-field"><?= uxFormLabel(uxFieldId('username'), 'Staff ID') ?><input type="text" name="username" id="<?= e(uxFieldId('username')) ?>" required class="ap-input" placeholder="ops01" autocomplete="username"></div>
                <div class="ap-field"><?= uxFormLabel(uxFieldId('password'), 'Password') ?><input type="password" name="password" id="<?= e(uxFieldId('password')) ?>" required class="ap-input" autocomplete="current-password"></div>
                <button type="submit" class="ap-btn">Continue</button>
            </form>
            <?php endif; ?>
            <p class="ap-foot">
                <a href="admin_login.php" class="ap-link">Super Admin login →</a> ·
                <a href="index.php" class="text-slate-500 hover:text-slate-800">Website</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
