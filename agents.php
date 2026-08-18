<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantAgentColumns();
if (!function_exists('getChildMerchants')) {
    require_once __DIR__ . '/includes/sub_merchant.php';
}
$merchant = getMerchant();
$db = getDB();
$agentList = getChildMerchants((int)$merchant['id']);

$agentStats = $db->prepare("SELECT COALESCE(SUM(t.amount),0) as total, COUNT(*) as cnt FROM transactions t JOIN merchants m ON t.merchant_id=m.id WHERE m.parent_merchant_id = ? AND t.status='success'");
$agentStats->execute([$merchant['id']]);
$stats = $agentStats->fetch();

$pageTitle = __('agents');
require_once __DIR__ . '/header.php';
?>
<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-4 mb-6 text-sm">
    <p class="text-sky-200 font-medium">Agents = franchise / branch child merchants</p>
    <p class="text-xs text-gray-500 mt-1">Not the same as <a href="merchant_team.php" class="text-sky-400 underline">Team Members</a> (people who log into your portal). Each agent here is also linked in Admin → Sub-Merchant Hierarchy automatically. Open via search if you need this page.</p>
    <p class="text-xs text-gray-500 mt-1">This is not a customer PPI wallet.</p>
</div>
<div class="grid grid-cols-2 gap-4 mb-8 max-w-lg">
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">Total Agents</p>
        <p class="text-2xl font-bold text-brand-400"><?= count($agentList) ?></p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">Agent Volume</p>
        <p class="text-2xl font-bold text-cyan-400"><?= formatMoney((float)$stats['total']) ?></p>
    </div>
</div>

<div class="flex justify-between items-center mb-6">
    <h2 class="font-semibold">Your Agents</h2>
    <a href="add_agent.php" class="btn-primary text-sm px-4 py-2">+ Add Agent</a>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Code</th><th class="px-5 py-3 text-left">Business</th>
            <th class="px-5 py-3 text-left">Contact</th><th class="px-5 py-3 text-left">KYC</th>
            <th class="px-5 py-3 text-left">Commission</th><th class="px-5 py-3 text-left">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php if (empty($agentList)): ?>
            <tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No agents yet. Add using the button above.</td></tr>
            <?php else: foreach ($agentList as $a): ?>
            <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='agent_detail.php?id=<?= (int)$a['id'] ?>'">
                <td class="px-5 py-3 font-mono text-xs"><a href="agent_detail.php?id=<?= (int)$a['id'] ?>" class="text-sky-400 hover:underline"><?= e($a['merchant_code']) ?></a></td>
                <td class="px-5 py-3"><?= e($a['business_name']) ?></td>
                <td class="px-5 py-3 text-xs"><?= e($a['phone']) ?></td>
                <td class="px-5 py-3"><?= statusBadge($a['kyc_status']) ?></td>
                <td class="px-5 py-3"><?= $a['agent_commission'] ?? '0.50' ?>%</td>
                <td class="px-5 py-3"><?= statusBadge($a['status']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
