<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Trust & Security';
$pageDescription = 'UniWeb trust and security: HTTPS, KYC, audit logs, webhook verification, partner rails, grievance contacts and clear RBI positioning for Indian merchants.';
$pageKeywords = 'UniWeb security, payment gateway trust, PCI, KYC, grievance officer, fintech India compliance';
$canonicalUrl = APP_URL . '/trust.php';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Trust Centre</div>
        <h1>How UniWeb protects merchants and payment operations.</h1>
        <p>We publish the controls that matter for Indian businesses: transport security, KYC gates, audit trails, partner verification and a named grievance path — without claiming licences we do not hold.</p>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><h3>HTTPS everywhere</h3><p>Dashboard, checkout and API traffic use TLS. Session cookies are protected; idle timeout applies on merchant and admin portals.</p></div>
            <div class="company-card"><h3>KYC before Live Mode</h3><p>Live collections require verified documents, bank account, website review, video KYC where required, and signed merchant agreement.</p></div>
            <div class="company-card"><h3>Webhook &amp; API integrity</h3><p>Gateway webhooks are signature-checked. Merchant outbound webhooks use HMAC. API keys are separated for Test and Live.</p></div>
            <div class="company-card"><h3>Audit &amp; maker-checker</h3><p>Sensitive KYC and Live activation actions go through independent checker approval with immutable audit records.</p></div>
            <div class="company-card"><h3>Velocity &amp; fraud signals</h3><p>Failed login and non-QR payment abuse are rate-limited. Merchant QR checkout is high-throughput — UniWeb does not lock accounts for high frequency of small successful payments (up to 10 lakh/day).</p></div>
            <div class="company-card"><h3>Private KYC storage</h3><p>New KYC uploads are stored outside the public web root with access limited to authorized staff viewers.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Regulatory position</div>
        <h2 class="company-title">What we are — and what we are not</h2>
        <div class="company-card company-wide">
            <p><?= e(COMPANY_LEGAL_NAME) ?> operates a merchant technology platform. Live acquiring, cards, UPI settlement and payouts are provided through contracted banks and payment partners after commercial activation.</p>
            <p class="mt-3"><strong>We do not claim</strong> that UniWeb independently holds an RBI Payment Aggregator licence, a banking licence, or a card-network membership. Those claims appear only when the relevant licence or partner agreement is in force and disclosed here.</p>
            <p class="mt-3"><a href="compliance.php" class="text-brand-400">Read the full Compliance Framework →</a></p>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Certifications</div>
        <h2 class="company-title">Badges we will show only when true</h2>
        <p class="company-lead">We do not display PCI DSS, ISO 27001 or SOC 2 badges until an independent assessment is completed. Partner gateways (Razorpay, Cashfree, PayU and banks) maintain their own PCI and network certifications for card-present and card-not-present rails.</p>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Grievance</div>
        <h2 class="company-title">Named contact for complaints</h2>
        <div class="company-facts">
            <div class="company-fact"><span>Grievance Officer</span><strong><?= e(COMPANY_CEO) ?></strong></div>
            <div class="company-fact"><span>Email</span><strong><?= e(COMPANY_SUPPORT_EMAIL) ?></strong></div>
            <div class="company-fact"><span>Phone</span><strong><?= e(COMPANY_PHONE) ?></strong></div>
            <div class="company-fact"><span>Registered office</span><strong><?= e(COMPANY_ADDRESS) ?></strong></div>
        </div>
        <p class="company-lead mt-4">Include merchant code and transaction/settlement ID. Do not send OTPs, PINs or passwords. Escalation: reply on the same ticket within 7 days if unresolved.</p>
        <a href="contact.php" class="btn-primary inline-block px-6 py-3 mt-4">Open contact form</a>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Review legal documents</h2>
        <div class="flex flex-wrap gap-3">
            <a href="terms.php" class="btn-primary px-6 py-3">Terms</a>
            <a href="privacy.php" class="px-6 py-3 rounded-lg border border-gray-700">Privacy</a>
            <a href="business_agreement.php" class="px-6 py-3 rounded-lg border border-gray-700">Merchant Agreement</a>
            <a href="refund_policy.php" class="px-6 py-3 rounded-lg border border-gray-700">Refund Policy</a>
        </div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
