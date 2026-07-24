<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);
ensureKycSchema();
$db = getDB();
$role = adminRole();
$canManageAll = isSuperAdmin() || in_array($role, ['ceo', 'regional_manager', 'ops', 'kyc'], true);

if ($canManageAll && isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $id = (int)$_GET['id'];
    if (staffHasMerchantAccess($id)) {
        $action = $_GET['action'] ?? '';
        if ($action === 'suspend' || $action === 'disable') {
            $db->prepare("UPDATE merchants SET status='suspended' WHERE id=?")->execute([$id]);
        } elseif ($action === 'activate' || $action === 'enable') {
            $db->prepare("UPDATE merchants SET status='active' WHERE id=?")->execute([$id]);
        } elseif ($action === 'delete') {
            try {
                $db->prepare("UPDATE merchants SET status='deleted', deleted_at=NOW() WHERE id=?")->execute([$id]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE merchants SET status='deleted' WHERE id=?")->execute([$id]);
            }
        }
        logStaffActivity('merchant_' . $action, 'Merchant #' . $id, $id);
        flash('success', 'Merchant updated.');
    } else {
        flash('error', 'Access denied for this merchant.');
    }
    redirect('manage_merchant.php');
}

$search = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$defs = staffRoleDefinitions()[$role] ?? null;

if (!empty($defs['all_merchants']) || isSuperAdmin()) {
    $sql = "SELECT m.* FROM merchants m WHERE m.status != 'deleted'";
    $params = [];
} else {
    $sql = "SELECT DISTINCT m.* FROM merchants m
        LEFT JOIN staff_merchant_assignments sma ON sma.merchant_id = m.id
        LEFT JOIN admins a ON a.id = sma.admin_id
        WHERE m.status != 'deleted' AND (sma.admin_id = ? OR a.reports_to = ?)";
    $params = [$adminId, $adminId];
}
if ($search) {
    $like = '%' . strtolower($search) . '%';
    $sql .= " AND (
        LOWER(TRIM(COALESCE(m.name,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.email,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.merchant_code,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(m.phone,''))) LIKE ? OR CAST(m.id AS CHAR) LIKE ?
    )";
    $params = array_merge($params, array_fill(0, 6, $like));
}
$sql .= " ORDER BY m.created_at DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$allMerchants = $stmt->fetchAll();
$listParams = listPageParams(25);
$merchantTotal = count($allMerchants);
$merchants = array_slice($allMerchants, $listParams['offset'], $listParams['perPage']);
$pageTitle = 'Manage Merchants';
require_once __DIR__ . '/header.php';
?>
<div class="flex flex-wrap justify-between items-center gap-3 mb-6">
    <form method="GET" data-live-search-form data-results-target="merchant-results" class="flex gap-2"><label class="sr-only" for="merchant-search">Search merchants</label><input id="merchant-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Name / Company / Email / Mobile / Merchant ID" class="input-field w-80 max-w-full" autocomplete="off" aria-label="Search merchants"><button class="btn-primary px-4 py-2 text-sm">Search</button></form>
    <?php if ($canManageAll): ?>
    <a href="add_merchant.php" class="btn-primary text-sm">+ Add Merchant</a>
    <?php endif; ?>
</div>
<?php if (!$canManageAll && empty($merchants)): ?>
<div class="glass rounded-xl p-8 text-center text-gray-400 text-sm">No merchants assigned to you yet. Ask your manager to assign merchants from Merchant View.</div>
<?php else: ?>
<div id="merchant-results" class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm min-w-[720px]">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Code</th><th class="px-5 py-3 text-left">Business</th>
                <th class="px-5 py-3 text-left">Entity Type</th><th class="px-5 py-3 text-left">Contact</th><th class="px-5 py-3 text-left">KYC</th>
                <th class="px-5 py-3 text-left">Mode</th>
            <th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Actions</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($merchants as $m):
                $mid = (int)$m['id'];
                $waUrl = merchantWhatsAppUrl($m['phone'] ?? '');
                $phoneIsPlaceholder = function_exists('isPlaceholderMerchantPhone')
                    && isPlaceholderMerchantPhone((string)($m['phone'] ?? ''), $mid);
                $bizIsDefault = trim((string)($m['business_name'] ?? '')) === 'My Business';
            ?>
            <tr class="hover:bg-white/5">
                <td class="px-5 py-3 font-mono text-xs">
                    <?= adminMerchantLink($mid, $m['merchant_code']) ?>
                </td>
                <td class="px-5 py-3">
                    <?= adminMerchantLink($mid, $m['business_name'], 'font-medium text-white hover:text-sky-300') ?>
                    <p class="text-xs text-gray-500"><?= e($m['name']) ?></p>
                    <?php if ($bizIsDefault): ?>
                    <p class="text-[10px] text-amber-400/90 mt-0.5">Default business name (email signup — setup not finished)</p>
                    <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-xs"><?= e(entityTypeLabel($m['business_entity_type'] ?? 'sole_proprietorship')) ?></td>
                <td class="px-5 py-3 text-xs">
                    <p><?= merchantMailtoLink((string)$m['email']) ?></p>
                    <?php if (!empty($m['phone'])): ?>
                    <p class="text-gray-500 mt-0.5">
                        <?php if ($phoneIsPlaceholder): ?>
                        <span class="text-amber-300" title="System placeholder for email signup — not a real WhatsApp number"><?= e($m['phone']) ?></span>
                        <span class="block text-[10px] text-amber-400/80 mt-0.5">Provisional phone (email signup)</span>
                        <?php else: ?>
                        <a href="tel:<?= e(preg_replace('/\D+/', '', $m['phone'])) ?>" class="hover:text-white"><?= e($m['phone']) ?></a>
                        <?php if ($waUrl): ?> · <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="text-emerald-400 hover:underline">WA</a><?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </td>
                <td class="px-5 py-3">
                    <a href="<?= e(adminMerchantKycUrl($mid)) ?>" class="hover:opacity-90"><?= statusBadge($m['kyc_status']) ?></a>
                </td>
                <td class="px-5 py-3">
                    <a href="<?= e(adminMerchantModeUrl($mid)) ?>" class="text-xs hover:underline <?= isMerchantLive($m) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= isMerchantLive($m) ? 'Live' : 'Test' ?>
                    </a>
                </td>
                <td class="px-5 py-3"><?= statusBadge($m['status']) ?></td>
                <td class="px-5 py-3">
                    <div class="flex flex-wrap gap-2">
                    <a href="<?= e(adminMerchantUrl($mid)) ?>" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold">View</a>
                    <?php if (staffCanAccess('admin_refunds.php')): ?>
                    <a href="<?= e(adminMerchantRefundsUrl($mid)) ?>" class="text-xs text-amber-400 hover:text-amber-300">Refunds</a>
                    <?php endif; ?>
                    <?php if (staffCanAccess('admin_transactions.php')): ?>
                    <a href="<?= e(adminMerchantTransactionsUrl($mid)) ?>" class="text-xs text-gray-400 hover:text-white">Txns</a>
                    <?php endif; ?>
                    <?php if ($canManageAll): ?>
                    <?php if ($m['status'] === 'active'): ?>
                    <a href="?action=disable&id=<?= $m['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-amber-400 hover:text-amber-300" onclick="return confirm('Disable this merchant?')">Disable</a>
                    <?php elseif ($m['status'] === 'suspended'): ?>
                    <a href="?action=enable&id=<?= $m['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400">Enable</a>
                    <?php endif; ?>
                    <?php if (staffCanEditMerchant()): ?>
                    <a href="<?= e(adminMerchantEditUrl($mid)) ?>" class="text-xs text-brand-400">Edit</a>
                    <?php endif; ?>
                    <?php if (staffCanAccess('admin_gateway_submit.php')): ?>
                    <a href="admin_gateway_submit.php" class="text-xs text-cyan-400">Gateway</a>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?= $m['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-red-400 hover:text-red-300" onclick="return confirm('Permanently delete this merchant account?')">Delete</a>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= renderListPagination($listParams['page'], $merchantTotal, $listParams['perPage'], ['q' => $search]) ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
