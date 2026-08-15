<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Product Roadmap 2026–2028';
$pageDescription = 'UniWeb fintech roadmap: AI fraud detection, partner payouts, BNPL, subscriptions, SEO growth and payment gateway expansion for Indian merchants.';
$pageKeywords = 'UniWeb roadmap, payment gateway roadmap India, fintech startup India, payment aggregator growth';
$canonicalUrl = APP_URL . '/roadmap.php';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Vision &amp; roadmap</div>
        <h1>Where UniWeb is going — transparently.</h1>
        <p>We are building a merchant-first payment operations platform for India. This page shows what is live today, what ships next, and what depends on partner or regulatory approval.</p>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-kicker">Today — live on platform</div>
        <div class="company-grid">
            <div class="company-card"><h3>2026 Q3</h3><ul class="text-sm text-gray-400 space-y-2 list-disc pl-4"><li>Test &amp; Live merchant modes</li><li>Payment links, QR, hosted checkout</li><li>KYC upload + admin verify + e-agreement PDF</li><li>PayU / Razorpay / Cashfree partner checkout</li><li>Staff RBAC + settlement ops</li></ul></div>
            <div class="company-card"><h3>Bootstrapped focus</h3><p>With a ₹1.5–2 lakh launch budget, priority is one reliable live PG partner, automated KYC API, payout rail and honest marketing — not rebuilding the entire banking stack.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Next 6–12 months</div>
        <h2 class="company-title">2026–2027 delivery plan</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Onboarding UX</h3><p>DigiLocker / CKYC pull, Aadhaar OTP completion, liveness vendor hook, faster mobile KYC.</p></div>
            <div class="company-card"><h3>Collections</h3><p>PhonePe checkout, payment pages embed, WooCommerce/Shopify parity, subscription mandates when partner approves.</p></div>
            <div class="company-card"><h3>Money movement</h3><p>RazorpayX / Cashfree Payouts automation, T+1 settlement scheduling, bank reconciliation at scale.</p></div>
            <div class="company-card"><h3>Trust &amp; growth</h3><p>SEO content hub, case studies, public status SLA, merchant success notifications (email + WhatsApp).</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">2027–2028 horizon</div>
        <h2 class="company-title">Long-term vision</h2>
        <div class="company-grid">
            <div class="company-card"><h3>BNPL &amp; EMI</h3><p>Partner-led EMI and Buy Now Pay Later with merchant-configurable eligibility rules.</p></div>
            <div class="company-card"><h3>Neo-banking layer</h3><p>Vendor payouts, expense cards and virtual accounts via BaaS partners.</p></div>
            <div class="company-card"><h3>AI operations</h3><p>Velocity-based fraud scoring, anomaly alerts and reconciliation assist for ops teams.</p></div>
            <div class="company-card"><h3>Enterprise</h3><p>Marketplace splits, sub-merchants, granular API scopes, and partner-led rails for larger merchants — not a separate white-label product.</p></div>
        </div>
        <p class="company-lead mt-6">Regulatory licences (Payment Aggregator, PPI, NBFC partnerships) follow commercial traction and legal counsel — we do not claim licences we have not been granted. We do not copy Razorpay Route, Cashfree Easy Split or Worldline POS until the Owner starts that deal. See <a href="compare.php" class="text-brand-400">how UniWeb compares</a>.</p>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Start with Test Mode today.</h2>
        <p>Integrate, demo to customers, complete KYC — go Live when gates and partner keys are ready.</p>
        <a href="merchant_register.php" class="btn-primary px-6 py-3">Create merchant account</a>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
