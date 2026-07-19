<?php
require_once __DIR__ . '/config.php';
ensureAdminAuthSecurity();
if (isAdminLoggedIn()) {
    redirect(isSuperAdmin() ? 'admin_dashboard.php' : 'staff_dashboard.php');
}
$error = '';
$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    ensureStaffRoles();
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
    }
    if (!$locked && !empty($valid)) {
        $role = adminRole($admin);
        if (in_array($role, ['super', 'ceo'], true)) {
            $error = 'Admin accounts must use the Master Admin Portal login.';
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
            redirect('staff_dashboard.php');
        }
    } elseif (!$locked) {
        usleep(350000);
        $error = 'Invalid credentials or inactive account.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security token expired. Refresh and try again.';
}
$pageTitle = 'Staff Login';
$hideNav = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-gradient-to-br from-sky-500 to-cyan-500 rounded-2xl flex items-center justify-center font-bold text-dark-900 text-xl mx-auto mb-4">UW</div>
            <h1 class="text-2xl font-bold">Operations Portal</h1>
            <p class="text-gray-500 text-sm mt-1">For UniWeb employees — KYC, refunds, support, settlements</p>
        </div>
        <div class="glass rounded-2xl p-8 border border-sky-500/20">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div><label class="text-sm text-gray-400">Staff ID</label><input type="text" name="username" required class="input-field mt-1" placeholder="ops01"></div>
                <div><label class="text-sm text-gray-400">Password</label><input type="password" name="password" required class="input-field mt-1"></div>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-3 rounded-xl font-semibold transition">Login to Operations</button>
            </form>
            <p class="text-center text-xs text-gray-600 mt-6">
                <a href="admin_login.php" class="hover:text-gray-400">Super Admin login →</a> ·
                <a href="index.php" class="hover:text-gray-400">Website</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
