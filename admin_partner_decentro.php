<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
redirect('admin_gateway_detail.php?partner=decentro');

$db = getDB();
$demoMerchant = $db->query("SELECT id FROM merchants WHERE email='demo@uniweb.co.in' LIMIT 1")->fetch();
$packLink = '';
if ($demoMerchant) {
    $lnk = $db->prepare("SELECT link_id FROM payment_links WHERE merchant_id=? AND payment_method='upi_p2m' ORDER BY id DESC LIMIT 1");
    $lnk->execute([(int)$demoMerchant['id']]);
    $row = $lnk->fetch();
    if ($row) {
        $packLink = buildPaymentLinkUrl($row['link_id'], 'upi');
    }
}
$emailBody = partnerProductionEmail('decentro');
?>

<div class="mb-4 flex flex-wrap gap-3 items-center justify-between">
    <a href="admin_partner_requests.php" class="text-sm text-gray-400 hover:text-white">← Partner Requests</a>
    <a href="demo.php" target="_blank" class="text-sm text-sky-400">Open Public Tour →</a>
</div>

<div class="bg-brand-500/10 border border-brand-500/30 rounded-xl p-4 sm:p-6 mb-6 sm:mb-8">
    <h1 class="text-xl sm:text-2xl font-bold text-brand-300 mb-2">Decentro — 30 Minute Demo Call Script</h1>
    <p class="text-gray-400 text-sm">Follow this order during screen share. Show Decentro we need full collections + payouts stack, not KYC-only.</p>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <h2 class="font-semibold text-lg mb-4">Before Call — Checklist</h2>
        <ul class="text-sm text-gray-400 space-y-2">
            <li>☐ Browser tabs: Homepage, Platform Tour, Admin, Demo Checkout</li>
            <li>☐ Login: <code class="text-xs bg-dark-900 px-1 rounded break-all">demo@uniweb.co.in</code> (demo password in ops notes — do not paste live secrets here)</li>
            <li>☐ Live demo link ready<?= $packLink ? ': <a class="text-sky-400 break-all" href="' . e($packLink) . '" target="_blank">' . e($packLink) . '</a>' : ' (run Auto Setup on demo merchant)' ?></li>
            <li>☐ Gateway Settings: Decentro sandbox keys (if available)</li>
            <li>☐ Screen resolution 1920×1080, notifications off</li>
        </ul>
    </div>
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <h2 class="font-semibold text-lg mb-4">What Decentro Should Enable</h2>
        <ul class="text-sm text-gray-400 space-y-1 list-disc list-inside">
            <li>KYC Stack (PAN, GST, Bank, Udyam)</li>
            <li>Collections / Virtual Account</li>
            <li>Payouts / Penny Drop</li>
            <li>Webhooks + Sandbox → Production</li>
        </ul>
        <a href="admin_partner_requests.php?partner=decentro" class="inline-block mt-4 text-sm text-brand-400">Open Partner Email Draft →</a>
    </div>
</div>

<div class="space-y-6">
    <?php
    $steps = [
        ['0–5 min', 'Intro & Problem', [
            'UniWeb Technologist — B2B payment aggregator for Indian SMEs',
            'Merchants need: signup → KYC → payment links → wallet → settlement in one platform',
            'We use Decentro for compliance + banking rails (not just one API)',
            'Show: uniweb.co.in homepage → Platform Tour',
        ]],
        ['5–12 min', 'Merchant Onboarding', [
            'Admin → All Merchants → demo merchant → ⚡ Auto Setup Merchant',
            'Show Payment Pack: 6 method-specific links (UPI, VA, PayU, Razorpay, etc.)',
            'Explain: each link locks payment method — less cart abandonment',
            'Merchant dashboard: QR, links, transactions',
        ]],
        ['12–18 min', 'Live Payment', [
            'Open checkout link — amount ₹1 (test mode)',
            'Walk through UPI P2M flow + PayU card option',
            'Show success → transaction in dashboard → wallet credit',
            'Mention: webhooks to merchant URL (api_settings.php)',
        ]],
        ['18–24 min', 'KYC & Compliance', [
            'Merchant KYC page — PAN, GST, bank verification',
            'Admin KYC Review queue — approve/reject',
            'AML flags, document storage',
            'Ask Decentro: sandbox keys for full KYC stack + rate card',
        ]],
        ['24–28 min', 'Settlement & Ops', [
            'Wallet balance → settlement request → T+1 bank transfer',
            'Platform commission auto-deduct',
            'Admin: settlements, platform wallet, reports',
        ]],
        ['28–30 min', 'Close & Next Steps', [
            'Request: Sandbox credentials for Collections + Payouts + KYC',
            'Timeline: sandbox integration 1 week → UAT → production',
            'Volume estimate: 500 merchants Year 1, ₹50Cr GMV target',
            'Share: partner email already sent — confirm receipt',
        ]],
    ];
    foreach ($steps as [$time, $title, $bullets]): ?>
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <span class="text-xs font-bold bg-sky-600/30 text-sky-300 px-3 py-1 rounded-full"><?= e($time) ?></span>
            <h3 class="font-semibold text-lg"><?= e($title) ?></h3>
        </div>
        <ul class="text-sm text-gray-400 space-y-2 list-disc list-inside">
            <?php foreach ($bullets as $b): ?><li><?= e($b) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
</div>

<div class="mt-6 sm:mt-8 glass rounded-xl p-4 sm:p-6 min-w-0">
    <h2 class="font-semibold mb-3">Email Sent to Decentro (reference)</h2>
    <pre class="text-xs text-gray-500 whitespace-pre-wrap max-h-64 overflow-y-auto overflow-x-auto bg-dark-900 p-3 sm:p-4 rounded-lg"><?= e($emailBody) ?></pre>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
