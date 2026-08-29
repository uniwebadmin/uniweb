<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
if (!function_exists('ensurePartnerForwardQueueTable')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}
if (!function_exists('holdWindowAdminEducation') && is_file(__DIR__ . '/includes/hold_window_workflow.php')) {
    require_once __DIR__ . '/includes/hold_window_workflow.php';
}
if (!function_exists('forwardStagedAdminEducation') && is_file(__DIR__ . '/includes/forward_queue_workflow.php')) {
    require_once __DIR__ . '/includes/forward_queue_workflow.php';
}
$holdWindowEdu = function_exists('holdWindowAdminEducation') ? holdWindowAdminEducation() : null;
$forwardStagedEdu = function_exists('forwardStagedAdminEducation') ? forwardStagedAdminEducation() : null;
$gwSyncEdu = function_exists('gatewaySubmitVsForwardQueueEducation') ? gatewaySubmitVsForwardQueueEducation() : null;

$statusFilter = trim((string)($_GET['status'] ?? ''));
$partnerFilter = strtolower(trim((string)($_GET['partner'] ?? '')));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$detailId = (int)($_GET['item_id'] ?? 0);
$detailTimeline = ($detailId > 0 && function_exists('getForwardQueueRowTimeline')) ? getForwardQueueRowTimeline($detailId) : [];

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
    redirect('admin_forward_queue.php' . ($statusFilter !== '' || $partnerFilter !== '' || $q !== '' ? ('?' . http_build_query(array_filter(['status' => $statusFilter ?: null, 'partner' => $partnerFilter ?: null, 'q' => $q ?: null]))) : ''));
}

$matrix = getAdminForwardMatrix($statusFilter, $q, $partnerFilter);
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
        <p class="text-xs text-gray-500 mb-2">After Admin Verify: one queue row per partner that already has keys. Each row is tied to exactly one <strong class="text-gray-300">partner_key</strong> — no cross-wire.</p>
        <p class="text-xs text-amber-200/90 mb-2"><?= e(function_exists('forwardQueueRetryPolicyHint') ? forwardQueueRetryPolicyHint() : '') ?></p>
        <p class="text-xs text-gray-500 mb-3"><strong class="text-amber-300">Staged</strong> / <code class="text-gray-400">local_record</code> = package saved on UniWeb only — <strong class="text-gray-300">not sent to the bank/partner yet</strong>. <strong class="text-emerald-300">Accepted</strong> (<code class="text-gray-400">success</code>) only when a live partner API returns ACK. Manual bulk: <a href="admin_gateway_submit.php" class="text-sky-400 hover:underline">Multi-Gateway Forward</a>.</p>
        <?php if (is_array($forwardStagedEdu)): ?>
        <p class="text-[11px] text-amber-200/90 mb-3"><?= e((string)$forwardStagedEdu['mostly_staged']) ?></p>
        <?php endif; ?>
        <?php if (is_array($gwSyncEdu)): ?>
        <p class="text-[11px] text-violet-300/90 mb-3"><?= e((string)$gwSyncEdu['sync']) ?></p>
        <?php endif; ?>
        <?php if (is_array($holdWindowEdu)): ?>
        <p class="text-[11px] text-sky-300/90 mb-3"><?= e($holdWindowEdu['policy']) ?></p>
        <?php endif; ?>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 mb-4">
            <?php
            $statOrder = ['queued', 'processing', 'staged', 'success', 'retry', 'failed', 'paused'];
            foreach ($statOrder as $sk):
                $n = (int)($fwdStats['by_status'][$sk] ?? 0);
                $statLabel = function_exists('forwardQueueAdminStatusLabel') ? forwardQueueAdminStatusLabel($sk) : $sk;
            ?>
            <a href="?status=<?= e($sk) ?>" class="rounded-lg border border-gray-800 bg-dark-900/40 px-3 py-2 hover:border-gray-600" title="<?= e($statLabel) ?>">
                <p class="text-[10px] uppercase text-gray-500 truncate"><?= e($sk) ?></p>
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
        <form method="GET" data-live-search-form data-results-target="forward-results" class="flex flex-wrap gap-2 items-end">
            <div class="flex-1 min-w-[180px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Merchant / Partner / Status / ID" class="input-field mt-1 text-sm" autocomplete="off"></div>
            <div class="min-w-[140px]"><label class="text-[10px] text-gray-600 uppercase">Partner</label>
                <select name="partner" class="input-field mt-1 text-sm">
                    <option value="">All partners</option>
                    <?php
                    $partnerKeys = function_exists('getKycForwardPartnerKeys') ? getKycForwardPartnerKeys() : array_keys($adapterRegistry);
                    foreach ($partnerKeys as $pk):
                    ?>
                    <option value="<?= e($pk) ?>" <?= $partnerFilter === $pk ? 'selected' : '' ?>><?= e(ucfirst($pk)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <button class="btn-primary px-4 py-2.5 text-sm whitespace-nowrap">Search</button>
        </form>
        <?php if ($detailId > 0 && $detailTimeline !== []): ?>
        <div class="mt-4 rounded-lg border border-sky-500/30 bg-sky-500/5 p-4">
            <p class="text-sm font-semibold text-sky-300 mb-2">Queue row #<?= $detailId ?> — timeline</p>
            <ol class="space-y-2 text-xs text-gray-300">
                <?php foreach ($detailTimeline as $ev): ?>
                <li><span class="text-gray-500"><?= e($ev['at'] !== '' ? $ev['at'] : '—') ?></span> · <strong><?= e($ev['event']) ?></strong> — <?= e($ev['detail']) ?></li>
                <?php endforeach; ?>
            </ol>
            <a href="admin_forward_queue.php<?= $statusFilter !== '' || $q !== '' ? ('?' . http_build_query(array_filter(['status' => $statusFilter ?: null, 'q' => $q ?: null]))) : '' ?>" class="text-xs text-gray-500 hover:text-white mt-2 inline-block">Close timeline</a>
        </div>
        <?php endif; ?>
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
                            'staged' => 'bg-sky-500/20 text-sky-300',
                            'success' => 'bg-emerald-500/20 text-emerald-400',
                            'retry' => 'bg-amber-500/20 text-amber-400',
                            'failed' => 'bg-red-500/20 text-red-400',
                        ];
                        $statusKey = (string)$row['status'];
                        $cls = $colors[$statusKey] ?? 'bg-gray-500/20 text-gray-400';
                        $statusLabel = function_exists('forwardQueueAdminStatusBadge') ? forwardQueueAdminStatusBadge($row) : (function_exists('forwardQueueAdminStatusLabel') ? forwardQueueAdminStatusLabel($statusKey) : $statusKey);
                        ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $cls ?>" title="<?= e($statusLabel) ?>"><?= e($statusKey) ?></span>
                        <p class="text-[10px] text-gray-500 mt-0.5 max-w-[180px]"><?= e($statusLabel) ?></p>
                    </td>
                    <td class="px-4 py-3 text-gray-400"><?= (int)$row['attempts'] ?>/<?= (int)$row['max_attempts'] ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['schedule_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['last_attempt_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['partner_reference'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate" title="<?= e(function_exists('maskForwardQueueErrorMessage') ? maskForwardQueueErrorMessage($row['error_message'] ?? '') : ($row['error_message'] ?? '')) ?>"><?= e(function_exists('maskForwardQueueErrorMessage') ? maskForwardQueueErrorMessage($row['error_message'] ?? '') : ($row['error_message'] ?? '—')) ?></td>
                    <td class="px-4 py-3">
                        <?php if (in_array((string)$row['status'], ['failed', 'staged'], true)): ?>
                        <form method="POST" action="admin_forward_queue.php" onsubmit="return confirm('Re-queue this item?')" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="requeue">
                            <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="text-xs text-amber-400 hover:text-amber-300">Re-queue</button>
                        </form>
                        <?php endif; ?>
                        <a href="?item_id=<?= (int)$row['id'] ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="text-xs text-sky-400 hover:text-sky-300 ml-2">Timeline</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
      </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
