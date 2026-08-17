<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
ensurePaymentLinkAnalytics();
$mid = $merchant['id'];

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$method = trim($_GET['method'] ?? 'all');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
$validMethods = ['upi','card','netbanking','wallet','razorpay','cashfree','payu'];
if ($method !== 'all' && !in_array($method, $validMethods, true)) $method = 'all';

$dateWhere = 'merchant_id = ?';
$dateParams = [$mid];
if ($from !== '') { $dateWhere .= ' AND DATE(created_at) >= ?'; $dateParams[] = $from; }
if ($to !== '') { $dateWhere .= ' AND DATE(created_at) <= ?'; $dateParams[] = $to; }

$dailyFrom = $from !== '' ? $from : date('Y-m-d', strtotime('-29 days'));
$dailyWhere = "merchant_id = ? AND status = 'success' AND DATE(created_at) >= ?";
$dailyParams = [$mid, $dailyFrom];
if ($to !== '') { $dailyWhere .= ' AND DATE(created_at) <= ?'; $dailyParams[] = $to; }
$daily = $db->prepare("SELECT DATE(created_at) as d, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM transactions WHERE $dailyWhere GROUP BY DATE(created_at) ORDER BY d");
$daily->execute($dailyParams); $dailyData = $daily->fetchAll();

$methodWhere = $dateWhere . " AND status = 'success'";
$methodParams = $dateParams;
if ($method !== 'all') { $methodWhere .= ' AND payment_method = ?'; $methodParams[] = $method; }
$methods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE $methodWhere GROUP BY payment_method");
$methods->execute($methodParams); $methodData = $methods->fetchAll();

$statuses = $db->prepare("SELECT status, COUNT(*) as cnt FROM transactions WHERE $dateWhere GROUP BY status");
$statuses->execute($dateParams); $statusData = $statuses->fetchAll();

$monthlyFrom = $from !== '' ? $from : date('Y-m-01', strtotime('-5 months'));
$monthlyWhere = "merchant_id = ? AND status = 'success' AND DATE(created_at) >= ?";
$monthlyParams = [$mid, $monthlyFrom];
if ($to !== '') { $monthlyWhere .= ' AND DATE(created_at) <= ?'; $monthlyParams[] = $to; }
$monthly = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as m, COALESCE(SUM(amount),0) as total FROM transactions WHERE $monthlyWhere GROUP BY m ORDER BY m");
$monthly->execute($monthlyParams); $monthlyData = $monthly->fetchAll();

$linkWhere = 'pl.merchant_id = ?';
$linkParams = [$mid];
if ($from !== '') { $linkWhere .= ' AND DATE(pl.created_at) >= ?'; $linkParams[] = $from; }
if ($to !== '') { $linkWhere .= ' AND DATE(pl.created_at) <= ?'; $linkParams[] = $to; }
if ($method !== 'all') {
    $linkMethodMap = [
        'upi' => ['upi_p2m', 'payu_upi'],
        'card' => ['debit_card', 'credit_card'],
        'netbanking' => ['netbanking'],
        'wallet' => ['wallet'],
        'razorpay' => ['razorpay'],
        'cashfree' => ['cashfree'],
        'payu' => ['payu_upi'],
    ];
    $linkMethods = $linkMethodMap[$method] ?? [$method];
    $linkWhere .= ' AND pl.payment_method IN (' . implode(',', array_fill(0, count($linkMethods), '?')) . ')';
    array_push($linkParams, ...$linkMethods);
}
$checkout = $db->prepare("SELECT COUNT(*) AS links, COALESCE(SUM(pl.view_count),0) AS views, COALESCE(SUM((SELECT COUNT(*) FROM transactions t WHERE t.payment_link_id=pl.id AND t.status='success')),0) AS paid FROM payment_links pl WHERE {$linkWhere}");
$checkout->execute($linkParams);
$checkoutData = $checkout->fetch() ?: ['links' => 0, 'views' => 0, 'paid' => 0];
$failureWhere = $dateWhere . " AND status IN ('failed','error')";
$failureParams = $dateParams;
if ($method !== 'all') { $failureWhere .= ' AND payment_method = ?'; $failureParams[] = $method; }
try {
    $failureReasons = $db->prepare("SELECT COALESCE(NULLIF(TRIM(failure_reason),''),'No partner reason received') AS reason, COUNT(*) AS cnt FROM transactions WHERE {$failureWhere} GROUP BY reason ORDER BY cnt DESC LIMIT 3");
    $failureReasons->execute($failureParams);
    $failureReasonData = $failureReasons->fetchAll();
} catch (Throwable $e) {
    $failureReasonData = [];
}

$pageTitle = __('reports');
require_once __DIR__ . '/header.php';
echo renderPrintStylesheet();
$hasData = !empty($dailyData) || !empty($methodData) || !empty($statusData) || !empty($monthlyData);
?>
<form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div>
        <label class="text-[10px] text-gray-600 uppercase">From</label>
        <input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 text-sm">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">To</label>
        <input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 text-sm">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Method</label>
        <select name="method" class="input-field mt-1 text-sm">
            <option value="all" <?= $method === 'all' ? 'selected' : '' ?>>All methods</option>
            <?php foreach (['upi'=>'UPI','card'=>'Card','netbanking'=>'Netbanking','wallet'=>'Wallet','razorpay'=>'Razorpay','cashfree'=>'Cashfree','payu'=>'PayU'] as $mk => $ml): ?>
            <option value="<?= e($mk) ?>" <?= $method === $mk ? 'selected' : '' ?>><?= e($ml) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
    <a href="reports.php" class="text-sm text-gray-500 hover:text-white px-2 py-2.5">Reset</a>
    <?= renderExportCsvLink('export_reports.php?' . http_build_query(['from' => $from, 'to' => $to, 'method' => $method]), 'Download CSV (date range)') ?>
    <a href="export_accounting.php?<?= http_build_query(['from' => $from, 'to' => $to]) ?>" class="text-xs text-violet-300 hover:text-violet-200 px-2 py-2.5">Tally Accounting CSV</a>
</form>
<?php
$checkoutViews = (int)($checkoutData['views'] ?? 0);
$checkoutPaid = (int)($checkoutData['paid'] ?? 0);
$checkoutConversion = $checkoutViews > 0 ? round($checkoutPaid / $checkoutViews * 100, 1) : null;
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="glass rounded-xl p-4 border border-gray-800"><p class="text-xs text-gray-500">Checkout links</p><p class="text-2xl font-bold mt-1"><?= (int)($checkoutData['links'] ?? 0) ?></p></div>
    <div class="glass rounded-xl p-4 border border-violet-500/20"><p class="text-xs text-gray-500">Checkout views</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= $checkoutViews ?></p></div>
    <div class="glass rounded-xl p-4 border border-emerald-500/20"><p class="text-xs text-gray-500">Successful payments</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= $checkoutPaid ?></p></div>
    <div class="glass rounded-xl p-4 border border-sky-500/20"><p class="text-xs text-gray-500">View-to-paid</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= $checkoutConversion === null ? '—' : $checkoutConversion . '%' ?></p></div>
</div>
<div class="glass rounded-xl p-5 mb-6 border border-gray-800">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-semibold">Top payment failure reasons</h2><a href="transactions.php?status=failed" class="text-xs text-sky-400 hover:underline">View failed payments →</a></div>
    <?php if ($failureReasonData): ?><ul class="space-y-2 text-sm"><?php foreach ($failureReasonData as $reason): ?><li class="flex gap-3"><span class="text-red-400 font-semibold"><?= (int)$reason['cnt'] ?>×</span><span class="text-gray-300"><?= e($reason['reason']) ?></span></li><?php endforeach; ?></ul><?php else: ?><p class="text-sm text-gray-500">No failed payments in this period.</p><?php endif; ?>
</div>
<?php if (!$hasData): ?>
<div class="mb-6">
    <?= renderMerchantEmptyState(
        'No report data yet',
        'Complete a ₹1 test payment to see daily collections, methods, and trends here.',
        'merchant_register.php',
        'Pay ₹1 test →'
    ) ?>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<div class="grid lg:grid-cols-2 gap-6 mb-6 <?= $hasData ? '' : 'opacity-60' ?>">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Daily Collection (30 Days)</h2>
        <div class="chart-box"><canvas id="dailyChart"></canvas></div>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Payment Methods</h2>
        <div class="chart-box"><canvas id="methodChart"></canvas></div>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Transaction Status</h2>
        <div class="chart-box"><canvas id="statusChart"></canvas></div>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Monthly Trend (6 Months)</h2>
        <div class="chart-box"><canvas id="monthlyChart"></canvas></div>
    </div>
</div>
<style>.chart-box{position:relative;height:260px;width:100%}.chart-box canvas{max-width:100%!important}</style>
<script>
const brand='#10b981', cyan='#06b6d4', amber='#f59e0b', red='#ef4444';
const commonOpts={responsive:true,maintainAspectRatio:false};
new Chart(document.getElementById('dailyChart'),{type:'line',data:{labels:<?= json_encode(array_column($dailyData,'d')) ?>,datasets:[{label:'<?= CURRENCY_SYMBOL ?>',data:<?= json_encode(array_map(fn($r)=>(float)$r['total'],$dailyData)) ?>,borderColor:brand,backgroundColor:'rgba(16,185,129,.1)',fill:true,tension:.3}]},options:{...commonOpts,plugins:{legend:{display:false}},scales:{y:{ticks:{color:'#9ca3af'}},x:{ticks:{color:'#9ca3af'}}}}});
new Chart(document.getElementById('methodChart'),{type:'doughnut',data:{labels:<?= json_encode(array_column($methodData,'payment_method')) ?>,datasets:[{data:<?= json_encode(array_map(fn($r)=>(float)$r['total'],$methodData)) ?>,backgroundColor:[brand,cyan,amber,red,'#8b5cf6']}]},options:{...commonOpts,plugins:{legend:{position:'bottom',labels:{color:'#9ca3af',boxWidth:12,font:{size:11}}}}}});
new Chart(document.getElementById('statusChart'),{type:'bar',data:{labels:<?= json_encode(array_column($statusData,'status')) ?>,datasets:[{data:<?= json_encode(array_map(fn($r)=>(int)$r['cnt'],$statusData)) ?>,backgroundColor:[brand,amber,red,'#6b7280']}]},options:{...commonOpts,plugins:{legend:{display:false}},scales:{y:{ticks:{color:'#9ca3af'}},x:{ticks:{color:'#9ca3af'}}}}});
new Chart(document.getElementById('monthlyChart'),{type:'bar',data:{labels:<?= json_encode(array_column($monthlyData,'m')) ?>,datasets:[{label:'<?= CURRENCY_SYMBOL ?>',data:<?= json_encode(array_map(fn($r)=>(float)$r['total'],$monthlyData)) ?>,backgroundColor:cyan}]},options:{...commonOpts,plugins:{legend:{display:false}},scales:{y:{ticks:{color:'#9ca3af'}},x:{ticks:{color:'#9ca3af'}}}}});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
