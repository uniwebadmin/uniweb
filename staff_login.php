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
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-gradient-to-br from-sky-500 to-cyan-500 rounded-2xl flex items-center justify-center font-bold text-dark-900 text-xl mx-auto mb-4">UW</div>
            <h1 class="text-2xl font-bold">Operations Portal</h1>
            <p class="text-gray-500 text-sm mt-1"><?= $mfaSetup ? 'Mandatory MFA enrollment' : ($mfaPending ? 'Authenticator challenge' : 'Employees — KYC, refunds, support, settlements') ?></p>
            <p class="text-[11px] text-gray-600 mt-2">Policy: Staff MFA is mandatory. First login enrolls authenticator — no lockout without a setup prompt.</p>
        </div>
        <div class="glass rounded-2xl p-8 border border-sky-500/20">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
            <?php if ($mfaSetup && $setupSecret !== ''): ?>
            <p class="text-sm text-gray-400 mb-3">Scan / enter this secret in your authenticator app:</p>
            <p class="font-mono text-xs break-all bg-dark-900 border border-gray-800 rounded-lg p-3 mb-4"><?= e($setupSecret) ?></p>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_setup">
                <div><label class="text-sm text-gray-400">Authenticator Code</label><input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field mt-1 text-center text-2xl tracking-widest" autofocus></div>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-3 rounded-xl font-semibold">Enable MFA &amp; Continue</button>
            </form>
            <?php elseif ($mfaPending): ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mfa_verify">
                <div><label class="text-sm text-gray-400">Authenticator Code</label><input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field mt-1 text-center text-2xl tracking-widest" autofocus></div>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-3 rounded-xl font-semibold">Verify &amp; Login</button>
            </form>
            <?php else: ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="password">
                <div><label class="text-sm text-gray-400">Staff ID</label><input type="text" name="username" required class="input-field mt-1" placeholder="ops01"></div>
                <div><label class="text-sm text-gray-400">Password</label><input type="password" name="password" required class="input-field mt-1"></div>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-3 rounded-xl font-semibold transition">Continue</button>
            </form>
            <?php endif; ?>
            <p class="text-center text-xs text-gray-600 mt-6">
                <a href="admin_login.php" class="hover:text-gray-400">Super Admin login →</a> ·
                <a href="index.php" class="hover:text-gray-400">Website</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
