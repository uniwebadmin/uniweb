<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!password_verify($current, (string)($merchant['password'] ?? ''))) { flash('error', 'Current password is incorrect.'); }
    elseif (strlen($new) < 8) { flash('error', 'New password must be 8+ characters.'); }
    elseif ($new !== $confirm) { flash('error', 'Passwords do not match.'); }
    else {
        getDB()->prepare('UPDATE merchants SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $merchant['id']]);
        flash('success', 'Password changed successfully.');
    }
    redirect('security.php');
}
$pageTitle = 'Security';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-lg space-y-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Change Password</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Current Password</label><input type="password" name="current_password" required class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">New Password</label><input type="password" name="new_password" required minlength="8" class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">Confirm New Password</label><input type="password" name="confirm_password" required class="input-field mt-1"></div>
            <button type="submit" class="btn-primary px-6 py-2.5">Update Password</button>
        </form>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Security Tips</h2>
        <ul class="text-sm text-gray-400 space-y-2">
            <li>✓ Use a strong, unique password</li>
            <li>✓ Never share your API keys</li>
            <li>✓ Log out from shared devices</li>
            <li>✓ Report suspicious activity to support</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
