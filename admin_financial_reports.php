<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'staff_manager']);
require_once __DIR__ . '/includes/admin_reports_ops.php';

$db = getDB();
$view = ($_GET['view'] ?? 'financial') === 'ops' ? 'ops' : 'financial';

if ($view === 'ops') {
    $days = (int)($_GET['days'] ?? 30);
    extract(adminReportsOpsData($db, $days));
    $pageTitle = 'Reports — Ops summary';
    require_once __DIR__ . '/header.php';
    ?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-2 text-xs" role="navigation" aria-label="Reports hub">
        <span class="text-gray-500 self-center mr-1">Reports:</span>
        <a href="admin_financial_reports.php?view=financial" class="px-3 py-1.5 rounded-lg text-gray-400 hover:text-white border border-gray-800">Date range</a>
        <a href="admin_financial_reports.php?view=ops" class="px-3 py-1.5 rounded-lg bg-brand-600/20 text-brand-400 border border-brand-500/30">Ops day summary</a>
    </div>
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Ops snapshot by day range. Money + GST detail: switch to <strong class="text-gray-300">Date range</strong> tab.</p>
        <form method="get" class="flex gap-2 items-center">
            <input type="hidden" name="view" value="ops">
            <select name="days" class="input-field text-xs w-32" onchange="this.form.submit()">
                <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
                <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
                <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Last 1 year</option>
            </select>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Transactions</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($txSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $txSummary['success'] ?> success · <?= $txSummary['failed'] ?> failed</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Volume</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($txSummary['volume']) ?></p><p class="text-xs text-gray-500 mt-1">Fees: <?= formatMoney($txSummary['fees']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Settlements</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= number_format($stlSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $stlSummary['completed'] ?> done · <?= $stlSummary['pending'] ?> pending</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Merchants</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= number_format($merchantSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $merchantSummary['new_this_period'] ?> new · <?= $merchantSummary['pending_kyc'] ?> KYC pending</p></div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Disputes (total)</p><p class="text-2xl font-bold <?= $disputeSummary['open'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($disputeSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $disputeSummary['open'] ?> open · <?= $disputeSummary['resolved'] ?> resolved</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Refunds</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($refundSummary['total']) ?></p><p class="text-xs text-gray-500 mt-1"><?= formatMoney($refundSummary['amount']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Support Tickets</p><p class="text-2xl font-bold <?= $supportSummary['open'] > 0 ? 'text-amber-400' : 'text-emerald-400' ?> mt-1"><?= number_format($supportSummary['open'] + $supportSummary['closed']) ?></p><p class="text-xs text-gray-500 mt-1"><?= $supportSummary['open'] ?> open · <?= $supportSummary['closed'] ?> closed</p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Success Rate</p><p class="text-2xl font-bold <?= ($txSummary['total'] > 0 ? ($txSummary['success'] / $txSummary['total'] * 100) : 100) >= 95 ? 'text-emerald-400' : 'text-amber-400' ?> mt-1"><?= $txSummary['total'] > 0 ? round($txSummary['success'] / $txSummary['total'] * 100, 1) : '100' ?>%</p></div>
    </div>

    <?php if (!empty($dailyTrend)): ?>
    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Daily Volume Trend (14 days)</h3>
        <div class="flex items-end gap-1 h-32">
            <?php
            $maxVol = max(array_column($dailyTrend, 'volume') ?: [1]);
            foreach ($dailyTrend as $d):
                $h = $maxVol > 0 ? max(2, ($d['volume'] / $maxVol) * 100) : 2;
            ?>
            <div class="flex-1 flex flex-col items-center gap-1">
                <div class="w-full bg-brand-500/30 rounded-t" style="height: <?= $h ?>%"></div>
                <span class="text-[10px] text-gray-500"><?= date('d', strtotime($d['d'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Top Merchants by Volume (<?= $days ?> days)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[500px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-right">Transactions</th><th class="px-4 py-3 text-right">Volume</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($topMerchants)): ?><tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">No data for this period.</td></tr>
                <?php else: foreach ($topMerchants as $m): ?>
                <tr><td class="px-4 py-3 text-xs"><?= e($m['business_name']) ?> <span class="font-mono text-gray-500"><?= e($m['merchant_code']) ?></span></td><td class="px-4 py-3 text-right text-xs"><?= number_format((int)$m['txn_count']) ?></td><td class="px-4 py-3 text-right text-xs text-emerald-400"><?= formatMoney((float)$m['volume']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

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

$testSummary = ['txn_count' => 0, 'gross' => 0.0];
try {
    $testSt = $db->prepare(
        "SELECT COUNT(*) AS txn_count, COALESCE(SUM(amount),0) AS gross
         FROM transactions
         WHERE COALESCE(is_test,0)=1 AND status IN ('success','refunded')
           AND DATE(created_at) BETWEEN ? AND ?"
    );
    $testSt->execute([$from, $to]);
    $testSummary = $testSt->fetch() ?: $testSummary;
} catch (Throwable $e) {
    /* ok */
}

$liveTxnCount = (int)($s['txn_count'] ?? 0);
$testTxnCount = (int)($testSummary['txn_count'] ?? 0);

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

$pageTitle = 'Reports — Financial';
require_once __DIR__ . '/header.php';
?>
<div class="flex flex-wrap gap-2 mb-4 text-xs" role="navigation" aria-label="Reports hub">
    <span class="text-gray-500 self-center mr-1">Reports:</span>
    <a href="admin_financial_reports.php?view=financial" class="px-3 py-1.5 rounded-lg bg-brand-600/20 text-brand-400 border border-brand-500/30">Date range</a>
    <a href="admin_financial_reports.php?view=ops" class="px-3 py-1.5 rounded-lg text-gray-400 hover:text-white border border-gray-800">Ops day summary</a>
</div>
<div class="mb-6 flex flex-col sm:flex-row flex-wrap gap-3 items-stretch sm:items-end justify-between">
    <form method="get" class="flex flex-col sm:flex-row flex-wrap gap-3 items-stretch sm:items-end w-full sm:w-auto">
        <input type="hidden" name="view" value="financial">
        <div class="min-w-0"><label class="text-xs text-gray-500">From</label><input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 w-full"></div>
        <div class="min-w-0"><label class="text-xs text-gray-500">To</label><input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 w-full"></div>
        <button class="btn-primary px-4 py-2.5 w-full sm:w-auto">Apply</button>
        <a href="?view=financial&from=<?= e(urlencode($from)) ?>&to=<?= e(urlencode($to)) ?>&export=csv" class="glass px-4 py-2.5 rounded-xl text-sm text-center w-full sm:w-auto">Export CSV</a>
    </form>
    <a href="admin_chargebacks.php" class="glass px-4 py-2.5 rounded-xl text-sm text-center">Chargebacks</a>
</div>

<p class="text-xs text-gray-500 mb-4">This tab counts <strong class="text-gray-300">live money only</strong> — real payments where <code class="text-gray-400">is_test=0</code>. Test Mode / UniWeb Test Pay is excluded (use <a href="?view=ops" class="text-sky-400 hover:underline">Ops day summary</a> for all activity including test).</p>

<?php if ($liveTxnCount === 0 && $testTxnCount > 0): ?>
<div class="glass rounded-xl p-4 mb-4 border border-amber-500/30 bg-amber-500/5 text-sm text-amber-100/90">
    <p class="font-semibold text-amber-200">No live volume in this date range</p>
    <p class="text-xs mt-1 text-amber-100/80">Test Mode activity in the same range: <strong><?= number_format($testTxnCount) ?></strong> payment(s) · <?= formatMoney((float)($testSummary['gross'] ?? 0)) ?>. Live gross stays ₹0 until merchants go live and real partner money flows. Check <a href="gateway_settings.php#live-money-switches" class="text-sky-300 hover:underline">Platform Settings → Live Money Switches</a>.</p>
</div>
<?php elseif ($liveTxnCount === 0 && $testTxnCount === 0): ?>
<div class="glass rounded-xl p-4 mb-4 border border-gray-700 text-sm text-gray-400">
    <p>No payments (live or test) in <?= e($from) ?> → <?= e($to) ?>. Try a wider range or use <a href="?view=ops" class="text-sky-400 hover:underline">Ops day summary</a>.</p>
</div>
<?php endif; ?>

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
