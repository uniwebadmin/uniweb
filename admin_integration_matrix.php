<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
require_once __DIR__ . '/includes/integration_matrix.php';

$pageTitle = 'Integration Status Board';
require_once __DIR__ . '/header.php';
$rows = integrationMatrixSummary();
$counts = ['scaffold' => 0, 'blocked_owner' => 0, 'blocked_axis_uat' => 0, 'pending' => 0];
foreach ($rows as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
}
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-xl font-bold">Gateway × Operation Matrix</h1>
        <p class="text-sm text-gray-500 mt-1">Scaffold registry only — no live partner API calls from this overnight run.</p>
    </div>
    <?= renderPrintButton() ?>
</div>
<?= renderPagePrintStyles() ?>

<div class="grid sm:grid-cols-4 gap-3 mb-6">
    <?php foreach ([
        ['Scaffold', $counts['scaffold'] ?? 0, 'text-sky-400'],
        ['Blocked (keys)', $counts['blocked_owner'] ?? 0, 'text-amber-400'],
        ['Blocked (Axis)', $counts['blocked_axis_uat'] ?? 0, 'text-red-400'],
        ['Pending', $counts['pending'] ?? 0, 'text-gray-400'],
    ] as [$label, $n, $color]): ?>
    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-xs text-gray-500"><?= e($label) ?></p>
        <p class="text-2xl font-bold mt-1 <?= $color ?>"><?= (int)$n ?></p>
    </div>
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
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3"><?= e($r['gateway_label']) ?></td>
                    <td class="px-5 py-3 text-gray-400"><?= e($r['operation_label']) ?></td>
                    <td class="px-5 py-3"><?= integrationMatrixStatusBadge($r['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500 max-w-md"><?= e($r['note']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-xs text-gray-600 mt-4 no-print">Owner action: paste partner keys in Partner Registry → Partner Detail → Keys when received. Axis live paths wait for RM/UAT.</p>
<?php require_once __DIR__ . '/footer.php'; ?>
