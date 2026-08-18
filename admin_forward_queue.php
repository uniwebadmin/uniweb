<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
if (!function_exists('ensurePartnerForwardQueueTable')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Session expired. Retry.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'requeue' && !empty($_POST['item_id'])) {
            $ok = manualRequeueForward((int)$_POST['item_id']);
            flash($ok ? 'success' : 'error', $ok ? 'Item re-queued for processing.' : 'Could not re-queue item.');
        } elseif ($action === 'run_now' && isSuperAdmin()) {
            // D3: Super-admin only — run queue processor immediately with audit
            if (function_exists('recordImmutableAudit')) {
                recordImmutableAudit('forward_queue_run_now', 0, 'system', '0', 'Super-admin triggered immediate queue processing');
            }
            if (!function_exists('processPerPartnerForwardQueue')) {
                require_once __DIR__ . '/includes/partner_forward_queue.php';
            }
            $result = processPerPartnerForwardQueue(50);
            flash('success', 'Queue processed: ' . ($result['processed'] ?? 0) . ' items, '
                . ($result['success'] ?? 0) . ' success, '
                . ($result['staged'] ?? 0) . ' staged, '
                . ($result['failed'] ?? 0) . ' failed, '
                . ($result['retry'] ?? 0) . ' retry.');
        }
    }
    redirect('admin_forward_queue.php' . ($statusFilter !== '' || $q !== '' ? ('?' . http_build_query(array_filter(['status' => $statusFilter ?: null, 'q' => $q ?: null]))) : ''));
}

$matrix = getAdminForwardMatrix($statusFilter, $q);
$fwdStats = getForwardQueueStats();
$adapterRegistry = getKycForwardAdapterRegistry();

$pageTitle = 'KYC Forward Queue';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-4">
    <div class="glass rounded-xl p-5 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-xl font-bold">KYC Forward Queue — Status Matrix</h2>
            <?php if (isSuperAdmin()): ?>
            <form method="POST" action="admin_forward_queue.php" onsubmit="return confirm('Run queue processor now?')" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-500/20 text-amber-400 text-xs font-medium hover:bg-amber-500/30 whitespace-nowrap">⚡ Run Now</button>
            </form>
            <?php endif; ?>
        </div>
        <p class="text-xs text-gray-500 mb-3">After Admin Verify: one queue row per partner that already has keys. <strong class="text-amber-300">Staged</strong> = package saved on UniWeb — <strong class="text-gray-300">not sent to the bank/partner yet</strong> (live KYC API + success-rate routing stay parked).</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 mb-4">
            <?php
            $statOrder = ['queued', 'processing', 'staged', 'success', 'retry', 'failed', 'paused'];
            foreach ($statOrder as $sk):
                $n = (int)($fwdStats['by_status'][$sk] ?? 0);
            ?>
            <a href="?status=<?= e($sk) ?>" class="rounded-lg border border-gray-800 bg-dark-900/40 px-3 py-2 hover:border-gray-600">
                <p class="text-[10px] uppercase text-gray-500"><?= e($sk) ?></p>
                <p class="text-lg font-bold text-gray-100"><?= $n ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="mb-4 rounded-lg border border-violet-500/20 bg-violet-500/5 px-3 py-2 text-[11px] text-gray-400">
            <p class="font-semibold text-violet-300 mb-1">Partner adapters (hooks)</p>
            <p class="mb-1">Registered: <?= count($adapterRegistry) ?> · Mode <code class="text-gray-300">local_record</code> saves onboarding row + API log. Live partner push comes later — not checkout success-rate routing.</p>
            <p class="text-gray-500"><?= e(implode(' · ', array_map(static fn($k, $m) => $k . '=' . ($m['mode'] ?? ''), array_keys($adapterRegistry), $adapterRegistry))) ?></p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs mb-3">
            <a href="?status=" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === '' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">All</a>
            <a href="?status=queued" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'queued' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Queued</a>
            <a href="?status=processing" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'processing' ? 'bg-brand-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Processing</a>
            <a href="?status=success" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'success' ? 'bg-emerald-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Success</a>
            <a href="?status=staged" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'staged' ? 'bg-sky-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Staged</a>
            <a href="?status=retry" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'retry' ? 'bg-amber-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Retry</a>
            <a href="?status=failed" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $statusFilter === 'failed' ? 'bg-red-500 text-white' : 'bg-dark-700 text-gray-400' ?>">Failed</a>
        </div>
        <form method="GET" data-live-search-form data-results-target="forward-results" class="flex gap-2 items-end">
            <div class="flex-1 min-w-[180px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Merchant / Partner / Status / ID" class="input-field mt-1 text-sm" autocomplete="off"></div>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <button class="btn-primary px-4 py-2.5 text-sm whitespace-nowrap">Search</button>
        </form>
    </div>

    <div id="forward-results" class="glass rounded-xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
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
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No items in queue yet. Rows appear when KYC is verified — one per partner with keys (or unassigned if none).</td></tr>
                <?php else: foreach ($matrix as $row): ?>
                <tr class="border-t border-gray-800/50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-200"><a href="admin_view_merchant.php?id=<?= (int)($row['merchant_id'] ?? 0) ?>" class="hover:text-sky-300"><?= e($row['business_name'] ?? '—') ?></a></div>
                        <div class="text-xs text-gray-500"><?= e($row['merchant_code'] ?? '') ?></div>
                    </td>
                    <td class="px-4 py-3"><a href="<?= e(function_exists('adminPartnerDetailUrl') ? adminPartnerDetailUrl((string)$row['partner_key']) : ('admin_gateway_detail.php?partner=' . urlencode((string)$row['partner_key']) . '&tab=keys&env=test')) ?>" class="hover:text-sky-300"><?= e(ucfirst($row['partner_key'])) ?></a></td>
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
                        <?php if (in_array((string)$row['status'], ['failed', 'staged'], true)): ?>
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
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
