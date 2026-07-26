<?php
/** Demo & partner pitch table — include on admin dashboard / platform status */
$db = getDB();
$demoMerchant = $db->query("SELECT id, business_name, email FROM merchants WHERE email='demo@uniweb.co.in' LIMIT 1")->fetch();
$packLinks = [];
if ($demoMerchant) {
    $st = $db->prepare("SELECT link_id, link_label, payment_method, amount, is_test FROM payment_links WHERE merchant_id=? ORDER BY id DESC LIMIT 8");
    $st->execute([(int)$demoMerchant['id']]);
    $packLinks = $st->fetchAll();
}
$primaryLink = '';
foreach ($packLinks as $pl) {
    if (($pl['payment_method'] ?? '') === 'upi_p2m' || !$primaryLink) {
        $primaryLink = APP_URL . '/checkout.php?link=' . $pl['link_id'];
        if (($pl['payment_method'] ?? '') === 'upi_p2m') break;
    }
}
?>
<div class="glass rounded-xl border border-violet-500/20 overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="font-semibold text-violet-300">Demo &amp; Partner Pitch</h3>
            <p class="text-xs text-gray-500 mt-1">30-minute partner calls · Decentro / banks · quick links</p>
        </div>
        <a href="admin_partner_decentro.php" class="text-xs text-violet-400 hover:text-violet-300 font-medium">Full Demo Script →</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Item</th>
                    <th class="px-5 py-3 text-left">Credentials / Link</th>
                    <th class="px-5 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/80">
                <tr class="hover:bg-white/[0.02]">
                    <td class="px-5 py-3 font-medium">Demo Merchant Login</td>
                    <td class="px-5 py-3 text-gray-400 font-mono text-xs">demo@uniweb.co.in · Demo@1234</td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($demoMerchant): ?>
                        <a href="admin_view_merchant.php?id=<?= (int)$demoMerchant['id'] ?>" class="text-emerald-400 hover:text-emerald-300 text-xs font-medium">View Merchant</a>
                        <?php else: ?>
                        <span class="text-gray-600 text-xs">Run Auto Setup</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="hover:bg-white/[0.02]">
                    <td class="px-5 py-3 font-medium">Live ₹1 Checkout</td>
                    <td class="px-5 py-3 text-gray-400 text-xs break-all max-w-md"><?= $primaryLink ? e($primaryLink) : 'Payment Pack → Auto Setup' ?></td>
                    <td class="px-5 py-3 text-right">
                        <?php if ($primaryLink): ?>
                        <a href="<?= e($primaryLink) ?>" target="_blank" class="text-sky-400 hover:text-sky-300 text-xs font-medium">Open Checkout ↗</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="hover:bg-white/[0.02]">
                    <td class="px-5 py-3 font-medium">Decentro 30-Min Demo</td>
                    <td class="px-5 py-3 text-gray-400 text-xs">Screen-share script · KYC + Collections + Payouts</td>
                    <td class="px-5 py-3 text-right"><a href="admin_partner_decentro.php" class="text-violet-400 hover:text-violet-300 text-xs font-medium">Open Script</a></td>
                </tr>
                <tr class="hover:bg-white/[0.02]">
                    <td class="px-5 py-3 font-medium">Platform Tour</td>
                    <td class="px-5 py-3 text-gray-400 text-xs">Public product walkthrough</td>
                    <td class="px-5 py-3 text-right"><a href="platform_demo.php" target="_blank" class="text-brand-400 hover:text-brand-300 text-xs font-medium">Open Tour ↗</a></td>
                </tr>
                <?php if (!empty($packLinks)): ?>
                <tr>
                    <td colspan="3" class="px-5 py-3 bg-dark-900/30">
                        <p class="text-xs text-gray-500 mb-2 font-medium uppercase tracking-wide">Payment Pack Links</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($packLinks as $pl):
                                $url = APP_URL . '/checkout.php?link=' . $pl['link_id'];
                                $label = $pl['link_label'] ?: ($pl['payment_method'] ?: 'Link');
                            ?>
                            <a href="<?= e($url) ?>" target="_blank" class="text-xs px-3 py-1.5 rounded-lg border border-gray-700 text-gray-400 hover:text-sky-300 hover:border-sky-500/40 transition">
                                <?= e($label) ?> · ₹<?= number_format((float)$pl['amount'], 0) ?><?= !empty($pl['is_test']) ? ' (test)' : '' ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
