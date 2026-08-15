<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'support', 'kyc', 'finance']);
ensureSupportTicketTable();
ensureDisputesEngine();
$db = getDB();

$pendingKyc = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status IN ('submitted','pending')")->fetchColumn();
$openTickets = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
$openDisputes = (int)$db->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn();
$pendingRefunds = 0;
try {
    ensureRefundsEngine();
    $pendingRefunds = (int)$db->query("SELECT COUNT(*) FROM refunds WHERE status='pending'")->fetchColumn();
} catch (Throwable $e) { /* ok */ }

$pageTitle = 'Operations Dashboard';
require_once __DIR__ . '/header.php';
$admin = getAdmin();
$assignedCount = count(getStaffAssignedMerchants((int)($admin['id'] ?? 0)));
?>
<div class="mb-6">
    <p class="text-gray-400 text-sm">Welcome, <strong class="text-white"><?= e($admin['name'] ?? 'Staff') ?></strong> — <?= e(staffRoleLabel(adminRole($admin))) ?></p>
    <?php if ($assignedCount > 0): ?>
    <p class="text-xs text-sky-400 mt-1"><?= $assignedCount ?> merchant(s) assigned to you · <a href="manage_merchant.php" class="underline">View list</a></p>
    <?php endif; ?>
</div>
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php foreach ([
        ['Pending KYC', $pendingKyc, 'admin_kyc.php', 'text-amber-400'],
        ['Open Tickets', $openTickets, 'admin_support.php', 'text-sky-400'],
        ['Open Disputes', $openDisputes, 'admin_disputes.php', 'text-red-400'],
        ['Pending Refunds', $pendingRefunds, 'admin_refunds.php', 'text-emerald-400'],
    ] as [$label, $count, $url, $color]): if (!staffCanAccess($url)) continue; ?>
    <a href="<?= $url ?>" class="glass rounded-xl p-5 stat-card hover:border-sky-500/30 border border-gray-800 transition">
        <p class="text-xs text-gray-500"><?= $label ?></p>
        <p class="text-3xl font-bold mt-1 <?= $color ?>"><?= $count ?></p>
    </a>
    <?php endforeach; ?>
</div>
<?php if ($pendingKyc === 0 && $openTickets === 0 && $openDisputes === 0 && $pendingRefunds === 0): ?>
<div class="glass rounded-xl p-8 mb-8 text-center border border-emerald-500/20">
    <p class="text-emerald-300 font-semibold">Queue clear</p>
    <p class="text-sm text-gray-500 mt-2">No pending KYC, tickets, disputes or refunds in your workspace right now.</p>
</div>
<?php endif; ?>
<?php if (staffCanAccess('admin_kyc.php') && $pendingKyc > 0):
    $queue = [];
    if (function_exists('getPendingKycQueue')) {
        try {
            $queue = getPendingKycQueue(5);
        } catch (Throwable $e) {
            $queue = [];
        }
    }
?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-amber-500/30">
    <div class="px-5 py-3 border-b border-gray-800 flex justify-between items-center">
        <h2 class="font-semibold text-sm text-amber-300">KYC queue — verify for Live</h2>
        <a href="admin_kyc.php" class="text-xs text-sky-400">Open KYC →</a>
    </div>
    <div class="divide-y divide-gray-800">
        <?php if (empty($queue)): ?>
        <p class="px-5 py-4 text-sm text-gray-500">KYC queue could not be loaded. Open KYC Review to continue.</p>
        <?php else: foreach ($queue as $m): ?>
        <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <div>
                <p class="font-medium"><?= e($m['business_name'] ?: $m['merchant_code']) ?></p>
                <p class="text-xs text-gray-500 font-mono"><?= e($m['merchant_code']) ?> · <?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?= statusBadge($m['kyc_status']) ?>
                <a href="admin_view_merchant.php?id=<?= (int)$m['id'] ?>" class="text-xs text-emerald-400">View</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="glass rounded-xl p-6">
    <h2 class="font-semibold mb-4">Your workspace</h2>
    <div class="grid sm:grid-cols-2 gap-3 text-sm">
        <?php foreach (staffNavForRole(adminRole($admin)) as [$url, $label]): if ($url === 'staff_dashboard.php') continue; ?>
        <a href="<?= $url ?>" class="px-4 py-3 rounded-lg border border-gray-800 hover:border-sky-500/40 hover:bg-white/5 transition"><?= e($label) ?> →</a>
        <?php endforeach; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
