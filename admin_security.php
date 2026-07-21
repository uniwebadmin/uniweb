<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc', 'staff_manager', 'regional_manager', 'area_sales_manager', 'team_leader', 'field_staff']);
ensureAdminMfaColumns();
$admin = getAdmin();
if (!$admin) {
    session_destroy();
    redirect(isSuperAdmin() ? 'admin_login.php' : 'staff_login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $db = getDB();

    if ($action === 'password') {
        requireStepUpAuth(900);
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $policyError = validateStrongPassword($new, 12);
        if (!password_verify($current, (string)$admin['password'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($policyError) {
            flash('error', $policyError);
        } elseif ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
        } else {
            $db->prepare('UPDATE admins SET password=?, auth_version=auth_version+1, password_changed_at=NOW() WHERE id=?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $admin['id']]);
            recordImmutableAudit('admin_password_changed', null, 'admin', (string)$admin['id'], 'Password rotated');
            clearPortalSession('Password changed. Login again with MFA.');
            redirect(in_array(adminRole($admin), ['super', 'ceo'], true) ? 'admin_login.php' : 'staff_login.php');
        }
    }

    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $db->prepare('UPDATE admins SET name = ? WHERE id = ?')->execute([$name, $admin['id']]);
            $_SESSION['admin_name'] = $name;
            flash('success', 'Profile updated.');
        }
    }
    redirect('admin_security.php');
}

$admin = getAdmin();
$pageTitle = 'Admin Security';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-lg space-y-6">
    <?php $mfaPol = mfaPolicy(in_array(adminRole($admin), ['super', 'ceo'], true) ? 'admin' : 'staff'); ?>
    <div class="glass rounded-xl p-4 border border-amber-500/25 text-sm">
        <p class="font-semibold text-amber-300">MFA policy — <?= e($mfaPol['label']) ?></p>
        <p class="text-xs text-gray-500 mt-1"><?= e($mfaPol['summary']) ?></p>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Admin Profile</h2>
        <div class="text-sm text-gray-400 mb-4">
            <p>Username: <span class="text-white font-mono"><?= e($admin['username']) ?></span></p>
            <p class="mt-1">Logged in as: <span class="text-white"><?= e($admin['name']) ?></span></p>
            <p class="mt-1">MFA: <span class="<?= adminHasMfaEnabled($admin) ? 'text-emerald-400' : 'text-amber-400' ?>"><?= adminHasMfaEnabled($admin) ? 'Enabled (mandatory)' : 'Required at next login — setup prompt on sign-in' ?></span></p>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="profile">
            <div>
                <label class="text-sm text-gray-400">Display Name</label>
                <input type="text" name="name" required class="input-field mt-1" value="<?= e($admin['name']) ?>">
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Update Profile</button>
        </form>
    </div>

    <div class="glass rounded-xl p-6 border border-red-500/20">
        <h2 class="font-semibold mb-4 text-red-400">Change Password</h2>
        <p class="text-xs text-gray-500 mb-4">Minimum 12 characters with upper, lower, number and special character. All sessions are revoked after change.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="password">
            <div>
                <label class="text-sm text-gray-400">Current Password</label>
                <input type="password" name="current_password" required class="input-field mt-1" autocomplete="current-password">
            </div>
            <div>
                <label class="text-sm text-gray-400">New Password</label>
                <input type="password" name="new_password" required minlength="12" class="input-field mt-1" autocomplete="new-password">
            </div>
            <div>
                <label class="text-sm text-gray-400">Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="12" class="input-field mt-1" autocomplete="new-password">
            </div>
            <button type="submit" class="w-full bg-red-600 hover:bg-red-500 text-white py-3 rounded-xl font-semibold transition">Change Password</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
