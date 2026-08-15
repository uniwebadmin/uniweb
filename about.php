<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'About Us';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">About UniWeb</div>
        <h1>Payment operations built for Indian businesses.</h1>
        <p>UniWeb brings merchant onboarding, test payments, payment links, QR experiences, transaction visibility, refunds, settlements and support into one practical platform—designed to work with approved banking and payment partners.</p>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-kicker">Who we are</div>
        <h2 class="company-title">Technology with clear operational responsibility.</h2>
        <p class="company-lead">We are building infrastructure that helps businesses move from a demo integration to controlled live payment operations. That means a clean checkout is only one part of the job. Merchant verification, permissions, reconciliation, refund evidence, settlement reasons, audit records and support must also work together.</p>
        <div class="company-grid" style="margin-top:26px">
            <div class="company-card"><h3>Our mission</h3><p>Make digital payment operations understandable and accessible for Indian merchants without hiding important compliance, risk or settlement details.</p></div>
            <div class="company-card"><h3>Our vision</h3><p>Build a trusted multi-partner merchant platform where businesses can onboard carefully, integrate once and manage day-to-day payment operations with confidence.</p></div>
            <div class="company-card"><h3>Our method</h3><p>Test before live activation, verify before fund movement, record sensitive actions and clearly separate platform capability from partner approval.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Company identity</div>
        <h2 class="company-title">Registered details</h2>
        <div class="company-facts">
            <div class="company-fact"><span>Legal name</span><strong><?= e(COMPANY_LEGAL_NAME) ?></strong></div>
            <div class="company-fact"><span>Managing Director / CEO</span><strong><?= e(COMPANY_CEO) ?></strong></div>
            <div class="company-fact"><span>Corporate Identity Number</span><strong><?= e(COMPANY_CIN) ?></strong></div>
            <div class="company-fact"><span>GST registration</span><strong><?= e(COMPANY_GST) ?></strong></div>
            <div class="company-fact"><span>Registered office</span><strong><?= e(COMPANY_ADDRESS) ?></strong></div>
            <div class="company-fact"><span>Official contact</span><strong><?= e(COMPANY_SUPPORT_EMAIL) ?> · <?= e(COMPANY_PHONE) ?></strong></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Who it is for</div>
        <h2 class="company-title">Three merchant segments — one console.</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Shop &amp; counter</h3><p>QR at the till, payment links on WhatsApp, UPI confirmation without building a website first.</p></div>
            <div class="company-card"><h3>Online SME</h3><p>Hosted checkout, invoices, refunds and settlement tracking after KYC. Live cards and UPI use approved partner rails.</p></div>
            <div class="company-card"><h3>Developers</h3><p>Test and Live API keys, HMAC webhooks and OpenAPI docs. Sandbox money never looks like a live capture.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">What the platform covers</div>
        <h2 class="company-title">From onboarding to reconciliation.</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Merchant readiness</h3><p>Entity-specific KYC, bank details, business profile, website review, agreement acceptance and separate Test and Live environments.</p></div>
            <div class="company-card"><h3>Collection tools</h3><p>Payment links, reusable and amount-based QR journeys, checkout experiences, API credentials and transaction status visibility.</p></div>
            <div class="company-card"><h3>Operations</h3><p>Settlement scheduling, refund workflows, dispute reasons, staff controls, support threads, audit events and reconciliation tools.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card company-wide"><div class="company-kicker">Clear disclosure</div><h2>What UniWeb does not promise</h2><p>We do not describe a demo as a live banking service, guarantee partner approval, promise an exact settlement time during external outages, or claim a regulatory licence that has not been granted. Live capabilities are shown only after the relevant KYC, commercial and technical activation.</p></div>
            <div class="company-card"><div class="company-kicker">Trust centre</div><h2>Read our framework</h2><p>Review how we approach merchant review, transaction monitoring, customer protection and data handling.</p><a href="compliance.php" class="text-brand-400 text-sm inline-block mt-4">Open Compliance Framework →</a></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Explore UniWeb before applying.</h2>
        <p>Use the platform tour and Test Mode to understand the workflow. Live activation follows merchant verification and partner approval.</p>
        <div class="flex flex-wrap gap-3"><a href="demo.php" class="btn-primary px-6 py-3">View platform tour</a><a href="merchant_register.php" class="px-6 py-3 rounded-lg border border-gray-700">Create merchant account</a></div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
