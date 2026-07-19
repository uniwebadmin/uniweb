<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager']);

$filterStaff = (int)($_GET['staff_id'] ?? 0);
$logs = getStaffActivityLogs($filterStaff ?: null, 80);
$staffList = getDB()->query("SELECT id, name, username, role FROM admins WHERE role NOT IN ('super') ORDER BY name")->fetchAll();
$pageTitle = 'Staff Activity Log';
require_once __DIR__ . '/header.php';
?>
<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">
    <p class="text-sm text-gray-400">Full audit trail — who did what, when.</p>
    <form method="GET" class="flex gap-2">
        <select name="staff_id" class="input-field text-sm">
            <option value="">All staff</option>
            <?php foreach ($staffList as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $filterStaff === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(staffRoleLabel($s['role'])) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary text-sm px-4">Filter</button>
    </form>
</div>
<div class="glass rounded-xl overflow-hidden">
    <table class="w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Time</th><th class="px-5 py-3 text-left">Staff</th><th class="px-5 py-3 text-left">Action</th>
            <th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Details</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php if (empty($logs)): ?><tr><td colspan="5" class="px-5 py-12 text-center text-gray-500">No activity logged yet.</td></tr>
            <?php else: foreach ($logs as $log): ?>
            <tr class="hover:bg-white/5">
                <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                <td class="px-5 py-3 text-xs"><?= e($log['staff_name']) ?> <span class="text-gray-600">(<?= e($log['username']) ?>)</span></td>
                <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($log['action']) ?></td>
                <td class="px-5 py-3 text-xs"><?= $log['merchant_id'] ? adminMerchantLink((int)$log['merchant_id'], $log['business_name'] ?? 'Merchant') : '—' ?></td>
                <td class="px-5 py-3 text-xs text-gray-400 max-w-md truncate" title="<?= e($log['details'] ?? '') ?>"><?= e($log['details'] ?? '') ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
