<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$days = (int)($_GET['days'] ?? 7);

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
$pageTitle = 'PG Reconciliation';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Match gateway webhooks against settled transactions</p>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="admin_pg_webhooks.php" class="text-xs text-sky-400 hover:underline">Webhook log →</a>
            <a href="admin_bank_reconciliation.php" class="text-xs text-emerald-400 hover:underline">⭐ Bank Auto-Reconciliation →</a>
            <a href="gateway_settings.php" class="text-xs text-gray-400 hover:text-white">Gateway keys →</a>
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

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Successful Txns</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($report['transactions_success']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Pending Txns</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($report['transactions_pending']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Refunds</p><p class="text-2xl font-bold text-gray-300 mt-1"><?= number_format($report['refunds']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Unmatched Webhooks</p><p class="text-2xl font-bold text-red-400 mt-1"><?= count($report['unmatched_webhooks']) ?></p></div>
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
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
