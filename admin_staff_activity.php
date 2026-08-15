<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/ui_links.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager']);

$filterStaff = (int)($_GET['staff_id'] ?? 0);
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$logs = getStaffActivityLogs($filterStaff ?: null, 500);
if ($q !== '') {
    $qLower = strtolower($q);
    $logs = array_values(array_filter($logs, static function (array $log) use ($qLower): bool {
        $hay = strtolower(implode(' ', [
            $log['action'] ?? '',
            $log['staff_name'] ?? '',
            $log['username'] ?? '',
            $log['business_name'] ?? '',
            $log['details'] ?? '',
        ]));
        return str_contains($hay, $qLower);
    }));
}
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($logs as $log) {
        $csvRows[] = [
            $log['created_at'] ?? '',
            $log['staff_name'] ?? '',
            $log['username'] ?? '',
            $log['action'] ?? '',
            $log['business_name'] ?? '',
            $log['details'] ?? '',
        ];
    }
    sendCsvDownload(['Time', 'Staff', 'Username', 'Action', 'Merchant', 'Details'], $csvRows, 'staff-activity-' . date('Y-m-d') . '.csv');
}
$paged = uxPaginateSlice($logs, $page, 50);
$logs = $paged['rows'];
$staffList = [];
try {
    $staffList = getDB()->query("SELECT id, name, username, role FROM admins ORDER BY name")->fetchAll() ?: [];
} catch (Throwable $e) {
    $staffList = [];
}
$pageTitle = 'Staff Activity Log';
require_once __DIR__ . '/header.php';
?>
<?= uxListToolbar(uxExportCsvLink(array_filter(['staff_id' => $filterStaff ?: null, 'q' => $q ?: null]))) ?>
<div class="mb-6 flex flex-wrap gap-3 items-center justify-between no-print">
    <p class="text-sm text-gray-400">Who did what, when — KYC, Live, settlements, refunds, partners. Money audit stays on <a href="admin_audit_log.php" class="text-sky-400">Audit Log</a>.</p>
    <form method="GET" class="flex flex-wrap gap-2 items-end" aria-label="Filter staff activity">
        <div><?= uxLabel('staff-filter', 'Staff') ?>
        <select id="staff-filter" name="staff_id" class="input-field text-sm">
            <option value="">All staff</option>
            <?php foreach ($staffList as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $filterStaff === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(staffRoleLabel($s['role'])) ?>)</option>
            <?php endforeach; ?>
        </select></div>
        <div><?= uxLabel('staff-q', 'Search') ?><input id="staff-q" name="q" value="<?= e($q) ?>" class="input-field text-sm" placeholder="Action / merchant / details"></div>
        <button type="submit" class="btn-primary text-sm px-4">Filter</button>
    </form>
</div>
<div class="glass rounded-xl overflow-hidden">
    <?php if (empty($logs) && $paged['total'] === 0): ?>
    <?= uxEmptyState('No activity logged yet', 'Staff and admin actions on merchants, KYC, Live, settlements, refunds, and partners appear here. Older money events are on Audit Log.') ?>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <?= uxTableCaption('Staff activity log') ?>
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Time</th><th class="px-5 py-3 text-left">Staff</th><th class="px-5 py-3 text-left">Action</th>
            <th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Details</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($logs as $log): ?>
            <tr class="hover:bg-white/5">
                <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                <td class="px-5 py-3 text-xs"><?php
                    $sid = (int)($log['admin_id'] ?? 0);
                    if ($sid > 0) {
                        echo adminStaffLink($sid, (string)($log['staff_name'] ?: $log['username'] ?: 'Staff'), 'text-sky-400 hover:underline');
                        if (!empty($log['username'])) {
                            echo ' <span class="text-gray-600">(' . e((string)$log['username']) . ')</span>';
                        }
                    } else {
                        echo e((string)($log['staff_name'] ?? '')) . ' <span class="text-gray-600">(' . e((string)($log['username'] ?? '')) . ')</span>';
                    }
                ?></td>
                <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($log['action']) ?></td>
                <td class="px-5 py-3 text-xs"><?= $log['merchant_id'] ? adminMerchantLink((int)$log['merchant_id'], $log['business_name'] ?? 'Merchant') : '—' ?></td>
                <td class="px-5 py-3 text-xs text-gray-400 max-w-md truncate" title="<?= e($log['details'] ?? '') ?>"><?= e($log['details'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= uxPageNav($paged['page'], $paged['total_pages'], array_filter(['staff_id' => $filterStaff ?: null, 'q' => $q ?: null])) ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
