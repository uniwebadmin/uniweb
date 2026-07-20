<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Payment Solutions for Indian Businesses';
$pageDescription = 'UniWeb payment gateway India: UPI, QR codes, payment links, cards, net banking, settlements, payouts, API and merchant onboarding for startups and SMEs.';
$pageKeywords = 'payment gateway India, UPI payment gateway, payment aggregator India, QR code payment, payment links, net banking payment, merchant onboarding, fintech platform India';
$canonicalUrl = APP_URL . '/solutions.php';
require_once __DIR__ . '/header.php';

$products = [
    [
        'id' => 'checkout',
        'eyebrow' => 'Checkout',
        'title' => 'Hosted payment gateway',
        'body' => 'Customers pay on a secure UniWeb checkout with UPI, cards, net banking and wallets via Razorpay, Cashfree or PayU when your Live rails are activated. Test Mode uses Instant Test Pay so you can demo without real money.',
        'points' => ['Test vs Live mode like major Indian gateways', 'Method tabs with clear amount and fee split', 'Customer name/phone captured for receipt lookup'],
        'cta' => ['demo.php', 'Try ₹1 demo'],
    ],
    [
        'id' => 'links',
        'eyebrow' => 'Collections',
        'title' => 'Payment links',
        'body' => 'Create shareable links for invoices, deposits or one-time orders. Track views, status and settlements from the merchant dashboard — the workflow SMEs expect from Razorpay Payment Links.',
        'points' => ['Expiry, amount and description controls', 'WhatsApp-ready payment intents', 'Webhook + in-app notifications on success'],
        'cta' => ['merchant_register.php', 'Start free Test Mode'],
    ],
    [
        'id' => 'qr',
        'eyebrow' => 'In-store',
        'title' => 'QR code payments',
        'body' => 'Fixed-amount, dynamic UPI and all-method QR journeys for shops, counters and field teams. Designed for high volume — up to 10 lakh small payments per day without UniWeb high-frequency account locks.',
        'points' => ['Reusable counter QR', 'Amount-entry dynamic QR', 'Scan analytics on the QR record'],
        'cta' => ['platform_demo.php', 'See platform tour'],
    ],
    [
        'id' => 'api',
        'eyebrow' => 'Developers',
        'title' => 'Merchant API',
        'body' => 'REST API for payment links, transactions, balance and refunds with separate Test and Live credentials, HMAC webhooks and OpenAPI reference.',
        'points' => ['Key + secret authentication', 'Idempotent create flows', 'Outbound webhook retries'],
        'cta' => ['api_docs.php', 'Open API docs'],
    ],
    [
        'id' => 'settle',
        'eyebrow' => 'Money out',
        'title' => 'Settlements & wallet',
        'body' => 'Successful collections credit the merchant wallet. Settle to a verified bank account on a T+N style schedule or manual Settle Now — with UTR tracking for ops.',
        'points' => ['Available vs pending visibility', 'Min settlement threshold', 'Partner payout rails when keys are live'],
        'cta' => ['pricing.php', 'View pricing model'],
    ],
    [
        'id' => 'kyc',
        'eyebrow' => 'Compliance',
        'title' => 'KYC & agreements',
        'body' => 'Entity-based document checklist, PAN/GST/Aadhaar flows, video KYC and electronic merchant agreement with IP, typed signature and downloadable PDF.',
        'points' => ['Maker-checker admin review', 'Live Mode gates before real money', 'Named grievance officer on Contact'],
        'cta' => ['trust.php', 'Trust & Security'],
    ],
];
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Products</div>
        <h1>Payment products Indian merchants actually use.</h1>
        <p>Compare UniWeb to Razorpay, Cashfree and PayU on the workflows that matter: checkout, links, QR, API, settlements and KYC — with honest partner-rail positioning.</p>
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="demo.php" class="btn-primary px-6 py-3">Try ₹1 demo payment</a>
            <a href="merchant_register.php" class="px-6 py-3 rounded-lg border border-gray-700">Create merchant account</a>
        </div>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-kicker">At a glance</div>
        <div class="company-grid">
            <?php foreach ($products as $p): ?>
            <a href="#<?= e($p['id']) ?>" class="company-card hover:border-sky-500/40 transition block">
                <div class="company-kicker"><?= e($p['eyebrow']) ?></div>
                <h3><?= e($p['title']) ?></h3>
                <p><?= e(mb_substr($p['body'], 0, 120)) ?>…</p>
            </a>
            <?php endforeach; ?>
        </div>
    </div></section>

    <?php foreach ($products as $p): ?>
    <section id="<?= e($p['id']) ?>" class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker"><?= e($p['eyebrow']) ?></div>
        <h2 class="company-title"><?= e($p['title']) ?></h2>
        <p class="company-lead"><?= e($p['body']) ?></p>
        <ul class="mt-4 space-y-2 text-sm text-gray-400 list-disc pl-5">
            <?php foreach ($p['points'] as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
        </ul>
        <a href="<?= e($p['cta'][0]) ?>" class="btn-primary inline-block px-6 py-3 mt-6"><?= e($p['cta'][1]) ?> →</a>
    </div></section>
    <?php endforeach; ?>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">How we compare</div>
        <h2 class="company-title">What matches big gateways — and what does not</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Matches today</h3><p>Test/Live modes, payment links, QR, hosted checkout, KYC gates, wallet + settlements, refunds, staff RBAC, webhooks.</p></div>
            <div class="company-card"><h3>Partner-dependent</h3><p>Live cards/UPI money movement, PhonePe native, EMI/BNPL, Instant Settlement — need partner keys and commercial approval.</p></div>
            <div class="company-card"><h3>Still building</h3><p>Payment Pages builder, full developer hub, case studies — see the <a href="roadmap.php" class="text-brand-400">roadmap</a>. Merchant team invites are live under Team in the dashboard.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Integrate in Test Mode today.</h2>
        <p>Go Live only after KYC, agreement and partner activation — the same discipline serious Indian aggregators use.</p>
        <div class="flex flex-wrap gap-3">
            <a href="platform_demo.php" class="btn-primary px-6 py-3">Platform tour</a>
            <a href="contact.php" class="px-6 py-3 rounded-lg border border-gray-700">Talk to sales</a>
        </div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
