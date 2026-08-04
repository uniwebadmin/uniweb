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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '') && ($_POST['action'] ?? '') === 'save_sftp') {
    saveBankReconciliationSftpConfig($_POST);
    flash('success', 'Auto-reconciliation settings saved.');
    redirect('admin_bank_reconciliation.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '') && ($_POST['action'] ?? '') === 'test_sftp') {
    try {
        $settings = getBankReconciliationSftpSettings();
        if ($settings['mode'] === 'local') {
            $files = count(glob(bankStatementsDir() . '/inbox/*.csv'));
            flash('success', 'Local inbox connection OK. ' . $files . ' CSV file(s) waiting.');
        } else {
            $files = sftpListRemoteFiles($settings);
            flash('success', 'SFTP connection OK. ' . count($files) . ' matching file(s) found.');
        }
    } catch (Throwable $e) {
        flash('error', 'Connection failed: ' . $e->getMessage());
    }
    redirect('admin_bank_reconciliation.php');
}

if (isset($_GET['action']) && $_GET['action'] === 'run_sftp' && verifyCsrf($_GET['token'] ?? '')) {
    try {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $sftpResult = runBankReconciliationFetch($adminId ?: null);
        if (!empty($sftpResult['skipped'])) {
            flash('info', $sftpResult['message'] ?? 'Auto-reconciliation skipped.');
        } else {
            $processed = count(array_filter($sftpResult['files'] ?? [], fn($r) => empty($r['skipped'])));
            flash('success', 'Auto-fetch finished. ' . $processed . ' new file(s) processed.');
        }
    } catch (Throwable $e) {
        flash('error', 'Auto-fetch failed: ' . $e->getMessage());
    }
    redirect('admin_bank_reconciliation.php');
}

$sftpSettings = getBankReconciliationSftpSettings();
$reconCronKey = bankReconciliationCronKey();
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

    <div class="glass rounded-xl p-4 sm:p-6">
        <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-3">
            <input type="hidden" name="action" value="upload_statement">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <div class="flex-1 min-w-0 w-full sm:min-w-[240px]">
                <label class="text-sm text-gray-400">Bank statement CSV</label>
                <input type="file" name="statement" accept=".csv,text/csv" required class="input-field mt-1 w-full">
                <p class="text-[11px] text-gray-500 mt-1">Expected columns (any order, any casing): UTR/Reference, Amount, Date. Exact UTR matches only — never auto-settles without review.</p>
            </div>
            <button type="submit" class="btn-primary w-full sm:w-auto px-6 py-2.5 rounded-xl">Upload &amp; Reconcile</button>
        </form>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Auto-Fetch Settings</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_sftp">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">Mode</label>
                    <select name="bank_reconciliation_mode" class="input-field mt-1 w-full">
                        <option value="sftp" <?= $sftpSettings['mode'] === 'sftp' ? 'selected' : '' ?>>SFTP (auto daily)</option>
                        <option value="local" <?= $sftpSettings['mode'] === 'local' ? 'selected' : '' ?>>Local inbox (manual drop)</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-400">
                        <input type="checkbox" name="bank_reconciliation_enabled" value="1" <?= $sftpSettings['enabled'] ? 'checked' : '' ?> class="rounded border-gray-600">
                        Enable auto-fetch
                    </label>
                </div>
                <div>
                    <label class="text-sm text-gray-400">SFTP host</label>
                    <input type="text" name="bank_sftp_host" value="<?= e($sftpSettings['host']) ?>" class="input-field mt-1 w-full">
                </div>
                <div>
                    <label class="text-sm text-gray-400">SFTP port</label>
                    <input type="number" name="bank_sftp_port" value="<?= (int)$sftpSettings['port'] ?>" class="input-field mt-1 w-full">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Username</label>
                    <input type="text" name="bank_sftp_user" value="<?= e($sftpSettings['user']) ?>" class="input-field mt-1 w-full">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Password</label>
                    <input type="password" name="bank_sftp_pass" value="<?= e($sftpSettings['pass']) ?>" class="input-field mt-1 w-full" placeholder="Leave blank to keep current">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Remote path</label>
                    <input type="text" name="bank_sftp_remote_path" value="<?= e($sftpSettings['remote_path']) ?>" class="input-field mt-1 w-full" placeholder="/statements">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Filename pattern</label>
                    <input type="text" name="bank_sftp_filename_pattern" value="<?= e($sftpSettings['filename_pattern']) ?>" class="input-field mt-1 w-full" placeholder="*.csv">
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary px-6 py-2.5">Save</button>
                <button type="submit" name="action" value="test_sftp" class="border border-gray-700 rounded-lg hover:bg-white/5 px-6 py-2.5">Test</button>
                <a href="?action=run_sftp&token=<?= csrfToken() ?>" class="inline-block btn-primary text-sm px-5 py-2.5" onclick="return confirm('Run auto-fetch now?')">▶ Run Now</a>
            </div>
        </form>
        <div class="mt-6 pt-4 border-t border-gray-800">
            <p class="text-xs text-gray-500 mb-2">Daily cron URL</p>
            <code class="block bg-dark-900 rounded-lg p-3 text-xs text-sky-400 font-mono break-all"><?= e(rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/cron_bank_reconciliation.php?key=' . $reconCronKey) ?></code>
            <p class="text-[11px] text-gray-600 mt-2">Hostinger cron example: once a day at 6 AM → <code class="text-gray-500">curl -s "<?= e(rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/cron_bank_reconciliation.php?key=' . $reconCronKey) ?>"</code></p>
        </div>
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
