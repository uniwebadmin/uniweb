<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payout.php';
requireStaffAccess(['super', 'ceo', 'ops']);

$db = getDB();
ensurePayoutSchema();

$action = $_GET['action'] ?? '';
$batchId = (int)($_GET['batch_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'upload_bulk') {
        $merchantId = (int)($_POST['merchant_id'] ?? 0);
        $csv = '';
        if (!empty($_FILES['bulk_csv']['tmp_name']) && is_uploaded_file($_FILES['bulk_csv']['tmp_name'])) {
            $csv = (string)file_get_contents($_FILES['bulk_csv']['tmp_name']);
        } else {
            $csv = (string)($_POST['bulk_csv_text'] ?? '');
        }
        if ($merchantId <= 0 || trim($csv) === '') {
            flash('error', 'Merchant and CSV data are required.');
            redirect('admin_bulk_payout.php');
        }
        $maker = 'admin:' . ($admin['username'] ?? 'admin');
        $res = processPayoutBulkCsvWithBatch($merchantId, $csv, $maker);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Bulk upload failed'));
        if (!empty($res['batch_id'])) {
            redirect('admin_bulk_payout.php?action=preview&batch_id=' . $res['batch_id']);
        }
        redirect('admin_bulk_payout.php');
    } elseif ($postAction === 'process_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $res = processPayoutBatchJobs($batchId);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
        redirect('admin_bulk_payout.php?action=view&batch_id=' . $batchId);
    } elseif ($postAction === 'retry_failed') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $res = retryFailedBatchPayouts($batchId);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
        redirect('admin_bulk_payout.php?action=view&batch_id=' . $batchId);
    } elseif ($postAction === 'cancel_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $res = cancelPayoutBatch($batchId);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
        redirect('admin_bulk_payout.php');
    }
}

$pageTitle = 'Bulk Payout Manager';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Bulk Payout Manager</h1>
            <p class="text-sm text-gray-500 mt-1">Upload CSV, preview rows, process via queue, track individual status</p>
        </div>
        <a href="admin_payout.php" class="text-sm text-gray-400 border border-gray-700 px-4 py-2 rounded-lg">← Back to Payouts</a>
    </div>

    <?php if ($action === 'preview' && $batchId > 0): ?>
        <?php
        $batch = getPayoutBatchSummary($batchId);
        $rows = getPayoutBatchRows($batchId);
        ?>
        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-white">Batch Preview — <?= e($batch['batch_code'] ?? '') ?></h2>
                <div class="flex gap-2">
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="process_batch">
                        <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                        <button class="text-sm text-emerald-400 border border-emerald-500/40 px-4 py-2 rounded-lg" onclick="return confirm('Process all queued payouts in this batch?')">Process Batch</button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="cancel_batch">
                        <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                        <button class="text-sm text-red-400 border border-red-500/40 px-4 py-2 rounded-lg" onclick="return confirm('Cancel this entire batch?')">Cancel Batch</button>
                    </form>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Total Rows</div><div class="text-lg font-bold text-white"><?= (int)($batch['total_rows'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Total Amount</div><div class="text-lg font-bold text-white">₹<?= number_format((float)($batch['total_amount'] ?? 0), 2) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Success</div><div class="text-lg font-bold text-emerald-400"><?= (int)($batch['success_count'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Failed</div><div class="text-lg font-bold text-red-400"><?= (int)($batch['failed_count'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Queued</div><div class="text-lg font-bold text-amber-400"><?= (int)($batch['queued_count'] ?? 0) ?></div></div>
            </div>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-800/50 text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">#</th>
                        <th class="px-4 py-3 text-left">Beneficiary</th>
                        <th class="px-4 py-3 text-left">Account</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $row): ?>
                    <tr class="border-t border-gray-800">
                        <td class="px-4 py-3 text-gray-500"><?= $i + 1 ?></td>
                        <td class="px-4 py-3 text-white"><?= e($row['account_holder'] ?? '') ?></td>
                        <td class="px-4 py-3 text-gray-400"><?= e($row['account_number'] ?? '') ?> / <?= e($row['ifsc_code'] ?? '') ?></td>
                        <td class="px-4 py-3 text-right text-white">₹<?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td class="px-4 py-3">
                            <?php $st = $row['status'] ?? 'queued'; ?>
                            <?php if ($st === 'success'): ?><span class="text-emerald-400">✓ Success</span>
                            <?php elseif ($st === 'failed'): ?><span class="text-red-400">✗ Failed</span>
                            <?php elseif ($st === 'processing'): ?><span class="text-blue-400">⟳ Processing</span>
                            <?php else: ?><span class="text-amber-400">⏳ Queued</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-red-400 text-xs"><?= e($row['error_message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
          </div>
        </div>

        <?php if (($batch['failed_count'] ?? 0) > 0): ?>
        <div class="mt-4">
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="retry_failed">
                <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                <button class="text-sm text-amber-400 border border-amber-500/40 px-4 py-2 rounded-lg">Retry Failed Rows</button>
            </form>
        </div>
        <?php endif; ?>

    <?php elseif ($action === 'view' && $batchId > 0): ?>
        <?php
        $batch = getPayoutBatchSummary($batchId);
        $rows = getPayoutBatchRows($batchId);
        ?>
        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-white">Batch Status — <?= e($batch['batch_code'] ?? '') ?></h2>
                <a href="admin_bulk_payout.php" class="text-sm text-gray-400 border border-gray-700 px-4 py-2 rounded-lg">← All Batches</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Total</div><div class="text-lg font-bold text-white"><?= (int)($batch['total_rows'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Amount</div><div class="text-lg font-bold text-white">₹<?= number_format((float)($batch['total_amount'] ?? 0), 2) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Success</div><div class="text-lg font-bold text-emerald-400"><?= (int)($batch['success_count'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Failed</div><div class="text-lg font-bold text-red-400"><?= (int)($batch['failed_count'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Processing</div><div class="text-lg font-bold text-blue-400"><?= (int)($batch['processing_count'] ?? 0) ?></div></div>
                <div class="bg-gray-800/50 rounded-lg p-3"><div class="text-xs text-gray-500">Queued</div><div class="text-lg font-bold text-amber-400"><?= (int)($batch['queued_count'] ?? 0) ?></div></div>
            </div>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-800/50 text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">Payout ID</th>
                        <th class="px-4 py-3 text-left">Beneficiary</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">UTR</th>
                        <th class="px-4 py-3 text-left">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr class="border-t border-gray-800">
                        <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= e($row['payout_id'] ?? '') ?></td>
                        <td class="px-4 py-3 text-white"><?= e($row['account_holder'] ?? '') ?></td>
                        <td class="px-4 py-3 text-right text-white">₹<?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                        <td class="px-4 py-3">
                            <?php $st = $row['status'] ?? 'queued'; ?>
                            <?php if ($st === 'success'): ?><span class="text-emerald-400">✓ Success</span>
                            <?php elseif ($st === 'failed'): ?><span class="text-red-400">✗ Failed</span>
                            <?php elseif ($st === 'processing'): ?><span class="text-blue-400">⟳ Processing</span>
                            <?php else: ?><span class="text-amber-400">⏳ Queued</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= e($row['utr'] ?? '') ?></td>
                        <td class="px-4 py-3 text-red-400 text-xs"><?= e($row['error_message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
          </div>
        </div>

        <?php if (($batch['failed_count'] ?? 0) > 0): ?>
        <div class="mt-4">
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="retry_failed">
                <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                <button class="text-sm text-amber-400 border border-amber-500/40 px-4 py-2 rounded-lg">Retry Failed Rows</button>
            </form>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
                <h2 class="text-lg font-bold text-white mb-4">Upload Bulk Payout CSV</h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="upload_bulk">
                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-2">Merchant</label>
                        <select name="merchant_id" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                            <option value="">Select merchant…</option>
                            <?php
                            $merchants = $db->query("SELECT id, business_name, merchant_code FROM merchants WHERE status='active' ORDER BY business_name")->fetchAll();
                            foreach ($merchants as $m):
                            ?>
                            <option value="<?= (int)$m['id'] ?>"><?= e($m['business_name']) ?> (<?= e($m['merchant_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-2">CSV File</label>
                        <input type="file" name="bulk_csv" accept=".csv" class="w-full text-sm text-gray-400">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-400 mb-2">Or paste CSV text</label>
                        <textarea name="bulk_csv_text" rows="6" placeholder="label,account_holder,account_number,ifsc_code,amount,purpose,bank_name,account_type" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm font-mono"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button class="text-sm text-emerald-400 border border-emerald-500/40 px-4 py-2 rounded-lg">Upload & Preview</button>
                        <a href="admin_bulk_payout.php?download_csv_template=1" class="text-sm text-gray-400 border border-gray-700 px-4 py-2 rounded-lg">Download Template</a>
                    </div>
                </form>
            </div>

            <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
                <h2 class="text-lg font-bold text-white mb-4">Recent Batches</h2>
                <?php
                $batches = getRecentPayoutBatches(20);
                if (empty($batches)):
                ?>
                <p class="text-sm text-gray-500">No bulk payout batches yet.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[560px]">
                    <thead class="text-gray-400">
                        <tr>
                            <th class="px-2 py-2 text-left">Batch</th>
                            <th class="px-2 py-2 text-left">Merchant</th>
                            <th class="px-2 py-2 text-right">Rows</th>
                            <th class="px-2 py-2 text-right">Amount</th>
                            <th class="px-2 py-2 text-left">Status</th>
                            <th class="px-2 py-2 text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $b): ?>
                        <tr class="border-t border-gray-800">
                            <td class="px-2 py-2"><a href="admin_bulk_payout.php?action=view&batch_id=<?= (int)$b['id'] ?>" class="text-blue-400 font-mono text-xs"><?= e($b['batch_code'] ?? '') ?></a></td>
                            <td class="px-2 py-2 text-gray-400"><?= e($b['business_name'] ?? '') ?></td>
                            <td class="px-2 py-2 text-right text-white"><?= (int)($b['total_rows'] ?? 0) ?></td>
                            <td class="px-2 py-2 text-right text-white">₹<?= number_format((float)($b['total_amount'] ?? 0), 2) ?></td>
                            <td class="px-2 py-2">
                                <?php $st = $b['status'] ?? 'open'; ?>
                                <?php if ($st === 'completed'): ?><span class="text-emerald-400">Completed</span>
                                <?php elseif ($st === 'processing'): ?><span class="text-blue-400">Processing</span>
                                <?php elseif ($st === 'cancelled'): ?><span class="text-red-400">Cancelled</span>
                                <?php else: ?><span class="text-amber-400">Open</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2 text-gray-500 text-xs"><?= e($b['created_at'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
