<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Pricing';
$pageDescription = 'UniWeb payment gateway pricing for India: UPI, cards, net banking, wallets, platform fee and settlement — transparent commercial schedules after merchant approval.';
$pageKeywords = 'payment gateway pricing India, UPI MDR, low transaction fee gateway, merchant pricing UniWeb';
$canonicalUrl = APP_URL . '/pricing.php';
$publicPricingApproved = getSetting('public_pricing_approved', '0') === '1';
$modes = function_exists('getPaymentModes') ? getPaymentModes() : [];
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Pricing</div>
        <h1>Clear commercial terms for Indian merchants.</h1>
        <p>Start free in Test Mode. Live rates depend on payment method, risk category and partner rails. Below is how UniWeb prices collections — without fake “0% forever” claims.</p>
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="demo.php" class="btn-primary px-6 py-3">Try ₹1 demo</a>
            <a href="merchant_register.php" class="px-6 py-3 rounded-lg border border-gray-700">Create merchant account</a>
        </div>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><h3>Test Mode</h3><p>Free. Instant Test Pay, API sandbox keys, payment links and QR — no real money movement.</p></div>
            <div class="company-card"><h3>Live Mode</h3><p>Enabled after KYC, agreement and partner activation. Real UPI/cards/netbanking via approved gateways.</p></div>
            <div class="company-card"><h3>Platform fee</h3><p>Default commission is configurable per merchant (typically a small % on successful collections). Exact schedule is shown in the Merchant Portal.</p></div>
            <div class="company-card"><h3>Gateway MDR</h3><p>Partner MDR for cards, net banking and wallets is passed through and may include a published platform margin when approved for public display.</p></div>
        </div>
    </div></section>

    <?php if ($publicPricingApproved && !empty($modes)): ?>
    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Published MDR</div>
        <h2 class="company-title">Indicative rates (INR)</h2>
        <div class="glass rounded-xl overflow-hidden border border-gray-800">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Method</th>
                    <th class="px-5 py-3 text-left">Indicative total MDR</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                <?php foreach ($modes as $key => $label): ?>
                    <tr><td class="px-5 py-3"><?= e(is_array($label) ? ($label['label'] ?? $key) : (string)$label) ?></td>
                    <td class="px-5 py-3 font-mono"><?= e(number_format((float)getMdrWithMargin((string)$key), 2)) ?>%</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-3">Rates are indicative and may change by MCC, volume and partner. Your portal schedule is authoritative.</p>
    </div></section>
    <?php else: ?>
    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-card company-wide">
            <h2 class="company-title" style="font-size:1.5rem">Commercial pricing is approval-based</h2>
            <p class="company-lead mt-3">Public MDR tables appear here once commercial rates are approved for publication. Until then:</p>
            <ul class="text-sm text-gray-400 mt-4 space-y-2 list-disc pl-5">
                <li>Use <strong class="text-gray-200">Test Mode</strong> and the ₹1 demo at no charge</li>
                <li>After signup, your dashboard shows applicable fees for enabled methods</li>
                <li>Sales / admin can share a written commercial schedule for Live Mode</li>
            </ul>
            <div class="flex flex-wrap gap-3 mt-6">
                <a href="contact.php" class="btn-primary px-6 py-3">Request pricing</a>
                <a href="index.php#pricing" class="px-6 py-3 rounded-lg border border-gray-700">Homepage pricing section</a>
            </div>
        </div>
    </div></section>
    <?php endif; ?>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Compare honestly</div>
        <h2 class="company-title">Vs typical Indian gateways</h2>
        <p class="company-lead">Razorpay, Cashfree and PayU publish broad rate cards because they hold large partner books. UniWeb starts with Test Mode + partner-routed Live rails and a written schedule per merchant — the same model many early aggregators used before public rate cards.</p>
    </div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
