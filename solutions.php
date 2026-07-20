<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Payment Solutions for Indian Businesses';
$pageDescription = 'UniWeb payment gateway India: UPI, QR codes, payment links, cards, net banking, settlements, payouts, API and merchant onboarding for startups and SMEs.';
$pageKeywords = 'payment gateway India, UPI payment gateway, payment aggregator India, QR code payment, payment links, net banking payment, merchant onboarding, fintech platform India';
$canonicalUrl = APP_URL . '/solutions.php';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Products</div>
        <h1>One platform for collections, settlements and merchant operations.</h1>
        <p>Built for Indian businesses that need Razorpay-class tooling on a practical budget — payment links, QR, checkout, API, KYC and settlement visibility in one place.</p>
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="demo.php" class="btn-primary px-6 py-3">Try ₹1 demo payment</a>
            <a href="merchant_register.php" class="px-6 py-3 rounded-lg border border-gray-700">Start merchant onboarding</a>
        </div>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><h3>Payment Gateway &amp; Checkout</h3><p>Hosted checkout with UPI, cards, net banking and wallets via approved partners (Razorpay, Cashfree, PayU). Test Mode for integration; Live after KYC.</p></div>
            <div class="company-card"><h3>Payment Links</h3><p>Create shareable links for invoices, subscriptions or one-time collections. Track views, status and customer details from the merchant dashboard.</p></div>
            <div class="company-card"><h3>QR Code Payments</h3><p>Fixed-amount, dynamic UPI and all-method QR journeys. Ideal for shops, delivery and field sales.</p></div>
            <div class="company-card"><h3>UPI Collections</h3><p>Direct UPI intent, merchant VPA and virtual-account style flows where partner activation allows.</p></div>
            <div class="company-card"><h3>Merchant API</h3><p>REST API for payment links, transactions, balance and refunds — with separate test and live credentials.</p></div>
            <div class="company-card"><h3>Settlements &amp; Payouts</h3><p>Wallet ledger, settlement batches and bank payout tracking. Automated rails when RazorpayX / partner payouts are enabled.</p></div>
            <div class="company-card"><h3>Refunds &amp; Disputes</h3><p>Merchant and admin refund workflows, chargeback queue and evidence tracking.</p></div>
            <div class="company-card"><h3>KYC &amp; Onboarding</h3><p>Entity-based document checklist, PAN/GST/Aadhaar verification, video KYC and e-agreement with PDF audit trail.</p></div>
            <div class="company-card"><h3>Staff &amp; Admin Ops</h3><p>Role-based admin, finance, KYC and support portals with merchant assignment and maker-checker approvals.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Coming with partner activation</div>
        <h2 class="company-title">Roadmap-aligned capabilities</h2>
        <p class="company-lead">EMI, Buy Now Pay Later, recurring mandates, PhonePe native checkout and international cards require separate partner agreements. UniWeb shows them in pricing and activates them when your commercial and technical setup is ready — see our <a href="roadmap.php" class="text-brand-400">2026–2028 roadmap</a>.</p>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Compare before you commit.</h2>
        <p>Run Test Mode, review KYC, sign the merchant agreement and go Live only when every gate is green.</p>
        <a href="platform_demo.php" class="btn-primary px-6 py-3">Watch platform tour</a>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
