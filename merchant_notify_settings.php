<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

$events = [
    'payment_success' => 'Payment successful',
    'payment_failed' => 'Payment failed',
    'settlement' => 'Settlement completed',
    'refund' => 'Refund processed',
];
$channels = [
    'email' => 'Email',
    'webhook' => 'Webhook',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $incoming = $_POST['prefs'] ?? [];
    $prefs = defaultMerchantNotifyPrefs();
    foreach ($prefs as $event => $chs) {
        foreach ($chs as $ch => $_) {
            $prefs[$event][$ch] = !empty($incoming[$event][$ch]);
        }
    }
    saveMerchantNotifyPrefs($merchantId, $prefs);
    flash('success', 'Notification preferences saved.');
    redirect('merchant_notify_settings.php');
}

$prefs = getMerchantNotifyPrefs($merchantId);
$pageTitle = 'Manage Notifications';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-gray-400">Choose how you want alerts for each event</p>
        <p class="text-xs text-gray-600 mt-1 font-mono">MID: <?= e($merchant['merchant_code'] ?? '') ?> · <?= e($merchant['email'] ?? '') ?></p>
    </div>
    <a href="merchant_settings.php" class="text-sm text-gray-400 hover:text-white">← Settings</a>
</div>

<form method="POST" class="glass rounded-xl overflow-hidden border border-gray-800 max-w-3xl">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="px-5 py-4 border-b border-gray-800 flex flex-wrap gap-4 text-xs text-gray-500">
        <span class="w-40">Event</span>
        <?php foreach ($channels as $label): ?>
        <span class="w-24 text-center"><?= e($label) ?></span>
        <?php endforeach; ?>
    </div>
    <?php foreach ($events as $key => $label): ?>
    <div class="px-5 py-4 border-b border-gray-800 flex flex-wrap items-center gap-4">
        <span class="w-40 text-sm font-medium text-gray-200"><?= e($label) ?></span>
        <?php foreach ($channels as $ch => $chLabel): ?>
        <label class="w-24 flex justify-center items-center gap-2 text-xs text-gray-400 cursor-pointer">
            <input type="checkbox" name="prefs[<?= e($key) ?>][<?= e($ch) ?>]" value="1" class="rounded border-gray-600 bg-dark-900 text-brand-500"
                <?= !empty($prefs[$key][$ch]) ? 'checked' : '' ?>>
            <span class="sr-only"><?= e($chLabel) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="px-5 py-4 flex flex-wrap gap-3 items-center justify-between">
        <p class="text-[11px] text-gray-600">Webhook URL is configured in <a href="api_settings.php" class="text-sky-400">API Settings</a>. Email goes to your registered address.</p>
        <button type="submit" class="btn-primary text-sm px-5 py-2">Save preferences</button>
    </div>
</form>

<div class="mt-6 max-w-3xl">
    <?= renderMerchantEmptyState(
        'Inbox notifications',
        'In-app alerts still appear under Notifications. This page controls email and webhook delivery for ops / approval demos.',
        'notifications.php',
        'Open notification inbox →'
    ) ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
