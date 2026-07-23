<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
ensurePasswordResetsTable();
ensureAdminAuthSecurity();

$pageTitle = 'Reset Admin Password';
$hideNav = true;
$plainToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $plainToken !== '' ? hash('sha256', $plainToken) : '';
$error = '';
$success = false;
$reset = null;

if ($tokenHash !== '') {
    $stmt = getDB()->prepare("SELECT * FROM password_resets
        WHERE token=? AND user_type='admin' AND expires_at>NOW() LIMIT 1");
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } elseif (!$reset) {
        $error = 'Invalid or expired reset link.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 12
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $error = 'Use 12+ characters with uppercase, lowercase, number and symbol.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $db = getDB();
            $adminStmt = $db->prepare("SELECT id, username, role FROM admins
                WHERE email=? AND is_active=1 LIMIT 1");
            $adminStmt->execute([$reset['email']]);
            $admin = $adminStmt->fetch();
            if (!$admin || !in_array(adminRole($admin), ['super', 'ceo'], true)) {
                $error = 'Admin account is unavailable. Contact the company owner.';
            } else {
                $db->beginTransaction();
                try {
                    $db->prepare('UPDATE admins SET password=?, auth_version=auth_version+1 WHERE id=?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$admin['id']]);
                    $db->prepare("DELETE FROM password_resets WHERE email=? AND user_type='admin'")
                        ->execute([$reset['email']]);
                    $db->prepare('DELETE FROM admin_login_attempts WHERE username=?')
                        ->execute([strtolower((string)$admin['username'])]);
                    $db->commit();
                    $success = true;
                    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_auth_version'], $_SESSION['admin_authenticated_at']);
                    sendPlatformEmail(
                        (string)$reset['email'],
                        APP_NAME . ' — Admin password changed',
                        "Your UniWeb Master Admin password was changed successfully.\n\n"
                        . "All existing admin sessions have been signed out. If this was not you, contact support immediately.\n\n— " . APP_NAME
                    );
                } catch (Throwable $e) {
                    $db->rollBack();
                    $error = 'Could not reset password. Request a new link.';
                }
            }
        }
    }
} elseif (!$reset) {
    $error = 'Invalid or expired reset link.';
}

require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12" role="main">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-orange-500 rounded-2xl flex items-center justify-center font-bold text-dark-900 text-xl mx-auto mb-4" aria-hidden="true">UW</div>
            <h1 class="text-2xl font-bold">Reset Admin Password</h1>
        </div>
        <div class="glass rounded-2xl p-8 border border-red-500/10">
            <?php if ($success): ?>
            <div class="text-center" role="status">
                <p class="text-emerald-400 font-semibold mb-2">Password changed securely.</p>
                <p class="text-xs text-gray-500 mb-5">All old admin sessions were signed out.</p>
                <a href="admin_login.php" class="inline-block bg-red-600 hover:bg-red-500 text-white px-6 py-2.5 rounded-xl">Admin Login</a>
            </div>
            <?php elseif ($reset): ?>
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-5" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" class="space-y-5" aria-label="Reset admin password">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="token" value="<?= e($plainToken) ?>">
                <p id="pwd-policy" class="text-xs text-gray-500">Use 12+ characters containing uppercase, lowercase, number and symbol.</p>
                <div><?= uxLabel('admin-new-password', 'New Password', true) ?><input id="admin-new-password" type="password" name="password" required minlength="12" autocomplete="new-password" class="input-field mt-1" aria-describedby="pwd-policy"></div>
                <div><?= uxLabel('admin-confirm-password', 'Confirm Password', true) ?><input id="admin-confirm-password" type="password" name="confirm_password" required minlength="12" autocomplete="new-password" class="input-field mt-1"></div>
                <button type="submit" class="w-full bg-red-600 hover:bg-red-500 text-white py-3 rounded-xl font-semibold">Reset & Sign Out All Sessions</button>
            </form>
            <?php else: ?>
            <div class="text-center" role="alert">
                <p class="text-red-400 mb-5"><?= e($error) ?></p>
                <a href="admin_forgot_password.php" class="text-red-300 hover:text-red-200">Request a new reset link</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
