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
    if ($result['ok']) {
        $statusWord = ($result['status'] ?? '') === 'completed' ? 'processed' : 'initiated (pending partner confirmation)';
        flash('success', 'Refund ' . ($result['refund_id'] ?? '') . ' ' . $statusWord . '.');
    } else {
        flash('error', $result['error'] ?? 'Refund failed.');
    }
    if ($result['ok'] && function_exists('logStaffActivity')) {
        logStaffActivity('refund_processed', ($result['refund_id'] ?? '') . ' — txn ' . $txn['txn_id'], (int)$txn['merchant_id'], 'transaction', $txn['txn_id']);
    }
    redirect(transactionDetailUrl($txn['txn_id']) . '#refund');
}

if ($adminView && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireStaffAccess(['super', 'ceo', 'finance', 'ops']);
    if (!function_exists('manualReconcileTransaction') && is_file(__DIR__ . '/includes/payment_reconcile.php')) {
        require_once __DIR__ . '/includes/payment_reconcile.php';
    }
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'admin_reconcile_txn') {
        $result = manualReconcileTransaction((string)$txn['txn_id'], (int)($_SESSION['admin_id'] ?? 0));
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? ((string)($result['message'] ?? 'Reconcile completed.') . ' Source: Manual.')
            : ((string)($result['error'] ?? 'Reconcile failed.')));
        redirect(transactionDetailUrl($txn['txn_id']));
    }
    if ($postAction === 'admin_backfill_ledger') {
        $result = manualBackfillTransactionLedger((int)$txn['id'], (int)($_SESSION['admin_id'] ?? 0));
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? ((string)($result['message'] ?? 'Ledger backfill posted.') . ' Source: Manual.')
            : ((string)($result['error'] ?? 'Ledger backfill failed.')));
        redirect(transactionDetailUrl($txn['txn_id']));
    }
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

$partnerTransfers = [];
if (is_file(__DIR__ . '/includes/split_settlement.php')) {
    require_once __DIR__ . '/includes/split_settlement.php';
    if (function_exists('getTransactionPartnerTransfers')) {
        $partnerTransfers = getTransactionPartnerTransfers((int)$txn['id']);
    }
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
                <div><p class="text-gray-500 text-xs">Collection Mode</p><p class="mt-1"><?= e(collectionModeLabel($txn['collection_mode'] ?? $txn['merchant_collection_mode'] ?? '', !$adminView)) ?></p></div>
                <div><p class="text-gray-500 text-xs">Date & Time</p><p class="mt-1"><?= formatDate($txn['created_at']) ?></p></div>
                <?php
                $confirmSrc = function_exists('transactionConfirmationSourceSummary')
                    ? transactionConfirmationSourceSummary((int)$txn['id'], (string)$txn['txn_id'], (string)($txn['utr'] ?? ''), (string)($txn['payment_method'] ?? ''))
                    : ['source' => 'unknown', 'label' => '', 'at' => null];
                if (($confirmSrc['source'] ?? 'unknown') !== 'unknown'):
                ?>
                <div><p class="text-gray-500 text-xs">Status confirmed via</p><p class="mt-1 text-xs"><?= e((string)$confirmSrc['label']) ?><?= !empty($confirmSrc['at']) ? ' · ' . e(formatDate((string)$confirmSrc['at'])) : '' ?></p></div>
                <?php endif; ?>
                <?php if ($txn['description']): ?>
                <div class="sm:col-span-2"><p class="text-gray-500 text-xs">Description</p><p class="mt-1"><?= e($txn['description']) ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-4">Money Timeline</h2>
            <ol class="space-y-4 text-sm">
                <li class="flex gap-3"><span class="mt-1.5 w-2.5 h-2.5 rounded-full <?= in_array(strtolower((string)$txn['status']), ['success','paid','captured','refunded'], true) ? 'bg-emerald-400' : 'bg-amber-400' ?>"></span><div><p class="font-medium">Payment <?= e(strtolower((string)$txn['status'])) ?></p><p class="text-xs text-gray-500 mt-0.5"><?= formatMoney((float)$txn['amount']) ?> · <?= formatDate($txn['created_at']) ?></p></div></li>
                <?php if (!empty($txn['wallet_entry'])): ?>
                <li class="flex gap-3"><span class="mt-1.5 w-2.5 h-2.5 rounded-full bg-sky-400"></span><div><p class="font-medium">Credited to merchant wallet</p><p class="text-xs text-gray-500 mt-0.5"><?= formatMoney((float)$txn['wallet_entry']['amount']) ?> · <?= formatDate($txn['wallet_entry']['created_at']) ?></p></div></li>
                <?php elseif (in_array(strtolower((string)$txn['status']), ['success','paid','captured'], true)): ?>
                <li class="flex gap-3"><span class="mt-1.5 w-2.5 h-2.5 rounded-full bg-gray-600"></span><div><p class="font-medium text-gray-300">Wallet credit processing</p><p class="text-xs text-gray-500 mt-0.5">The confirmed payment has not appeared in a wallet entry yet.</p></div></li>
                <?php endif; ?>
                <?php foreach (($txn['refunds'] ?? []) as $refund): ?>
                <?php
                    if (!function_exists('refundDisplayStatus') && is_file(__DIR__ . '/includes/refund_webhooks.php')) {
                        require_once __DIR__ . '/includes/refund_webhooks.php';
                    }
                    $refundLabel = function_exists('refundDisplayStatus') ? refundDisplayStatus($refund) : (string)($refund['status'] ?? 'requested');
                    $dotClass = $refundLabel === 'processed' ? 'bg-violet-400' : ($refundLabel === 'failed' ? 'bg-red-400' : 'bg-amber-400');
                ?>
                <li class="flex gap-3"><span class="mt-1.5 w-2.5 h-2.5 rounded-full <?= $dotClass ?>"></span><div><p class="font-medium">Refund <?= e(ucfirst($refundLabel)) ?></p><p class="text-xs text-gray-500 mt-0.5"><?= formatMoney((float)$refund['amount']) ?> · <?= formatDate($refund['processed_at'] ?: $refund['created_at']) ?> · <?= e((string)$refund['refund_id']) ?><?php if (!empty($refund['provider'])): ?> · <?= e(strtoupper((string)$refund['provider'])) ?><?php endif; ?></p><?php if ($refundLabel === 'failed' && !empty($refund['failure_reason'])): ?><p class="text-xs text-red-400/90 mt-0.5"><?= e(mb_substr((string)$refund['failure_reason'], 0, 120)) ?></p><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ol>
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
        <div class="txn-alert-banner rounded-xl border border-red-500/40 bg-red-500/10 p-4 flex flex-col sm:flex-row gap-3 sm:items-center">
            <div class="flex-1 min-w-0 w-full">
                <p class="text-sm font-semibold text-red-200">Active complaint / dispute on this payment</p>
                <p class="text-xs text-red-100/80 mt-1">Refund is available below. Prefer resolving with a clear reason code.</p>
            </div>
            <div class="flex flex-col sm:flex-row flex-wrap gap-2 w-full sm:w-auto shrink-0">
            <?php if ($openComplaint): ?>
            <a href="admin_customer_tickets.php?id=<?= (int)$openComplaint['id'] ?>" class="text-sm glass px-3 py-2 rounded-lg text-sky-300 text-center sm:text-left break-all sm:break-normal">Complaint <?= e($openComplaint['ticket_id']) ?> →</a>
            <?php endif; ?>
            <?php if ($openDispute): ?>
            <a href="admin_disputes.php" class="text-sm glass px-3 py-2 rounded-lg text-amber-300 text-center sm:text-left break-all sm:break-normal">Dispute <?= e($openDispute['dispute_id']) ?></a>
            <?php endif; ?>
            </div>
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
            <p class="text-sm text-amber-200 flex-1">Pending — approve for legacy UPI path, or Reconcile when partner keys + bound order exist.</p>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <button type="submit" name="action" value="admin_reconcile_txn" class="text-sm px-4 py-2 rounded-lg bg-sky-600/30 text-sky-200 hover:bg-sky-600/40">Reconcile</button>
            </form>
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
            <h3 class="font-semibold mb-1">Amount Split</h3>
            <?php
            $partnerSplitNotice = (!$adminView && function_exists('transactionPartnerSplitMerchantNotice'))
                ? transactionPartnerSplitMerchantNotice($partnerTransfers)
                : null;
            ?>
            <?php if ($partnerSplitNotice !== null): ?>
            <p class="text-xs text-amber-200/90 bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2 mb-3"><?= e($partnerSplitNotice) ?></p>
            <?php endif; ?>
            <p class="text-[11px] text-gray-500 mb-3">On success: Gross → Admin cut + Partner cut + Merchant baaki. Percents come from Admin-saved commercial (M/P) at capture — not a live Route SDK.</p>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between"><span class="text-gray-500">Gross</span><span><?= formatMoney($split['gross']) ?></span></div>
                <?php if (!empty($txn['mdr_m']) && (float)$txn['mdr_m'] > 0): ?>
                <div class="flex justify-between"><span class="text-gray-500">Merchant MDR (M)</span><span><?= e(number_format((float)$txn['mdr_m'], 2)) ?>%</span></div>
                <?php endif; ?>
                <?php if (!empty($txn['mdr_p']) && (float)$txn['mdr_p'] > 0): ?>
                <div class="flex justify-between"><span class="text-gray-500">Partner MDR (P)</span><span><?= e(number_format((float)$txn['mdr_p'], 2)) ?>%</span></div>
                <?php endif; ?>
                <div class="flex justify-between"><span class="text-gray-500">Admin cut (UniWeb)</span><span class="text-amber-400">−<?= formatMoney($split['platform_fee']) ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Partner cut (info)</span><span class="text-gray-400"><?= formatMoney((float)($txn['partner_fee'] ?? 0)) ?></span></div>
                <div class="flex justify-between border-t border-gray-800 pt-2 font-semibold"><span>Merchant baaki (settlement)</span><span class="text-emerald-400"><?= formatMoney($split['merchant_net']) ?></span></div>
            </div>
            <?php if ($partnerTransfers): ?>
            <div class="mt-4 pt-3 border-t border-gray-800">
                <p class="text-[11px] text-gray-500 mb-2">Partner transfer legs (Route / Split scaffold)</p>
                <ul class="space-y-1 text-[11px]">
                    <?php foreach ($partnerTransfers as $pt): ?>
                    <li class="flex justify-between gap-2">
                        <span class="text-gray-500"><?= e(str_replace('_', ' ', (string)$pt['transfer_type'])) ?> · <?= e($pt['partner_key']) ?></span>
                        <span class="<?= ($pt['status'] ?? '') === 'processed' ? 'text-emerald-400' : (($pt['status'] ?? '') === 'failed' ? 'text-red-400' : 'text-amber-400') ?>"><?= formatMoney((float)$pt['amount']) ?> · <?= e($pt['status']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-[10px] text-gray-600 mt-2">Live Razorpay Route / Easy Split API not connected — pending legs are audit records only.</p>
            </div>
            <?php endif; ?>
        </div>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Wallet &amp; Ledger</h3>
            <?php if ($txn['status'] !== 'success'): ?>
            <p class="text-amber-400 text-xs">Wallet credits when status is <strong>Success</strong><?= $txn['status'] === 'pending' ? ' (Admin approve required)' : '' ?>.</p>
            <?php else: ?>
            <?php
            $ledgerStatus = (string)($txn['ledger_status'] ?? 'pending');
            $ledgerJournal = $txn['ledger_journal'] ?? null;
            ?>
            <div class="mb-3 pb-3 border-b border-gray-800">
                <p class="text-[11px] text-gray-500 mb-1">Ledger (payment capture)</p>
                <?php if ($ledgerStatus === 'posted' && is_array($ledgerJournal)): ?>
                <p class="text-emerald-400 text-xs">✓ Posted · journal <?= e((string)($ledgerJournal['journal_ref'] ?? '')) ?></p>
                <p class="text-gray-500 text-[11px] mt-0.5"><?= formatDate((string)($ledgerJournal['posted_at'] ?? '')) ?></p>
                <?php elseif ($ledgerStatus === 'failed' || $ledgerStatus === 'pending'): ?>
                <p class="text-amber-400 text-xs"><?= $ledgerStatus === 'failed' ? 'Ledger failed — retry scheduled; check Error Log' : 'Ledger pending — reconcile will retry automatically' ?></p>
                <?php if ($adminView): ?>
                <a href="admin_error_log.php" class="text-xs text-sky-400 mt-1 inline-block">Open Error Log →</a>
                <form method="POST" class="flex flex-wrap gap-2 mt-3">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" name="action" value="admin_backfill_ledger" class="text-xs px-3 py-1.5 rounded-lg bg-sky-600/20 text-sky-300 hover:bg-sky-600/30">Backfill ledger</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-gray-500 text-xs">Not applicable for this status.</p>
                <?php endif; ?>
            </div>
            <?php if (!empty($txn['wallet_credited']) || $txn['wallet_entry']): ?>
            <p class="text-emerald-400 text-xs mb-2">✓ Credited to wallet</p>
            <?php if ($txn['wallet_entry']): ?>
            <p class="text-gray-500 text-xs"><?= formatMoney((float)$txn['wallet_entry']['amount']) ?> · <?= formatDate($txn['wallet_entry']['created_at']) ?></p>
            <a href="<?= $adminView ? 'admin_edit_merchant.php?id=' . (int)$txn['merchant_id'] : 'wallet.php' ?>" class="text-xs text-sky-400 mt-2 inline-block">View Wallet →</a>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-gray-500 text-xs">Wallet line not synced yet<?= $ledgerStatus === 'posted' ? ' (check reconcile)' : '' ?>.</p>
            <?php if ($adminView): ?>
            <a href="admin_transactions.php?action=approve&id=<?= (int)$txn['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400 mt-2 inline-block">Force Approve</a>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
