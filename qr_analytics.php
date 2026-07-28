<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/qr_events.php';
requireLogin();
ensureMerchantQrCodes();

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$db = getDB();
$isTest = isMerchantPaymentTest($merchant);

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 14, 30, 90], true)) {
    $days = 30;
}
$since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

// ---- KPI summary ----
$summary = $db->prepare("SELECT
        COUNT(*) AS total_qrs,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_qrs,
        COALESCE(SUM(scan_count),0) AS total_scans
    FROM merchant_qr_codes
    WHERE merchant_id=? AND is_test=? AND qr_type != 'instant_upi'");
$summary->execute([$merchantId, $isTest ? 1 : 0]);
$kpi = $summary->fetch() ?: ['total_qrs' => 0, 'active_qrs' => 0, 'total_scans' => 0];

$collected = $db->prepare("SELECT COUNT(*) AS paid_count, COALESCE(SUM(t.amount),0) AS paid_total
    FROM transactions t
    JOIN payment_links pl ON pl.id = t.payment_link_id
    JOIN merchant_qr_codes q ON q.id = pl.qr_code_id
    WHERE t.merchant_id=? AND t.is_test=? AND t.status='success' AND q.qr_type != 'instant_upi'");
$collected->execute([$merchantId, $isTest ? 1 : 0]);
$paid = $collected->fetch() ?: ['paid_count' => 0, 'paid_total' => 0];

$totalScans = (int)$kpi['total_scans'];
$paidCount = (int)$paid['paid_count'];
$conversion = $totalScans > 0 ? round(($paidCount / $totalScans) * 100, 1) : null;

// ---- Daily trend: scans vs successful payments ----
$scanTrend = $db->prepare("SELECT DATE(e.created_at) AS d, COUNT(*) AS c
    FROM qr_code_events e
    JOIN merchant_qr_codes q ON q.id = e.qr_code_id
    WHERE q.merchant_id=? AND q.is_test=? AND q.qr_type != 'instant_upi' AND e.event_type='scan' AND DATE(e.created_at) >= ?
    GROUP BY DATE(e.created_at) ORDER BY d");
$scanTrend->execute([$merchantId, $isTest ? 1 : 0, $since]);
$scanTrendData = $scanTrend->fetchAll();

$payTrend = $db->prepare("SELECT DATE(t.created_at) AS d, COUNT(*) AS c, COALESCE(SUM(t.amount),0) AS amt
    FROM transactions t
    JOIN payment_links pl ON pl.id = t.payment_link_id
    JOIN merchant_qr_codes q ON q.id = pl.qr_code_id
    WHERE t.merchant_id=? AND t.is_test=? AND t.status='success' AND q.qr_type != 'instant_upi' AND DATE(t.created_at) >= ?
    GROUP BY DATE(t.created_at) ORDER BY d");
$payTrend->execute([$merchantId, $isTest ? 1 : 0, $since]);
$payTrendData = $payTrend->fetchAll();

// Merge into one date-indexed series so the chart has a value for every day in range.
$scanByDate = [];
foreach ($scanTrendData as $r) {
    $scanByDate[$r['d']] = (int)$r['c'];
}
$payByDate = [];
$amtByDate = [];
foreach ($payTrendData as $r) {
    $payByDate[$r['d']] = (int)$r['c'];
    $amtByDate[$r['d']] = (float)$r['amt'];
}
$labels = [];
$scanSeries = [];
$paySeries = [];
$amtSeries = [];
for ($i = 0; $i < $days; $i++) {
    $d = date('Y-m-d', strtotime($since . " +{$i} days"));
    $labels[] = date('d M', strtotime($d));
    $scanSeries[] = $scanByDate[$d] ?? 0;
    $paySeries[] = $payByDate[$d] ?? 0;
    $amtSeries[] = round($amtByDate[$d] ?? 0, 2);
}

// ---- Top performing QR codes ----
$top = $db->prepare("SELECT q.id, q.qr_code, q.label, q.qr_type, q.amount, q.scan_count, q.status,
        COALESCE(paid.cnt,0) AS paid_count, COALESCE(paid.total,0) AS paid_total
    FROM merchant_qr_codes q
    LEFT JOIN (
        SELECT pl.qr_code_id, COUNT(*) AS cnt, SUM(t.amount) AS total
        FROM transactions t JOIN payment_links pl ON pl.id = t.payment_link_id
        WHERE t.merchant_id=? AND t.is_test=? AND t.status='success'
        GROUP BY pl.qr_code_id
    ) paid ON paid.qr_code_id = q.id
    WHERE q.merchant_id=? AND q.is_test=? AND q.qr_type != 'instant_upi'
    ORDER BY paid_total DESC, q.scan_count DESC
    LIMIT 10");
$top->execute([$merchantId, $isTest ? 1 : 0, $merchantId, $isTest ? 1 : 0]);
$topQrCodes = $top->fetchAll();

$qrTypeLabels = ['fixed' => 'Fixed Amount', 'upi_dynamic' => 'Dynamic UPI', 'all_methods' => 'All Methods'];

$pageTitle = 'QR Analytics';
require_once __DIR__ . '/header.php';
$hasData = $totalScans > 0 || $paidCount > 0 || (int)$kpi['total_qrs'] > 0;
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-xl font-bold">QR Analytics</h1>
        <p class="text-xs text-gray-500 mt-1">Scan and collection performance across your checkout QR codes.</p>
    </div>
    <div class="flex items-center gap-2">
        <form method="GET" class="flex gap-2">
            <select name="days" onchange="this.form.submit()" class="input-field text-sm">
                <?php foreach ([7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= $days === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="qr_code.php" class="text-sm text-sky-400 hover:underline">Manage QR codes →</a>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="glass rounded-xl p-4 border border-gray-800"><p class="text-xs text-gray-500">Total QR codes</p><p class="text-2xl font-bold mt-1"><?= (int)$kpi['total_qrs'] ?></p></div>
    <div class="glass rounded-xl p-4 border border-gray-800"><p class="text-xs text-gray-500">Active</p><p class="text-2xl font-bold mt-1 text-emerald-400"><?= (int)$kpi['active_qrs'] ?></p></div>
    <div class="glass rounded-xl p-4 border border-sky-500/20"><p class="text-xs text-gray-500">Total scans</p><p class="text-2xl font-bold mt-1 text-sky-400"><?= $totalScans ?></p></div>
    <div class="glass rounded-xl p-4 border border-emerald-500/20"><p class="text-xs text-gray-500">Successful payments</p><p class="text-2xl font-bold mt-1 text-emerald-400"><?= $paidCount ?></p></div>
    <div class="glass rounded-xl p-4 border border-violet-500/20"><p class="text-xs text-gray-500">Scan-to-pay</p><p class="text-2xl font-bold mt-1 text-violet-400"><?= $conversion === null ? '—' : $conversion . '%' ?></p></div>
</div>

<div class="glass rounded-xl p-4 border border-gray-800 mb-6">
    <p class="text-xs text-gray-500">Total collected via QR (<?= (int)$days ?> days &amp; all-time combined by QR list below)</p>
    <p class="text-2xl font-bold mt-1 text-brand-400"><?= formatMoney((float)$paid['paid_total']) ?></p>
</div>

<?php if (!$hasData): ?>
<div class="mb-6">
    <?= renderMerchantEmptyState(
        'No QR activity yet',
        'Create a QR code and get it scanned to see analytics here.',
        'qr_code.php',
        'Create QR code →'
    ) ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<div class="grid lg:grid-cols-2 gap-6 mb-6 <?= $hasData ? '' : 'opacity-60' ?>">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Scans vs Payments (<?= (int)$days ?> days)</h2>
        <div class="chart-box"><canvas id="scanPayChart"></canvas></div>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Daily Collection (<?= (int)$days ?> days)</h2>
        <div class="chart-box"><canvas id="collectionChart"></canvas></div>
    </div>
</div>
<style>.chart-box{position:relative;height:260px;width:100%}.chart-box canvas{max-width:100%!important}</style>
<script>
const brand='#10b981', sky='#0ea5e9';
const commonOpts={responsive:true,maintainAspectRatio:false};
new Chart(document.getElementById('scanPayChart'),{type:'line',data:{labels:<?= json_encode($labels) ?>,datasets:[
    {label:'Scans',data:<?= json_encode($scanSeries) ?>,borderColor:sky,backgroundColor:'rgba(14,165,233,.1)',fill:true,tension:.3},
    {label:'Payments',data:<?= json_encode($paySeries) ?>,borderColor:brand,backgroundColor:'rgba(16,185,129,.1)',fill:true,tension:.3}
]},options:{...commonOpts,plugins:{legend:{position:'bottom',labels:{color:'#9ca3af'}}},scales:{y:{ticks:{color:'#9ca3af'}},x:{ticks:{color:'#9ca3af'}}}}});
new Chart(document.getElementById('collectionChart'),{type:'bar',data:{labels:<?= json_encode($labels) ?>,datasets:[{label:'<?= CURRENCY_SYMBOL ?>',data:<?= json_encode($amtSeries) ?>,backgroundColor:brand}]},options:{...commonOpts,plugins:{legend:{display:false}},scales:{y:{ticks:{color:'#9ca3af'}},x:{ticks:{color:'#9ca3af'}}}}});
</script>

<div class="glass rounded-xl p-6 border border-gray-800">
    <h2 class="font-semibold mb-4">Top performing QR codes</h2>
    <?php if (empty($topQrCodes)): ?>
    <p class="text-sm text-gray-500">No QR codes yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-800">
                <th class="py-2 pr-3">QR</th>
                <th class="py-2 pr-3">Type</th>
                <th class="py-2 pr-3">Status</th>
                <th class="py-2 pr-3 text-right">Scans</th>
                <th class="py-2 pr-3 text-right">Payments</th>
                <th class="py-2 pr-3 text-right">Scan-to-pay</th>
                <th class="py-2 pr-3 text-right">Collected</th>
            </tr></thead>
            <tbody>
                <?php foreach ($topQrCodes as $qr):
                    $scans = (int)$qr['scan_count'];
                    $pcount = (int)$qr['paid_count'];
                    $conv = $scans > 0 ? round(($pcount / $scans) * 100, 1) : null;
                ?>
                <tr class="border-b border-gray-900">
                    <td class="py-2 pr-3"><?= e($qr['label']) ?><br><span class="text-[10px] text-gray-600"><?= e($qr['qr_code']) ?></span></td>
                    <td class="py-2 pr-3 text-gray-400"><?= e($qrTypeLabels[$qr['qr_type']] ?? $qr['qr_type']) ?></td>
                    <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded text-[11px] <?= $qr['status'] === 'active' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-gray-700/40 text-gray-400' ?>"><?= e(ucfirst($qr['status'])) ?></span></td>
                    <td class="py-2 pr-3 text-right"><?= $scans ?></td>
                    <td class="py-2 pr-3 text-right"><?= $pcount ?></td>
                    <td class="py-2 pr-3 text-right"><?= $conv === null ? '—' : $conv . '%' ?></td>
                    <td class="py-2 pr-3 text-right text-brand-400 font-medium"><?= formatMoney((float)$qr['paid_total']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
