<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchant2FA();
$merchant = getMerchant();
$db = getDB();
$error = '';
$policy = mfaPolicy('merchant');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'enable') {
        $secret = $_SESSION['pending_2fa_secret'] ?? '';
        $code = $_POST['totp_code'] ?? '';
        if (!$secret) {
            $error = 'Setup expired, please scan the QR again.';
        } elseif (!totpVerify($secret, $code)) {
            $error = 'That code did not match. Check the time on your phone and try again.';
        } else {
            $db->prepare('UPDATE merchants SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([$secret, $merchant['id']]);
            unset($_SESSION['pending_2fa_secret']);
            createNotification($merchant['id'], '2FA Enabled', 'Two-factor authentication was turned on for your account. You will need your authenticator app code at every login.');
            flash('success', '2FA enabled. You will need your authenticator code at every future login.');
            redirect('merchant_2fa.php');
        }
    } elseif (($_POST['action'] ?? '') === 'disable') {
        $password = $_POST['password'] ?? '';
        if (!password_verify($password, $merchant['password'])) {
            $error = 'Incorrect password.';
        } else {
            $db->prepare('UPDATE merchants SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$merchant['id']]);
            createNotification($merchant['id'], '2FA Disabled', 'Two-factor authentication was turned off for your account.');
            flash('success', '2FA disabled.');
            redirect('merchant_2fa.php');
        }
    }
}

$stmt = $db->prepare('SELECT totp_enabled, totp_secret, email FROM merchants WHERE id = ?');
$stmt->execute([$merchant['id']]);
$row = $stmt->fetch();
$enabled = merchantHasMfaEnabled($row ?: null);

$pendingSecret = null;
$qrUrl = null;
$manualKey = null;
if (!$enabled) {
    if (empty($_SESSION['pending_2fa_secret'])) {
        $_SESSION['pending_2fa_secret'] = totpGenerateSecret();
    }
    $pendingSecret = $_SESSION['pending_2fa_secret'];
    $authUrl = totpAuthUrl($pendingSecret, $row['email'] ?? $merchant['email'] ?? 'merchant');
    $qrUrl = APP_URL . '/qr_image.php?d=' . rtrim(strtr(base64_encode($authUrl), '+/', '-_'), '=') . '&s=220&logo=0';
    $manualKey = trim(chunk_split($pendingSecret, 4, ' '));
}

$pageTitle = 'Two-Factor Authentication';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl mx-auto space-y-6">
    <div class="glass rounded-2xl p-6 border border-sky-500/20">
        <p class="text-xs text-sky-400 uppercase tracking-wider mb-1">Security policy</p>
        <h1 class="text-xl font-bold mb-2">Two-Factor Authentication (2FA)</h1>
        <p class="text-sm text-gray-400"><?= e($policy['summary']) ?></p>
        <p class="text-xs text-gray-500 mt-3 inline-flex items-center gap-2">
            <span class="px-2 py-0.5 rounded-md bg-sky-500/15 text-sky-300 border border-sky-500/30">Optional for merchants</span>
            <span class="text-gray-600">Admin &amp; staff MFA is mandatory at login.</span>
        </p>
    </div>

    <div class="glass rounded-2xl p-8">
        <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>

        <?php if ($enabled): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm px-4 py-3 rounded-lg mb-6">2FA is currently <strong>ON</strong> for your account. You will be asked for an authenticator code at every login.</div>
        <form method="POST" class="space-y-4 max-w-sm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="disable">
            <div>
                <label class="text-sm text-gray-400 block mb-1">Confirm your password to disable 2FA</label>
                <input type="password" name="password" required class="input-field" placeholder="••••••••">
            </div>
            <button type="submit" class="bg-red-600/20 border border-red-500/30 text-red-400 px-5 py-2.5 rounded-xl text-sm font-medium hover:bg-red-600/30">Disable 2FA</button>
        </form>
        <?php else: ?>
        <p class="text-sm text-gray-400 mb-6"><?= e($policy['setup_hint']) ?> You can skip this and continue using the dashboard — setup is never forced.</p>
        <div class="grid sm:grid-cols-2 gap-6 items-start">
            <div class="text-center">
                <img src="<?= e($qrUrl) ?>" alt="2FA QR Code" class="mx-auto rounded-xl border border-gray-800 bg-white p-2" width="220" height="220">
                <p class="text-[11px] text-gray-500 mt-2">Scan with Google Authenticator, Authy, or any TOTP app</p>
            </div>
            <div>
                <p class="text-sm text-gray-400 mb-2">Can't scan? Enter this key manually:</p>
                <p class="font-mono text-sm bg-dark-900 border border-gray-800 rounded-lg px-3 py-2 tracking-wider break-all"><?= e($manualKey) ?></p>
                <form method="POST" class="space-y-3 mt-5">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="enable">
                    <label class="text-sm text-gray-400 block">Enter the 6-digit code shown in your app to confirm:</label>
                    <input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field text-center text-xl tracking-widest" placeholder="000000" autofocus>
                    <button type="submit" class="w-full btn-primary py-2.5">Enable 2FA</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
