<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'staff_manager']);
$db = getDB();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');

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

$pageTitle = 'Financial Reports';
require_once __DIR__ . '/header.php';
?>
<div class="mb-6 flex flex-wrap gap-3 items-end">
    <form method="get" class="flex flex-wrap gap-3 items-end">
        <div><label class="text-xs text-gray-500">From</label><input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1"></div>
        <div><label class="text-xs text-gray-500">To</label><input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1"></div>
        <button class="btn-primary px-4 py-2">Apply</button>
    </form>
    <a href="admin_chargebacks.php" class="glass px-4 py-2 rounded-xl text-sm">Chargebacks</a>
</div>
<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <div class="glass rounded-xl p-5"><p class="text-xs text-gray-500">Live gross</p><p class="text-xl font-bold mt-1"><?= formatMoney((float)($s['gross'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-5"><p class="text-xs text-gray-500">Fees</p><p class="text-xl font-bold mt-1"><?= formatMoney((float)($s['fees'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-5"><p class="text-xs text-gray-500">GST</p><p class="text-xl font-bold mt-1"><?= formatMoney((float)($s['gst'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-5"><p class="text-xs text-gray-500">Refunded gross</p><p class="text-xl font-bold mt-1"><?= formatMoney((float)($s['refunded_gross'] ?? 0)) ?></p></div>
    <div class="glass rounded-xl p-5"><p class="text-xs text-gray-500">Settled net</p><p class="text-xl font-bold mt-1"><?= formatMoney($settledAmt) ?></p></div>
</div>
<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Settlement breakup by method</h2><p class="text-xs text-gray-500 mt-1">Live success volume only. Reserves/adjustments appear when partner settlement files include them.</p></div>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-5 py-3 text-left">Method</th><th class="px-5 py-3 text-left">Count</th><th class="px-5 py-3 text-left">Amount</th></tr></thead>
        <tbody class="divide-y divide-gray-800">
        <?php if (!$methods): ?><tr><td colspan="3" class="px-5 py-8 text-center text-gray-500">No live volume in range.</td></tr><?php endif; ?>
        <?php foreach ($methods as $m): ?>
        <tr><td class="px-5 py-3"><?= e($m['method']) ?></td><td class="px-5 py-3"><?= (int)$m['c'] ?></td><td class="px-5 py-3"><?= formatMoney((float)$m['amt']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
