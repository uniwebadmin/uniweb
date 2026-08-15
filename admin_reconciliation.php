<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$days = (int)($_GET['days'] ?? 7);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_settlement' && isset($_FILES['settlement_file'])) {
        $gateway = trim($_POST['gateway'] ?? '');
        if (!in_array($gateway, ['razorpay', 'cashfree', 'payu', 'axis', 'upi', 'card', 'netbanking', 'wallet'], true)) {
            flash('error', 'Invalid gateway.');
        } elseif ($_FILES['settlement_file']['error'] !== UPLOAD_ERROR_OK) {
            flash('error', 'File upload failed.');
        } else {
            $rows = parseGatewaySettlementCsv($_FILES['settlement_file']['tmp_name'], $gateway);
            if (empty($rows)) {
                flash('error', 'No valid rows found in CSV.');
            } else {
                $result = reconcileGatewaySettlementRows($rows, $gateway, $adminId, $_FILES['settlement_file']['name']);
                flash('success', "Settlement file processed: {$result['matched']} matched, {$result['unmatched']} unmatched out of {$result['total']} rows.");
            }
        }
        redirect('admin_reconciliation.php?days=' . $days . '&tab=settlement');
    }

    if ($action === 'manual_resolve' && isset($_POST['row_id'], $_POST['txn_id'])) {
        try {
            manualResolveSettlementRow((int)$_POST['row_id'], (int)$_POST['txn_id'], $adminId, trim($_POST['reason'] ?? ''));
            flash('success', 'Row manually resolved.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin_reconciliation.php?days=' . $days . '&tab=settlement');
    }

    if ($action === 'ignore_row' && isset($_POST['row_id'])) {
        ignoreSettlementRow((int)$_POST['row_id'], $adminId, trim($_POST['reason'] ?? ''));
        flash('success', 'Row ignored.');
        redirect('admin_reconciliation.php?days=' . $days . '&tab=settlement');
    }

    if ($action === 'generate_summary') {
        $date = trim($_POST['summary_date'] ?? date('Y-m-d'));
        $summaries = generateDailyReconciliationSummary($date);
        flash('success', 'Daily summary generated for ' . e($date) . ' (' . count($summaries) . ' gateways).');
        redirect('admin_reconciliation.php?days=' . $days . '&tab=summary');
    }

    if ($action === 'auto_mark') {
        $count = autoMarkReconciledTransactions($days);
        flash('success', "Auto-marked {$count} transactions as reconciled.");
        redirect('admin_reconciliation.php?days=' . $days);
    }
}

// GET actions
if (isset($_GET['action'], $_GET['token']) && verifyCsrf($_GET['token'])) {
  if ($_GET['action'] === 'retry_all') {
        $results = reprocessUnmatchedWebhooks($days, 20);
        $ok = count(array_filter($results, fn($r) => !empty($r['result']['ok'])));
        logStaffActivity('webhook_retry_batch', "Retried {$ok}/" . count($results) . ' unmatched webhooks');
        flash('success', "Retried {$ok} of " . count($results) . ' unmatched webhook(s).');
        redirect('admin_reconciliation.php?days=' . $days);
    }
    if ($_GET['action'] === 'retry' && isset($_GET['id'])) {
        $result = reprocessPgWebhookLog((int)$_GET['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Webhook reprocessed' . (!empty($result['duplicate']) ? ' (already matched)' : ''))
            : ($result['error'] ?? 'Retry failed'));
        redirect('admin_reconciliation.php?days=' . $days);
    }
}

$report = getPgReconciliationReport($days);
$settlementFiles = getGatewaySettlementFiles(20);
$dailySummaries = getDailyReconciliationSummaries($days);
$activeTab = $_GET['tab'] ?? 'webhooks';

// Get unmatched rows for selected file
$unmatchedRows = [];
$selectedFileId = (int)($_GET['file_id'] ?? 0);
if ($selectedFileId > 0) {
    $unmatchedRows = getUnmatchedSettlementRows($selectedFileId, 100);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pg_reconciliation_' . $days . 'd.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Days', $days]);
    fputcsv($out, ['Successful txns', $report['transactions_success'] ?? 0]);
    fputcsv($out, ['Pending txns', $report['transactions_pending'] ?? 0]);
    fputcsv($out, ['Refunds', $report['refunds'] ?? 0]);
    fputcsv($out, ['Unmatched webhooks', count($report['unmatched_webhooks'] ?? [])]);
    fputcsv($out, []);
    fputcsv($out, ['Webhook ID', 'Gateway', 'Event', 'Created']);
    foreach ($report['unmatched_webhooks'] ?? [] as $wh) {
        fputcsv($out, [$wh['id'] ?? '', $wh['gateway'] ?? '', $wh['event_type'] ?? '', $wh['created_at'] ?? '']);
    }
    fclose($out);
    exit;
}

$pageTitle = 'PG Reconciliation';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Match gateway webhooks against settled transactions. After real volume: upload the partner settlement CSV, match unmatched rows, then generate the daily summary. Do not invent extra crons — auto-audit already marks obvious matches.</p>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="admin_pg_webhooks.php" class="text-xs text-sky-400 hover:underline">Webhook log →</a>
            <a href="admin_bank_reconciliation.php" class="text-xs text-emerald-400 hover:underline">⭐ Bank Auto-Reconciliation →</a>
            <a href="gateway_settings.php" class="text-xs text-gray-400 hover:text-white">Gateway keys →</a>
            <a href="?days=<?= $days ?>&export=csv" class="text-xs text-sky-400 hover:text-white">Export CSV</a>
            <?php if (!empty($report['unmatched_webhooks'])): ?>
            <a href="?action=retry_all&days=<?= $days ?>&token=<?= csrfToken() ?>" class="text-xs bg-amber-600/20 text-amber-300 px-3 py-1.5 rounded-lg hover:bg-amber-600/30" onclick="return confirm('Retry all unmatched webhooks (max 20)?')">Retry unmatched</a>
            <?php endif; ?>
        </div>
        <div class="flex gap-2 text-xs">
            <?php foreach ([7, 14, 30] as $d): ?>
            <a href="?days=<?= $d ?>" class="px-3 py-1.5 rounded-lg <?= $days === $d ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>"><?= $d ?> days</a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Successful Txns</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($report['transactions_success']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Pending Txns</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($report['transactions_pending']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Refunds</p><p class="text-2xl font-bold text-gray-300 mt-1"><?= number_format($report['refunds']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Unmatched Webhooks</p><p class="text-2xl font-bold text-red-400 mt-1"><?= count($report['unmatched_webhooks']) ?></p></div>
        <a href="admin_settlements.php?status=pending" class="glass rounded-xl p-5 stat-card border border-amber-500/20 hover:border-amber-500/50"><p class="text-xs text-gray-500">Settlement pending 24h+</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format((int)($report['delayed_settlements'] ?? 0)) ?></p></a>
        <a href="admin_refunds.php?status=pending" class="glass rounded-xl p-5 stat-card border border-violet-500/20 hover:border-violet-500/50"><p class="text-xs text-gray-500">Refund pending 3d+</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= number_format((int)($report['delayed_refunds'] ?? 0)) ?></p></a>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Webhook Volume by Gateway</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-5 py-3 text-left">Gateway</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Count</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($report['webhook_stats'])): ?><tr><td colspan="3" class="px-5 py-8 text-center text-gray-500">No webhook events in period.</td></tr>
                <?php else: foreach ($report['webhook_stats'] as $row): ?>
                <tr><td class="px-5 py-3 capitalize"><?= e($row['gateway']) ?></td><td class="px-5 py-3"><?= statusBadge($row['status']) ?></td><td class="px-5 py-3"><?= (int)$row['c'] ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-red-400">Unmatched Webhooks</h2></div>
            <div class="max-h-80 overflow-y-auto">
                <?php if (empty($report['unmatched_webhooks'])): ?><p class="text-gray-500 text-sm text-center py-8">All webhooks matched.</p>
                <?php else: foreach ($report['unmatched_webhooks'] as $w): ?>
                <div class="px-5 py-3 border-b border-gray-800 text-xs flex justify-between items-center gap-2">
                    <div>
                    <span class="text-gray-500"><?= formatDate($w['created_at']) ?></span> · <span class="capitalize"><?= e($w['gateway']) ?></span>
                    <p class="font-mono text-sky-400 mt-1"><?= e($w['reference'] ?: $w['link_id'] ?: '—') ?></p>
                    </div>
                    <a href="?action=retry&id=<?= (int)$w['id'] ?>&days=<?= $days ?>&token=<?= csrfToken() ?>" class="text-amber-400 shrink-0">Retry</a>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-amber-400">Gateway Txns Without Webhook Log</h2></div>
            <div class="max-h-80 overflow-y-auto">
                <?php if (empty($report['txns_without_webhook'])): ?><p class="text-gray-500 text-sm text-center py-8">No gaps found.</p>
                <?php else: foreach ($report['txns_without_webhook'] as $t): ?>
                <div class="px-5 py-3 border-b border-gray-800 text-xs flex justify-between gap-3">
                    <a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="font-mono text-sky-400 hover:underline"><?= e($t['txn_id']) ?></a>
                    <span class="shrink-0"><?= formatMoney(capStatAmount((float)$t['amount'])) ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <p class="text-xs text-gray-600">Webhook URLs: <?= e(pgWebhookUrl('razorpay')) ?> · <?= e(pgWebhookUrl('cashfree')) ?> · <?= e(pgWebhookUrl('payu')) ?></p>
    <p class="text-xs text-amber-400/90 mt-2">Note: Opening webhook URLs in a browser shows a health check. "Invalid signature" entries in the log are from browser tests — real payments from Razorpay/Cashfree/PayU will POST signed data automatically.</p>

    <!-- Tab Navigation -->
    <div class="flex gap-2 border-b border-gray-800">
        <a href="?days=<?= $days ?>&tab=webhooks" class="px-4 py-2 text-sm <?= $activeTab === 'webhooks' ? 'text-brand-400 border-b-2 border-brand-500' : 'text-gray-400 hover:text-white' ?>">Webhooks</a>
        <a href="?days=<?= $days ?>&tab=settlement" class="px-4 py-2 text-sm <?= $activeTab === 'settlement' ? 'text-brand-400 border-b-2 border-brand-500' : 'text-gray-400 hover:text-white' ?>">Gateway Settlement Files</a>
        <a href="?days=<?= $days ?>&tab=summary" class="px-4 py-2 text-sm <?= $activeTab === 'summary' ? 'text-brand-400 border-b-2 border-brand-500' : 'text-gray-400 hover:text-white' ?>">Daily Summary</a>
    </div>

    <!-- Gateway Settlement Files Tab -->
    <?php if ($activeTab === 'settlement'): ?>
    <div class="space-y-6">
        <div class="glass rounded-xl p-4 sm:p-6">
            <h3 class="font-semibold mb-4">Upload Gateway Settlement File (CSV)</h3>
            <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="upload_settlement">
                <div><label class="text-sm text-gray-400">Gateway</label>
                    <select name="gateway" class="input-field mt-1 w-full">
                        <option value="razorpay">Razorpay</option>
                        <option value="cashfree">Cashfree</option>
                        <option value="payu">PayU</option>
                        <option value="axis">Axis Bank</option>
                        <option value="upi">UPI</option>
                    </select>
                </div>
                <div class="flex-1"><label class="text-sm text-gray-400">CSV File</label>
                    <input type="file" name="settlement_file" accept=".csv" class="input-field mt-1 w-full" required>
                </div>
                <button type="submit" class="btn-primary px-6 py-2.5">Upload &amp; Match</button>
            </form>
            <p class="text-xs text-gray-500 mt-2">Runbook: 1) Download the partner settlement file. 2) Upload CSV here (UTR, Amount, Date, Merchant Code, Gateway Ref). 3) Open unmatched and link or ignore. 4) Generate daily summary. Use after there is live volume — empty files are expected in Test.</p>
        </div>

        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Settlement Files History</h2></div>
            <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">File</th><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Rows</th><th class="px-4 py-3 text-left">Matched</th><th class="px-4 py-3 text-left">Unmatched</th><th class="px-4 py-3 text-left">Amount</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($settlementFiles)): ?><tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No settlement files uploaded yet.</td></tr>
                    <?php else: foreach ($settlementFiles as $f): ?>
                    <tr>
                        <td class="px-4 py-3 capitalize"><?= e($f['gateway']) ?></td>
                        <td class="px-4 py-3 text-xs font-mono"><?= e($f['filename']) ?></td>
                        <td class="px-4 py-3 text-xs"><?= e($f['file_date'] ?? $f['created_at']) ?></td>
                        <td class="px-4 py-3"><?= (int)$f['rows_total'] ?></td>
                        <td class="px-4 py-3 text-emerald-400"><?= (int)$f['rows_matched'] ?></td>
                        <td class="px-4 py-3 text-red-400"><?= (int)$f['rows_unmatched'] ?></td>
                        <td class="px-4 py-3 text-xs"><?= formatMoney((float)$f['rows_amount_total']) ?></td>
                        <td class="px-4 py-3"><?= statusBadge($f['status']) ?></td>
                        <td class="px-4 py-3"><?php if ((int)$f['rows_unmatched'] > 0): ?><a href="?days=<?= $days ?>&tab=settlement&file_id=<?= (int)$f['id'] ?>" class="text-xs text-sky-400 hover:underline">View unmatched</a><?php endif; ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table></div>
        </div>

        <?php if ($selectedFileId > 0 && !empty($unmatchedRows)): ?>
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-red-400">Unmatched Rows (File #<?= $selectedFileId ?>)</h2></div>
            <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">UTR</th><th class="px-4 py-3 text-left">Gateway Ref</th><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Amount</th><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($unmatchedRows as $r): ?>
                    <tr>
                        <td class="px-4 py-3 text-xs font-mono"><?= e($r['utr'] ?: '—') ?></td>
                        <td class="px-4 py-3 text-xs font-mono"><?= e($r['gateway_ref'] ?: '—') ?></td>
                        <td class="px-4 py-3 text-xs"><?= e($r['merchant_code'] ?: '—') ?></td>
                        <td class="px-4 py-3 text-xs"><?= formatMoney((float)$r['amount']) ?></td>
                        <td class="px-4 py-3 text-xs"><?= e($r['settlement_date'] ?? '—') ?></td>
                        <td class="px-4 py-3">
                            <form method="POST" class="flex gap-1 items-center">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                                <input type="number" name="txn_id" placeholder="Txn ID" class="input-field text-xs w-20" required>
                                <button type="submit" name="action" value="manual_resolve" class="text-xs text-emerald-400 hover:underline">Resolve</button>
                                <button type="submit" name="action" value="ignore_row" class="text-xs text-gray-400 hover:underline" onclick="return confirm('Ignore this row?')">Ignore</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Daily Summary Tab -->
    <?php if ($activeTab === 'summary'): ?>
    <div class="space-y-6">
        <div class="glass rounded-xl p-4 sm:p-6">
            <h3 class="font-semibold mb-4">Generate Daily Summary</h3>
            <form method="POST" class="flex flex-col sm:flex-row gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="generate_summary">
                <div><label class="text-sm text-gray-400">Date</label>
                    <input type="date" name="summary_date" value="<?= date('Y-m-d') ?>" class="input-field mt-1 w-full">
                </div>
                <button type="submit" class="btn-primary px-6 py-2.5">Generate</button>
            </form>
        </div>

        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Daily Reconciliation Summaries (Last <?= $days ?> days)</h2></div>
            <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Total</th><th class="px-4 py-3 text-left">Success</th><th class="px-4 py-3 text-left">Failed</th><th class="px-4 py-3 text-left">Pending</th><th class="px-4 py-3 text-left">Amount</th><th class="px-4 py-3 text-left">Webhooks</th><th class="px-4 py-3 text-left">Mismatches</th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($dailySummaries)): ?><tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No summaries generated yet.</td></tr>
                    <?php else: foreach ($dailySummaries as $s): ?>
                    <tr>
                        <td class="px-4 py-3 text-xs"><?= e($s['summary_date']) ?></td>
                        <td class="px-4 py-3 capitalize"><?= e($s['gateway']) ?></td>
                        <td class="px-4 py-3"><?= (int)$s['total_txns'] ?></td>
                        <td class="px-4 py-3 text-emerald-400"><?= (int)$s['success_txns'] ?></td>
                        <td class="px-4 py-3 text-red-400"><?= (int)$s['failed_txns'] ?></td>
                        <td class="px-4 py-3 text-amber-400"><?= (int)$s['pending_txns'] ?></td>
                        <td class="px-4 py-3 text-xs"><?= formatMoney((float)$s['total_amount']) ?></td>
                        <td class="px-4 py-3 text-xs"><?= (int)$s['webhooks_received'] ?> recv / <?= (int)$s['webhooks_matched'] ?> match</td>
                        <td class="px-4 py-3"><?= (int)$s['mismatches'] > 0 ? '<span class="text-red-400">' . (int)$s['mismatches'] . '</span>' : '<span class="text-emerald-400">0</span>' ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="glass rounded-xl p-4 sm:p-6">
            <h3 class="font-semibold mb-4">Auto-Mark Reconciled</h3>
            <p class="text-sm text-gray-400 mb-3">Mark transactions as reconciled where webhook + transaction UTR both exist.</p>
            <form method="POST" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="auto_mark">
                <button type="submit" class="btn-primary px-6 py-2.5">Auto-Mark (last <?= $days ?> days)</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
