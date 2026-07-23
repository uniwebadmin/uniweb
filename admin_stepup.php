<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc', 'staff_manager', 'regional_manager']);
ensureAdminMfaColumns();
$admin = getAdmin();
if (!$admin) {
    redirect('admin_login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $passwordOk = password_verify((string)($_POST['password'] ?? ''), (string)$admin['password']);
    $mfaOk = adminHasMfaEnabled($admin) && totpVerify((string)$admin['totp_secret'], (string)($_POST['totp_code'] ?? ''));
    if ($passwordOk && $mfaOk) {
        markStepUpAuthenticated();
        recordImmutableAudit('admin_stepup', null, 'admin', (string)$admin['id'], 'Step-up re-authentication');
        $return = basename((string)($_SESSION['stepup_return'] ?? 'admin_dashboard.php'));
        unset($_SESSION['stepup_return']);
        if (!preg_match('/^[a-z0-9_.-]+$/i', $return) || !is_file(__DIR__ . '/' . $return)) {
            $return = isSuperAdmin() ? 'admin_dashboard.php' : 'staff_dashboard.php';
        }
        flash('success', 'Identity confirmed. Continue the sensitive action.');
        redirect($return);
    }
    usleep(250000);
    $error = 'Password or authenticator code is incorrect.';
}

$pageTitle = 'Re-authenticate';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-md mx-auto glass rounded-2xl p-8 border border-amber-500/20" role="main">
    <h1 class="text-xl font-bold mb-2">Step-up authentication</h1>
    <p class="text-sm text-gray-500 mb-6">Bank, API, settlement and Live activation actions require a fresh password + MFA challenge.</p>
    <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-4" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="space-y-4" aria-label="Step-up authentication">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div><?= uxLabel('stepup-password', 'Password', true) ?><input id="stepup-password" type="password" name="password" required class="input-field mt-1" autocomplete="current-password"></div>
        <div><?= uxLabel('stepup-totp', 'Authenticator Code', true) ?><input id="stepup-totp" type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" class="input-field mt-1 text-center text-xl tracking-widest" autocomplete="one-time-code"></div>
        <button class="w-full bg-amber-600 hover:bg-amber-500 text-white py-3 rounded-xl font-semibold">Confirm identity</button>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
