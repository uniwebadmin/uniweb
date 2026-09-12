<?php
declare(strict_types=1);

/**
 * Forward queue panel — embedded in KYC Ops hub or standalone admin_forward_queue.php.
 *
 * Expects: statusFilter, partnerFilter, q, detailId, detailTimeline, matrix, fwdStats,
 * adapterRegistry, holdWindowEdu, forwardStagedEdu, gwSyncEdu
 * Optional: $kycForwardPanelStandalone (bool), $kycForwardPanelPostUrl (string)
 */

if (!function_exists('kycOpsForwardQuery')) {
    require_once __DIR__ . '/kyc_ops.php';
}

$kycForwardPanelStandalone = !empty($kycForwardPanelStandalone);
$kycForwardPanelPostUrl = (string)($kycForwardPanelPostUrl ?? (
    $kycForwardPanelStandalone ? 'admin_forward_queue.php' : kycOpsForwardHubUrl()
));

$forwardChipHref = static function (array $overrides = []) use ($kycForwardPanelStandalone): string {
    $qs = kycOpsForwardQuery($overrides, $kycForwardPanelStandalone);
    $base = $kycForwardPanelStandalone ? 'admin_forward_queue.php' : 'admin_kyc.php';
    return $base . ($qs !== '' ? ('?' . $qs) : '');
};

$chipStatuses = [
    'view:active' => ['label' => 'Needs action', 'cls' => 'bg-emerald-600 text-white'],
    'view:legacy_sync' => ['label' => 'Legacy sync', 'cls' => 'bg-slate-600 text-white'],
    '' => ['label' => 'All statuses', 'cls' => 'bg-brand-500 text-white'],
    'queued' => ['label' => 'Queued', 'cls' => 'bg-brand-500 text-white'],
    'processing' => ['label' => 'Processing', 'cls' => 'bg-brand-500 text-white'],
    'waiting_keys' => ['label' => 'Waiting keys', 'cls' => 'bg-orange-500 text-white'],
    'staged' => ['label' => 'Staged', 'cls' => 'bg-sky-500 text-white'],
    'success' => ['label' => 'Success', 'cls' => 'bg-emerald-500 text-white'],
    'retry' => ['label' => 'Retry', 'cls' => 'bg-amber-500 text-white'],
    'failed' => ['label' => 'Failed', 'cls' => 'bg-red-500 text-white'],
];
?>
<div class="space-y-4">
    <?php if ($kycForwardPanelStandalone): ?>
    <p class="text-xs text-gray-500"><a href="<?= e(kycOpsUrl('review')) ?>" class="text-sky-400 hover:underline">← KYC Ops hub</a> · Forward queue (standalone view)</p>
    <?php endif; ?>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-xl font-bold">Partner forward queue</h2>
            <?php if (isSuperAdmin()): ?>
            <form method="POST" action="<?= e($kycForwardPanelPostUrl) ?>" onsubmit="return confirm('Run queue processor now?')" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="run_now">
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
                <?php if ($partnerFilter !== ''): ?><input type="hidden" name="partner" value="<?= e($partnerFilter) ?>"><?php endif; ?>
                <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-500/20 text-amber-400 text-xs font-medium hover:bg-amber-500/30 whitespace-nowrap">⚡ Run Now</button>
            </form>
            <?php endif; ?>
        </div>
        <p class="text-xs text-gray-500 mb-2">After KYC verify: one row per Registry partner (<code class="text-gray-400">kyc_verify</code>). <strong class="text-orange-300">Waiting keys</strong> = paste keys. <strong class="text-slate-400">Legacy sync</strong> = old Gateway Submit rows — use <strong>Needs action</strong> filter for today’s work.</p>
        <p class="text-xs text-amber-200/90 mb-2"><?= e(function_exists('forwardQueueRetryPolicyHint') ? forwardQueueRetryPolicyHint() : '') ?></p>
        <?php if (is_array($forwardStagedEdu ?? null)): ?>
        <p class="text-[11px] text-amber-200/90 mb-3"><?= e((string)$forwardStagedEdu['mostly_staged']) ?></p>
        <?php endif; ?>
        <?php if (is_array($gwSyncEdu ?? null)): ?>
        <p class="text-[11px] text-violet-300/90 mb-3"><?= e((string)$gwSyncEdu['sync']) ?></p>
        <?php endif; ?>
        <?php if (is_array($holdWindowEdu ?? null)): ?>
        <p class="text-[11px] text-sky-300/90 mb-3"><?= e($holdWindowEdu['policy']) ?></p>
        <?php endif; ?>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 mb-4">
            <?php
            $statOrder = ['queued', 'processing', 'waiting_keys', 'staged', 'success', 'retry', 'failed', 'paused'];
            foreach ($statOrder as $sk):
                $n = (int)($fwdStats['by_status'][$sk] ?? 0);
                $statLabel = function_exists('forwardQueueAdminStatusLabel') ? forwardQueueAdminStatusLabel($sk) : $sk;
            ?>
            <a href="<?= e($forwardChipHref(['status' => $sk, 'item_id' => null])) ?>" class="rounded-lg border border-gray-800 bg-dark-900/40 px-3 py-2 hover:border-gray-600" title="<?= e($statLabel) ?>">
                <p class="text-[10px] uppercase text-gray-500 truncate"><?= e($sk) ?></p>
                <p class="text-lg font-bold text-gray-100"><?= $n ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="mb-4 rounded-lg border border-violet-500/20 bg-violet-500/5 px-3 py-2 text-[11px] text-gray-400">
            <p class="font-semibold text-violet-300 mb-1">Partner adapters</p>
            <p class="mb-1">Registered: <?= count($adapterRegistry) ?> · <code class="text-gray-300">local_record</code> = UniWeb save only. <code class="text-gray-300">success</code> only with live partner ACK.</p>
        </div>
        <p class="text-[10px] uppercase text-gray-600 mb-2">Filter by status</p>
        <div class="flex flex-wrap gap-2 text-xs mb-3">
            <?php foreach ($chipStatuses as $chipKey => $chipMeta):
                if (str_starts_with($chipKey, 'view:')) {
                    $viewKey = substr($chipKey, 5);
                    $active = (($viewFilter ?? 'active') === $viewKey) && ($statusFilter ?? '') === '';
                    $href = $forwardChipHref(['view' => $viewKey, 'status' => null, 'item_id' => null]);
                } else {
                    $active = ($statusFilter ?? '') === $chipKey && ($viewFilter ?? '') === '';
                    $href = $forwardChipHref(['status' => $chipKey, 'view' => null, 'item_id' => null]);
                }
                $cls = $active ? $chipMeta['cls'] : 'bg-dark-700 text-gray-400';
            ?>
            <a href="<?= e($href) ?>" class="px-3 py-1.5 rounded-lg whitespace-nowrap <?= $cls ?>"><?= e($chipMeta['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="<?= e($kycForwardPanelStandalone ? 'admin_forward_queue.php' : 'admin_kyc.php') ?>" data-live-search-form data-results-target="forward-results" class="flex flex-wrap gap-2 items-end">
            <?php if (!$kycForwardPanelStandalone): ?>
            <input type="hidden" name="tab" value="forward">
            <?php endif; ?>
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
            <input type="hidden" name="view" value="<?= e($viewFilter ?? '') ?>">
            <button class="btn-primary px-4 py-2.5 text-sm whitespace-nowrap">Search</button>
        </form>
        <?php if ($detailId > 0 && ($detailTimeline ?? []) !== []): ?>
        <div class="mt-4 rounded-lg border border-sky-500/30 bg-sky-500/5 p-4">
            <p class="text-sm font-semibold text-sky-300 mb-2">Queue row #<?= $detailId ?> — timeline</p>
            <ol class="space-y-2 text-xs text-gray-300">
                <?php foreach ($detailTimeline as $ev): ?>
                <li><span class="text-gray-500"><?= e($ev['at'] !== '' ? $ev['at'] : '—') ?></span> · <strong><?= e($ev['event']) ?></strong> — <?= e($ev['detail']) ?></li>
                <?php endforeach; ?>
            </ol>
            <a href="<?= e($forwardChipHref(['item_id' => null])) ?>" class="text-xs text-gray-500 hover:text-white mt-2 inline-block">Close timeline</a>
        </div>
        <?php endif; ?>
    </div>

    <div id="forward-results" class="glass rounded-xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-dark-900/50 text-gray-400 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">Source</th>
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
                <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">No rows in this view. After checker <strong class="text-gray-400">Verify KYC</strong>, open <strong class="text-emerald-400">Needs action</strong> — new <code class="text-gray-400">kyc_verify</code> rows appear here (not the old Gateway Submit pile).</td></tr>
                <?php else: foreach ($matrix as $row):
                    $rowSource = function_exists('forwardQueueRowForwardSource') ? forwardQueueRowForwardSource($row) : 'gateway_sync';
                    $sourceLabel = $rowSource === 'gateway_sync' ? 'Legacy sync' : str_replace('_', ' ', $rowSource);
                ?>
                <tr class="border-t border-gray-800/50">
                    <td class="px-4 py-3 text-xs">
                        <span class="<?= $rowSource === 'gateway_sync' ? 'text-slate-400' : 'text-emerald-400' ?>"><?= e($sourceLabel) ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-200"><a href="admin_view_merchant.php?id=<?= (int)($row['merchant_id'] ?? 0) ?>" class="hover:text-sky-300"><?= e($row['business_name'] ?? '—') ?></a></div>
                        <div class="text-xs text-gray-500"><?= e($row['merchant_code'] ?? '') ?></div>
                    </td>
                    <td class="px-4 py-3"><a href="<?= e(function_exists('adminPartnerDetailUrl') ? adminPartnerDetailUrl((string)$row['partner_key']) : ('admin_gateway_detail.php?partner=' . urlencode((string)$row['partner_key']) . '&tab=keys&env=test')) ?>" class="hover:text-sky-300"><?= e(ucfirst($row['partner_key'])) ?></a></td>
                    <td class="px-4 py-3">
                        <?php
                        $statusKey = (string)$row['status'];
                        $statusLabel = function_exists('forwardQueueAdminStatusBadge') ? forwardQueueAdminStatusBadge($row) : (function_exists('forwardQueueAdminStatusLabel') ? forwardQueueAdminStatusLabel($statusKey) : $statusKey);
                        $statusPill = function_exists('forwardQueueStatusPill') ? forwardQueueStatusPill($row) : e($statusKey);
                        ?>
                        <div class="flex flex-col gap-1 items-start">
                            <?= $statusPill ?>
                            <p class="text-[10px] text-gray-500 max-w-[180px]"><?= e($statusLabel) ?></p>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-400"><?= (int)$row['attempts'] ?>/<?= (int)$row['max_attempts'] ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['schedule_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['last_attempt_at'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($row['partner_reference'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate" title="<?= e(function_exists('maskForwardQueueErrorMessage') ? maskForwardQueueErrorMessage($row['error_message'] ?? '') : ($row['error_message'] ?? '')) ?>"><?= e(function_exists('maskForwardQueueErrorMessage') ? maskForwardQueueErrorMessage($row['error_message'] ?? '') : ($row['error_message'] ?? '—')) ?></td>
                    <td class="px-4 py-3">
                        <?php if (in_array((string)$row['status'], ['failed', 'staged', 'waiting_keys'], true)): ?>
                        <form method="POST" action="<?= e($kycForwardPanelPostUrl) ?>" onsubmit="return confirm('Re-queue this item?')" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="requeue">
                            <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="text-xs text-amber-400 hover:text-amber-300">Re-queue</button>
                        </form>
                        <?php endif; ?>
                        <a href="<?= e($forwardChipHref(['item_id' => (int)$row['id']])) ?>" class="text-xs text-sky-400 hover:text-sky-300 ml-2">Timeline</a>
                        <?php if (isSuperAdmin()): ?>
                        <details class="inline-block mt-1 ml-2 align-top">
                            <summary class="text-xs text-violet-400 hover:text-violet-300 cursor-pointer">Log need docs</summary>
                            <form method="POST" action="<?= e($kycForwardPanelPostUrl) ?>" class="mt-2 p-2 rounded-lg border border-violet-500/20 bg-dark-900/80 space-y-1 min-w-[220px]">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="partner_inbound_log">
                                <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                                <label class="block text-[10px] text-gray-500">Type</label>
                                <select name="event_type" class="w-full text-xs rounded-md bg-dark-800 border border-gray-700 text-gray-200 px-2 py-1">
                                    <option value="need_info" selected>Need more documents</option>
                                    <option value="partner_query">Partner query</option>
                                    <option value="partner_reply">Partner reply</option>
                                    <option value="reject">Reject</option>
                                </select>
                                <label class="block text-[10px] text-gray-500">Note</label>
                                <textarea name="note" required rows="2" class="w-full text-xs rounded-md bg-dark-800 border border-gray-700 text-gray-200 px-2 py-1" placeholder="What the partner asked"></textarea>
                                <button type="submit" class="text-xs text-violet-300 hover:text-violet-200">Save log</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
      </div>
    </div>
</div>
