<?php
require_once __DIR__ . '/config.php';
ensurePasswordResetsTable();
$pageTitle = 'Reset Password';
$hideNav = true;
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($password) < 8) $error = 'Password must be 8+ characters.';
    elseif ($password !== $confirm) $error = 'Passwords do not match.';
    else {
        $stmt = getDB()->prepare('SELECT * FROM password_resets WHERE token = ? AND user_type = ? AND expires_at > NOW()');
        $stmt->execute([$token, 'merchant']);
        $reset = $stmt->fetch();
        if (!$reset) $error = 'Invalid or expired reset link.';
        else {
            getDB()->prepare('UPDATE merchants SET password = ? WHERE email = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID), $reset['email']]);
            getDB()->prepare('DELETE FROM password_resets WHERE token = ?')->execute([$token]);
            $success = true;
        }
    }
} elseif ($token) {
    $stmt = getDB()->prepare('SELECT id FROM password_resets WHERE token = ? AND expires_at > NOW()');
    $stmt->execute([$token]);
    if (!$stmt->fetch()) $error = 'Invalid or expired reset link.';
}

require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8"><h1 class="text-2xl font-bold">Reset Password</h1></div>
        <div class="glass rounded-2xl p-8">
            <?php if ($success): ?>
            <div class="text-center">
                <p class="text-brand-400 mb-4">Password reset successfully!</p>
                <a href="login.php" class="btn-primary inline-block px-6 py-2">Login Now</a>
            </div>
            <?php elseif ($error && !$token): ?>
            <p class="text-red-400 text-center"><?= e($error) ?></p>
            <p class="text-center mt-4"><a href="forgot_password.php" class="text-brand-400">Request new link</a></p>
            <?php elseif ($token && !$error): ?>
            <?php if ($error): ?><p class="text-red-400 text-sm mb-4"><?= e($error) ?></p><?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div><label class="text-sm text-gray-400">New Password</label><input type="password" name="password" required minlength="8" class="input-field mt-1"></div>
                <div><label class="text-sm text-gray-400">Confirm Password</label><input type="password" name="confirm_password" required class="input-field mt-1"></div>
                <button type="submit" class="w-full btn-primary py-3">Reset Password</button>
            </form>
            <?php else: ?>
            <p class="text-red-400 text-center"><?= e($error ?: 'Invalid reset link.') ?></p>
            <p class="text-center mt-4"><a href="forgot_password.php" class="text-brand-400">Request new link</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
