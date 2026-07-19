<?php
require_once __DIR__ . '/config.php';
ensureAdminAuthSecurity();
if (isAdminLoggedIn()) {
    if (isSuperAdmin()) {
        redirect('admin_dashboard.php');
    }
    redirect('staff_dashboard.php');
}
$error = '';
$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = $_POST['password'] ?? '';
    $db = getDB();
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
    }

    if (!$locked && !empty($valid)) {
        if (!in_array(adminRole($admin), ['super', 'ceo'], true)) {
            $error = 'Staff accounts must use the Operations Portal login.';
        } else {
            unset($_SESSION['merchant_id'], $_SESSION['merchant_code'], $_SESSION['pending_merchant_id'], $_SESSION['pending_otp_identifier'], $_SESSION['login_otp_wa_url']);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_auth_version'] = (int)($admin['auth_version'] ?? 1);
            $_SESSION['admin_authenticated_at'] = time();
            initializePortalSession();
            $db->prepare('UPDATE admins SET last_login_at=NOW(), last_login_ip=? WHERE id=?')
                ->execute([$ipAddress, (int)$admin['id']]);
            $db->prepare('DELETE FROM admin_login_attempts WHERE username=? OR ip_address=?')
                ->execute([$username, $ipAddress]);
            flash('success', 'Welcome, ' . $admin['name']);
            if (function_exists('isSuperAdmin') && isSuperAdmin()) {
                $_SESSION['force_auto_audit'] = true;
            }
            redirect('admin_dashboard.php');
        }
    } elseif (!$locked) {
        usleep(350000);
        $error = 'Invalid credentials.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security token expired. Refresh and try again.';
}
$pageTitle = 'Admin Login';
$hideNav = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-orange-500 rounded-2xl flex items-center justify-center font-bold text-dark-900 text-xl mx-auto mb-4">UW</div>
            <h1 class="text-2xl font-bold"><?= APP_NAME ?></h1>
            <p class="text-gray-500 text-sm mt-1">Master Admin Portal</p>
        </div>
        <div class="glass rounded-2xl p-8 border border-red-500/10">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div><label class="text-sm text-gray-400">Admin ID</label><input type="text" name="username" required autocomplete="username" class="input-field mt-1" placeholder="admin"></div>
                <div><label class="text-sm text-gray-400">Master Password</label><input type="password" name="password" required autocomplete="current-password" class="input-field mt-1"></div>
                <div class="flex justify-end"><a href="admin_forgot_password.php" class="text-xs text-red-300 hover:text-red-200">Forgot password?</a></div>
                <button type="submit" class="w-full bg-red-600 hover:bg-red-500 text-white py-3 rounded-xl font-semibold transition">Login to Console</button>
            </form>
            <p class="text-center text-xs text-gray-600 mt-6">
                <a href="staff_login.php" class="hover:text-gray-400">Employee / Operations login →</a> ·
                <a href="index.php" class="hover:text-gray-400">Website</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
