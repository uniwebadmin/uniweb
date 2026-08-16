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
        <p>Test Mode is free. Live fees are partner MDR + UniWeb platform commission + GST. We do not sell a white-label software package — revenue is a cut on successful collections. Settlement follows the written T+N schedule.</p>
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="demo.php" class="btn-primary px-6 py-3">Try ₹1 demo</a>
            <a href="merchant_register.php" class="px-6 py-3 rounded-lg border border-gray-700">Create merchant account</a>
        </div>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><h3>Shop / counter</h3><p>QR and links in Test Mode at ₹0. Live UPI/QR fees appear in the commercial schedule after KYC — we do not print a fake 0% live rate here.</p></div>
            <div class="company-card"><h3>Online SME</h3><p>Cards, net banking and wallets carry partner MDR plus UniWeb platform fee and GST. Your portal schedule is authoritative.</p></div>
            <div class="company-card"><h3>High volume</h3><p>Custom MCC, reserves and settlement cut-offs. Request a written proposal — we do not invent public “same day” or “instant” guarantees.</p></div>
        </div>
    </div></section>

    <?php if ($publicPricingApproved && !empty($modes)): ?>
    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Published MDR</div>
        <h2 class="company-title">Indicative rates (INR)</h2>
        <div class="glass rounded-xl overflow-hidden border border-gray-800">
            <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
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
            </table></div>
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
        <div class="company-kicker">Fee stack</div>
        <h2 class="company-title">What you actually pay on a Live capture</h2>
        <div class="company-grid">
            <div class="company-card"><h3>1. Partner MDR</h3><p>Bank or gateway charge for UPI, cards, net banking or wallets. Set by the activated rail, MCC and volume.</p></div>
            <div class="company-card"><h3>2. UniWeb platform commission</h3><p>Our fee on successful collections, shown per merchant in the Portal. Not a sold white-label licence. Not a hidden “convenience fee” on the customer unless you configure one.</p></div>
            <div class="company-card"><h3>3. GST on fees</h3><p>GST applies on applicable fee components as per Indian tax rules. Settlement is net of fees, refunds, reserves and holds.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Compare honestly</div>
        <h2 class="company-title">Vs typical Indian gateways</h2>
        <p class="company-lead">Razorpay, Cashfree and PayU publish broad rate cards because they hold large partner books. UniWeb starts with Test Mode + partner-routed Live rails and a written schedule per merchant — the same model many early aggregators used before public rate cards. We do not advertise 0% live UPI or instant settlement as a public fact.</p>
    </div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
