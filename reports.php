<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$mid = $merchant['id'];

$daily = $db->prepare("SELECT DATE(created_at) as d, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d");
$daily->execute([$mid]); $dailyData = $daily->fetchAll();

$methods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE merchant_id=? AND status='success' GROUP BY payment_method");
$methods->execute([$mid]); $methodData = $methods->fetchAll();

$statuses = $db->prepare("SELECT status, COUNT(*) as cnt FROM transactions WHERE merchant_id=? GROUP BY status");
$statuses->execute([$mid]); $statusData = $statuses->fetchAll();

$monthly = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as m, COALESCE(SUM(amount),0) as total FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY m ORDER BY m");
$monthly->execute([$mid]); $monthlyData = $monthly->fetchAll();

$pageTitle = __('reports');
require_once __DIR__ . '/header.php';
$hasData = !empty($dailyData) || !empty($methodData) || !empty($statusData) || !empty($monthlyData);
?>
<?php if (!$hasData): ?>
<div class="mb-6">
    <?= renderMerchantEmptyState(
        'No report data yet',
        'Complete a ₹1 test payment to see daily collections, methods, and trends here.',
        'demo.php',
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
