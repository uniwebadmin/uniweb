<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
if (!function_exists('getAllAuditLog') && is_file(__DIR__ . '/includes/audit_log.php')) {
    require_once __DIR__ . '/includes/audit_log.php';
}

$pageTitle = 'Audit Log';
$adminSection = 'financial';

$actionFilter = trim($_GET['action'] ?? '');
$merchantFilter = (int)($_GET['merchant_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = getAllAuditLog(5000, 0, $actionFilter ?: null, $merchantFilter ?: null, $from, $to, $q);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Event ID', 'Action', 'Actor', 'Merchant', 'Resource', 'Reason', 'IP', 'Time']);
    foreach ($rows as $a) {
        fputcsv($out, [
            $a['event_id'] ?? '',
            $a['action'] ?? '',
            trim(($a['actor_type'] ?? '') . ' #' . (int)($a['actor_id'] ?? 0)),
            $a['merchant_id'] ? '#' . (int)$a['merchant_id'] : '',
            trim(($a['resource_type'] ?? '') . ' ' . ($a['resource_id'] ?? '')),
            $a['reason'] ?? '',
            $a['ip_address'] ?? '',
            $a['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$auditEntries = getAllAuditLog($perPage, $offset, $actionFilter ?: null, $merchantFilter ?: null, $from, $to, $q);
$totalEntries = countAuditLog($actionFilter ?: null, $merchantFilter ?: null, $from, $to, $q);
$totalPages = max(1, (int)ceil($totalEntries / $perPage));

// Get distinct actions for filter dropdown
$distinctActions = [];
try {
    $distinctActions = getDB()->query("SELECT DISTINCT action FROM immutable_audit_log ORDER BY action")->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/header.php';
?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Immutable Audit Log</h1>
    <p class="text-sm text-gray-500 mb-6">All money actions recorded permanently. Rows cannot be updated or deleted (DB triggers enforce immutability).</p>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <select name="action" class="bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700">
            <option value="">All actions</option>
            <?php foreach ($distinctActions as $a): ?>
            <option value="<?= e($a['action']) ?>" <?= $actionFilter === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="min-w-[14rem]"><label class="text-[10px] text-gray-600 uppercase block mb-1">Merchant</label><?= renderAdminMerchantSelect('merchant_id', $merchantFilter, false, true, 'All merchants') ?></div>
        <div class="min-w-[12rem]"><label class="text-[10px] text-gray-600 uppercase block mb-1">Search TXN / resource</label><?= renderTxnRefSearchField('q', $q, 'TXN / event / resource…', '') ?></div>
        <input type="date" name="from" value="<?= e($from) ?>" class="bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700">
        <input type="date" name="to" value="<?= e($to) ?>" class="bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700">
        <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white rounded-lg px-4 py-2 text-sm">Filter</button>
        <a href="admin_audit_log.php" class="text-gray-400 hover:text-white text-sm px-3 py-2">Reset</a>
        <a href="admin_audit_log.php?<?= e(http_build_query(['action' => $actionFilter, 'merchant_id' => $merchantFilter ?: '', 'q' => $q, 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" class="text-sky-400 hover:text-white text-sm px-3 py-2">Download CSV</a>
    </form>

    <div class="text-xs text-gray-500 mb-4"><?= number_format($totalEntries) ?> total entries · Page <?= $page ?> of <?= $totalPages ?></div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Event ID</th><th class="px-4 py-3 text-left">Action</th>
                <th class="px-4 py-3 text-left">Actor</th><th class="px-4 py-3 text-left">Merchant</th>
                <th class="px-4 py-3 text-left">Resource</th><th class="px-4 py-3 text-left">Reason</th>
                <th class="px-4 py-3 text-left">IP</th><th class="px-4 py-3 text-left">Time</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($auditEntries)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No audit log entries found.</td></tr>
                <?php else: foreach ($auditEntries as $a): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= e(mb_substr($a['event_id'], 0, 20)) ?>...</td>
                    <td class="px-4 py-3 text-xs"><span class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400"><?= e($a['action']) ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?php
                        $actorType = strtolower(trim((string)($a['actor_type'] ?? '')));
                        $actorId = (int)($a['actor_id'] ?? 0);
                        $actorLabel = trim((string)($a['actor_type'] ?? '')) . ' #' . $actorId;
                        if ($actorId > 0 && in_array($actorType, ['admin', 'staff'], true) && function_exists('adminStaffLink')) {
                            echo adminStaffLink($actorId, $actorLabel, 'text-sky-400 hover:underline');
                        } else {
                            echo e($actorLabel);
                        }
                    ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= $a['merchant_id'] ? '#' . (int)$a['merchant_id'] : '—' ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e($a['resource_type'] ?? '') ?> <?= e($a['resource_id'] ?? '') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 max-w-xs truncate" title="<?= e($a['reason'] ?? '') ?>"><?= e(mb_substr($a['reason'] ?? '', 0, 60)) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-600"><?= e($a['ip_address'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($a['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2 mt-6">
        <?php for ($i = 1; $i <= min(10, $totalPages); $i++): ?>
        <a href="?action=<?= urlencode($actionFilter) ?>&merchant_id=<?= $merchantFilter ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&page=<?= $i ?>"
           class="px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'bg-sky-600 text-white' : 'glass text-gray-400 hover:text-white' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
