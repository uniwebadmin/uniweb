<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'ops', 'kyc']);

$db = getDB();
$currentUser = getAdmin();
$isSuper = isSuperAdmin();

$statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$validStatuses = ['all', 'pending', 'verified', 'rejected', 'not_set'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$where = 'm.status != \'deleted\'';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND m.website_status = ?';
    $params[] = $statusFilter;
} else {
    $where .= " AND m.website_url IS NOT NULL AND m.website_url != ''";
}
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (LOWER(TRIM(m.business_name)) LIKE ? OR LOWER(TRIM(m.merchant_code)) LIKE ? OR LOWER(TRIM(m.website_url)) LIKE ? OR CAST(m.id AS CHAR) LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

if (!$isSuper && in_array(adminRole(), ['regional_manager', 'ops', 'kyc'], true)) {
    $assigned = array_column($db->query("SELECT merchant_id FROM staff_merchant_assignments WHERE admin_id = " . (int)($currentUser['id'] ?? 0))->fetchAll(), 'merchant_id');
    if (!empty($assigned)) {
        $placeholders = implode(',', array_fill(0, count($assigned), '?'));
        $where .= " AND m.id IN ({$placeholders})";
        array_push($params, ...$assigned);
    } else {
        $where .= ' AND 0=1';
    }
}

$counts = $db->query("SELECT website_status, COUNT(*) AS c FROM merchants WHERE status != 'deleted' AND website_url IS NOT NULL AND website_url != '' GROUP BY website_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalPending = (int)($counts['pending'] ?? 0);
$totalVerified = (int)($counts['verified'] ?? 0);
$totalRejected = (int)($counts['rejected'] ?? 0);
$totalSubmitted = $totalPending + $totalVerified + $totalRejected;

$listParams = listPageParams(25);
$countStmt = $db->prepare("SELECT COUNT(*) FROM merchants m WHERE {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT m.id, m.merchant_code, m.business_name, m.email, m.phone, m.website_url, m.android_app_url, m.ios_app_url, m.website_status, m.created_at
    FROM merchants m
    WHERE {$where}
    ORDER BY FIELD(m.website_status, 'pending', 'rejected', 'verified', 'not_set'), m.created_at DESC
    LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
$stmt->execute($params);
$merchants = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $mid = (int)($_POST['merchant_id'] ?? 0);
    if (in_array($action, ['verify', 'reject'], true) && $mid > 0) {
        requireMerchantAccess($mid);
        $newStatus = $action === 'verify' ? 'verified' : 'rejected';
        adminSetMerchantWebsiteStatus($mid, $newStatus);
        logStaffActivity('website_review', "Website {$newStatus} for merchant #{$mid}", $mid);
        flash('success', 'Website marked ' . $newStatus . '.');
        redirect('admin_website_reviews.php?status=' . $statusFilter . '&page=' . $listParams['page'] . ($q ? '&q=' . rawurlencode($q) : ''));
    }
}

$pageTitle = 'Website Reviews';
require_once __DIR__ . '/header.php';
?>
<div class="container mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold">Website Reviews</h1>
            <p class="text-sm text-gray-400">Review merchant website URLs for PayU / Razorpay / Cashfree onboarding</p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="glass rounded-xl p-4 border border-sky-500/20"><p class="text-xs text-gray-500">Pending</p><p class="text-2xl font-bold text-sky-400"><?= $totalPending ?></p></div>
        <div class="glass rounded-xl p-4 border border-emerald-500/20"><p class="text-xs text-gray-500">Verified</p><p class="text-2xl font-bold text-emerald-400"><?= $totalVerified ?></p></div>
        <div class="glass rounded-xl p-4 border border-red-500/20"><p class="text-xs text-gray-500">Rejected</p><p class="text-2xl font-bold text-red-400"><?= $totalRejected ?></p></div>
        <div class="glass rounded-xl p-4 border border-violet-500/20"><p class="text-xs text-gray-500">Total Submitted</p><p class="text-2xl font-bold text-violet-400"><?= $totalSubmitted ?></p></div>
    </div>

    <form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Merchant / Code / Website URL" class="input-field mt-1 text-sm"></div>
        <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All Submitted','pending'=>'Pending','verified'=>'Verified','rejected'=>'Rejected','not_set'=>'Not Set'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $statusFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
    </form>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Merchant</th>
                    <th class="px-5 py-3 text-left">Website</th>
                    <th class="px-5 py-3 text-left">Apps</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Submitted</th>
                    <th class="px-5 py-3 text-left">Action</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($merchants)): ?>
                        <tr><td colspan="6" class="p-0"><?= uxEmptyState('No website reviews found', 'Merchants will appear here after they submit a website URL.') ?></td></tr>
                    <?php else: foreach ($merchants as $m):
                        $status = merchantWebsiteStatus($m);
                    ?>
                    <tr>
                        <td class="px-5 py-3 text-xs">
                            <a href="admin_view_merchant.php?id=<?= (int)$m['id'] ?>" class="font-medium text-brand-400 hover:underline"><?= e($m['business_name']) ?></a>
                            <p class="text-[10px] text-gray-500 font-mono mt-0.5"><?= e($m['merchant_code']) ?></p>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            <?php if (!empty($m['website_url'])): ?>
                            <a href="<?= e($m['website_url']) ?>" target="_blank" rel="noopener" class="text-sky-400 hover:underline break-all"><?= e($m['website_url']) ?></a>
                            <?php else: ?><span class="text-gray-500">—</span><?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-400">
                            <?php if (!empty($m['android_app_url'])): ?><span class="text-emerald-400 mr-2">Android</span><?php endif; ?>
                            <?php if (!empty($m['ios_app_url'])): ?><span class="text-sky-400">iOS</span><?php endif; ?>
                            <?php if (empty($m['android_app_url']) && empty($m['ios_app_url'])): ?><span class="text-gray-600">—</span><?php endif; ?>
                        </td>
                        <td class="px-5 py-3"><?= merchantWebsiteStatusBadge($m) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($m['created_at']) ?></td>
                        <td class="px-5 py-3 text-xs whitespace-nowrap">
                            <a href="admin_edit_merchant.php?id=<?= (int)$m['id'] ?>#website" class="text-sky-400 hover:underline mr-2">Review</a>
                            <?php if ($status === 'pending'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="merchant_id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="text-emerald-400 hover:underline mr-2">Verify</button>
                            </form>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="merchant_id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="text-red-400 hover:underline">Reject</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= uxPageNav($listParams['page'], max(1, (int)ceil($total / $listParams['perPage'])), ['q' => $q, 'status' => $statusFilter]) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
