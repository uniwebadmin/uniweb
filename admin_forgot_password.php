<?php
require_once __DIR__ . '/config.php';
ensurePasswordResetsTable();
ensureAdminAuthSecurity();

$pageTitle = 'Admin Password Recovery';
$hideNav = true;
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } else {
        $identifier = strtolower(trim((string)($_POST['identifier'] ?? '')));
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, name, email, role FROM admins
            WHERE is_active=1 AND (LOWER(username)=? OR LOWER(email)=?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $admin = $stmt->fetch();

        if ($admin && in_array(adminRole($admin), ['super', 'ceo'], true)) {
            $email = trim((string)($admin['email'] ?? ''));
            if ($email === '' && strtolower((string)$admin['username']) === 'admin') {
                $email = COMPANY_ADMIN_EMAIL;
                $db->prepare('UPDATE admins SET email=? WHERE id=? AND (email IS NULL OR email=?)')
                    ->execute([$email, (int)$admin['id'], '']);
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $plainToken);
                $expires = date('Y-m-d H:i:s', time() + 1800);
                $db->prepare('DELETE FROM password_resets WHERE email=? AND user_type=?')
                    ->execute([$email, 'admin']);
                $db->prepare('INSERT INTO password_resets (email, token, user_type, expires_at) VALUES (?,?,?,?)')
                    ->execute([$email, $tokenHash, 'admin', $expires]);
                $link = APP_URL . '/admin_reset_password.php?token=' . rawurlencode($plainToken);
                $body = "Hi {$admin['name']},\n\nA password reset was requested for the UniWeb Master Admin Portal.\n\n"
                    . "Reset password: {$link}\n\nThis single-use link expires in 30 minutes. "
                    . "If you did not request this, do not open the link.\n\n— " . APP_NAME;
                sendPlatformEmail($email, APP_NAME . ' — Admin Password Recovery', $body);
            }
        }
        // Always show the same response to prevent account discovery.
        $sent = true;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-10 sm:py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-orange-500 rounded-2xl flex items-center justify-center font-bold text-dark-900 text-xl mx-auto mb-4">UW</div>
            <h1 class="text-2xl font-bold">Admin Password Recovery</h1>
            <p class="text-gray-500 text-sm mt-2">Master Admin / CEO accounts only</p>
        </div>
        <div class="glass rounded-2xl p-5 sm:p-8 border border-red-500/10">
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-5"><?= e($error) ?></div><?php endif; ?>
            <?php if ($sent): ?>
            <div class="text-center">
                <p class="text-gray-300 mb-4">If an active master-admin account matches, a secure reset link was sent to its registered email.</p>
                <p class="text-xs text-gray-600 mb-5">The link is single-use and expires in 30 minutes. Response is the same whether or not an account exists (prevents account discovery).</p>
                <a href="admin_login.php" class="text-red-300 hover:text-red-200">← Back to Admin Login</a>
            </div>
            <?php else: ?>
            <form method="POST" class="space-y-5" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-sm text-gray-400">Enter your Admin ID or registered email.</p>
                <div>
                    <label class="text-sm text-gray-400">Admin ID / Email</label>
                    <input type="text" name="identifier" required autocomplete="username" inputmode="email" class="input-field mt-1 w-full">
                </div>
                <button type="submit" class="w-full bg-red-600 hover:bg-red-500 text-white py-3 rounded-xl font-semibold">Send Secure Reset Link</button>
            </form>
            <p class="text-center text-sm text-gray-500 mt-6"><a href="admin_login.php" class="text-red-300">← Back to Admin Login</a></p>
            <?php endif; ?>
        </div>
        <p class="text-[11px] text-center text-gray-600 mt-4">CSRF protected · Super/CEO only · No secrets in URL</p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
