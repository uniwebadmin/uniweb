<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$db = getDB();

// Ensure merchant_qr_codes has payment_order_id column (migration 045)
try {
    $db->exec("ALTER TABLE merchant_qr_codes ADD COLUMN IF NOT EXISTS payment_order_id INT DEFAULT NULL");
    $db->exec("ALTER TABLE merchant_qr_codes ADD INDEX IF NOT EXISTS idx_qr_order (payment_order_id)");
} catch (Throwable $e) {}

$pageTitle = 'Orders';
require_once __DIR__ . '/header.php';

$statusFilter = trim($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'created', 'pending', 'paid', 'failed', 'expired'], true)) $statusFilter = 'all';
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = 'o.merchant_id = ?';
$params = [$merchantId];
if ($statusFilter !== 'all') {
    $where .= ' AND o.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $where .= ' AND (o.order_ref LIKE ? OR o.description LIKE ? OR o.customer_name LIKE ? OR o.provider_order_id LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$countSt = $db->prepare("SELECT COUNT(*) FROM payment_orders o WHERE $where");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();

$st = $db->prepare("SELECT o.*, pl.link_id, pl.description AS link_desc, q.qr_code
    FROM payment_orders o
    LEFT JOIN payment_links pl ON pl.id = o.payment_link_id
    LEFT JOIN merchant_qr_codes q ON q.payment_order_id = o.id
    WHERE $where
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset");
$st->execute($params);
$orders = $st->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));
?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Orders</h1>
            <p class="text-sm text-gray-500 mt-1">All payment orders — from links, QR codes, and direct API.</p>
        </div>
        <div class="flex gap-2">
            <a href="payment_links.php" class="glass px-4 py-2 rounded-xl text-sm text-sky-400 hover:text-sky-300">Payment Links →</a>
            <a href="qr_code.php" class="glass px-4 py-2 rounded-xl text-sm text-emerald-400 hover:text-emerald-300">QR Codes →</a>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search order ref, customer, provider..." class="bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700 w-64">
        <select name="status" class="bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
            <option value="created" <?= $statusFilter === 'created' ? 'selected' : '' ?>>Created</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
        </select>
        <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white rounded-lg px-4 py-2 text-sm">Filter</button>
    </form>

    <div class="text-xs text-gray-500 mb-4"><?= number_format($total) ?> orders · Page <?= $page ?> of <?= $totalPages ?></div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Order Ref</th><th class="px-4 py-3 text-left">Amount</th>
                <th class="px-4 py-3 text-left">Customer</th><th class="px-4 py-3 text-left">Provider</th>
                <th class="px-4 py-3 text-left">Source</th><th class="px-4 py-3 text-left">QR / Link</th>
                <th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No orders found.</td></tr>
                <?php else: foreach ($orders as $o):
                    $scls = match(strtolower($o['status'])) {
                        'paid' => 'text-emerald-400',
                        'pending' => 'text-sky-400',
                        'created' => 'text-gray-400',
                        'failed' => 'text-red-400',
                        'expired' => 'text-amber-400',
                        default => 'text-gray-400',
                    };
                ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-sky-400"><?= e($o['order_ref']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-300"><?= formatMoney((float)$o['expected_amount']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e($o['customer_name'] ?? '—') ?><br><span class="text-gray-600"><?= e($o['customer_phone'] ?? '') ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e($o['provider'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs"><span class="px-2 py-0.5 rounded bg-gray-700 text-gray-300"><?= e($o['source'] ?? 'link') ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        <?php if (!empty($o['qr_code'])): ?>
                            <a href="qr_code.php?code=<?= e($o['qr_code']) ?>" class="text-emerald-400 hover:underline">QR: <?= e(mb_substr($o['qr_code'], 0, 12)) ?></a>
                        <?php elseif (!empty($o['link_id'])): ?>
                            <a href="payment_links.php#<?= e($o['link_id']) ?>" class="text-sky-400 hover:underline">Link: <?= e(mb_substr($o['link_id'], 0, 12)) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs <?= $scls ?>"><?= e($o['status']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($o['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2 mt-6">
        <?php for ($i = max(1, $page - 4); $i <= min($totalPages, $page + 4); $i++): ?>
        <a href="?status=<?= e($statusFilter) ?>&q=<?= e($q) ?>&page=<?= $i ?>"
           class="px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'bg-sky-600 text-white' : 'glass text-gray-400 hover:text-white' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
