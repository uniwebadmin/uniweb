<?php
require_once __DIR__ . '/config.php';
ensureMerchantTeamSchema();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$invite = $token !== '' ? findMerchantTeamInvite($token) : null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $result = acceptMerchantTeamInvite($token, $password);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'Could not accept invite.';
        } else {
            flash('success', 'Invite accepted. Sign in with your email and new password.');
            redirect('login.php');
        }
    }
    $invite = findMerchantTeamInvite($token);
}

$pageTitle = 'Accept Team Invite';
$hideNav = true;
$footerVariant = 'auth';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <h1 class="text-2xl font-bold mt-4">Accept team invite</h1>
        </div>
        <div class="glass rounded-2xl p-8">
            <?php if (!$invite): ?>
            <p class="text-sm text-red-400">This invite link is invalid or already used.</p>
            <a href="login.php" class="inline-block mt-4 text-sky-400 text-sm">Go to login →</a>
            <?php else: ?>
            <p class="text-sm text-gray-400 mb-4">Join <strong class="text-white"><?= e($invite['business_name'] ?? 'merchant') ?></strong> as <strong class="text-sky-300"><?= e(merchantTeamRoleLabel((string)$invite['role'])) ?></strong>.</p>
            <p class="text-xs text-gray-500 mb-4"><?= e($invite['email']) ?></p>
            <?php if ($error): ?><p class="text-sm text-red-400 mb-4"><?= e($error) ?></p><?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div><label class="text-sm text-gray-400">Create password</label><input type="password" name="password" required minlength="8" class="input-field mt-1"></div>
                <div><label class="text-sm text-gray-400">Confirm password</label><input type="password" name="confirm_password" required minlength="8" class="input-field mt-1"></div>
                <button type="submit" class="w-full btn-primary py-3">Activate access</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
