<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensureMethodRequestSchema();
$map = merchantMethodRequestMap($merchantId);
$enabled = getMerchantEnabledMethods($merchant);
$status = in_array('nbfc', $enabled, true)
    ? 'approved'
    : (string)($map['nbfc'] ?? 'not_requested');
$pageTitle = 'NBFC Finance';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h1 class="text-xl font-bold mb-2">NBFC / Merchant Finance</h1>
        <p class="text-sm text-gray-500 mb-4">Working-capital and finance rails via licensed NBFC partners. UniWeb queues your access automatically — you do not need to raise a separate request.</p>
        <div class="rounded-xl border border-gray-800 bg-dark-900/40 p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Status</p>
            <p class="font-semibold <?= $status === 'approved' ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= e(methodRequestStatusLabel($status === 'not_requested' ? 'pending' : $status)) ?>
            </p>
            <?php if ($status !== 'approved'): ?>
            <p class="text-xs text-gray-500 mt-2">Queued for admin → partner. When the partner approves, this turns ON automatically.</p>
            <?php else: ?>
            <p class="text-xs text-emerald-400/80 mt-2">Enabled on your account. Partner production keys are required before live disbursements.</p>
            <?php endif; ?>
        </div>
        <ul class="mt-5 text-sm text-gray-400 space-y-2 list-disc list-inside">
            <li>Auto-requested at signup and when you upload KYC documents</li>
            <li>Partner decides approve / reject</li>
            <li>Live money only after bank / NBFC keys are pasted in Gateway Settings</li>
        </ul>
        <a href="collection_settings.php" class="inline-block mt-6 text-sm text-sky-400">← Collection Settings</a>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
