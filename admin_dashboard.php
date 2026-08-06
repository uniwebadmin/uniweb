<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
ensureDisputesEngine();
$db = getDB();

$totalMerchants = (int)$db->query("SELECT COUNT(*) as c FROM merchants WHERE status != 'deleted'")->fetch()['c'];
$activeMerchants = (int)$db->query("SELECT COUNT(*) as c FROM merchants WHERE status = 'active'")->fetch()['c'];
$pendingKyc = (int)$db->query("SELECT COUNT(*) as c FROM merchants WHERE kyc_status IN ('pending','submitted')")->fetch()['c'];
$todayTxn = $db->query("SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM transactions WHERE status='success' AND DATE(created_at)=CURDATE()")->fetch();
$monthTxn = $db->query("SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM transactions WHERE status='success' AND MONTH(created_at)=MONTH(CURDATE())")->fetch();
$pendingSettlements = (int)$db->query("SELECT COUNT(*) as c FROM settlements WHERE status='pending'")->fetch()['c'];
$openDisputes = (int)$db->query("SELECT COUNT(*) as c FROM disputes WHERE status='open'")->fetch()['c'];
$agedSettlements = (int)$db->query("SELECT COUNT(*) FROM settlements WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
try {
    ensureRefundsEngine();
    $agedRefunds = (int)$db->query("SELECT COUNT(*) FROM refunds WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")->fetchColumn();
} catch (Throwable $e) {
    $agedRefunds = 0;
}
$unresolvedErrors = countUnresolvedPlatformErrors();
$lastAutoAudit = getLastAutoAuditRun();
$gatewayGaps = getGatewaySetupGaps();
$quickWatchdog = $_SESSION['watchdog_quick_scan'] ?? null;
$watchdogIssues = $quickWatchdog ? (
    (int)($quickWatchdog['summary']['broken_links'] ?? 0)
    + (int)($quickWatchdog['summary']['missing_files'] ?? 0)
    + (int)($quickWatchdog['summary']['syntax_fail'] ?? 0)
) : 0;

$recentTxns = $db->query('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id=m.id ORDER BY t.created_at DESC LIMIT 10')->fetchAll();
$recentMerchants = $db->query('SELECT * FROM merchants WHERE status != "deleted" ORDER BY created_at DESC LIMIT 5')->fetchAll();

$platformWallet = getPlatformWalletBalance();
$readiness = getPlatformReadiness();
$opsAllClear = ($unresolvedErrors === 0 && $watchdogIssues === 0 && $pendingKyc === 0 && ($lastAutoAudit && !empty($lastAutoAudit['ok'])));
$todayVol = capStatAmount((float)$todayTxn['t']);
$monthVol = capStatAmount((float)$monthTxn['t']);
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/header.php';
?>

<div class="flex flex-wrap gap-2 sm:gap-3 mb-6 sm:mb-8">
    <a href="admin_partners.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-violet-400 hover:text-violet-300">All Partners</a>
    <a href="admin_platform_status.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Platform Status</a>
    <a href="admin_error_log.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm <?= $unresolvedErrors > 0 ? 'text-red-400 border border-red-500/30' : 'text-gray-400' ?>">Error Log<?= $unresolvedErrors > 0 ? " ($unresolvedErrors)" : '' ?></a>
    <a href="admin_watchdog.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400">Link Watchdog</a>
    <a href="admin_link_audit.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400">Link Audit</a>
    <a href="admin_partner_decentro.php" class="bg-violet-600/20 border border-violet-500/30 text-violet-300 px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm font-medium hover:bg-violet-600/30">Decentro Checklist</a>
    <a href="platform_demo.php" target="_blank" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-violet-400">Platform Tour ↗</a>
    <a href="admin_reconciliation.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400 hover:text-sky-300">Reconciliation</a>
    <a href="admin_risk_engine.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-red-400 hover:text-red-300">Risk Engine</a>
    <a href="admin_rolling_reserve.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400 hover:text-amber-300">Rolling Reserve</a>
    <a href="admin_grievance.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-orange-400 hover:text-orange-300">Grievance</a>
    <a href="admin_partner_commercial.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-violet-400 hover:text-violet-300">Partner Stats</a>
    <a href="admin_merchant_health.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Health Scores</a>
    <a href="admin_security_hardening.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-red-400 hover:text-red-300">Security</a>
    <a href="admin_gateway_matrix.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400 hover:text-sky-300">Gateway Matrix</a>
    <a href="admin_webhook_reliability.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400 hover:text-amber-300">Webhooks</a>
    <a href="admin_reports.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400 hover:text-sky-300">Reports</a>
    <a href="admin_circuit_breaker.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-red-400 hover:text-red-300">Circuit Breaker</a>
    <a href="admin_throughput.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Throughput</a>
    <a href="admin_sub_merchants.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-violet-400 hover:text-violet-300">Sub-Merchants</a>
    <a href="admin_integration_matrix.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400 hover:text-sky-300">Integration Matrix</a>
    <a href="admin_ledger_state.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Ledger State</a>
    <a href="admin_audit_log.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400 hover:text-amber-300">Audit Log</a>
    <a href="gateway_settings.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-gray-300 hover:text-white">Gateway Keys</a>
    <a href="demo.php" target="_blank" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-brand-400">Live Demo ↗</a>
    <a href="<?= APP_URL ?>/demo" target="_blank" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-gray-400">/demo</a>
</div>

<div class="glass rounded-xl p-5 mb-8 border border-sky-500/30">
    <div class="flex flex-wrap justify-between items-center gap-3 mb-4"><div><p class="font-semibold text-white">Decision Center</p><p class="text-xs text-gray-500 mt-1">Prioritize by risk, money impact and age. Review queues before routine work.</p></div><a href="admin_reconciliation.php" class="text-xs text-sky-400">Reconciliation queue →</a></div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
        <a href="admin_kyc.php" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 hover:border-amber-500/50"><p class="text-xs text-gray-500">Risk · KYC review</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= $pendingKyc ?></p><p class="text-xs text-gray-500 mt-1">merchant(s) waiting</p></a>
        <a href="admin_disputes.php" class="rounded-xl border border-red-500/30 bg-red-500/5 p-4 hover:border-red-500/50"><p class="text-xs text-gray-500">Risk · Open disputes</p><p class="text-2xl font-bold text-red-400 mt-1"><?= $openDisputes ?></p><p class="text-xs text-gray-500 mt-1">needs evidence / decision</p></a>
        <a href="admin_settlements.php?status=pending" class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-4 hover:border-violet-500/50"><p class="text-xs text-gray-500">Money · Payout 24h+</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= $agedSettlements ?></p><p class="text-xs text-gray-500 mt-1">aged bank transfer(s)</p></a>
        <a href="admin_refunds.php?status=pending" class="rounded-xl border border-fuchsia-500/30 bg-fuchsia-500/5 p-4 hover:border-fuchsia-500/50"><p class="text-xs text-gray-500">Money · Refund 3d+</p><p class="text-2xl font-bold text-fuchsia-400 mt-1"><?= $agedRefunds ?></p><p class="text-xs text-gray-500 mt-1">aged customer refund(s)</p></a>
    </div>
</div>

<?php if ($opsAllClear): ?>
<div class="glass rounded-xl p-4 mb-8 border border-emerald-500/40 bg-emerald-500/5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="font-semibold text-emerald-300">● All systems operational</p>
        <p class="text-xs text-gray-500 mt-1">Auto-audit OK · No errors · No broken links · KYC queue clear · Hostinger cron running every <?= (int)(autoAuditIntervalSeconds() / 60) ?> min</p>
    </div>
    <a href="admin_watchdog.php?tab=auto" class="text-xs text-emerald-400">Audit history →</a>
</div>
<?php endif; ?>

<div class="glass rounded-xl p-5 mb-8 border <?= ($unresolvedErrors === 0 && $watchdogIssues === 0 && $pendingKyc === 0) ? 'border-emerald-500/30' : 'border-amber-500/40' ?>">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <p class="font-semibold text-white">Good morning — daily check</p>
            <p class="text-xs text-gray-500 mt-1">Background auto-audit every <?= (int)(autoAuditIntervalSeconds() / 60) ?> min · Link Watchdog for full scan</p>
        </div>
        <div class="flex flex-wrap gap-2">
        <a href="admin_watchdog.php?tab=auto" class="glass text-xs px-3 py-2 rounded-lg text-violet-400">Auto Audit</a>
        <a href="admin_watchdog.php?scan=1&csrf=<?= e(csrfToken()) ?>" class="btn-primary text-xs px-4 py-2">Run Full Watchdog Scan</a>
        </div>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3 text-sm">
        <a href="admin_watchdog.php?tab=auto" class="rounded-lg border border-violet-500/30 bg-violet-500/5 px-4 py-3 hover:border-violet-500/50">
            <p class="text-xs text-gray-500">Auto-audit</p>
            <p class="text-sm font-bold <?= ($lastAutoAudit && !empty($lastAutoAudit['ok'])) ? 'text-emerald-400' : 'text-violet-400' ?>">
                <?= $lastAutoAudit ? (!empty($lastAutoAudit['ok']) ? '● ON · OK' : '● Issues') : '● Starting…' ?>
            </p>
        </a>
        <a href="admin_error_log.php" class="rounded-lg border border-gray-800 bg-dark-900/40 px-4 py-3 hover:border-red-500/30">
            <p class="text-xs text-gray-500">Errors</p>
            <p class="text-xl font-bold <?= $unresolvedErrors > 0 ? 'text-red-400' : 'text-emerald-400' ?>"><?= $unresolvedErrors ?></p>
        </a>
        <a href="admin_watchdog.php" class="rounded-lg border border-gray-800 bg-dark-900/40 px-4 py-3 hover:border-amber-500/30">
            <p class="text-xs text-gray-500">Link issues</p>
            <p class="text-xl font-bold <?= $watchdogIssues > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $watchdogIssues ?></p>
        </a>
        <a href="admin_kyc.php" class="rounded-lg border border-gray-800 bg-dark-900/40 px-4 py-3 hover:border-amber-500/30">
            <p class="text-xs text-gray-500">Pending KYC</p>
            <p class="text-xl font-bold <?= $pendingKyc > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $pendingKyc ?></p>
        </a>
        <a href="admin_platform_status.php" class="rounded-lg border border-gray-800 bg-dark-900/40 px-4 py-3 hover:border-emerald-500/30">
            <p class="text-xs text-gray-500">Platform health</p>
            <p class="text-xl font-bold text-emerald-400"><?= (int)($readiness['pct'] ?? 0) ?>%</p>
        </a>
    </div>
    <?php if ($lastAutoAudit): ?>
    <div class="mt-4 pt-4 border-t border-gray-800 text-xs text-gray-500">
        Last background audit: <?= formatDate($lastAutoAudit['ran_at'] ?? '') ?>
        · <?= !empty($lastAutoAudit['ok']) ? '<span class="text-emerald-400">all clear</span>' : '<span class="text-amber-400">' . (int)($lastAutoAudit['failed'] ?? 0) . ' issue(s)</span>' ?>
        <?php if (!empty($lastAutoAudit['merchants_fixed'])): ?> · <?= (int)$lastAutoAudit['merchants_fixed'] ?> merchant(s) auto-activated<?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($pendingKyc > 0):
    $kycQueue = getPendingKycQueue(min(5, $pendingKyc));
?>
<div class="glass rounded-xl p-5 mb-8 border border-amber-500/40 bg-amber-500/5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-semibold text-amber-300"><?= (int)$pendingKyc ?> merchant(s) waiting for KYC review</p>
            <p class="text-xs text-gray-500 mt-1">Review documents and send for independent checker approval. Live mode is a separate activation gate.<?php if (!empty($kycQueue)): ?> — <?= e(implode(', ', array_map(static fn($m) => (string)($m['business_name'] ?? $m['merchant_code'] ?? ''), $kycQueue))) ?><?php endif; ?></p>
        </div>
        <a href="admin_kyc.php" class="btn-primary text-xs px-4 py-2 whitespace-nowrap">Review KYC →</a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($gatewayGaps)): ?>
<div class="glass rounded-xl p-5 mb-8 border border-amber-500/30">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <p class="font-semibold text-amber-300">Gateway setup needed (<?= count($gatewayGaps) ?>)</p>
        <a href="gateway_settings.php" class="text-xs text-sky-400">Gateway Settings →</a>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2 text-xs">
        <?php foreach ($gatewayGaps as $gap): ?>
        <a href="<?= e($gap['test_url'] ?? 'gateway_settings.php') ?>" class="rounded-lg border border-gray-800 px-3 py-2 hover:border-amber-500/40">
            <span class="text-amber-400">● <?= e($gap['label']) ?></span>
            <span class="text-gray-500 block mt-0.5"><?= e($gap['status']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="text-[10px] text-gray-600 mt-3">Optional: WhatsApp, Axis, Decentro — enable when you receive partner keys.</p>
</div>
<?php endif; ?>

<div class="glass rounded-xl p-5 mb-8 border border-emerald-500/20">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-semibold text-emerald-300">Platform <?= $readiness['pct'] ?>% Ready</p>
            <p class="text-xs text-gray-500 mt-1"><?= $readiness['done'] ?>/<?= $readiness['total'] ?> checks · <?= $readiness['merchants'] ?> merchants · <?= $readiness['transactions'] ?> txns</p>
        </div>
        <a href="admin_platform_status.php" class="text-xs text-emerald-400 hover:text-emerald-300">Full Status →</a>
    </div>
    <div class="w-full bg-gray-800 rounded-full h-1.5 mt-3">
        <div class="bg-emerald-500 h-1.5 rounded-full" style="width:<?= $readiness['pct'] ?>%"></div>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <?php foreach ([
        ['Total Merchants', $totalMerchants, 'text-brand-400', 'manage_merchant.php'],
        ['Active Merchants', $activeMerchants, 'text-cyan-400', 'manage_merchant.php'],
        ['Pending KYC', $pendingKyc, 'text-amber-400', 'admin_kyc.php'],
        ['Pending Settlements', $pendingSettlements, 'text-purple-400', 'admin_settlements.php'],
        ['Platform Wallet', formatMoney($platformWallet), 'text-emerald-400', 'admin_wallet.php'],
    ] as [$l,$v,$c,$link]): ?>
    <a href="<?= e($link) ?>" class="stat-card border border-gray-800 rounded-xl p-3 sm:p-5 block hover:border-brand-500/40 transition min-w-0">
        <p class="text-xs text-gray-500"><?= $l ?></p>
        <p class="text-xl sm:text-3xl font-bold <?= $c ?> mt-1 break-words"><?= is_numeric($v) ? $v : $v ?></p>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <div class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0">
        <p class="text-xs text-gray-500">Today's Volume</p>
        <p class="text-xl sm:text-2xl font-bold text-brand-400 mt-1 break-words"><?= formatMoney($todayVol) ?></p>
        <p class="text-xs text-gray-600"><?= $todayTxn['c'] ?> transactions</p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0">
        <p class="text-xs text-gray-500">This Month</p>
        <p class="text-xl sm:text-2xl font-bold text-cyan-400 mt-1 break-words"><?= formatMoney($monthVol) ?></p>
        <p class="text-xs text-gray-600"><?= $monthTxn['c'] ?> transactions</p>
    </div>
    <div class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0 sm:col-span-2 lg:col-span-1">
        <p class="text-xs text-gray-500">Open Disputes</p>
        <p class="text-xl sm:text-2xl font-bold text-red-400 mt-1"><?= $openDisputes ?></p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex justify-between"><h2 class="font-semibold">Recent Transactions</h2><a href="admin_transactions.php" class="text-xs text-brand-400">View All</a></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($recentTxns as $t): ?>
                <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='<?= e(transactionDetailUrl($t['txn_id'])) ?>'">
                    <td class="px-4 sm:px-5 py-3"><p class="font-mono text-xs"><a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="text-sky-400 hover:underline"><?= e($t['txn_id']) ?></a></p><p class="text-xs text-gray-500"><?= e($t['business_name']) ?></p></td>
                    <td class="px-4 sm:px-5 py-3 font-semibold"><?= formatMoney(capStatAmount((float)$t['amount'])) ?></td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($t['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex justify-between"><h2 class="font-semibold">New Merchants</h2><a href="manage_merchant.php" class="text-xs text-brand-400">View All</a></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($recentMerchants as $m): ?>
                <tr<?= uiRowClick(adminMerchantUrl((int)$m['id'])) ?>>
                    <td class="px-4 sm:px-5 py-3">
                        <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="font-medium text-white hover:text-sky-300" onclick="event.stopPropagation()"><?= e($m['business_name']) ?></a>
                        <p class="text-xs text-gray-500 break-all"><?= e($m['email']) ?></p>
                    </td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($m['status']) ?></td>
                    <td class="px-4 sm:px-5 py-3 text-right" onclick="event.stopPropagation()"><a href="admin_view_merchant.php?id=<?= (int)$m['id'] ?>" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
