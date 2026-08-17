<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$totalMerchants = 0;
$activeMerchants = 0;
$pendingKyc = 0;
$todayTxn = ['t' => 0, 'c' => 0];
$monthTxn = ['t' => 0, 'c' => 0];
$pendingSettlements = 0;
$openDisputes = 0;
$agedSettlements = 0;
$agedRefunds = 0;
$unresolvedErrors = 0;
$lastAutoAudit = null;
$gatewayGaps = [];
$quickWatchdog = null;
$watchdogIssues = 0;
$recentTxns = [];
$recentMerchants = [];
$platformWallet = 0;
$readiness = ['pct' => 0, 'done' => 0, 'total' => 0, 'merchants' => 0, 'transactions' => 0];
$opsAllClear = false;
$todayVol = 0;
$monthVol = 0;

if (!function_exists('adminDashWidget')) {
    function adminDashWidget(string $widget, callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'admin_dashboard ' . $widget . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            }
            return $fallback;
        }
    }
}

$db = adminDashWidget('db', static fn() => getDB(), null);
adminDashWidget('disputes_engine', static function () {
    ensureDisputesEngine();
    return true;
}, false);

if ($db) {
    $totalMerchants = (int)adminDashWidget('total_merchants', static fn() => $db->query("SELECT COUNT(*) as c FROM merchants WHERE status != 'deleted'")->fetch()['c'] ?? 0, 0);
    $activeMerchants = (int)adminDashWidget('active_merchants', static fn() => $db->query("SELECT COUNT(*) as c FROM merchants WHERE status = 'active'")->fetch()['c'] ?? 0, 0);
    $pendingKyc = (int)adminDashWidget('pending_kyc', static fn() => $db->query("SELECT COUNT(*) as c FROM merchants WHERE kyc_status IN ('pending','submitted')")->fetch()['c'] ?? 0, 0);
    $todayTxn = adminDashWidget('today_txn', static fn() => $db->query("SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM transactions WHERE status='success' AND DATE(created_at)=CURDATE()")->fetch() ?: ['t' => 0, 'c' => 0], ['t' => 0, 'c' => 0]);
    $monthTxn = adminDashWidget('month_txn', static fn() => $db->query("SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM transactions WHERE status='success' AND MONTH(created_at)=MONTH(CURDATE())")->fetch() ?: ['t' => 0, 'c' => 0], ['t' => 0, 'c' => 0]);
    $pendingSettlements = (int)adminDashWidget('pending_settlements', static fn() => $db->query("SELECT COUNT(*) as c FROM settlements WHERE status='pending'")->fetch()['c'] ?? 0, 0);
    $openDisputes = (int)adminDashWidget('open_disputes', static fn() => $db->query("SELECT COUNT(*) as c FROM disputes WHERE status='open'")->fetch()['c'] ?? 0, 0);
    $agedSettlements = (int)adminDashWidget('aged_settlements', static fn() => $db->query("SELECT COUNT(*) FROM settlements WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn(), 0);
    $agedRefunds = (int)adminDashWidget('aged_refunds', static function () use ($db) {
        ensureRefundsEngine();
        return $db->query("SELECT COUNT(*) FROM refunds WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")->fetchColumn();
    }, 0);
    $recentTxns = adminDashWidget('recent_txns', static fn() => $db->query('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id=m.id ORDER BY t.created_at DESC LIMIT 10')->fetchAll() ?: [], []);
    $recentMerchants = adminDashWidget('recent_merchants', static fn() => $db->query('SELECT * FROM merchants WHERE status != "deleted" ORDER BY created_at DESC LIMIT 5')->fetchAll() ?: [], []);
}

$unresolvedErrors = (int)adminDashWidget('unresolved_errors', static fn() => countUnresolvedPlatformErrors(), 0);
$lastAutoAudit = adminDashWidget('last_auto_audit', static fn() => getLastAutoAuditRun(), null);
$gatewayGaps = adminDashWidget('gateway_gaps', static fn() => getGatewaySetupGaps(), []);
$quickWatchdog = $_SESSION['watchdog_quick_scan'] ?? null;
$watchdogIssues = $quickWatchdog ? (
    (int)($quickWatchdog['summary']['broken_links'] ?? 0)
    + (int)($quickWatchdog['summary']['missing_files'] ?? 0)
    + (int)($quickWatchdog['summary']['syntax_fail'] ?? 0)
) : 0;
$platformWallet = (float)adminDashWidget('platform_wallet', static fn() => getPlatformWalletBalance(), 0);
$readiness = adminDashWidget('readiness', static fn() => getPlatformReadiness(), ['pct' => 0, 'done' => 0, 'total' => 0, 'merchants' => 0, 'transactions' => 0]);
$opsAllClear = ($unresolvedErrors === 0 && $watchdogIssues === 0 && $pendingKyc === 0 && ($lastAutoAudit && !empty($lastAutoAudit['ok'])));
$todayVol = capStatAmount((float)($todayTxn['t'] ?? 0));
$monthVol = capStatAmount((float)($monthTxn['t'] ?? 0));
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/header.php';
?>

<div class="glass rounded-xl p-5 mb-6 border border-emerald-500/25 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Live corridor — soft launch checklist (before advertise)</p>
    <ol class="text-xs text-gray-500 list-decimal list-inside space-y-1.5 mt-2">
        <li>CR-01: live <code class="text-gray-400">config.php</code> — remove old <code class="text-gray-400">createNotification</code>; keep <code class="text-gray-400">includes/notifications.php</code> (Hostinger File Manager; never overwrite secrets).</li>
        <li><a href="gateway_settings.php#cron-security" class="text-sky-400 hover:underline">Apply pending migrations</a> → JSON <code class="text-sky-300">ok: true</code> (never DROP database).</li>
        <li><a href="admin_gateway_registry.php" class="text-sky-400 hover:underline">Partner Registry → Keys</a>: paste Test keys → Test Connection → then one merchant <strong class="text-gray-300">Instant Test Pay</strong> on a Test Mode link.</li>
        <li><a href="gateway_settings.php" class="text-sky-400 hover:underline">SMTP</a> + backup notify email — so backup mail arrives.</li>
        <li>Then soft launch. Disputes queue stays <a href="admin_disputes.php" class="text-sky-400 hover:underline">Admin first</a>.</li>
    </ol>
</div>

<div class="flex flex-wrap gap-2 sm:gap-3 mb-4">
    <a href="admin_gateway_registry.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-violet-400 hover:text-violet-300">Partner Registry</a>
    <a href="admin_kyc.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400 hover:text-amber-300">KYC Review</a>
    <a href="admin_transactions.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-sky-400 hover:text-sky-300">Transactions</a>
    <a href="admin_settlements.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Settlements</a>
    <a href="admin_error_log.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm <?= $unresolvedErrors > 0 ? 'text-red-400 border border-red-500/30' : 'text-gray-400' ?>">Error Log<?= $unresolvedErrors > 0 ? " ($unresolvedErrors)" : '' ?></a>
    <a href="admin_watchdog.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-amber-400">Watchdog</a>
    <a href="admin_platform_status.php" class="glass px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl text-xs sm:text-sm text-emerald-400 hover:text-emerald-300">Platform Status</a>
</div>
<details class="mb-6 sm:mb-8">
    <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-300">Advanced pages</summary>
    <div class="flex flex-wrap gap-2 sm:gap-3 mt-3">
        <a href="admin_link_audit.php" class="glass px-3 py-2 rounded-xl text-xs text-sky-400">Link Audit</a>
        <a href="admin_partner_decentro.php" class="glass px-3 py-2 rounded-xl text-xs text-violet-300">Decentro Checklist</a>
        <a href="admin_reconciliation.php" class="glass px-3 py-2 rounded-xl text-xs text-sky-400">Reconciliation</a>
        <a href="admin_risk_engine.php" class="glass px-3 py-2 rounded-xl text-xs text-red-400">Risk Engine</a>
        <a href="admin_rolling_reserve.php" class="glass px-3 py-2 rounded-xl text-xs text-amber-400">Rolling Reserve</a>
        <a href="admin_grievance.php" class="glass px-3 py-2 rounded-xl text-xs text-orange-400">Grievance</a>
        <a href="admin_partner_commercial.php" class="glass px-3 py-2 rounded-xl text-xs text-violet-400">Partner Stats</a>
        <a href="admin_merchant_health.php" class="glass px-3 py-2 rounded-xl text-xs text-emerald-400">Health Scores</a>
        <a href="admin_security_hardening.php" class="glass px-3 py-2 rounded-xl text-xs text-red-400">Security</a>
        <a href="admin_gateway_matrix.php" class="glass px-3 py-2 rounded-xl text-xs text-sky-400">Gateway Matrix</a>
        <a href="admin_webhook_reliability.php" class="glass px-3 py-2 rounded-xl text-xs text-amber-400">Webhooks</a>
        <a href="admin_reports.php" class="glass px-3 py-2 rounded-xl text-xs text-sky-400">Reports</a>
        <a href="admin_circuit_breaker.php" class="glass px-3 py-2 rounded-xl text-xs text-red-400">Circuit Breaker</a>
        <a href="admin_throughput.php" class="glass px-3 py-2 rounded-xl text-xs text-emerald-400">Throughput</a>
        <a href="admin_sub_merchants.php" class="glass px-3 py-2 rounded-xl text-xs text-violet-400">Sub-Merchants</a>
        <a href="admin_integration_matrix.php" class="glass px-3 py-2 rounded-xl text-xs text-sky-400">Integration Matrix</a>
        <a href="admin_ledger_state.php" class="glass px-3 py-2 rounded-xl text-xs text-emerald-400">Ledger State</a>
        <a href="admin_audit_log.php" class="glass px-3 py-2 rounded-xl text-xs text-amber-400">Audit Log</a>
        <a href="admin_reason_map.php" class="glass px-3 py-2 rounded-xl text-xs text-violet-400">Reason Maps</a>
        <a href="admin_platform_wallet.php" class="glass px-3 py-2 rounded-xl text-xs text-emerald-400">Platform Fee Ledger</a>
        <a href="gateway_settings.php" class="glass px-3 py-2 rounded-xl text-xs text-gray-300">Platform Settings</a>
        <a href="merchant_register.php" target="_blank" class="glass px-3 py-2 rounded-xl text-xs text-brand-400">Live Demo ↗</a>
    </div>
</details>

<div class="glass rounded-xl p-5 mb-8 border border-sky-500/30">
    <div class="flex flex-wrap justify-between items-center gap-3 mb-4"><div><p class="font-semibold text-white">Decision Center</p><p class="text-xs text-gray-500 mt-1">Prioritize by risk, money impact and age. Review queues before routine work.</p></div><a href="admin_reconciliation.php" class="text-xs text-sky-400">Reconciliation queue →</a></div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
        <a href="admin_kyc.php" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 hover:border-amber-500/50"><p class="text-xs text-gray-500">Risk · KYC review</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= $pendingKyc ?></p><p class="text-xs text-gray-500 mt-1">merchant(s) waiting</p></a>
        <a href="admin_disputes.php?status=open" class="rounded-xl border border-red-500/30 bg-red-500/5 p-4 hover:border-red-500/50"><p class="text-xs text-gray-500">Risk · Open disputes</p><p class="text-2xl font-bold text-red-400 mt-1"><?= $openDisputes ?></p><p class="text-xs text-gray-500 mt-1">needs evidence / decision</p></a>
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
    $kycQueue = function_exists('getPendingKycQueue')
        ? adminDashWidget('kyc_queue', static fn() => getPendingKycQueue(min(5, max(1, $pendingKyc))), [])
        : [];
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
        ['Active Merchants', $activeMerchants, 'text-cyan-400', 'manage_merchant.php?status=active'],
        ['Pending KYC', $pendingKyc, 'text-amber-400', 'admin_kyc.php'],
        ['Pending Settlements', $pendingSettlements, 'text-purple-400', 'admin_settlements.php?status=pending'],
        ['Platform Wallet', formatMoney($platformWallet), 'text-emerald-400', 'admin_wallet.php'],
    ] as [$l,$v,$c,$link]): ?>
    <a href="<?= e($link) ?>" class="stat-card border border-gray-800 rounded-xl p-3 sm:p-5 block hover:border-brand-500/40 transition min-w-0">
        <p class="text-xs text-gray-500"><?= $l ?></p>
        <p class="text-xl sm:text-3xl font-bold <?= $c ?> mt-1 break-words"><?= is_numeric($v) ? $v : $v ?></p>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <a href="admin_transactions.php?from=<?= e(date('Y-m-d')) ?>&to=<?= e(date('Y-m-d')) ?>" class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0 block hover:border-brand-500/40 transition">
        <p class="text-xs text-gray-500">Today's Volume</p>
        <p class="text-xl sm:text-2xl font-bold text-brand-400 mt-1 break-words"><?= formatMoney($todayVol) ?></p>
        <p class="text-xs text-gray-600"><?= $todayTxn['c'] ?> transactions</p>
    </a>
    <a href="admin_transactions.php?from=<?= e(date('Y-m-01')) ?>&to=<?= e(date('Y-m-d')) ?>" class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0 block hover:border-brand-500/40 transition">
        <p class="text-xs text-gray-500">This Month</p>
        <p class="text-xl sm:text-2xl font-bold text-cyan-400 mt-1 break-words"><?= formatMoney($monthVol) ?></p>
        <p class="text-xs text-gray-600"><?= $monthTxn['c'] ?> transactions</p>
    </a>
    <a href="admin_transactions.php?status=failed" class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0 block hover:border-brand-500/40 transition">
        <p class="text-xs text-gray-500">Failed Transactions</p>
        <p class="text-xl sm:text-2xl font-bold text-amber-400 mt-1">Open list →</p>
    </a>
    <a href="admin_disputes.php?status=open" class="stat-card border border-gray-800 rounded-xl p-4 sm:p-5 min-w-0 block hover:border-brand-500/40 transition">
        <p class="text-xs text-gray-500">Open Disputes</p>
        <p class="text-xl sm:text-2xl font-bold text-red-400 mt-1"><?= $openDisputes ?></p>
    </a>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex justify-between"><h2 class="font-semibold">Recent Transactions</h2><a href="admin_transactions.php" class="text-xs text-brand-400">View All</a></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($recentTxns)): ?>
                <tr><td class="px-4 sm:px-5 py-8 text-sm text-gray-500 text-center" colspan="3">No recent transactions.</td></tr>
                <?php else: foreach ($recentTxns as $t): ?>
                <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='<?= e(transactionDetailUrl($t['txn_id'])) ?>'">
                    <td class="px-4 sm:px-5 py-3"><p class="font-mono text-xs"><a href="<?= e(transactionDetailUrl($t['txn_id'])) ?>" class="text-sky-400 hover:underline" onclick="event.stopPropagation()"><?= e($t['txn_id']) ?></a></p><p class="text-xs text-gray-500"><a href="<?= e(adminMerchantUrl((int)$t['merchant_id'])) ?>" class="hover:text-sky-300" onclick="event.stopPropagation()"><?= e($t['business_name']) ?></a></p></td>
                    <td class="px-4 sm:px-5 py-3 font-semibold"><?= formatMoney(capStatAmount((float)$t['amount'])) ?></td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($t['status']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex justify-between"><h2 class="font-semibold">New Merchants</h2><a href="manage_merchant.php" class="text-xs text-brand-400">View All</a></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($recentMerchants)): ?>
                <tr><td class="px-4 sm:px-5 py-8 text-sm text-gray-500 text-center" colspan="4">No new merchants.</td></tr>
                <?php else: foreach ($recentMerchants as $m): ?>
                <tr<?= uiRowClick(adminMerchantUrl((int)$m['id'])) ?>>
                    <td class="px-4 sm:px-5 py-3">
                        <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="font-medium text-white hover:text-sky-300" onclick="event.stopPropagation()"><?= e($m['business_name']) ?></a>
                        <p class="text-xs text-gray-500 break-all"><?= e($m['email']) ?></p>
                    </td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($m['status']) ?></td>
                    <td class="px-4 sm:px-5 py-3 text-right" onclick="event.stopPropagation()"><a href="admin_view_merchant.php?id=<?= (int)$m['id'] ?>" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold">View</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>