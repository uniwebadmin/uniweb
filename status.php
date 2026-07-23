<?php
require_once __DIR__ . '/config.php';

$health = getPlatformHealth();
$pageTitle = 'System Status';
require_once __DIR__ . '/header.php';

$overall = $health['operational'] && !$health['maintenance'];
?>
<?= renderPagePrintStyles() ?>
<section class="pt-28 pb-16 px-4 max-w-3xl mx-auto">
    <div class="text-center mb-10">
        <div class="no-print flex justify-center mb-4"><?= renderPrintButton('Print status') ?></div>
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold mb-4 <?= $overall ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-amber-500/10 text-amber-400 border border-amber-500/30' ?>">
            <span class="w-2 h-2 rounded-full <?= $overall ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400' ?>"></span>
            <?= $health['maintenance'] ? 'Maintenance Mode' : ($overall ? 'Core Platform Available' : 'Partial Service') ?>
        </div>
        <h1 class="text-3xl font-bold mb-2"><?= e(APP_NAME) ?> Platform Status</h1>
        <p class="text-gray-500 text-sm">Configuration and availability status. Partner configuration does not prove transaction or settlement health.</p>
    </div>

    <div class="grid sm:grid-cols-2 gap-4 mb-8">
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
