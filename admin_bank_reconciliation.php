<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_reconciliation.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_statement') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        flash('error', 'Session expired, please retry.');
        redirect('admin_bank_reconciliation.php');
    }
    if (empty($_FILES['statement']) || ($_FILES['statement']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a valid CSV file.');
        redirect('admin_bank_reconciliation.php');
    }
    $tmp = $_FILES['statement']['tmp_name'];
    $origName = $_FILES['statement']['name'];
    if (!preg_match('/\.csv$/i', $origName)) {
        flash('error', 'Only .csv files are supported. Export your bank statement as CSV first.');
        redirect('admin_bank_reconciliation.php');
    }
    $rows = parseBankStatementCsv($tmp);
    if (empty($rows)) {
        flash('error', 'No usable rows found. Make sure the CSV has UTR/Reference and Amount columns.');
        redirect('admin_bank_reconciliation.php');
    }
    $adminId = $_SESSION['admin_id'] ?? null;
    $result = reconcileBankStatementRows($rows, $adminId, $origName);
    logStaffActivity('bank_reconciliation_upload', "Uploaded {$origName}: {$result['confirmed']} confirmed, {$result['suggested']} suggested, " . count($result['unmatched']) . ' unmatched');
    flash('success', "Processed {$result['total']} rows — {$result['confirmed']} confirmed, {$result['suggested']} review suggestion(s), " . count($result['unmatched']) . ' unmatched.');
}

$history = getBankReconciliationHistory(20);
$pageTitle = 'Bank Auto-Reconciliation';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <div>
            <h2 class="text-xl font-bold">Bank Reconciliation Review</h2>
            <p class="text-sm text-gray-400 mt-1">Upload a bank statement. Exact confirmed UTR matches are recorded; proposed matches require review and never auto-settle a batch.</p>
        </div>
        <a href="admin_reconciliation.php" class="text-xs text-sky-400 hover:underline">PG Webhook Reconciliation →</a>
    </div>

    <div class="glass rounded-xl p-6">
        <form method="POST" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="action" value="upload_statement">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <div class="flex-1 min-w-[240px]">
                <label class="text-sm text-gray-400">Bank statement CSV</label>
                <input type="file" name="statement" accept=".csv" required class="input-field mt-1">
                <p class="text-[11px] text-gray-500 mt-1">Expected columns (any order, any casing): UTR/Reference, Amount, Date.</p>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5 rounded-xl">Upload &amp; Reconcile</button>
        </form>
    </div>

    <?php if ($result): ?>
    <div class="grid sm:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Confirmed (existing UTR matched)</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= (int)$result['confirmed'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Review Suggestions</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= (int)$result['suggested'] ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Unmatched — needs manual review</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= count($result['unmatched']) ?></p></div>
    </div>
    <?php if (!empty($result['unmatched'])): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h3 class="font-semibold text-amber-400">Unmatched Rows</h3></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-5 py-3 text-left">UTR/Reference</th><th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Date</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($result['unmatched'] as $u): ?>
                <tr><td class="px-5 py-3 font-mono text-sky-400"><?= e($u['utr'] ?: '—') ?></td><td class="px-5 py-3"><?= formatMoney((float)$u['amount']) ?></td><td class="px-5 py-3 text-gray-500"><?= e($u['date'] ?: '—') ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="px-5 py-3 text-[11px] text-gray-500 border-t border-gray-800">No batch matched by UTR or amount+date. Check settlement_batches manually or verify the amount/date format.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Upload History</h3></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-5 py-3 text-left">File</th><th class="px-5 py-3 text-left">Rows</th><th class="px-5 py-3 text-left">Confirmed</th><th class="px-5 py-3 text-left">Suggested</th><th class="px-5 py-3 text-left">Unmatched</th><th class="px-5 py-3 text-left">When</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($history)): ?>
                <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No uploads yet.</td></tr>
                <?php else: foreach ($history as $h): ?>
                <tr>
                    <td class="px-5 py-3"><?= e($h['filename']) ?></td>
                    <td class="px-5 py-3"><?= (int)$h['rows_total'] ?></td>
                    <td class="px-5 py-3 text-emerald-400"><?= (int)$h['rows_confirmed'] ?></td>
                    <td class="px-5 py-3 text-brand-400"><?= (int)($h['rows_suggested'] ?? 0) ?></td>
                    <td class="px-5 py-3 text-amber-400"><?= (int)$h['rows_unmatched'] ?></td>
                    <td class="px-5 py-3 text-gray-500"><?= formatDate($h['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
