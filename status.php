<?php
require_once __DIR__ . '/config.php';
if (!function_exists('renderPagePrintStyles')) {
    require_once __DIR__ . '/includes/page_ux.php';
    require_once __DIR__ . '/includes/page_ux_compat.php';
}

$health = getPlatformHealth();
if (!function_exists('computeUptimeStats')) {
    require_once __DIR__ . '/includes/ops_security.php';
}
$uptime = computeUptimeStats(90);
$incidents = listIncidents(10, true);
$openIncidents = array_filter($incidents, static fn($i) => $i['status'] !== 'resolved');
$pageTitle = 'System Status';
require_once __DIR__ . '/header.php';

$overall = $health['operational'] && !$health['maintenance'] && empty($openIncidents);
?>
<?= renderPagePrintStyles() ?>
<section class="pt-28 pb-16 px-4 max-w-3xl mx-auto">
    <div class="text-center mb-10">
        <div class="no-print flex justify-center mb-4"><?= renderPrintButton('Print status') ?></div>
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold mb-4 <?= $overall ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-amber-500/10 text-amber-400 border border-amber-500/30' ?>">
            <span class="w-2 h-2 rounded-full <?= $overall ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400' ?>"></span>
            <?= $health['maintenance'] ? 'Maintenance Mode' : ($overall ? 'Core Platform Available' : (!empty($openIncidents) ? 'Active Incident' : 'Partial Service')) ?>
        </div>
        <h1 class="text-3xl font-bold mb-2"><?= e(APP_NAME) ?> Platform Status</h1>
        <p class="text-gray-500 text-sm">Named components below. Partner credentials do not prove a merchant’s Live rail is healthy.</p>
        <p class="text-xs text-gray-600 mt-2">Last updated <?= e(date('d M Y, H:i')) ?> IST · Uptime probe: <a href="health.php" class="text-sky-400 hover:underline">health.php</a> (plain OK)</p>
        <p class="text-xs text-gray-600 mt-1">Support acknowledgement target: 1 business day. Payment or bank issues wait on the partner.</p>
    </div>

    <?php
    $componentOk = $overall;
    $componentLabel = $health['maintenance'] ? 'Maintenance' : ($componentOk ? 'Operational' : 'Degraded');
    $componentClass = $health['maintenance'] ? 'text-amber-400' : ($componentOk ? 'text-emerald-400' : 'text-amber-400');
    $statusComponents = [
        ['Checkout', 'Hosted pay page, Instant Test Pay, UPI/QR and partner checkout'],
        ['Dashboard', 'Merchant and admin consoles, login, reports'],
        ['Webhooks', 'Inbound partner events and outbound merchant HMAC webhooks'],
        ['KYC', 'Document queue, video capture, Live Mode gates'],
        ['Settlements', 'Wallet, batches and UTR tracking — bank payout follows the activated rail'],
    ];
    ?>
    <div class="glass rounded-xl overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Components</h2></div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($statusComponents as [$name, $hint]): ?>
            <div class="px-6 py-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm text-gray-200"><?= e($name) ?></p>
                    <p class="text-xs text-gray-600 mt-1"><?= e($hint) ?></p>
                </div>
                <span class="text-xs font-medium <?= e($componentClass) ?>"><?= e($componentLabel) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid sm:grid-cols-3 gap-4 mb-8">
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Uptime (90 days)</p>
            <p class="text-3xl font-bold text-emerald-400 mt-1"><?= e((string)$uptime['uptime_pct']) ?>%</p>
            <p class="text-[11px] text-gray-600 mt-1">Tracking since <?= e($uptime['tracking_since']) ?></p>
        </div>
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Successful payments (24h)</p>
            <p class="text-3xl font-bold text-emerald-400 mt-1"><?= (int)$health['success_24h'] ?></p>
        </div>
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Failed payments (24h)</p>
            <p class="text-3xl font-bold text-red-400 mt-1"><?= (int)$health['failed_24h'] ?></p>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Incident History</h2></div>
        <?php if (empty($incidents)): ?>
        <div class="px-6 py-8 text-center text-gray-500 text-sm">No incidents recorded. This page will list any real incident, open or resolved.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($incidents as $inc): ?>
            <div class="px-6 py-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm text-gray-200"><?= e($inc['title']) ?></p>
                    <p class="text-xs text-gray-600 mt-1"><?= e(date('d M Y, H:i', strtotime($inc['opened_at']))) ?><?= $inc['resolved_at'] ? ' — resolved ' . e(date('d M Y, H:i', strtotime($inc['resolved_at']))) : '' ?></p>
                </div>
                <span class="text-xs font-medium px-2.5 py-1 rounded-full <?= $inc['status'] === 'resolved' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' ?>"><?= e(ucfirst($inc['status'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Payment Gateways</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <tbody class="divide-y divide-gray-800">
                <?php foreach (['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'payu' => 'PayU', 'axis' => 'Axis Bank VA'] as $key => $label): ?>
                <tr>
                    <td class="px-6 py-4"><?= e($label) ?></td>
                    <td class="px-6 py-4 text-right">
                        <?php if ($health['gateways'][$key]): ?>
                        <span class="text-sky-400 font-medium">Credentials configured</span>
                        <?php else: ?>
                        <span class="text-gray-500">Not configured</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="glass rounded-xl p-6 text-sm text-gray-400 space-y-2">
        <p><strong class="text-gray-300">Checkout application:</strong> Available</p>
        <p><strong class="text-gray-300">Merchant Dashboard:</strong> Available</p>
        <p><strong class="text-gray-300">Settlement ledger:</strong> Internal tracking; bank payout depends on activated rail</p>
        <p><strong class="text-gray-300">Platform version:</strong> v<?= e($health['version']) ?></p>
        <p class="text-xs text-gray-600 pt-2">For payment issues contact <?= e(COMPANY_SUPPORT_EMAIL) ?> · <?= e(COMPANY_PHONE) ?></p>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
