<?php
require_once __DIR__ . '/config.php';
requireLogin();
if (function_exists('ensureFailureReasonColumns')) {
    ensureFailureReasonColumns();
}
$merchant = getMerchant();
$db = getDB();
$filter = trim($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'success', 'failed', 'refunded'], true)) $filter = 'all';
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$method = trim($_GET['method'] ?? 'all');
$range = trim($_GET['range'] ?? '');
$qrId = (int)($_GET['qr_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; $offset = ($page - 1) * $perPage;
$where = 'merchant_id = ?'; $params = [$merchant['id']];
$viewTest = isDashboardTestMode($merchant);
$where .= ' AND is_test = ?';
$params[] = $viewTest ? 1 : 0;
$qrLabel = null;
if ($qrId > 0) {
    ensureMerchantQrCodes();
    $qrRow = $db->prepare('SELECT id, label FROM merchant_qr_codes WHERE id=? AND merchant_id=?');
    $qrRow->execute([$qrId, (int)$merchant['id']]);
    $qrInfo = $qrRow->fetch();
    if ($qrInfo) {
        $qrLabel = $qrInfo['label'];
        $where .= ' AND payment_link_id IN (SELECT id FROM payment_links WHERE qr_code_id = ?)';
        $params[] = $qrId;
    }
}
if ($filter !== 'all') { $where .= ' AND status = ?'; $params[] = $filter; }
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (
        LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(utr,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_phone,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_email,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(description,''))) LIKE ? OR
        CAST(amount AS CHAR) LIKE ? OR
        LOWER(CAST(COALESCE(metadata,'') AS CHAR)) LIKE ? OR
        payment_link_id IN (SELECT id FROM payment_links WHERE LOWER(TRIM(link_id)) LIKE ?) OR
        payment_link_id IN (SELECT payment_link_id FROM payment_orders WHERE merchant_id = ? AND (
            LOWER(TRIM(COALESCE(order_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(provider_order_id,''))) LIKE ?
        ))
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, (int)$merchant['id'], $like, $like);
}
if ($method !== 'all' && in_array($method, ['upi', 'card', 'netbanking', 'wallet', 'qr', 'razorpay', 'cashfree', 'payu'], true)) {
    $where .= ' AND payment_method = ?';
    $params[] = $method;
}
if ($range !== '') {
    $today = date('Y-m-d');
    if ($range === 'today') { $from = $today; $to = $today; }
    elseif ($range === 'yesterday') { $from = $to = date('Y-m-d', strtotime('-1 day')); }
    elseif ($range === '7days') { $from = date('Y-m-d', strtotime('-6 days')); $to = $today; }
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND DATE(created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND DATE(created_at) <= ?';
    $params[] = $to;
}
$countStmt = $db->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE $where");
$countStmt->execute($params); $total = (int)$countStmt->fetch()['cnt'];
$stmt = $db->prepare("SELECT * FROM transactions WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $transactions = $stmt->fetchAll();
$highlightTxnId = '';
if ($q !== '' && preg_match('/^TXN[A-F0-9]{8,}$/i', $q)) {
    $highlightTxnId = strtoupper($q);
}
$pageTitle = 'Transactions';
require_once __DIR__ . '/header.php';
?>
<?php if ($qrLabel !== null): ?>
<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-4 flex flex-wrap items-center justify-between gap-3 text-sm">
    <p class="text-sky-200">Filtered to QR: <strong><?= e($qrLabel) ?></strong> — only payments from this QR are shown.</p>
    <a href="transactions.php" class="text-xs text-gray-400 hover:text-white">Clear filter ×</a>
</div>
<?php endif; ?>
<div class="flex flex-wrap items-center justify-between gap-4 mb-4">
    <div class="flex gap-2 flex-wrap">
        <?php foreach (['all'=>__('all'),'success'=>__('success'),'pending'=>__('pending'),'failed'=>__('failed')] as $k=>$l): ?>
        <a href="?status=<?= $k ?>&q=<?= rawurlencode($q) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&qr_id=<?= (int)$qrId ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $filter===$k?'bg-brand-600 text-white':'bg-dark-900 text-gray-400 hover:text-white' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-3">
        <span class="text-sm text-gray-500"><?= $total ?> <?= __('transactions') ?> · <?= $viewTest ? 'Test' : 'Live' ?></span>
        <a href="export_transactions.php?<?= http_build_query(['status'=>$filter,'qr_id'=>$qrId,'q'=>$q,'from'=>$from,'to'=>$to,'method'=>$method]) ?>" class="text-sm bg-brand-600/20 text-brand-400 px-4 py-1.5 rounded-lg hover:bg-brand-600/30 transition"><?= __('export_csv') ?></a>
        <a href="statement_pdf.php?<?= http_build_query(['from'=>$from,'to'=>$to]) ?>" class="text-sm bg-emerald-600/20 text-emerald-400 px-4 py-1.5 rounded-lg hover:bg-emerald-600/30 transition">Statement PDF</a>
    </div>
</div>
<form method="GET" data-live-search-form data-results-target="transaction-results" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <input type="hidden" name="status" value="<?= e($filter) ?>">
    <input type="hidden" name="qr_id" value="<?= (int)$qrId ?>">
    <div class="flex-1 min-w-[140px]">
        <label class="text-[10px] text-gray-600 uppercase">Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Txn / Order / UTR / Amount / Name / Phone / Email" class="input-field mt-1 text-sm" autocomplete="off">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Method</label>
        <select name="method" class="input-field mt-1 text-sm">
            <?php foreach (['all'=>'All methods','upi'=>'UPI','card'=>'Card','netbanking'=>'Netbanking','wallet'=>'Wallet','razorpay'=>'Razorpay','cashfree'=>'Cashfree','payu'=>'PayU'] as $mk=>$ml): ?>
            <option value="<?= e($mk) ?>" <?= $method===$mk?'selected':'' ?>><?= e($ml) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Quick date</label>
        <select name="range" class="input-field mt-1 text-sm">
            <option value="">Custom / All</option>
            <option value="today" <?= $range==='today'?'selected':'' ?>>Today</option>
            <option value="yesterday" <?= $range==='yesterday'?'selected':'' ?>>Yesterday</option>
            <option value="7days" <?= $range==='7days'?'selected':'' ?>>Last 7 days</option>
        </select>
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">From</label>
        <input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 text-sm">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">To</label>
        <input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 text-sm">
    </div>
    <button class="btn-primary text-sm px-4 py-2.5">Filter</button>
</form>
<div id="transaction-results" class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Txn ID</th><th class="px-5 py-3 text-left">Customer</th>
                <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Method</th>
                <th class="px-5 py-3 text-left">UTR</th><th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Reason</th><th class="px-5 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($transactions)): ?>
                <tr><td colspan="8" class="p-0">
                    <?= renderMerchantEmptyState(
                        'No transactions found',
                        $viewTest
                            ? 'Create a ₹1 test payment link or open Payment Pack to generate sample traffic for demo / approval.'
                            : 'Live transactions will appear here after real payments. Switch to Test Mode to try sandbox payments.',
                        $viewTest ? 'merchant_payment_pack.php' : 'payment_links.php',
                        $viewTest ? 'Open Payment Pack →' : 'Create payment link →'
                    ) ?>
                </td></tr>
                <?php else: foreach ($transactions as $t):
                    $rowReason = null;
                    $st = strtolower((string)($t['status'] ?? ''));
                    $isHighlightTxn = $highlightTxnId !== '' && strcasecmp((string)($t['txn_id'] ?? ''), $highlightTxnId) === 0;
                    if (in_array($st, ['failed', 'error', 'pending', 'processing', 'initiated', 'expired', 'cancelled', 'canceled'], true)
                        && function_exists('transactionStatusExplainer')) {
                        $rowReason = transactionStatusExplainer($t);
                    }
                ?>
                <tr class="hover:bg-white/5 cursor-pointer <?= $isHighlightTxn ? 'bg-sky-500/10 ring-2 ring-sky-500/50' : '' ?>" onclick="location.href='<?= e(transactionDetailUrl($t['txn_id'])) ?>'"<?= $isHighlightTxn ? ' id="txn-highlight-row"' : '' ?>>
                    <td class="px-5 py-3 font-mono text-xs"><a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($t['txn_id']) ?></a></td>
                    <td class="px-5 py-3"><?= e(maskCustomerContact($t['customer_phone'] ?? null, $t['customer_name'] ?? null)) ?></td>
                    <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                    <td class="px-5 py-3 uppercase text-xs"><?= e($t['payment_method']) ?></td>
                    <td class="px-5 py-3 font-mono text-xs text-gray-500"><?= e($t['utr'] ?: '—') ?></td>
                    <td class="px-5 py-3">
                        <?php if ($rowReason): ?>
                        <div class="flex items-start gap-2" title="<?= e($rowReason['text']) ?>">
                            <span class="mt-1.5 w-1.5 h-1.5 rounded-full shrink-0 <?= $rowReason['tone'] === 'danger' ? 'bg-red-400' : ($rowReason['tone'] === 'warning' ? 'bg-amber-400' : 'bg-gray-500') ?>"></span>
                            <div>
                                <?= statusBadge($t['status']) ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <?= statusBadge($t['status']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-400 max-w-[220px] can-wrap">
                        <?php if ($rowReason && $rowReason['text'] !== ''): ?>
                        <span class="line-clamp-2" title="<?= e($rowReason['text']) ?>"><?= e($rowReason['text']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-600">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($t['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($highlightTxnId !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('txn-highlight-row');
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
