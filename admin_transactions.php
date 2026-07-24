<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
$db = getDB();

if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $id = (int)$_GET['id'];
    $txn = $db->prepare('SELECT merchant_id, amount, txn_id, status FROM transactions WHERE id=?');
    $txn->execute([$id]);
    $t = $txn->fetch();
    if (!$t) {
        flash('error', 'Transaction not found.');
        redirect('admin_transactions.php');
    }
    requireMerchantAccess((int)$t['merchant_id']);
    if ($_GET['action'] === 'approve') {
        if ($t['status'] === 'pending' && approvePendingTransaction($id)) {
            createNotification((int)$t['merchant_id'], 'Payment Approved', formatMoney((float)$t['amount']) . ' credited to wallet — ' . $t['txn_id']);
            logStaffActivity('txn_approved', $t['txn_id'] . ' ' . formatMoney((float)$t['amount']), (int)$t['merchant_id'], 'transaction', $t['txn_id']);
        }
    } elseif ($_GET['action'] === 'reject') {
        if ($t['status'] === 'pending') {
            $db->prepare("UPDATE transactions SET status='failed' WHERE id=?")->execute([$id]);
            logStaffActivity('txn_rejected', $t['txn_id'], (int)$t['merchant_id'], 'transaction', $t['txn_id']);
        }
    }
    flash('success', 'Transaction updated.');
    redirect('admin_transactions.php');
}

$filter = trim($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'success', 'failed', 'refunded'], true)) $filter = 'all';
$merchantFilter = (int)($_GET['merchant_id'] ?? 0);
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$method = trim($_GET['method'] ?? 'all');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$where = '1=1'; $params = [];
if ($filter !== 'all') { $where .= ' AND t.status = ?'; $params[] = $filter; }
if ($merchantFilter > 0) { $where .= ' AND t.merchant_id = ?'; $params[] = $merchantFilter; }
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (
        LOWER(TRIM(COALESCE(t.txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.utr,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(t.customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.customer_phone,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(t.customer_email,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(m.merchant_code,''))) LIKE ? OR CAST(t.amount AS CHAR) LIKE ? OR
        LOWER(CAST(COALESCE(t.metadata,'') AS CHAR)) LIKE ? OR
        t.payment_link_id IN (SELECT id FROM payment_links WHERE LOWER(TRIM(link_id)) LIKE ?)
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($method !== 'all' && in_array($method, ['upi','card','netbanking','wallet','qr','razorpay','cashfree','payu'], true)) {
    $where .= ' AND t.payment_method = ?'; $params[] = $method;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $from; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $to; }
$stmt = $db->prepare("SELECT t.*, m.business_name, m.merchant_code FROM transactions t JOIN merchants m ON t.merchant_id=m.id WHERE $where ORDER BY t.created_at DESC LIMIT 50");
$stmt->execute($params);
$transactions = $stmt->fetchAll();
if (!isSuperAdmin()) {
    $transactions = array_values(array_filter($transactions, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
}
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($transactions as $t) {
        $csvRows[] = [$t['txn_id'] ?? '', $t['business_name'] ?? '', $t['amount'] ?? '', $t['utr'] ?? '', $t['status'] ?? '', $t['created_at'] ?? ''];
    }
    sendCsvDownload(['Txn ID', 'Merchant', 'Amount', 'UTR', 'Status', 'Date'], $csvRows, 'admin-transactions-' . date('Y-m-d') . '.csv');
}
$filterMerchant = null;
if ($merchantFilter > 0) {
    $fm = $db->prepare('SELECT merchant_code, business_name FROM merchants WHERE id=?');
    $fm->execute([$merchantFilter]);
    $filterMerchant = $fm->fetch() ?: null;
}
$pageTitle = 'All Transactions';
require_once __DIR__ . '/header.php';
?>
<?php if ($filterMerchant): ?>
<div class="mb-4 glass rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-2 border border-sky-500/30">
    <p class="text-sm text-gray-300">Transactions for <span class="font-mono text-sky-400"><?= e($filterMerchant['merchant_code']) ?></span> — <?= e($filterMerchant['business_name']) ?></p>
    <a href="admin_transactions.php" class="text-xs text-gray-400 hover:text-white">Clear filter</a>
</div>
<?php endif; ?>
<?= uxListToolbar(uxExportCsvLink(array_filter(['status' => $filter !== 'all' ? $filter : null, 'merchant_id' => $merchantFilter ?: null, 'q' => $q ?: null, 'method' => $method !== 'all' ? $method : null, 'from' => $from ?: null, 'to' => $to ?: null]))) ?>
<div class="flex gap-2 mb-6 flex-wrap no-print">
    <?php foreach (['all'=>'All','pending'=>'Pending','success'=>'Success','failed'=>'Failed'] as $k=>$l):
        $qs = 'status=' . $k . ($merchantFilter > 0 ? '&merchant_id=' . $merchantFilter : '');
    ?>
    <a href="?<?= $qs ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $filter===$k?'bg-red-600 text-white':'bg-dark-900 text-gray-400' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>
<form method="GET" data-live-search-form data-results-target="admin-transaction-results" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <input type="hidden" name="status" value="<?= e($filter) ?>">
    <?php if ($merchantFilter): ?><input type="hidden" name="merchant_id" value="<?= $merchantFilter ?>"><?php endif; ?>
    <div class="flex-1 min-w-[220px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Txn / Order / UTR / Amount / Customer / Mobile / Email / Merchant" autocomplete="off"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Method</label><select name="method" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','upi'=>'UPI','card'=>'Card','netbanking'=>'Netbanking','wallet'=>'Wallet','razorpay'=>'Razorpay','cashfree'=>'Cashfree','payu'=>'PayU'] as $mk=>$ml): ?><option value="<?= $mk ?>" <?= $method===$mk?'selected':'' ?>><?= $ml ?></option><?php endforeach; ?></select></div>
    <div><label class="text-[10px] text-gray-600 uppercase">From</label><input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 text-sm"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">To</label><input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 text-sm"></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>
<div id="admin-transaction-results" class="glass rounded-xl overflow-hidden">
    <?php if (empty($transactions)): ?>
    <?= uxEmptyState('No transactions match', 'Try clearing filters or widening the date range.') ?>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm min-w-[640px]">
        <?= uxTableCaption('Admin transaction list') ?>
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Txn ID</th><th class="px-5 py-3 text-left">Merchant</th>
            <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">UTR</th>
            <th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Actions</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($transactions as $t): ?>
            <tr<?= uiRowClick(transactionDetailUrl($t['txn_id'])) ?>>
                <td class="px-5 py-3 font-mono text-xs"><?= txnDetailLink($t['txn_id']) ?></td>
                <td class="px-5 py-3">
                    <a href="admin_view_merchant.php?id=<?= (int)$t['merchant_id'] ?>" class="hover:text-sky-300">
                        <p><?= e($t['business_name']) ?></p>
                        <p class="text-xs text-sky-400 font-mono"><?= e($t['merchant_code']) ?></p>
                    </a>
                </td>
                <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                <td class="px-5 py-3 font-mono text-xs"><?= e($t['utr'] ?: '—') ?></td>
                <td class="px-5 py-3"><?= statusBadge($t['status']) ?></td>
                <td class="px-5 py-3 whitespace-nowrap"<?= uiStopClick() ?>>
                    <?php if ($t['status'] === 'pending'): ?>
                    <a href="?action=approve&id=<?= $t['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400 mr-2">Approve</a>
                    <a href="?action=reject&id=<?= $t['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-red-400 mr-2">Reject</a>
                    <?php endif; ?>
                    <?php if ($t['merchant_id']): ?>
                    <a href="admin_view_merchant.php?id=<?= (int)$t['merchant_id'] ?>" class="text-xs text-sky-400">Contact</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
