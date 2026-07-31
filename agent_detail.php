<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();

$agentId = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT * FROM merchants WHERE id = ? AND parent_merchant_id = ? AND status != ?');
$st->execute([$agentId, $merchant['id'], 'deleted']);
$agent = $st->fetch();
if (!$agent) {
    flash('error', 'Agent not found.');
    redirect('agents.php');
}

$stats = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM transactions WHERE merchant_id = ? AND status = 'success'");
$stats->execute([$agentId]);
$agentStats = $stats->fetch();

$recentTxns = $db->prepare('SELECT txn_id, amount, status, payment_method, created_at FROM transactions WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 10');
$recentTxns->execute([$agentId]);
$agentTxns = $recentTxns->fetchAll();

$pageTitle = 'Agent — ' . ($agent['business_name'] ?: $agent['merchant_code']);
require_once __DIR__ . '/header.php';
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <a href="agents.php" class="text-xs text-gray-400 hover:text-white">← Back to Agents</a>
        <h1 class="text-xl font-bold text-white mt-2"><?= e($agent['business_name'] ?: $agent['merchant_code']) ?></h1>
        <p class="text-xs text-gray-500 mt-1 font-mono"><?= e($agent['merchant_code']) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <?= statusBadge($agent['status']) ?>
    </div>
</div>

<div class="grid sm:grid-cols-3 gap-4 mb-8">
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Total Volume</p>
        <p class="text-2xl font-bold text-brand-400 mt-1"><?= formatMoney((float)$agentStats['total']) ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Successful Transactions</p>
        <p class="text-2xl font-bold text-cyan-400 mt-1"><?= (int)$agentStats['cnt'] ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Commission Rate</p>
        <p class="text-2xl font-bold text-amber-400 mt-1"><?= e($agent['agent_commission'] ?? '0.50') ?>%</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-4">Agent Details</h2>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Contact name</span><span><?= e($agent['name'] ?? '—') ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Phone</span><span><?= e($agent['phone'] ?? '—') ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Email</span><span class="break-all text-right"><?= e($agent['email'] ?? '—') ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">KYC status</span><?= statusBadge($agent['kyc_status'] ?? 'pending') ?></div>
            <div class="flex justify-between"><span class="text-gray-500">Added on</span><span><?= formatDate($agent['created_at'] ?? '') ?></span></div>
        </div>
    </div>
    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent Transactions</h2></div>
        <?php if (empty($agentTxns)): ?>
        <div class="px-6 py-8 text-center text-sm text-gray-500">No transactions yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($agentTxns as $t): ?>
                <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='<?= e(transactionDetailUrl($t['txn_id'])) ?>'">
                    <td class="px-5 py-3 font-mono text-xs"><a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($t['txn_id']) ?></a></td>
                    <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($t['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($t['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
