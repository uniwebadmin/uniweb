<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
ensurePaymentLinkAnalytics();

$db = getDB();
$currentUser = getAdmin();
$isSuper = isSuperAdmin();

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = trim($_GET['status'] ?? 'all');
$merchantFilter = (int)($_GET['merchant_id'] ?? 0);
$modeFilter = trim($_GET['mode'] ?? 'all');
$partnerFilter = trim($_GET['partner'] ?? 'all');

if (!in_array($statusFilter, ['all','active','inactive','paid','unpaid','expired'], true)) $statusFilter = 'all';
if (!in_array($modeFilter, ['all','test','live'], true)) $modeFilter = 'all';
$partnerChoices = ['all' => 'All', 'direct' => 'Direct UPI', 'axis' => 'Axis Bank', 'payu' => 'PayU', 'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'decentro' => 'Decentro'];
if (!isset($partnerChoices[$partnerFilter])) $partnerFilter = 'all';

$where = '1=1';
$params = [];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (LOWER(TRIM(COALESCE(pl.link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.description,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.link_label,''))) LIKE ? OR CAST(pl.amount AS CHAR) LIKE ? OR CAST(pl.merchant_id AS CHAR) LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFilter !== 'all') {
    if (in_array($statusFilter, ['active','inactive','expired'], true)) {
        $where .= ' AND pl.status = ?';
        $params[] = $statusFilter;
    }
}
if ($merchantFilter > 0) {
    $where .= ' AND pl.merchant_id = ?';
    $params[] = $merchantFilter;
}
if ($modeFilter === 'test') {
    $where .= ' AND pl.is_test = 1';
} elseif ($modeFilter === 'live') {
    $where .= ' AND pl.is_test = 0';
}
if ($partnerFilter !== 'all') {
    $where .= ' AND pl.gateway_code = ?';
    $params[] = $partnerFilter;
}

// Regional managers / ops only see assigned merchants unless super/ceo
if (!$isSuper && in_array(adminRole(), ['regional_manager', 'finance', 'ops'], true) && function_exists('staffHasMerchantAccess')) {
    $assigned = array_column($db->query("SELECT merchant_id FROM staff_merchant_assignments WHERE admin_id = " . (int)($currentUser['id'] ?? 0))->fetchAll(), 'merchant_id');
    if (!empty($assigned)) {
        $placeholders = implode(',', array_fill(0, count($assigned), '?'));
        $where .= " AND pl.merchant_id IN ({$placeholders})";
        array_push($params, ...$assigned);
    } else {
        $where .= ' AND 0=1';
    }
}

$paidCountSql = "(SELECT COUNT(*) FROM transactions t WHERE t.payment_link_id = pl.id AND t.status = 'success')";
$having = $statusFilter === 'paid' ? ' HAVING paid_count > 0' : ($statusFilter === 'unpaid' ? ' HAVING paid_count = 0' : '');

$countStmt = $db->prepare("SELECT COUNT(*) FROM (SELECT pl.id, {$paidCountSql} AS paid_count FROM payment_links pl WHERE {$where}{$having}) x");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listParams = listPageParams(25);
$order = "ORDER BY pl.created_at DESC";
$stmt = $db->prepare("SELECT pl.*, m.business_name, {$paidCountSql} AS paid_count
    FROM payment_links pl
    JOIN merchants m ON m.id = pl.merchant_id
    WHERE {$where}{$having}
    {$order}
    LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
$stmt->execute($params);
$links = $stmt->fetchAll();

// Stats
$statsSql = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN pl.status = 'active' THEN 1 ELSE 0 END) AS active,
    COALESCE(SUM(pl.view_count),0) AS views,
    COALESCE((SELECT COUNT(*) FROM transactions t WHERE t.status = 'success' AND t.payment_link_id IS NOT NULL),0) AS payments
    FROM payment_links pl" . ($merchantFilter ? " WHERE pl.merchant_id = {$merchantFilter}" : '');
$stats = $db->query($statsSql)->fetch();

$pageTitle = 'Admin Payment Links';
require_once __DIR__ . '/header.php';

$catalog = getPaymentMethodCatalog();
$merchants = $db->query("SELECT id, business_name FROM merchants WHERE status != 'deleted' ORDER BY business_name LIMIT 200")->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv' && verifyCsrf($_GET['csrf_token'] ?? '')) {
    // Export CSV
    $exportStmt = $db->prepare("SELECT pl.*, m.business_name, {$paidCountSql} AS paid_count
        FROM payment_links pl
        JOIN merchants m ON m.id = pl.merchant_id
        WHERE {$where}{$having}
        {$order}");
    $exportStmt->execute($params);
    $rows = [];
    foreach ($exportStmt->fetchAll() as $row) {
        $rows[] = [
            $row['link_id'],
            $row['business_name'],
            $row['link_label'] ?? 'All Methods',
            $row['amount'],
            $row['status'],
            $row['is_test'] ? 'Test' : 'Live',
            $row['view_count'] ?? 0,
            $row['paid_count'] ?? 0,
            $row['expires_at'],
            $row['created_at'],
        ];
    }
    sendCsvDownload(['Link ID', 'Merchant', 'Method', 'Amount', 'Status', 'Mode', 'Views', 'Paid', 'Expires', 'Created'], $rows, 'payment_links_' . date('Y-m-d') . '.csv');
    exit;
}
?>
<div class="container mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold">Payment Links</h1>
            <p class="text-sm text-gray-400">Admin view of all merchant payment links</p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="glass rounded-xl p-4 border border-sky-500/20"><p class="text-xs text-gray-500">Total Links</p><p class="text-2xl font-bold"><?= (int)($stats['total'] ?? 0) ?></p></div>
        <div class="glass rounded-xl p-4 border border-emerald-500/20"><p class="text-xs text-gray-500">Active</p><p class="text-2xl font-bold text-emerald-400"><?= (int)($stats['active'] ?? 0) ?></p></div>
        <div class="glass rounded-xl p-4 border border-violet-500/20"><p class="text-xs text-gray-500">Total Views</p><p class="text-2xl font-bold text-violet-400"><?= (int)($stats['views'] ?? 0) ?></p></div>
        <div class="glass rounded-xl p-4 border border-brand-500/20"><p class="text-xs text-gray-500">Payments</p><p class="text-2xl font-bold text-brand-400"><?= (int)($stats['payments'] ?? 0) ?></p></div>
    </div>

    <form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="flex-1 min-w-[200px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Link ID / Merchant / Amount" class="input-field mt-1 text-sm"></div>
        <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','active'=>'Active','inactive'=>'Inactive','paid'=>'Paid','unpaid'=>'Unpaid','expired'=>'Expired'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $statusFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label class="text-[10px] text-gray-600 uppercase">Mode</label><select name="mode" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','test'=>'Test','live'=>'Live'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $modeFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label class="text-[10px] text-gray-600 uppercase">Partner</label><select name="partner" class="input-field mt-1 text-sm"><?php foreach ($partnerChoices as $k=>$v): ?><option value="<?= e($k) ?>" <?= $partnerFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label class="text-[10px] text-gray-600 uppercase">Merchant</label><select name="merchant_id" class="input-field mt-1 text-sm"><option value="0">All</option><?php foreach ($merchants as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $merchantFilter===(int)$m['id']?'selected':'' ?>><?= e($m['business_name']) ?></option><?php endforeach; ?></select></div>
        <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
        <a href="admin_payment_links.php?export=csv&csrf_token=<?= rawurlencode(csrfToken()) ?><?= ($q ? '&q=' . rawurlencode($q) : '') . ($statusFilter !== 'all' ? '&status=' . $statusFilter : '') . ($modeFilter !== 'all' ? '&mode=' . $modeFilter : '') . ($partnerFilter !== 'all' ? '&partner=' . $partnerFilter : '') . ($merchantFilter ? '&merchant_id=' . $merchantFilter : '') ?>" class="glass px-3 py-2 rounded-lg text-xs text-brand-400 hover:text-brand-300 ml-auto">Export CSV</a>
    </form>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Link ID</th>
                    <th class="px-5 py-3 text-left">Merchant</th>
                    <th class="px-5 py-3 text-left">Method</th>
                    <th class="px-5 py-3 text-left">Partner</th>
                    <th class="px-5 py-3 text-left">Amount</th>
                    <th class="px-5 py-3 text-left">Views</th>
                    <th class="px-5 py-3 text-left">Paid</th>
                    <th class="px-5 py-3 text-left">Conv.</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Mode</th>
                    <th class="px-5 py-3 text-left">Expires</th>
                    <th class="px-5 py-3 text-left">Action</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($links)): ?>
                        <tr><td colspan="12" class="p-0"><?= uxEmptyState('No payment links found', 'Try clearing filters or create one from the merchant portal.') ?></td></tr>
                    <?php else: foreach ($links as $link):
                        $cat = $catalog[$link['payment_method'] ?? ''] ?? null;
                        $payUrl = buildPaymentLinkUrl($link['link_id'], $cat['pay_key'] ?? null);
                        $methodLabel = $link['link_label'] ?? ($cat['label'] ?? 'All Methods');
                        $partnerCode = !empty($link['gateway_code']) ? $link['gateway_code'] : ($cat['gateway'] ?? '—');
                        $partnerLabel = $partnerChoices[$partnerCode] ?? ($partnerCode !== '—' ? ucfirst((string)$partnerCode) : '—');
                    ?>
                    <tr>
                        <td class="px-5 py-3 font-mono text-xs"><a href="<?= e($payUrl) ?>" target="_blank" class="text-sky-400 hover:underline"><?= e($link['link_id']) ?></a></td>
                        <td class="px-5 py-3 text-xs"><a href="admin_view_merchant.php?id=<?= (int)$link['merchant_id'] ?>" class="text-brand-400 hover:underline"><?= e($link['business_name']) ?></a></td>
                        <td class="px-5 py-3 text-xs"><?= e($methodLabel) ?></td>
                        <td class="px-5 py-3 text-xs"><?= e($partnerLabel) ?></td>
                        <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$link['amount']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?= (int)($link['view_count'] ?? 0) ?></td>
                        <td class="px-5 py-3 text-xs text-emerald-400"><?= (int)($link['paid_count'] ?? 0) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?php $v = (int)($link['view_count'] ?? 0); $p = (int)($link['paid_count'] ?? 0); echo $v > 0 ? round($p / $v * 100) . '%' : '—'; ?></td>
                        <td class="px-5 py-3"><?= statusBadge($link['status']) ?></td>
                        <td class="px-5 py-3 text-xs"><?= $link['is_test'] ? '<span class="text-amber-400">Test</span>' : '<span class="text-emerald-400">Live</span>' ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($link['expires_at']) ?></td>
                        <td class="px-5 py-3 text-xs whitespace-nowrap"><a href="admin_transactions.php?q=<?= rawurlencode($link['link_id']) ?>" class="text-sky-400 hover:underline">Transactions</a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= uxPageNav($listParams['page'], max(1, (int)ceil($total / $listParams['perPage'])), ['q' => $q, 'status' => $statusFilter, 'mode' => $modeFilter, 'partner' => $partnerFilter, 'merchant_id' => $merchantFilter]) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
