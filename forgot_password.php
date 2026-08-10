<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
if (!function_exists('ensurePasswordResetsTable')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
ensurePasswordResetsTable();
$pageTitle = 'Forgot Password';
$hideNav = true;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $email = trim($_POST['email'] ?? '');
    $stmt = getDB()->prepare('SELECT id, name FROM merchants WHERE email = ? AND status = ?');
    $stmt->execute([$email, 'active']);
    $merchant = $stmt->fetch();
    if ($merchant) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        getDB()->prepare('DELETE FROM password_resets WHERE email = ? AND user_type = ?')->execute([$email, 'merchant']);
        getDB()->prepare('INSERT INTO password_resets (email, token, user_type, expires_at) VALUES (?,?,?,?)')
            ->execute([$email, $token, 'merchant', $expires]);
        $link = APP_URL . '/reset_password.php?token=' . $token;
        $subject = APP_NAME . ' — Password Reset';
        $body = "Hi {$merchant['name']},\n\nReset your password: $link\n\nLink expires in 1 hour.\n\n— " . APP_NAME;
        sendPlatformEmail($email, $subject, $body);
    }
    $sent = true;
}
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12" role="main">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="text-2xl font-bold">Forgot Password</h1>
        </div>
        <div class="glass rounded-2xl p-8">
            <?php if ($sent): ?>
            <div class="text-center" role="status">
                <p class="text-gray-300 mb-4">If an account exists with that email, a password reset link has been sent. Check your inbox and spam folder.</p>
                <a href="login.php" class="text-brand-400">← Back to Login</a>
            </div>
            <?php else: ?>
            <form method="POST" class="space-y-5" aria-label="Request password reset">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <p class="text-gray-400 text-sm">Enter your registered merchant email address.</p>
                <div><?= uxLabel('forgot-email', 'Email', true) ?><input id="forgot-email" type="email" name="email" required class="input-field mt-1" autocomplete="email"></div>
                <button type="submit" class="w-full btn-primary py-3">Send Reset Link</button>
            </form>
            <p class="text-center text-sm text-gray-500 mt-6"><a href="login.php" class="text-brand-400">← Back to Login</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
