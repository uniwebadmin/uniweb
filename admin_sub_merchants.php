<?php
require_once __DIR__ . '/config.php';
if (!function_exists('addSubMerchant')) {
    require_once __DIR__ . '/includes/sub_merchant.php';
}
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_sub' && isset($_POST['parent_id'], $_POST['child_id'])) {
        $r = addSubMerchant((int)$_POST['parent_id'], (int)$_POST['child_id'], trim($_POST['relationship'] ?? 'branch'));
        if ($r['ok'] ?? false) {
            flash('success', 'Sub-merchant added.');
        } else {
            flash('error', $r['error'] ?? 'Failed to add sub-merchant.');
        }
        redirect('admin_sub_merchants.php');
    }
    if ($action === 'remove_sub' && isset($_POST['parent_id'], $_POST['child_id'])) {
        removeSubMerchant((int)$_POST['parent_id'], (int)$_POST['child_id']);
        flash('success', 'Sub-merchant removed.');
        redirect('admin_sub_merchants.php');
    }
}

$merchants = [];
try {
    $st = getDB()->query("SELECT id, merchant_code, business_name, status FROM merchants ORDER BY business_name LIMIT 500");
    $merchants = $st->fetchAll();
} catch (Throwable $e) {}

$hierarchies = [];
try {
    $st = getDB()->query("SELECT h.*, p.business_name AS parent_name, p.merchant_code AS parent_code,
        c.business_name AS child_name, c.merchant_code AS child_code
        FROM merchant_hierarchy h
        JOIN merchants p ON p.id = h.parent_merchant_id
        JOIN merchants c ON c.id = h.child_merchant_id
        WHERE h.status='active'
        ORDER BY p.business_name, c.business_name LIMIT 200");
    $hierarchies = $st->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'Sub-Merchant Hierarchy';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="glass rounded-xl p-5 border border-sky-500/30">
        <h3 class="font-semibold mb-2">How this works</h3>
        <ul class="text-sm text-gray-400 space-y-1.5 list-disc pl-5">
            <li>Link two existing merchants: parent (head office) and child (branch, franchise, outlet, or store).</li>
            <li>Settlements and reports can roll up to the parent. Each child keeps its own login and KYC.</li>
            <li><strong>Two merchant models:</strong> Sub-merchants / Agents = same parent–child tree (this page + merchant Agents page stay in sync). Team Members = portal login users only.</li>
            <li>Bank/PG partners are rails with keys — they do <strong>not</strong> own this hierarchy or merchant logins.</li>
            <li>This is not a customer PPI wallet and not an NBFC loan product.</li>
            <li>Only UniWeb admin can add or remove these hierarchy links.</li>
        </ul>
    </div>
    <p class="text-sm text-gray-400">Manage parent/child merchant relationships. Settlements and reports roll up to parent.</p>

    <div class="glass rounded-xl p-5">
        <h3 class="font-semibold mb-4">Add Sub-Merchant</h3>
        <form method="POST" class="grid sm:grid-cols-4 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_sub">
            <div><label class="text-sm text-gray-400">Parent Merchant</label>
                <select name="parent_id" class="input-field mt-1 w-full" required>
                    <option value="">Select parent...</option>
                    <?php foreach ($merchants as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"><?= e($m['business_name']) ?> (<?= e($m['merchant_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Child Merchant</label>
                <select name="child_id" class="input-field mt-1 w-full" required>
                    <option value="">Select child...</option>
                    <?php foreach ($merchants as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"><?= e($m['business_name']) ?> (<?= e($m['merchant_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Relationship</label>
                <select name="relationship" class="input-field mt-1 w-full">
                    <option value="branch">Branch</option>
                    <option value="franchise">Franchise</option>
                    <option value="outlet">Outlet</option>
                    <option value="store">Store</option>
                </select>
            </div>
            <button type="submit" class="btn-primary px-4 py-2">Add</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Active Hierarchies</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[700px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Parent</th><th class="px-4 py-3 text-left">Child</th><th class="px-4 py-3 text-left">Relationship</th><th class="px-4 py-3 text-left">Created</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($hierarchies)): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No sub-merchant relationships configured.</td></tr>
                <?php else: foreach ($hierarchies as $h): ?>
                <tr>
                    <td class="px-4 py-3"><?= e($h['parent_name']) ?> <span class="font-mono text-xs text-gray-500"><?= e($h['parent_code']) ?></span></td>
                    <td class="px-4 py-3"><?= e($h['child_name']) ?> <span class="font-mono text-xs text-gray-500"><?= e($h['child_code']) ?></span></td>
                    <td class="px-4 py-3 capitalize"><?= e($h['relationship']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= formatDate($h['created_at']) ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="remove_sub">
                            <input type="hidden" name="parent_id" value="<?= (int)$h['parent_merchant_id'] ?>">
                            <input type="hidden" name="child_id" value="<?= (int)$h['child_merchant_id'] ?>">
                            <button type="submit" class="text-xs text-red-400 hover:underline" onclick="return confirm('Remove this sub-merchant?')">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
