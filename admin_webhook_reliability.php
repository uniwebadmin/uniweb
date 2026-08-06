<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);

// POST: replay dead letter event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'replay' && isset($_POST['event_id'])) {
        $ok = replayDeadLetterEvent((int)$_POST['event_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Event queued for replay.' : 'Failed to replay event.');
        redirect('admin_webhook_reliability.php');
    }
}

$stats = getWebhookReliabilityStats();
$deadLetters = getDeadLetterEvents(50);

// Recent events
$recentEvents = [];
try {
    $recentEvents = getDB()->query("SELECT * FROM webhook_events ORDER BY created_at DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'Webhook Reliability';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <p class="text-sm text-gray-400">Idempotency, retry queue, dead letter management</p>

    <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Events</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($stats['total']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Completed</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($stats['completed']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Failed (retrying)</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($stats['failed']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Dead Letter</p><p class="text-2xl font-bold <?= $stats['dead_letter'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($stats['dead_letter']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Pending Retry</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= number_format($stats['pending_retry']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Success Rate</p><p class="text-2xl font-bold <?= $stats['success_rate'] >= 95 ? 'text-emerald-400' : ($stats['success_rate'] >= 80 ? 'text-amber-400' : 'text-red-400') ?> mt-1"><?= $stats['success_rate'] ?>%</p></div>
    </div>

    <?php if (!empty($deadLetters)): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-red-400">Dead Letter Queue (<?= count($deadLetters) ?>)</h2><p class="text-xs text-gray-500 mt-1">Events that exhausted all retries. Review and replay if needed.</p></div>
        <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Event ID</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-right">Retries</th><th class="px-4 py-3 text-left">Error</th><th class="px-4 py-3 text-left">Created</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($deadLetters as $dl): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= e(mb_substr($dl['event_id'], 0, 24)) ?>...</td>
                    <td class="px-4 py-3 text-xs capitalize"><?= e($dl['gateway']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($dl['event_type'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right text-xs text-red-400"><?= (int)$dl['retry_count'] ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate" title="<?= e($dl['last_error']) ?>"><?= e(mb_substr($dl['last_error'] ?? '', 0, 60)) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($dl['created_at']) ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="replay"><input type="hidden" name="event_id" value="<?= (int)$dl['id'] ?>"><button type="submit" class="text-xs text-sky-400 hover:underline" onclick="return confirm('Replay this event?')">Replay</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent Events</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[700px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Event ID</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-right">Retries</th><th class="px-4 py-3 text-left">Created</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($recentEvents)): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No webhook events recorded yet.</td></tr>
                <?php else: foreach ($recentEvents as $ev):
                    $scls = match($ev['status']) {
                        'completed' => 'text-emerald-400',
                        'failed' => 'text-amber-400',
                        'dead_letter' => 'text-red-400',
                        'processing' => 'text-sky-400',
                        default => 'text-gray-400',
                    };
                ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= e(mb_substr($ev['event_id'], 0, 24)) ?>...</td>
                    <td class="px-4 py-3 text-xs capitalize"><?= e($ev['gateway']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($ev['event_type'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs <?= $scls ?>"><?= e($ev['status']) ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$ev['retry_count'] ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($ev['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
