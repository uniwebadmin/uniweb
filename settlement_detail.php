<?php
require_once __DIR__ . '/config.php';

$settlementId = trim($_GET['id'] ?? '');
if ($settlementId === '') {
    flash('error', 'Settlement ID required.');
    redirect(isAdminLoggedIn() && !isLoggedIn() ? 'admin_settlements.php' : 'settlements.php');
}

$adminView = isAdminLoggedIn() && !isLoggedIn();
if ($adminView) {
    requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
} else {
    requireLogin();
}

$db = getDB();
if ($adminView) {
    $st = $db->prepare('SELECT s.*, m.business_name, m.merchant_code, m.email AS merchant_email,
        b.bank_name, b.account_number, b.ifsc_code, b.account_holder
        FROM settlements s
        JOIN merchants m ON s.merchant_id = m.id
        LEFT JOIN bank_accounts b ON s.bank_account_id = b.id
        WHERE s.settlement_id = ? LIMIT 1');
    $st->execute([$settlementId]);
} else {
    $merchant = getMerchant();
    $st = $db->prepare('SELECT s.*, m.business_name, m.merchant_code, m.email AS merchant_email,
        b.bank_name, b.account_number, b.ifsc_code, b.account_holder
        FROM settlements s
        JOIN merchants m ON s.merchant_id = m.id
        LEFT JOIN bank_accounts b ON s.bank_account_id = b.id
        WHERE s.settlement_id = ? AND s.merchant_id = ? LIMIT 1');
    $st->execute([$settlementId, (int)$merchant['id']]);
}
$s = $st->fetch();
if (!$s) {
    flash('error', 'Settlement not found.');
    redirect($adminView ? 'admin_settlements.php' : 'settlements.php');
}

$walletRef = $db->prepare('SELECT * FROM wallet_transactions WHERE merchant_id = ? AND reference = ? ORDER BY id DESC LIMIT 5');
$walletRef->execute([(int)$s['merchant_id'], $s['settlement_id']]);
$walletRows = $walletRef->fetchAll();

$statusInfo = canonicalSettlementStatus($s['status']);
$reasonText = settlementReasonText($s, $adminView ? null : ($merchant ?? null));

$pageTitle = 'Settlement ' . $settlementId;
require_once __DIR__ . '/header.php';
$backUrl = $adminView ? 'admin_settlements.php' : 'settlements.php';
?>

<div class="mb-4 flex flex-wrap gap-3 items-center">
    <a href="<?= e($backUrl) ?>" class="text-sm text-gray-400 hover:text-white">← Back to Bank Transfers</a>
    <?php if ($adminView): ?>
    <span class="text-xs bg-red-500/20 text-red-300 px-2 py-1 rounded">Admin View</span>
    <a href="admin_view_merchant.php?id=<?= (int)$s['merchant_id'] ?>" class="text-xs text-sky-400">Merchant →</a>
    <?php endif; ?>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="glass rounded-xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
                <div>
                    <p class="text-xs text-gray-500 uppercase">Settlement ID</p>
                    <p class="font-mono text-lg text-sky-400 mt-1 break-all"><?= e($s['settlement_id']) ?></p>
                </div>
                <div class="text-right">
                    <span class="text-sm font-semibold <?= $statusInfo['class'] ?>"><?= e($statusInfo['label']) ?></span>
                </div>
            </div>
            <p class="text-4xl font-bold text-brand-400 mb-2"><?= formatMoney((float)$s['net_amount']) ?></p>
            <p class="text-xs text-gray-500 mb-4">Net transfer · Gross <?= formatMoney((float)$s['amount']) ?> · Fee <?= formatMoney((float)($s['fee'] ?? 0)) ?></p>
            <div class="rounded-xl border <?= $statusInfo['key'] === 'failed' ? 'border-red-500/30 bg-red-500/10' : ($statusInfo['key'] === 'completed' ? 'border-brand-500/30 bg-brand-500/10' : 'border-amber-500/30 bg-amber-500/10') ?> px-4 py-3 mb-6">
                <p class="text-xs font-semibold <?= $statusInfo['class'] ?> mb-1">Why is this <?= e(strtolower($statusInfo['label'])) ?>?</p>
                <p class="text-sm text-gray-300 leading-relaxed"><?= e($reasonText) ?></p>
            </div>

            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-500 text-xs">Created</p>
                    <p class="mt-1"><?= formatDate($s['created_at']) ?></p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">Processed</p>
                    <p class="mt-1"><?= !empty($s['processed_at']) ? formatDate($s['processed_at']) : '—' ?></p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">UTR / Reference</p>
                    <p class="font-mono text-xs mt-1"><?= e($s['utr'] ?: '—') ?></p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">Merchant</p>
                    <p class="mt-1"><?= e($s['business_name'] ?? '—') ?> <span class="text-gray-600 text-xs">(<?= e($s['merchant_code'] ?? '') ?>)</span></p>
                </div>
            </div>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-4">Bank Account</h2>
            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-500 text-xs">Bank</p>
                    <p class="mt-1"><?= e($s['bank_name'] ?? '—') ?></p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">Account Holder</p>
                    <p class="mt-1"><?= e($s['account_holder'] ?? '—') ?></p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">Account Number</p>
                    <p class="font-mono text-xs mt-1">
                        <?php if (!empty($s['account_number'])): ?>
                        ****<?= e(substr((string)$s['account_number'], -4)) ?>
                        <?php else: ?>—<?php endif; ?>
                    </p>
                </div>
                <div>
                    <p class="text-gray-500 text-xs">IFSC</p>
                    <p class="font-mono text-xs mt-1"><?= e($s['ifsc_code'] ?? '—') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Status meaning</h2>
            <ul class="text-xs text-gray-500 space-y-2 leading-relaxed">
                <li><strong class="text-amber-400">Pending</strong> — wallet debited; waiting for bank NEFT/IMPS (Live) or auto-complete (Test).</li>
                <li><strong class="text-brand-400">Complete</strong> — transfer marked done; UTR may be shown.</li>
                <li><strong class="text-red-400">Failed</strong> — transfer failed; amount may be refunded to wallet.</li>
            </ul>
        </div>

        <?php if (!empty($walletRows)): ?>
        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Wallet ledger</h2>
            <div class="space-y-2 text-xs">
                <?php foreach ($walletRows as $w): ?>
                <div class="flex justify-between gap-2 border-b border-gray-800 pb-2">
                    <span class="text-gray-400"><?= e($w['type'] ?? $w['description'] ?? 'entry') ?></span>
                    <span class="font-mono <?= ((float)$w['amount'] < 0) ? 'text-red-400' : 'text-emerald-400' ?>"><?= formatMoney((float)$w['amount']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <a href="<?= e($backUrl) ?>" class="block text-center glass rounded-xl py-3 text-sm text-sky-400 hover:text-sky-300">← All bank transfers</a>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
