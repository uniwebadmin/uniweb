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
<div class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100" role="status">
    <p class="font-semibold text-amber-200">Status board only — partner keys are not pasted here.</p>
    <p class="text-xs text-amber-100/80 mt-1">Paste Razorpay / Cashfree / PayU / Axis keys in <strong class="text-white">Partner Registry → partner → Keys</strong>. This page only shows which gateway × operation rows are scaffold / blocked / pending.</p>
    <p class="mt-3 flex flex-wrap gap-2">
        <a href="admin_gateway_registry.php" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-xs font-medium">Open Partner Registry →</a>
        <a href="gateway_settings.php" class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:text-white text-xs">Platform Settings (SMTP / cron)</a>
    </p>
</div>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-xl font-bold">Integration Status Board</h1>
        <p class="text-sm text-gray-500 mt-1">Read-only scaffold view — no live partner API calls and no key fields on this page.</p>
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

<p class="text-xs text-gray-600 mt-4 no-print">Still need keys? Use <a href="admin_gateway_registry.php" class="text-sky-400 hover:underline">Partner Registry → Keys</a> — not this board. Axis live paths wait for RM/UAT.</p>
<?php require_once __DIR__ . '/footer.php'; ?>
