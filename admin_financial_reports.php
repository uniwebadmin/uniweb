<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'staff_manager']);
$db = getDB();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$summary = $db->prepare(
    "SELECT
        COUNT(*) AS txn_count,
        COALESCE(SUM(amount),0) AS gross,
        COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END),0) AS refunded_gross,
        COALESCE(SUM(platform_fee),0) AS fees,
        COALESCE(SUM(ROUND(platform_fee * 0.18, 2)),0) AS gst
     FROM transactions
     WHERE COALESCE(is_test,0)=0 AND status IN ('success','refunded')
       AND DATE(created_at) BETWEEN ? AND ?"
);
$summary->execute([$from, $to]);
$s = $summary->fetch() ?: [];

$settled = $db->prepare(
    "SELECT COALESCE(SUM(net_amount),0) FROM settlement_batches
     WHERE status='settled' AND DATE(COALESCE(bank_reconciled_at, created_at)) BETWEEN ? AND ?"
);
$settled->execute([$from, $to]);
$settledAmt = (float)$settled->fetchColumn();

$byMethod = $db->prepare(
    "SELECT COALESCE(payment_method,'unknown') AS method, COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt
     FROM transactions WHERE COALESCE(is_test,0)=0 AND status='success'
       AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY COALESCE(payment_method,'unknown') ORDER BY amt DESC"
);
$byMethod->execute([$from, $to]);
$methods = $byMethod->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financial_report_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['From', $from, 'To', $to]);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Txn count', (int)($s['txn_count'] ?? 0)]);
    fputcsv($out, ['Live gross', (float)($s['gross'] ?? 0)]);
    fputcsv($out, ['Fees', (float)($s['fees'] ?? 0)]);
    fputcsv($out, ['GST (est. 18% on fees)', (float)($s['gst'] ?? 0)]);
    fputcsv($out, ['Refunded gross', (float)($s['refunded_gross'] ?? 0)]);
    fputcsv($out, ['Settled net', $settledAmt]);
    fputcsv($out, []);
    fputcsv($out, ['Method', 'Count', 'Amount']);
    foreach ($methods as $m) {
        fputcsv($out, [$m['method'], (int)$m['c'], (float)$m['amt']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Financial Reports';
require_once __DIR__ . '/header.php';
?>
<div class="mb-6 flex flex-col sm:flex-row flex-wrap gap-3 items-stretch sm:items-end justify-between">
    <form method="get" class="flex flex-col sm:flex-row flex-wrap gap-3 items-stretch sm:items-end w-full sm:w-auto">
        <div class="min-w-0"><label class="text-xs text-gray-500">From</label><input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 w-full"></div>
        <div class="min-w-0"><label class="text-xs text-gray-500">To</label><input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 w-full"></div>
        <button class="btn-primary px-4 py-2.5 w-full sm:w-auto">Apply</button>
        <a href="?from=<?= e(urlencode($from)) ?>&to=<?= e(urlencode($to)) ?>&export=csv" class="glass px-4 py-2.5 rounded-xl text-sm text-center w-full sm:w-auto">Export CSV</a>
    </form>
    <a href="admin_chargebacks.php" class="glass px-4 py-2.5 rounded-xl text-sm text-center">Chargebacks</a>
</div>

<p class="text-xs text-gray-500 mb-4">Live (non-test) success/refund rows only. Staff access: super / CEO / ops / staff manager.</p>

<div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">Txn count</p><p class="text-xl font-bold mt-1"><?= (int)($s['txn_count'] ?? 0) ?></p></div>
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">Live gross</p><p class="text-lg sm:text-xl font-bold mt-1 break-words"><?= formatMoney((float)($s['gross'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">Fees</p><p class="text-lg sm:text-xl font-bold mt-1 break-words"><?= formatMoney((float)($s['fees'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">GST (est.)</p><p class="text-lg sm:text-xl font-bold mt-1 break-words"><?= formatMoney((float)($s['gst'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">Refunded gross</p><p class="text-lg sm:text-xl font-bold mt-1 break-words"><?= formatMoney((float)($s['refunded_gross'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-4 sm:p-5 min-w-0"><p class="text-xs text-gray-500">Settled net</p><p class="text-lg sm:text-xl font-bold mt-1 break-words"><?= formatMoney($settledAmt) ?></p></div>
</div>
<div class="glass rounded-xl overflow-hidden min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Volume by payment method</h2>
        <p class="text-xs text-gray-500 mt-1">Live success volume only. Range: <?= e($from) ?> → <?= e($to) ?></p>
    </div>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 sm:px-5 py-3 text-left">Method</th><th class="px-4 sm:px-5 py-3 text-left">Count</th><th class="px-4 sm:px-5 py-3 text-left">Amount</th></tr></thead>
        <tbody class="divide-y divide-gray-800">
        <?php if (!$methods): ?><tr><td colspan="3" class="px-5 py-8 text-center text-gray-500">No live volume in range.</td></tr><?php endif; ?>
        <?php foreach ($methods as $m): ?>
        <tr><td class="px-4 sm:px-5 py-3"><?= e($m['method']) ?></td><td class="px-4 sm:px-5 py-3"><?= (int)$m['c'] ?></td><td class="px-4 sm:px-5 py-3"><?= formatMoney((float)$m['amt']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
