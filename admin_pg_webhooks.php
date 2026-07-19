<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);

$gateway = preg_replace('/[^a-z]/', '', $_GET['gateway'] ?? '');

if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'retry' && verifyCsrf($_GET['token'] ?? '')) {
    $result = reprocessPgWebhookLog((int)$_GET['id']);
    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? ('Webhook reprocessed' . (!empty($result['duplicate']) ? ' (duplicate)' : ''))
        : ($result['error'] ?? 'Retry failed'));
    redirect('admin_pg_webhooks.php' . ($gateway ? '?gateway=' . $gateway : ''));
}

$logs = getPgWebhookLogs(80, $gateway ?: null);
$pageTitle = 'PG Webhook Logs';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap gap-3 items-center justify-between">
        <h2 class="font-semibold">Payment Gateway Webhooks</h2>
        <div class="flex gap-2 text-xs">
            <a href="admin_pg_webhooks.php" class="px-3 py-1 rounded-lg <?= !$gateway ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white' ?>">All</a>
            <?php foreach (['razorpay','cashfree','payu'] as $g): ?>
            <a href="?gateway=<?= $g ?>" class="px-3 py-1 rounded-lg <?= $gateway === $g ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white' ?>"><?= ucfirst($g) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Time</th><th class="px-5 py-3 text-left">Gateway</th><th class="px-5 py-3 text-left">Event</th>
                <th class="px-5 py-3 text-left">Reference</th><th class="px-5 py-3 text-left">Link</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($logs)): ?><tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No webhook events logged yet.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                    <td class="px-5 py-3 text-xs capitalize"><?= e($log['gateway']) ?></td>
                    <td class="px-5 py-3 text-xs font-mono"><?= e($log['event_type'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-xs font-mono truncate max-w-[120px]" title="<?= e($log['reference'] ?? '') ?>"><?= e($log['reference'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-xs font-mono"><?= e($log['link_id'] ?? '—') ?></td>
                    <td class="px-5 py-3"><?= statusBadge($log['status']) ?></td>
                    <td class="px-5 py-3 text-xs">
                        <?php if (in_array($log['status'], ['received', 'failed', 'invalid_signature', 'invalid_hash'], true) && !empty($log['link_id'])): ?>
                        <a href="?action=retry&id=<?= (int)$log['id'] ?>&gateway=<?= e($gateway) ?>&token=<?= csrfToken() ?>" class="text-amber-400">Retry</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-gray-800 text-xs text-gray-500">
        Configure webhook URLs in <a href="gateway_settings.php" class="text-brand-400 hover:underline">Gateway Settings</a>.
        Razorpay: <?= e(pgWebhookUrl('razorpay')) ?> · Cashfree: <?= e(pgWebhookUrl('cashfree')) ?> · PayU: <?= e(pgWebhookUrl('payu')) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
