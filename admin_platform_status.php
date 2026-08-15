<?php
require_once __DIR__ . '/config.php';

$healthFile = __DIR__ . '/includes/platform_health.php';
if (is_file($healthFile)) {
    require_once $healthFile;
} else {
    function platformHealthSummary(): array
    {
        return [
            'services' => [[
                'id' => 'health_missing',
                'label' => 'Health module missing',
                'ok' => false,
                'status' => 'Deploy includes/platform_health.php',
                'detail' => 'File not found on server',
                'test_url' => 'gateway_settings.php',
            ]],
            'ok' => 0,
            'total' => 1,
            'pct' => 0,
        ];
    }
}

requireSuperAdmin();

if (isset($_GET['refresh_health']) && verifyCsrf($_GET['csrf'] ?? '')) {
    flash('success', 'Health status refreshed.');
    redirect('admin_platform_status.php');
}

try {
    $readiness = getPlatformReadiness();
} catch (Throwable $e) {
    error_log('UniWeb platform readiness: ' . $e->getMessage());
    $readiness = [
        'checks' => [['label' => 'Readiness check error', 'ok' => false, 'note' => $e->getMessage()]],
        'done' => 0,
        'total' => 1,
        'pct' => 0,
        'merchants' => 0,
        'transactions' => 0,
    ];
}

$health = platformHealthSummary();

$pageTitle = 'Platform Status';

require_once __DIR__ . '/header.php';

?>



<div class="flex flex-wrap gap-3 mb-8">

    <a href="gateway_settings.php" class="bg-brand-600/20 border border-brand-500/30 text-brand-300 px-4 py-2.5 rounded-xl text-sm font-medium hover:bg-brand-600/30">Gateway Settings →</a>

    <a href="admin_pg_webhooks.php" class="glass px-4 py-2.5 rounded-xl text-sm text-gray-300 hover:text-white">PG Webhook Log</a>

    <a href="admin_website.php" class="glass px-4 py-2.5 rounded-xl text-sm text-violet-400 hover:text-violet-300">Website & API Keys</a>

    <a href="admin_audit_plan.php" class="glass px-4 py-2.5 rounded-xl text-sm text-sky-400 hover:text-sky-300">Deep Audit Plan</a>

    <a href="admin_watchdog.php" class="glass px-4 py-2.5 rounded-xl text-sm text-amber-400 hover:text-amber-300">Link Watchdog</a>

    <a href="admin_incidents.php" class="glass px-4 py-2.5 rounded-xl text-sm text-amber-400 hover:text-amber-300">⭐ Incidents &amp; Uptime</a>

    <a href="admin_link_audit.php" class="glass px-4 py-2.5 rounded-xl text-sm text-emerald-400 hover:text-emerald-300">Link Audit</a>

    <a href="admin_bank_reconciliation.php" class="glass px-4 py-2.5 rounded-xl text-sm text-emerald-400 hover:text-emerald-300">⭐ Bank Reconciliation</a>

    <a href="admin_error_log.php" class="glass px-4 py-2.5 rounded-xl text-sm <?= countUnresolvedPlatformErrors() > 0 ? 'text-red-400 border border-red-500/30' : 'text-gray-300 hover:text-white' ?>">Error Log<?= countUnresolvedPlatformErrors() > 0 ? ' (' . countUnresolvedPlatformErrors() . ')' : '' ?></a>

    <a href="admin_gateway_registry.php" class="glass px-4 py-2.5 rounded-xl text-sm text-gray-300 hover:text-white">Partner Registry</a>

    <a href="demo.php" target="_blank" class="glass px-4 py-2.5 rounded-xl text-sm text-brand-400">Live Demo ↗</a>

</div>



<div class="glass rounded-xl p-6 mb-8 border border-gray-800">

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">

        <div>

            <h2 class="text-xl font-bold">System Health</h2>

            <p class="text-sm text-gray-500 mt-1">Live checks for gateways, email, WhatsApp, and webhooks</p>

        </div>

        <div class="text-right">

            <p class="text-4xl font-bold text-emerald-400"><?= $health['pct'] ?>%</p>

            <p class="text-xs text-gray-500"><?= $health['ok'] ?>/<?= $health['total'] ?> services healthy</p>
            <?php
            $optionalIds = ['axis', 'decentro', 'whatsapp', 'otp', 'settlement_cron'];
            $criticalDown = count(array_filter($health['services'], static fn($s) => empty($s['ok']) && !in_array($s['id'] ?? '', $optionalIds, true)));
            ?>
            <p class="text-[10px] text-gray-600 mt-1"><?= $criticalDown ?> critical · rest are optional until keys added</p>

        </div>

    </div>

    <div class="w-full bg-gray-800 rounded-full h-2 mb-6">

        <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width:<?= $health['pct'] ?>%"></div>

    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">

        <?php foreach ($health['services'] as $svc): ?>

        <div class="rounded-xl border p-4 <?= $svc['ok'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5' ?>">

            <div class="flex items-start justify-between gap-2">

                <div class="min-w-0">

                    <p class="font-medium text-sm"><?= e($svc['label']) ?></p>

                    <p class="text-xs mt-1 <?= $svc['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= e($svc['status']) ?></p>

                    <p class="text-[11px] text-gray-500 mt-1 truncate" title="<?= e($svc['detail']) ?>"><?= e($svc['detail']) ?></p>

                </div>

                <?php if (!empty($svc['test_url'])): ?>

                <a href="<?= e($svc['test_url']) ?>" class="shrink-0 px-2 py-1 rounded-lg text-[10px] bg-dark-800 text-gray-400 hover:text-white">Open</a>

                <?php endif; ?>

            </div>

        </div>

        <?php endforeach; ?>

    </div>

</div>



<div class="glass rounded-xl p-6 mb-8 border border-gray-800">

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">

        <div>

            <h2 class="text-xl font-bold">Platform Readiness</h2>

            <p class="text-sm text-gray-500 mt-1"><?= $readiness['merchants'] ?> merchants · <?= $readiness['transactions'] ?> success transactions</p>

        </div>

        <div class="text-right">

            <p class="text-4xl font-bold text-brand-400"><?= $readiness['pct'] ?>%</p>

            <p class="text-xs text-gray-500"><?= $readiness['done'] ?>/<?= $readiness['total'] ?> checks passed</p>

        </div>

    </div>

    <div class="w-full bg-gray-800 rounded-full h-2 mb-6">

        <div class="bg-brand-500 h-2 rounded-full transition-all" style="width:<?= $readiness['pct'] ?>%"></div>

    </div>

    <div class="space-y-2">

        <?php foreach ($readiness['checks'] as $check): ?>

        <div class="flex items-center justify-between gap-4 rounded-lg px-4 py-3 border <?= $check['ok'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5' ?>">

            <div class="flex items-center gap-3">

                <span class="<?= $check['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $check['ok'] ? '✓' : '○' ?></span>

                <span class="text-sm"><?= e($check['label']) ?></span>

            </div>

            <span class="text-xs text-gray-500"><?= e($check['note']) ?></span>

        </div>

        <?php endforeach; ?>

    </div>

</div>



<?php $gwHealth = gatewayHealthSummary(); ?>
<div class="glass rounded-xl p-6 mb-8 border border-gray-800">

    <div class="flex items-center justify-between gap-4 mb-4">
        <div>
            <h3 class="font-semibold">⭐ Smart Routing — Gateway Health</h3>
            <p class="text-xs text-gray-500 mt-1">If a card gateway's API fails 3 times in 10 min, checkout auto-diverts new payments to the other configured gateway.</p>
        </div>
    </div>
    <div class="grid sm:grid-cols-3 gap-3">
        <?php foreach ($gwHealth as $gw => $info): ?>
        <div class="rounded-xl border p-4 <?= !$info['configured'] ? 'border-gray-800 bg-dark-900/40' : ($info['healthy'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/30 bg-red-500/5') ?>">
            <p class="font-medium text-sm capitalize"><?= e($gw) ?></p>
            <p class="text-xs mt-1 <?= !$info['configured'] ? 'text-gray-500' : ($info['healthy'] ? 'text-emerald-400' : 'text-red-400') ?>">
                <?= !$info['configured'] ? 'Not configured' : ($info['healthy'] ? '● Healthy' : '● Unhealthy — auto-diverting') ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
$cronHeartbeats = getCronHeartbeatStatus();
?>
<div class="glass rounded-xl p-6 mb-8 border border-gray-800">
    <div class="flex items-center justify-between gap-4 mb-4">
        <div>
            <h3 class="font-semibold">⏰ Cron Job Status</h3>
            <p class="text-xs text-gray-500 mt-1">Last run of each scheduled job. STALE = not seen within expected window. NEVER = no heartbeat recorded yet.</p>
        </div>
        <a href="admin_platform_status.php?refresh_health=<?= csrfToken() ?>" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Refresh</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-gray-500 uppercase text-xs bg-dark-900/50">
                <tr>
                    <th class="px-4 py-2 text-left">Job</th>
                    <th class="px-4 py-2 text-left">Schedule</th>
                    <th class="px-4 py-2 text-left">Last Run</th>
                    <th class="px-4 py-2 text-left">Age</th>
                    <th class="px-4 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($cronHeartbeats as $ch): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 text-gray-300"><?= e($ch['label']) ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= e($ch['schedule']) ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs font-mono"><?= $ch['last_run'] ? e($ch['last_run']) : '—' ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= e($ch['age_human']) ?></td>
                    <td class="px-4 py-3">
                        <?php
                        $statusClass = match($ch['status']) {
                            'OK' => 'bg-emerald-500/20 text-emerald-400',
                            'STALE' => 'bg-amber-500/20 text-amber-400',
                            'NEVER' => 'bg-red-500/20 text-red-400',
                            default => 'bg-gray-700/50 text-gray-400',
                        };
                        ?>
                        <span class="text-[10px] px-2 py-1 rounded-full <?= $statusClass ?>"><?= e($ch['status']) ?></span>
                        <?php if ($ch['status'] === 'STALE'): ?>
                            <span class="text-[10px] text-gray-600 ml-1">Check Hostinger Cron Jobs</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-[11px] text-gray-600 mt-3">Hostinger needs one 10-minute job (Gateway Settings → Show full Hostinger command). Watchdog, KYC, settlements and recurring already run inside that job — NEVER on those rows usually means that one Hostinger job is not saved yet. See <a href="admin_error_log.php" class="text-sky-400 hover:underline">Error Log</a> for failures. Full keys are not shown on this page.</p>
</div>

<div class="glass rounded-xl p-6 border border-gray-800">

    <h3 class="font-semibold mb-3">End-to-End Test Path</h3>
    <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">

        <li>Register new merchant → auto Payment Pack</li>

        <li>Open ₹1 test link → checkout → Test Pay</li>

        <li>Merchant Dashboard → Wallet balance credited</li>

        <li>API Settings → configure webhook URL → Send Test Webhook</li>

        <li>Admin → Partner Registry → Partner Detail → Keys, then Test Connection per PG</li>

        <li>Admin → PG Webhook Log → retry unmatched events</li>

    </ol>

</div>



<?php require_once __DIR__ . '/footer.php'; ?>


