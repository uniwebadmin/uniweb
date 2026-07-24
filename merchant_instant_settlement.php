<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensureMethodRequestSchema();
$map = merchantMethodRequestMap($merchantId);
$enabled = getMerchantEnabledMethods($merchant);
$status = in_array('instant_settlement', $enabled, true)
    ? 'approved'
    : (string)($map['instant_settlement'] ?? 'not_requested');
$pageTitle = 'Instant Settlement';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h1 class="text-xl font-bold mb-2">Instant Settlement</h1>
        <p class="text-sm text-gray-500 mb-4">Faster settlement batches (near T+0 style) after partner approval. Request is raised automatically — no manual click needed.</p>
        <div class="rounded-xl border border-gray-800 bg-dark-900/40 p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Status</p>
            <p class="font-semibold <?= $status === 'approved' ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= e(methodRequestStatusLabel($status === 'not_requested' ? 'pending' : $status)) ?>
            </p>
            <?php if ($status !== 'approved'): ?>
            <p class="text-xs text-gray-500 mt-2">Waiting with admin / partner. Approval unlocks faster settlement settings on your account.</p>
            <?php else: ?>
            <p class="text-xs text-emerald-400/80 mt-2">Unlocked. Configure timing on Settlement Settings. Live payouts still need partner keys.</p>
            <a href="merchant_settlement_settings.php" class="inline-block mt-3 text-sm bg-brand-600 text-white px-4 py-2 rounded-lg">Open Settlement Settings</a>
            <?php endif; ?>
        </div>
        <a href="collection_settings.php" class="inline-block mt-6 text-sm text-sky-400">← Collection Settings</a>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
