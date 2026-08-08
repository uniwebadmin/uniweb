<?php
require_once __DIR__ . '/config.php';
if (!function_exists('uxFormLabel')) {
    require_once __DIR__ . '/includes/page_ux.php';
}
requireLogin();
$merchant = getMerchant();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!password_verify($current, (string)($merchant['password'] ?? ''))) { flash('error', 'Current password is incorrect.'); }
    elseif (function_exists('validateStrongPassword') && ($policyError = validateStrongPassword($new, 10))) { flash('error', $policyError); }
    elseif ($new !== $confirm) { flash('error', 'Passwords do not match.'); }
    else {
        getDB()->prepare('UPDATE merchants SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_ARGON2ID), $merchant['id']]);
        flash('success', 'Password changed successfully.');
    }
    redirect('security.php');
}
$pageTitle = 'Security';
require_once __DIR__ . '/header.php';
?>
<?= renderPagePrintStyles() ?>
<div class="max-w-lg space-y-6">
    <div class="flex justify-end no-print"><?= renderPrintButton() ?></div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Change Password</h2>
        <form method="POST" class="space-y-4" aria-label="Change password form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <?= uxFormLabel(uxFieldId('current_password'), 'Current Password', true) ?>
                <input type="password" name="current_password" id="<?= e(uxFieldId('current_password')) ?>" required class="input-field mt-1" autocomplete="current-password">
            </div>
            <div>
                <?= uxFormLabel(uxFieldId('new_password'), 'New Password', true) ?>
                <input type="password" name="new_password" id="<?= e(uxFieldId('new_password')) ?>" required minlength="10" class="input-field mt-1" autocomplete="new-password" aria-describedby="pwd-policy">
                <p id="pwd-policy" class="text-xs text-gray-500 mt-1">Min 10 characters with upper, lower, number &amp; special character.</p>
            </div>
            <div>
                <?= uxFormLabel(uxFieldId('confirm_password'), 'Confirm New Password', true) ?>
                <input type="password" name="confirm_password" id="<?= e(uxFieldId('confirm_password')) ?>" required class="input-field mt-1" autocomplete="new-password">
            </div>
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
