<?php
require_once __DIR__ . '/config.php';

$txnId = trim($_GET['txn'] ?? '');
if (!$txnId) {
    flash('error', 'Transaction ID required.');
    redirect(isAdminLoggedIn() && !isLoggedIn() ? 'admin_transactions.php' : 'transactions.php');
}

// Merchant portal wins if both admin + merchant sessions exist
$adminView = isAdminLoggedIn() && !isLoggedIn();
if ($adminView) {
    requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc', 'finance', 'support']);
}
$merchantId = null;
if (!$adminView) {
    requireLogin();
    $merchant = getMerchant();
    $merchantId = (int)$merchant['id'];
}

$txn = fetchTransactionDetail($txnId, $merchantId, $adminView);
if (!$txn) {
    flash('error', 'Transaction not found.');
    redirect($adminView ? 'admin_transactions.php' : 'transactions.php');
}

if ($adminView && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_payment' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
    require_once __DIR__ . '/includes/refunds.php';
    ensureRefundsEngine();
    $mode = (string)($_POST['refund_mode'] ?? 'full');
    $amount = $mode === 'partial' ? (float)($_POST['amount'] ?? 0) : 0.0;
    $reasonPick = trim((string)($_POST['reason_code'] ?? 'Customer requested refund'));
    $reasonExtra = trim((string)($_POST['reason_note'] ?? ''));
    $reasonOptions = function_exists('getRefundReasonOptions') ? getRefundReasonOptions() : [];
    if ($reasonOptions && !in_array($reasonPick, $reasonOptions, true)) {
        $reasonPick = 'Customer requested refund';
    }
    $reason = $reasonPick;
    if ($reasonExtra !== '') {
        $reason .= ' — ' . $reasonExtra;
    }
    requireStepUpAuth();
    $admin = getAdmin();
    $result = processRefund((int)$txn['id'], $amount, $reason, (int)($admin['id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? ('Refund ' . ($result['refund_id'] ?? '') . ' processed.') : ($result['error'] ?? 'Refund failed.'));
    if ($result['ok'] && function_exists('logStaffActivity')) {
        logStaffActivity('refund_processed', ($result['refund_id'] ?? '') . ' — txn ' . $txn['txn_id'], (int)$txn['merchant_id'], 'transaction', $txn['txn_id']);
    }
    redirect(transactionDetailUrl($txn['txn_id']) . '#refund');
}

$openComplaint = null;
$openDispute = null;
try {
    require_once __DIR__ . '/includes/customer_portal.php';
    ensureCustomerPortalSchema();
    $st = getDB()->prepare("SELECT id, ticket_id, status FROM customer_tickets WHERE txn_reference = ? AND status IN ('open','in_progress') ORDER BY id DESC LIMIT 1");
    $st->execute([$txn['txn_id']]);
    $openComplaint = $st->fetch() ?: null;
} catch (Throwable $e) {
    $openComplaint = null;
}
try {
    $st = getDB()->prepare("SELECT id, dispute_id, status FROM disputes WHERE txn_id = ? AND status IN ('open','pending','under_review') ORDER BY id DESC LIMIT 1");
    $st->execute([$txn['txn_id']]);
    $openDispute = $st->fetch() ?: null;
} catch (Throwable $e) {
    try {
        $st = getDB()->prepare("SELECT d.id, d.dispute_id, d.status FROM disputes d JOIN transactions t ON t.id = d.transaction_id WHERE t.txn_id = ? AND d.status IN ('open','pending','under_review') ORDER BY d.id DESC LIMIT 1");
        $st->execute([$txn['txn_id']]);
        $openDispute = $st->fetch() ?: null;
    } catch (Throwable $e2) {
        $openDispute = null;
    }
}

$canRefundHere = $adminView && strtolower((string)$txn['status']) === 'success'
    && function_exists('staffCanAccess') && (staffCanAccess('admin_refunds.php') || isSuperAdmin() || in_array(adminRole(), ['finance', 'ops', 'ceo', 'super'], true));
$reasonOptions = [];
if ($canRefundHere) {
    require_once __DIR__ . '/includes/refunds.php';
    if (function_exists('getRefundReasonOptions')) {
        $reasonOptions = getRefundReasonOptions();
    }
}

$split = [
    'gross' => (float)$txn['amount'],
    'platform_fee' => (float)($txn['platform_fee'] ?? 0),
    'merchant_net' => (float)($txn['split_amount'] ?? 0),
];
if ($split['merchant_net'] <= 0 && $split['platform_fee'] <= 0) {
    $calc = calculateSplitBreakdown($split['gross'], $txn);
    $split = $calc;
}

$pageTitle = 'Transaction ' . $txnId;
require_once __DIR__ . '/header.php';
?>

<div class="mb-4 flex flex-wrap gap-3 items-center">
    <a href="<?= $adminView ? 'admin_transactions.php' : 'transactions.php' ?>" class="text-sm text-gray-400 hover:text-white">← Back to Transactions</a>
    <?php if ($adminView): ?>
    <span class="text-xs bg-red-500/20 text-red-300 px-2 py-1 rounded">Admin View</span>
    <?php endif; ?>
    <a href="receipt.php?txn=<?= e($txn['txn_id']) ?>" target="_blank" class="ml-auto text-sm bg-brand-600/20 text-brand-400 hover:bg-brand-600/30 px-3 py-1.5 rounded-lg">Print receipt</a>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="glass rounded-xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
                <div>
                    <p class="text-xs text-gray-500 uppercase">Transaction ID</p>
                    <p class="font-mono text-lg text-sky-400 mt-1"><?= e($txn['txn_id']) ?></p>
                </div>
                <div class="text-right">
                    <?= statusBadge($txn['status']) ?>
                    <?php if (!empty($txn['is_test'])): ?>
                    <span class="block text-xs text-amber-400 mt-1">Test / Sandbox</span>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-4xl font-bold text-brand-400 mb-6"><?= formatMoney((float)$txn['amount']) ?></p>

            <?php
            $reason = transactionStatusExplainer($txn);
            $reasonTone = [
                'success' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300',
                'warning' => 'bg-amber-500/10 border-amber-500/30 text-amber-200',
                'danger' => 'bg-red-500/10 border-red-500/30 text-red-300',
                'muted' => 'bg-white/5 border-gray-700 text-gray-300',
            ][$reason['tone']] ?? 'bg-white/5 border-gray-700 text-gray-300';
            ?>
            <div class="rounded-xl border p-4 mb-6 <?= $reasonTone ?>">
                <p class="text-sm font-semibold"><?= e($reason['title']) ?></p>
                <?php if (!empty($reason['text'])): ?><p class="text-xs mt-1 leading-relaxed opacity-90"><?= e($reason['text']) ?></p><?php endif; ?>
            </div>

            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div><p class="text-gray-500 text-xs">Payment Method</p><p class="font-medium mt-1"><?= e(paymentMethodLabel($txn['payment_method'])) ?></p></div>
                <div><p class="text-gray-500 text-xs">UTR / Gateway Ref</p><p class="font-mono text-xs mt-1"><?= e($txn['utr'] ?: '—') ?></p></div>
                <div><p class="text-gray-500 text-xs">Collection Mode</p><p class="mt-1"><?= e(collectionModeLabel($txn['collection_mode'] ?? $txn['merchant_collection_mode'] ?? '')) ?></p></div>
                <div><p class="text-gray-500 text-xs">Date & Time</p><p class="mt-1"><?= formatDate($txn['created_at']) ?></p></div>
                <?php if ($txn['description']): ?>
                <div class="sm:col-span-2"><p class="text-gray-500 text-xs">Description</p><p class="mt-1"><?= e($txn['description']) ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-4">Payment Source</h2>
            <div class="space-y-3 text-sm">
                <?php if ($txn['link_id']): ?>
                <div class="flex justify-between gap-4 border-b border-gray-800 pb-3">
                    <span class="text-gray-500">Payment Link</span>
                    <span class="font-mono text-sky-400"><?= e($txn['link_id']) ?></span>
                </div>
                <?php if ($txn['link_label'] || $txn['link_payment_method']): ?>
                <div class="flex justify-between gap-4 border-b border-gray-800 pb-3">
                    <span class="text-gray-500">Link Type</span>
                    <span><?= e($txn['link_label'] ?: $txn['link_payment_method']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($txn['link_gateway']): ?>
                <div class="flex justify-between gap-4 border-b border-gray-800 pb-3">
                    <span class="text-gray-500">Gateway</span>
                    <span class="uppercase"><?= e($txn['link_gateway']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($txn['link_description']): ?>
                <div class="flex justify-between gap-4 border-b border-gray-800 pb-3">
                    <span class="text-gray-500">Link Description</span>
                    <span class="text-right"><?= e($txn['link_description']) ?></span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-gray-500">Direct payment (no payment link attached)</p>
                <?php endif; ?>
                <div class="flex justify-between gap-4 border-b border-gray-800 pb-3">
                    <span class="text-gray-500">Customer</span>
                    <span><?php if ($adminView): ?>
                        <?= e($txn['customer_name'] ?: '—') ?>
                        <?php
                        $cphone = (string)($txn['customer_phone'] ?: $txn['link_customer_phone'] ?: '');
                        if ($cphone !== '' && function_exists('adminCustomerHistoryUrl')):
                        ?> · <a href="<?= e(adminCustomerHistoryUrl($cphone)) ?>" class="text-sky-400 hover:underline"><?= e($cphone) ?></a>
                        <?php elseif ($cphone !== ''): ?> · <?= e($cphone) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= e(maskCustomerContact($txn['customer_phone'] ?: $txn['link_customer_phone'] ?? null, $txn['customer_name'] ?: $txn['link_customer_name'] ?? null)) ?>
                    <?php endif; ?></span>
                </div>
            </div>
        </div>

        <?php if ($adminView && ($openComplaint || $openDispute)): ?>
        <div class="rounded-xl border border-red-500/40 bg-red-500/10 p-4 flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-[200px]">
                <p class="text-sm font-semibold text-red-200">Active complaint / dispute on this payment</p>
                <p class="text-xs text-red-100/80 mt-1">Refund is available below. Prefer resolving with a clear reason code.</p>
            </div>
            <?php if ($openComplaint): ?>
            <a href="admin_customer_tickets.php?id=<?= (int)$openComplaint['id'] ?>" class="text-sm glass px-3 py-2 rounded-lg text-sky-300">Complaint <?= e($openComplaint['ticket_id']) ?> →</a>
            <?php endif; ?>
            <?php if ($openDispute): ?>
            <a href="admin_disputes.php" class="text-sm glass px-3 py-2 rounded-lg text-amber-300">Dispute <?= e($openDispute['dispute_id']) ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canRefundHere): ?>
        <div id="refund" class="glass rounded-xl p-6 border <?= ($openComplaint || $openDispute) ? 'border-red-500/50' : 'border-gray-800' ?> scroll-mt-24">
            <h2 class="font-semibold mb-1 <?= ($openComplaint || $openDispute) ? 'text-red-300' : '' ?>">Refund this payment</h2>
            <p class="text-xs text-gray-500 mb-4">1) Choose full or partial · 2) Pick a reason · 3) Confirm with step-up password · 4) Refund posts to the refunds ledger.</p>
            <form method="POST" class="space-y-4 max-w-lg">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refund_payment">
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="refund_mode" value="full" checked class="accent-brand-500"> Full amount (<?= formatMoney((float)$txn['amount']) ?>)</label>
                    <label class="flex items-center gap-2"><input type="radio" name="refund_mode" value="partial" class="accent-brand-500"> Partial</label>
                </div>
                <div>
                    <label class="text-xs text-gray-500" for="refund_amount">Partial amount (₹)</label>
                    <input id="refund_amount" type="number" step="0.01" min="0.01" max="<?= e((string)$txn['amount']) ?>" name="amount" class="input-field mt-1 w-full" placeholder="Leave blank for full when Full is selected">
                </div>
                <div>
                    <label class="text-xs text-gray-500" for="reason_code">Reason</label>
                    <select id="reason_code" name="reason_code" class="input-field mt-1 w-full" required>
                        <?php foreach ($reasonOptions ?: ['Customer requested refund', 'Duplicate payment', 'Goods/services not provided', 'Other'] as $opt): ?>
                        <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-500" for="reason_note">Extra note (optional)</label>
                    <input id="reason_note" type="text" name="reason_note" maxlength="200" class="input-field mt-1 w-full" placeholder="Ops note">
                </div>
                <button type="submit" class="btn-primary px-5 py-2.5 text-sm <?= ($openComplaint || $openDispute) ? '!bg-red-600 hover:!bg-red-500' : '' ?>">Process refund</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($adminView && $txn['status'] === 'pending'): ?>
        <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 flex flex-wrap gap-3 items-center">
            <p class="text-sm text-amber-200 flex-1">Pending UPI — approve to credit merchant wallet.</p>
            <a href="admin_transactions.php?action=approve&id=<?= (int)$txn['id'] ?>&token=<?= csrfToken() ?>" class="btn-primary text-sm px-4 py-2">Approve → Wallet</a>
            <a href="admin_transactions.php?action=reject&id=<?= (int)$txn['id'] ?>&token=<?= csrfToken() ?>" class="text-sm text-red-400 px-3">Reject</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <?php if ($adminView): ?>
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Merchant</h3>
            <p class="font-medium"><?= adminMerchantLink((int)$txn['merchant_id'], $txn['business_name'], 'font-medium text-white hover:text-sky-300') ?></p>
            <p class="text-xs text-gray-500 font-mono mt-1"><?= adminMerchantLink((int)$txn['merchant_id'], $txn['merchant_code'], 'font-mono text-sky-400') ?></p>
            <p class="text-xs text-gray-500 mt-2"><?= e($txn['merchant_email']) ?></p>
            <a href="<?= e(adminMerchantUrl((int)$txn['merchant_id'])) ?>" class="text-xs text-emerald-400 mt-2 inline-block">Merchant View →</a>
            <a href="<?= e(adminMerchantEditUrl((int)$txn['merchant_id'])) ?>" class="text-xs text-sky-400 mt-1 inline-block">Edit Merchant →</a>
        </div>
        <?php endif; ?>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Amount Split</h3>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between"><span class="text-gray-500">Gross</span><span><?= formatMoney($split['gross']) ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Platform Fee</span><span class="text-amber-400">−<?= formatMoney($split['platform_fee']) ?></span></div>
                <div class="flex justify-between border-t border-gray-800 pt-2 font-semibold"><span>Merchant Net</span><span class="text-emerald-400"><?= formatMoney($split['merchant_net']) ?></span></div>
            </div>
        </div>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Wallet Status</h3>
            <?php if ($txn['status'] !== 'success'): ?>
            <p class="text-amber-400 text-xs">Wallet credits when status is <strong>Success</strong><?= $txn['status'] === 'pending' ? ' (Admin approve required)' : '' ?>.</p>
            <?php elseif (!empty($txn['wallet_credited']) || $txn['wallet_entry']): ?>
            <p class="text-emerald-400 text-xs mb-2">✓ Credited to wallet</p>
            <?php if ($txn['wallet_entry']): ?>
            <p class="text-gray-500 text-xs"><?= formatMoney((float)$txn['wallet_entry']['amount']) ?> · <?= formatDate($txn['wallet_entry']['created_at']) ?></p>
            <a href="<?= $adminView ? 'admin_edit_merchant.php?id=' . (int)$txn['merchant_id'] : 'wallet.php' ?>" class="text-xs text-sky-400 mt-2 inline-block">View Wallet →</a>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-gray-500 text-xs">Not yet credited to wallet.</p>
            <?php if ($adminView): ?>
            <a href="admin_transactions.php?action=approve&id=<?= (int)$txn['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400 mt-2 inline-block">Force Approve</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
