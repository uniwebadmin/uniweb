<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);
if (!function_exists('getWebhookReliabilityStats') && is_file(__DIR__ . '/includes/webhook_reliability.php')) {
    require_once __DIR__ . '/includes/webhook_reliability.php';
}

// POST: replay dead letter event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'replay' && isset($_POST['event_id'])) {
        $ok = replayDeadLetterEvent((int)$_POST['event_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Event queued for replay.' : 'Failed to replay event.');
        redirect('admin_webhook_reliability.php');
    }
    if ($action === 'reprocess' && isset($_POST['event_id'])) {
        $ok = reprocessFailedWebhookEvent((int)$_POST['event_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Event queued for re-processing.' : 'Failed to re-process event.');
        redirect('admin_webhook_reliability.php');
    }
    if ($action === 'discard' && isset($_POST['event_id'])) {
        $ok = discardDeadLetterEvent((int)$_POST['event_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Event discarded.' : 'Failed to discard event.');
        redirect('admin_webhook_reliability.php');
    }
    if ($action === 'view_payload' && isset($_POST['event_id'])) {
        $payloadEvent = getWebhookEventForAdmin((int)$_POST['event_id']);
    }
}

$stats = getWebhookReliabilityStats();
$deadLetters = getDeadLetterEvents(50);
$failedEvents = getFailedWebhookEvents(50);
$payloadEvent = $payloadEvent ?? null;

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

    <?php if ($payloadEvent): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-sky-400">Payload Preview — Event #<?= (int)$payloadEvent['id'] ?></h2></div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4 mb-4 text-xs">
                <div><span class="text-gray-500">Event ID:</span> <span class="text-gray-300 font-mono"><?= e($payloadEvent['event_id']) ?></span></div>
                <div><span class="text-gray-500">Gateway:</span> <span class="text-gray-300"><?= e($payloadEvent['gateway']) ?></span></div>
                <div><span class="text-gray-500">Status:</span> <span class="text-gray-300"><?= e($payloadEvent['status']) ?></span></div>
                <div><span class="text-gray-500">Payload Size:</span> <span class="text-gray-300"><?= $payloadEvent['payload_size'] ?> bytes</span></div>
            </div>
            <pre class="bg-gray-900 rounded-lg p-4 text-xs text-gray-400 overflow-x-auto max-h-96"><?= e($payloadEvent['payload_preview']) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($failedEvents)): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-amber-400">Failed Events (Re-process)</h2><p class="text-xs text-gray-500 mt-1">Events with status=failed or dead_letter. Safe to re-process.</p></div>
        <div class="overflow-x-auto"><table class="min-w-[900px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">ID</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-right">Retries</th><th class="px-4 py-3 text-left">Error</th><th class="px-4 py-3 text-left">Actions</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($failedEvents as $fe): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= (int)$fe['id'] ?></td>
                    <td class="px-4 py-3 text-xs capitalize"><?= e($fe['gateway']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($fe['event_type'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs <?= $fe['status'] === 'dead_letter' ? 'text-red-400' : 'text-amber-400' ?>"><?= e($fe['status']) ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (int)$fe['retry_count'] ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate" title="<?= e($fe['last_error']) ?>"><?= e(mb_substr($fe['last_error'] ?? '', 0, 60)) ?></td>
                    <td class="px-4 py-3 flex gap-2">
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reprocess"><input type="hidden" name="event_id" value="<?= (int)$fe['id'] ?>"><button type="submit" class="text-xs text-sky-400 hover:underline" onclick="return confirm('Re-process this event?')">Re-process</button></form>
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="view_payload"><input type="hidden" name="event_id" value="<?= (int)$fe['id'] ?>"><button type="submit" class="text-xs text-violet-400 hover:underline">Payload</button></form>
                        <?php if ($fe['status'] === 'dead_letter'): ?>
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="discard"><input type="hidden" name="event_id" value="<?= (int)$fe['id'] ?>"><button type="submit" class="text-xs text-red-400 hover:underline" onclick="return confirm('Discard this event? This action is permanent.')">Discard</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
