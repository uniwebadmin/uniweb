<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);
$db = getDB();
$batchId = isset($_GET['batch']) ? (int)$_GET['batch'] : 0;
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);

if ($batchId > 0) {
    $st = $db->prepare('SELECT sb.*, m.business_name FROM settlement_batches sb JOIN merchants m ON sb.merchant_id=m.id WHERE sb.id=?');
    $st->execute([$batchId]);
    $batch = $st->fetch();
    $items = $batch ? getBatchItems($batchId) : [];
}

$allSql = 'SELECT sb.*, m.business_name FROM settlement_batches sb JOIN merchants m ON sb.merchant_id=m.id';
$allParams = [];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $allSql .= ' WHERE (LOWER(TRIM(COALESCE(sb.batch_code,\'\'))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,\'\'))) LIKE ?)';
    $allParams = [$like, $like];
}
$allSql .= ' ORDER BY sb.created_at DESC LIMIT 100';
$allStmt = $db->prepare($allSql);
$allStmt->execute($allParams);
$all = $allStmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($all as $b) {
        $csvRows[] = [$b['batch_code'] ?? '', $b['business_name'] ?? '', $b['batch_type'] ?? '', $b['net_amount'] ?? '', $b['status'] ?? '', $b['created_at'] ?? ''];
    }
    sendCsvDownload(['Batch', 'Merchant', 'Type', 'Net', 'Status', 'Date'], $csvRows, 'settlement-batches-' . date('Y-m-d') . '.csv');
}

$pageTitle = 'Settlement Batches';
require_once __DIR__ . '/header.php';
?>

<div class="mb-4 flex flex-wrap gap-3 items-center justify-between no-print">
    <a href="admin_settlement_settings.php" class="text-sm text-gray-400 hover:text-white">← Settlement Engine</a>
    <?= uxListToolbar(uxExportCsvLink(array_filter(['q' => $q ?: null, 'batch' => $batchId ?: null]))) ?>
</div>

<?php if (!empty($batch)): ?>
<div class="glass rounded-xl p-6 mb-8 border border-violet-500/20">
    <div class="flex flex-wrap justify-between gap-4 mb-4">
        <div>
            <h2 class="text-xl font-bold font-mono"><?= e($batch['batch_code']) ?></h2>
            <p class="text-sm text-gray-500"><?= e($batch['business_name']) ?> · <?= e($batch['batch_type']) ?> · <?= settlementBatchStatusBadge($batch['status']) ?></p>
            <?php
            $batchReason = trim((string)($batch['api_message'] ?? $batch['failure_reason'] ?? ''));
            if ($batchReason === '' && strtolower((string)$batch['status']) === 'failed') {
                $batchReason = 'Failed — bank or payout API error. Check merchant bank details and retry.';
            }
            if ($batchReason !== ''): ?>
            <p class="text-sm mt-2 <?= strtolower((string)$batch['status']) === 'failed' ? 'text-red-300' : 'text-gray-400' ?>"><span class="font-semibold">Reason:</span> <?= e($batchReason) ?></p>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold text-emerald-400"><?= walletMoney((float)$batch['net_amount']) ?></p>
            <p class="text-xs text-gray-500"><?= (int)$batch['txn_count'] ?> transactions</p>
        </div>
    </div>
    <?= settlementRailBadge($batch['settlement_rail']) ?>
    <?php if ($batch['utr']): ?><p class="text-xs text-gray-500 mt-3">UTR: <span class="font-mono text-sky-400"><?= e($batch['utr']) ?></span></p><?php endif; ?>
</div>
<div class="glass rounded-xl overflow-hidden mb-8">
    <?php if (empty($items)): ?>
    <?= uxEmptyState('No transactions in this batch', 'Batch line items appear here once generated.') ?>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <?= uxTableCaption('Batch transaction items') ?>
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-4 py-3 text-left">Txn ID</th><th class="px-4 py-3 text-left">Method</th>
            <th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-left">Date</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($items as $it): ?>
            <tr><td class="px-4 py-3 font-mono text-xs"><?= e($it['txn_id']) ?></td>
                <td class="px-4 py-3 uppercase text-xs"><?= e($it['payment_method']) ?></td>
                <td class="px-4 py-3 text-right"><?= walletMoney((float)$it['amount']) ?></td>
                <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($it['txn_at']) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end no-print" aria-label="Search settlement batches">
    <div class="flex-1 min-w-[200px]"><?= uxLabel('batch-q', 'Search') ?><input id="batch-q" name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Batch code / merchant"></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>

<div class="glass rounded-xl overflow-hidden">
    <?php if (empty($all)): ?>
    <?= uxEmptyState('No settlement batches yet', 'Scheduled or manual batches from the settlement engine appear here.') ?>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <?= uxTableCaption('Settlement batches') ?>
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-4 py-3 text-left">Batch</th><th class="px-4 py-3 text-left">Merchant</th>
            <th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-right">Net</th>
            <th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">View</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($all as $b): ?>
            <tr<?= uiRowClick('?batch=' . (int)$b['id']) ?>>
                <td class="px-4 py-3 font-mono text-xs"><a href="?batch=<?= (int)$b['id'] ?>" class="text-sky-400 hover:underline"<?= uiStopClick() ?>><?= e($b['batch_code']) ?></a></td>
                <td class="px-4 py-3"><?= e($b['business_name']) ?></td>
                <td class="px-4 py-3 text-xs capitalize"><?= e($b['batch_type']) ?></td>
                <td class="px-4 py-3 text-right text-emerald-400"><?= walletMoney((float)$b['net_amount']) ?></td>
                <td class="px-4 py-3"><?= settlementBatchStatusBadge($b['status']) ?></td>
                <td class="px-4 py-3"><a href="?batch=<?= (int)$b['id'] ?>" class="text-xs text-sky-400">Details</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
