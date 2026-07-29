<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
require_once __DIR__ . '/includes/nbfc.php';
requireLogin();
requireMerchantTeamCapability('settings');

if (getSetting('nbfc_live_enabled','0') !== '1') {
    flash('info','NBFC is not enabled yet.');
    redirect('dashboard.php');
}

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensureMethodRequestSchema();
ensureNbfcSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'apply') {
        $res = submitNbfcApplication(
            $merchantId,
            (float)($_POST['amount'] ?? 0),
            (int)($_POST['tenure_months'] ?? 12),
            (string)($_POST['purpose'] ?? ''),
            (string)($_POST['note'] ?? '')
        );
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? ($res['message'] ?? 'Saved.') : ($res['error'] ?? 'Failed.'));
    }
    redirect('merchant_nbfc.php');
}

$map = merchantMethodRequestMap($merchantId);
$entitled = merchantNbfcEntitled($merchant);
$status = $entitled ? 'approved' : (string)($map['nbfc'] ?? 'not_requested');
$apps = listNbfcApplications($merchantId);
$live = nbfcLiveDisburseAllowed();

$pageTitle = 'NBFC Finance';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h1 class="text-xl font-bold mb-2">NBFC / Merchant Finance</h1>
        <p class="text-sm text-gray-500 mb-4">Working capital via licensed NBFC partners. Access is auto-queued at signup — fill an application after partner enables NBFC on your account.</p>

        <div class="rounded-xl border border-gray-800 bg-dark-900/40 p-4 mb-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Access status</p>
            <p class="font-semibold <?= $entitled ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= e(methodRequestStatusLabel($status === 'not_requested' ? 'pending' : $status)) ?>
            </p>
            <p class="text-xs text-gray-500 mt-2">
                <?= $entitled
                    ? 'Access unlocked. You can submit an application below.'
                    : 'Waiting with admin → partner. No need to click Request.' ?>
            </p>
            <p class="text-xs mt-2 <?= $live ? 'text-emerald-400' : 'text-amber-300' ?>">
                <?= $live
                    ? 'Partner keys + NBFC live switch are ON — disbursement rail ready.'
                    : 'Live disbursement OFF until partner keys are pasted and admin turns nbfc_live_enabled on.' ?>
            </p>
        </div>

        <?php if ($entitled): ?>
        <form method="POST" class="space-y-4 border-t border-gray-800 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="apply">
            <h2 class="font-semibold">New application</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm text-gray-400">Amount (₹)</label>
                    <input type="number" name="amount" min="1000" max="5000000" step="100" required class="input-field mt-1" value="<?= e($_POST['amount'] ?? '50000') ?>">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Tenure (months)</label>
                    <input type="number" name="tenure_months" min="3" max="60" required class="input-field mt-1" value="12">
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-400">Purpose</label>
                <input type="text" name="purpose" required maxlength="255" class="input-field mt-1" placeholder="Inventory / working capital / expansion">
            </div>
            <div>
                <label class="text-sm text-gray-400">Note (optional)</label>
                <textarea name="note" rows="2" maxlength="500" class="input-field mt-1" placeholder="Anything admin / partner should know"></textarea>
            </div>
            <button type="submit" class="btn-primary px-5 py-2.5">Submit application</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Your applications</h2></div>
        <?php if (empty($apps)): ?>
        <p class="text-sm text-gray-500 text-center py-8">No applications yet.</p>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($apps as $a): ?>
            <div class="px-6 py-4 text-sm">
                <div class="flex flex-wrap justify-between gap-2">
                    <p class="font-mono text-sky-400"><?= e($a['app_ref']) ?></p>
                    <span class="text-xs <?= ($a['status'] ?? '') === 'approved' ? 'text-emerald-400' : 'text-amber-300' ?>"><?= e(ucfirst(str_replace('_', ' ', (string)$a['status']))) ?></span>
                </div>
                <p class="text-gray-300 mt-1"><?= formatMoney((float)$a['amount']) ?> · <?= (int)$a['tenure_months'] ?> months</p>
                <p class="text-xs text-gray-500 mt-1"><?= e((string)$a['purpose']) ?></p>
                <?php if (!empty($a['admin_note'])): ?><p class="text-xs text-gray-400 mt-1">Note: <?= e($a['admin_note']) ?></p><?php endif; ?>
                <?php if (($a['status'] ?? '') === 'approved'): ?>
                <a href="merchant_nbfc_loan.php" class="inline-block mt-2 text-xs text-sky-400">View loan &amp; EMI schedule →</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <a href="collection_settings.php" class="inline-block text-sm text-sky-400">← Collection Settings</a>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
