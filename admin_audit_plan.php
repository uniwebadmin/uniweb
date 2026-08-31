<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

$pageTitle = 'Deep Audit Plan';

$beforeLive = [
    ['Apply pending migrations (066–081)', 'gateway_settings.php', 'Platform Settings → Apply pending migrations → ok: true'],
    ['Soft Launch checklist', 'admin_soft_launch.php', 'Follow blockers a–g in order'],
    ['Partner Registry keys', 'admin_gateway_registry.php', 'Keys tab → Test Connection green for rails you will use'],
    ['Webhook URLs + secrets', 'admin_webhook_reliability.php', 'Partner dashboard → uniweb.co.in webhooks; secret in Registry'],
    ['Live Money Switches OFF', 'gateway_settings.php#live-money-switches', 'Payout / Recurring / Route routing default OFF'],
    ['Cron verify (already on Hostinger)', 'admin_platform_status.php', 'Auto Audit last run within 15 min — if stale, Owner checks hPanel'],
    ['Smoke green on laptop', null, 'php tests/run_smoke_checks.php → failed=0'],
];

$afterKeys = [
    ['Error Log clean', 'admin_error_log.php', 'Resolve noise; CR-01 live config if old createNotification() blocks notify helper'],
    ['Watchdog green', 'admin_watchdog.php', 'Broken links = 0 before traffic'],
    ['KYC queue ready', 'admin_kyc.php', 'Verify merchant when live collect required'],
    ['PG Reconciliation dry path', 'admin_reconciliation.php?tab=manual', 'Manual reconcile tab — one TXN test (no fake paid)'],
    ['First test pay', 'admin_transactions.php', 'Small amount → Success + ledger on Transaction detail'],
];

$parkedLater = [
    ['Phase 11 Route / Easy Split SDK', 'gateway_settings.php', 'PARKED — switch OFF until Owner + live keys + approval'],
    ['Marketplace payout / Easy Split to bank', 'gateway_settings.php', 'PARKED — Payout live switch OFF'],
    ['Recurring live debits', 'gateway_settings.php', 'PARKED — Recurring switch OFF'],
    ['KYC forward staged / local_record', 'admin_forward_queue.php', 'By design — not partner paid; queue until live API'],
    ['MDR layout simplify', 'admin_gateway_detail.php', 'PARKED — Owner approval required; pages stay as-is'],
    ['NBFC / customer PPI wallet / white-label product', null, 'NEVER — excluded by policy'],
];

$referencePhases = [
    ['0', 'DB, migrations, schema, fatal capture', 'Do first on live'],
    ['1', 'Single money / keys plane', 'After Phase 0 green'],
    ['2', 'Checkout, QR, methods, links', 'After Phase 1 green'],
    ['3–8', 'KYC, menus, ops, search, website, polish', 'Sequential — verify each on live'],
    ['9–10', 'Market comparison · white-label checklist', 'Reference only — does not override 0–2'],
    ['11', 'Route/Split optional', 'Later / excluded — Owner gate'],
];

require_once __DIR__ . '/header.php';
?>

<div class="mb-6 min-w-0">
    <p class="text-sm text-gray-400">Launch checklist — calm steps before first live pay. Aligned with <a href="admin_soft_launch.php" class="text-sky-400 hover:underline">Soft Launch</a> and <code class="text-gray-500">docs/SOFT_LAUNCH_BLOCKERS.md</code>.</p>
    <p class="text-xs text-amber-400/90 mt-2">PARKED rows are honest — not hidden failures. Do not skip migrations or keys.</p>
</div>

<div class="flex flex-wrap gap-2 mb-6">
    <a href="admin_soft_launch.php" class="glass px-4 py-2 rounded-xl text-sm text-emerald-400">Soft Launch →</a>
    <a href="gateway_settings.php" class="glass px-4 py-2 rounded-xl text-sm text-sky-400">Platform Settings</a>
    <a href="admin_error_log.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Error Log</a>
    <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400">Watchdog</a>
    <a href="admin_reconciliation.php?tab=manual" class="glass px-4 py-2 rounded-xl text-sm text-violet-300">Manual Reconcile</a>
</div>

<div class="space-y-6">
    <section class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-emerald-500/30 bg-emerald-500/5">
            <h2 class="font-semibold text-emerald-300">A) Before first live pay</h2>
        </div>
        <ol class="divide-y divide-gray-800">
            <?php foreach ($beforeLive as $i => $row): ?>
            <li class="px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 text-sm">
                <span class="text-gray-500 w-6 shrink-0"><?= $i + 1 ?>.</span>
                <span class="text-gray-200 flex-1 min-w-0"><?= e($row[0]) ?></span>
                <span class="text-xs text-gray-500 sm:max-w-[45%]"><?= e($row[2]) ?></span>
                <?php if ($row[1]): ?><a href="<?= e($row[1]) ?>" class="text-xs text-sky-400 shrink-0">Open →</a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-sky-500/30 bg-sky-500/5">
            <h2 class="font-semibold text-sky-300">B) After keys (live prep)</h2>
        </div>
        <ol class="divide-y divide-gray-800">
            <?php foreach ($afterKeys as $i => $row): ?>
            <li class="px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 text-sm">
                <span class="text-gray-500 w-6 shrink-0"><?= $i + 1 ?>.</span>
                <span class="text-gray-200 flex-1 min-w-0"><?= e($row[0]) ?></span>
                <span class="text-xs text-gray-500 sm:max-w-[45%]"><?= e($row[2]) ?></span>
                <?php if ($row[1]): ?><a href="<?= e($row[1]) ?>" class="text-xs text-sky-400 shrink-0">Open →</a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="glass rounded-xl overflow-hidden min-w-0 border border-amber-500/20">
        <div class="px-4 sm:px-6 py-4 border-b border-amber-500/30 bg-amber-500/5">
            <h2 class="font-semibold text-amber-300">C) PARKED later (not “do now”)</h2>
        </div>
        <ul class="divide-y divide-gray-800">
            <?php foreach ($parkedLater as $row): ?>
            <li class="px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 text-sm">
                <span class="text-[10px] uppercase tracking-wide text-amber-400/80 border border-amber-500/30 px-2 py-0.5 rounded w-fit shrink-0">PARKED</span>
                <span class="text-gray-300 flex-1"><?= e($row[0]) ?></span>
                <span class="text-xs text-gray-500 sm:max-w-[45%]"><?= e($row[2]) ?></span>
                <?php if ($row[1]): ?><a href="<?= e($row[1]) ?>" class="text-xs text-gray-400 shrink-0">Ref →</a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <details class="glass rounded-xl min-w-0">
        <summary class="px-4 sm:px-6 py-4 cursor-pointer font-semibold text-gray-400">Reference — phase history (collapse)</summary>
        <ol class="divide-y divide-gray-800 border-t border-gray-800">
            <?php foreach ($referencePhases as $phase): ?>
            <li class="px-4 sm:px-6 py-3 text-sm">
                <span class="text-sky-400/80 font-mono text-xs">Phase <?= e($phase[0]) ?></span>
                <span class="text-gray-300 ml-2"><?= e($phase[1]) ?></span>
                <p class="text-xs text-gray-600 mt-1"><?= e($phase[2]) ?></p>
            </li>
            <?php endforeach; ?>
        </ol>
        <p class="px-4 sm:px-6 py-3 text-xs text-gray-600 border-t border-gray-800">Migrations through <strong class="text-gray-500">081</strong>. Product exclusions: NBFC, customer PPI wallet, white-label sell.</p>
    </details>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
