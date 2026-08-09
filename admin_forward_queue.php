<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
if (!function_exists('ensurePartnerForwardQueueTable')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Session expired. Retry.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'requeue' && !empty($_POST['item_id'])) {
            $ok = manualRequeueForward((int)$_POST['item_id']);
            flash($ok ? 'success' : 'error', $ok ? 'Item re-queued for processing.' : 'Could not re-queue item.');
        }
    }
    redirect('admin_forward_queue.php?status=' . urlencode($statusFilter));
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$matrix = getAdminForwardMatrix($statusFilter);

$pageTitle = 'KYC Forward Queue';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-bold">KYC Forward Queue — Status Matrix</h2>
        <div class="flex gap-2 text-xs">
            <a href="?status=" class="px-3 py-1.5 rounded-lg <?= $statusFilter === '' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">All</a>
            <a href="?status=queued" class="px-3 py-1.5 rounded-lg <?= $statusFilter === 'queued' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Queued</a>
            <a href="?status=processing" class="px-3 py-1.5 rounded-lg <?= $statusFilter === 'processing' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Processing</a>
            <a href="?status=success" class="px-3 py-1.5 rounded-lg <?= $statusFilter === 'success' ? 'bg-emerald-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Success</a>
            <a href="?status=retry" class="px-3 py-1.5 rounded-lg <?= $statusFilter === 'retry' ? 'bg-amber-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Retry</a>
            <a href="?status=failed" class="px-3 py-1.5 rounded-lg <?= $statusFilter === 'failed' ? 'bg-red-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Failed</a>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-dark-900/50 text-gray-400 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">Merchant</th>
                    <th class="px-4 py-3 text-left">Partner</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Attempts</th>
                    <th class="px-4 py-3 text-left">Scheduled</th>
                    <th class="px-4 py-3 text-left">Last Attempt</th>
                    <th class="px-4 py-3 text-left">Reference</th>
                    <th class="px-4 py-3 text-left">Error</th>
                    <th class="px-4 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matrix)): ?>
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No items in queue.</td></tr>
                <?php else: foreach ($matrix as $row): ?>
                <tr class="border-t border-gray-800/50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-200"><?= e($row['business_name'] ?? '—') ?></div>
                        <div class="text-xs text-gray-500"><?= e($row['merchant_code'] ?? '') ?></div>
                    </td>
                    <td class="px-4 py-3"><?= e(ucfirst($row['partner_key'])) ?></td>
                    <td class="px-4 py-3">
                        <?php
                        $colors = [
                            'queued' => 'bg-blue-500/20 text-blue-400',
                            'processing' => 'bg-purple-500/20 text-purple-400',
                            'success' => 'bg-emerald-500/20 text-emerald-400',
                            'retry' => 'bg-amber-500/20 text-amber-400',
                            'failed' => 'bg-red-500/20 text-red-400',
                        ];
                        $cls = $colors[$row['status']] ?? 'bg-gray-500/20 text-gray-400';
                        ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $cls ?>"><?= e($row['status']) ?></span>
                    </td>
                    <td class="px-4 py-3 text-gray-400"><?= (int)$row['attempts'] ?>/<?= (int)$row['max_attempts'] ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['schedule_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['last_attempt_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['partner_reference'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate" title="<?= e($row['error_message'] ?? '') ?>"><?= e($row['error_message'] ?? '—') ?></td>
                    <td class="px-4 py-3">
                        <?php if ($row['status'] === 'failed'): ?>
                        <form method="POST" action="admin_forward_queue.php" onsubmit="return confirm('Re-queue this item?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="requeue">
                            <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="text-xs text-amber-400 hover:text-amber-300">Re-queue</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
