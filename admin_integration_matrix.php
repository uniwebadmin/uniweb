<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
require_once __DIR__ . '/includes/integration_matrix.php';
if (!function_exists('integrationMatrixReadinessReport')) {
    require_once __DIR__ . '/includes/integration_matrix_workflow.php';
}

$report = integrationMatrixReadinessReport();
$rows = $report['rows'];
$counts = $report['counts'];
$filterGw = strtolower(trim($_GET['gateway'] ?? ''));
if ($filterGw !== '') {
    $rows = array_values(array_filter($rows, static fn(array $r): bool => ($r['gateway'] ?? '') === $filterGw));
}
$partners = integrationMatrixPartnerLabels();

$pageTitle = 'Integration Status Board';
require_once __DIR__ . '/header.php';
?>
<div class="mb-4 rounded-xl border border-sky-500/35 bg-sky-950/30 px-4 py-3 text-sm" role="status">
    <p class="font-semibold text-sky-200">Scaffold board — reference &amp; checklist, not live API tests</p>
    <p class="text-xs text-gray-400 mt-1"><?= e($report['message']) ?></p>
    <p class="text-[11px] text-gray-500 mt-2"><?= (int)$report['partners'] ?> partners × <?= (int)$report['operations'] ?> operations from Partner Registry — derived list, not hardcoded.</p>
    <p class="mt-3 flex flex-wrap gap-2">
        <a href="admin_gateway_registry.php" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-xs font-medium">Open Partner Registry →</a>
        <a href="gateway_settings.php" class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:text-white text-xs">Platform Settings</a>
    </p>
</div>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-xl font-bold">Integration Status Board</h1>
        <p class="text-sm text-gray-500 mt-1">Read-only scaffold — partner keys are not pasted here; no outbound partner API calls on this page.</p>
    </div>
    <?= renderPrintButton() ?>
</div>
<?= renderPagePrintStyles() ?>

<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <?php foreach ([
        ['Scaffold', $counts['scaffold'] ?? 0, 'text-sky-400'],
        ['Blocked (keys)', $counts['blocked_owner'] ?? 0, 'text-amber-400'],
        ['Blocked (Axis UAT)', $counts['blocked_axis_uat'] ?? 0, 'text-red-400'],
        ['Pending', $counts['pending'] ?? 0, 'text-gray-400'],
    ] as [$label, $n, $color]): ?>
    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-xs text-gray-500"><?= e($label) ?></p>
        <p class="text-2xl font-bold mt-1 <?= $color ?>"><?= (int)$n ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="glass rounded-xl p-4 mb-6 border border-gray-800 max-w-4xl">
    <p class="text-xs uppercase tracking-wide text-gray-500 mb-2">Status legend</p>
    <div class="flex flex-wrap gap-2 text-xs">
        <?php foreach ($report['legend'] as $key => $meta): ?>
        <?php if ($key === 'pass') {
            continue;
        } ?>
        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-dark-900/50 border border-gray-800" title="<?= e($meta['hint'] ?? '') ?>">
            <?= integrationMatrixStatusBadgeHtml($key) ?>
            <span class="text-gray-500 hidden sm:inline"><?= e($meta['hint'] ?? '') ?></span>
        </span>
        <?php endforeach; ?>
    </div>
</div>

<div class="mb-4 flex flex-wrap items-center gap-2 no-print">
    <span class="text-xs text-gray-500">Filter partner:</span>
    <a href="admin_integration_matrix.php" class="text-xs px-2.5 py-1 rounded-lg border <?= $filterGw === '' ? 'border-sky-500 text-sky-300' : 'border-gray-700 text-gray-400 hover:text-white' ?>">All</a>
    <?php foreach ($partners as $gw => $label): ?>
    <a href="admin_integration_matrix.php?gateway=<?= e(rawurlencode($gw)) ?>" class="text-xs px-2.5 py-1 rounded-lg border <?= $filterGw === $gw ? 'border-sky-500 text-sky-300' : 'border-gray-700 text-gray-400 hover:text-white' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Gateway</th>
                    <th class="px-5 py-3 text-left">Operation</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($rows)): ?>
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500 text-sm">No rows for this filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3">
                        <a href="admin_gateway_detail.php?partner=<?= e(rawurlencode($r['gateway'])) ?>" class="hover:text-sky-400"><?= e($r['gateway_label']) ?></a>
                    </td>
                    <td class="px-5 py-3 text-gray-400"><?= e($r['operation_label']) ?></td>
                    <td class="px-5 py-3"><?= integrationMatrixStatusBadge($r['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500 max-w-md"><?= e($r['note']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-xs text-gray-600 mt-4 no-print">Verify live: <a href="admin_gateway_registry.php" class="text-sky-400 hover:underline">Partner Registry</a> → partner → <strong class="text-gray-400">Test Connection</strong>. This board never auto-passes cells.</p>
<?php require_once __DIR__ . '/footer.php'; ?>
