<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'How UniWeb compares';
$pageDescription = 'Honest comparison of UniWeb vs Razorpay, Cashfree, PayU, Juspay, Stripe, Worldline and Decentro — quality bar, not a copy of every feature.';
$pageKeywords = 'UniWeb vs Razorpay, payment gateway comparison India, Cashfree PayU alternative';
$canonicalUrl = APP_URL . '/compare.php';
require_once __DIR__ . '/header.php';

$peers = [
    [
        'name' => 'Razorpay',
        'they' => 'Docs, payment links, QR, Route/split, polished trust UX.',
        'we' => 'Match links and QR reliability first. Razorpay Route / linked-account split stays off until the Owner asks and partner keys exist.',
    ],
    [
        'name' => 'Cashfree',
        'they' => 'Payouts, verification APIs, marketplace Easy Split.',
        'we' => 'Collect and settle first. Payout polish and vendor split come after core collections are green on live rails.',
    ],
    [
        'name' => 'PayU',
        'they' => 'Broad India coverage and card acquiring.',
        'we' => 'PayU is a partner rail. Live cards need credentials, MCC and method activation — we do not pretend coverage without keys.',
    ],
    [
        'name' => 'Juspay',
        'they' => 'Orchestration reliability across many gateways.',
        'we' => 'One UniWeb checkout with partner routing. We measure uptime on Status. We do not ship a second orchestrator product.',
    ],
    [
        'name' => 'Stripe',
        'they' => 'Developer docs, signed webhooks, strict test vs live.',
        'we' => 'HMAC webhooks, OpenAPI, uw_test_ vs uw_live_ keys. Docs stay practical — not a clone of Stripe’s catalogue.',
    ],
    [
        'name' => 'Worldline',
        'they' => 'Acquiring for POS and online together.',
        'we' => 'Online-first: checkout, links, QR. In-store POS terminals only if the Owner adds that deal.',
    ],
    [
        'name' => 'Decentro',
        'they' => 'Banking and UPI APIs for platforms.',
        'we' => 'Decentro is a partner API. Sandbox vs live labels stay visible. Staging keys never look like a live capture.',
    ],
];
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Quality bar</div>
        <h1>We study the big gateways. We do not copy their catalogue.</h1>
        <p>UniWeb is a merchant console for Indian collections: links, QR, UPI, KYC and settlements on licensed partner rails. This page is the quality bar — not a promise that every Razorpay or Cashfree product is live here.</p>
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="solutions.php" class="btn-primary px-6 py-3">See products</a>
            <a href="status.php" class="px-6 py-3 rounded-lg border border-gray-700">System status</a>
        </div>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-kicker">Aggregator model</div>
        <h2 class="company-title">One UniWeb signup — not ten separate PG dashboards</h2>
        <p class="company-lead">Merchants register once on UniWeb. You paste partner keys once in Partner Registry. Customers see payment methods only (UPI, Card, Net Banking) — not Razorpay or PayU brand buttons. That is the bar we hold against Razorpay, Cashfree, PayU and Juspay.</p>
        <div class="overflow-x-auto mt-6">
            <table class="min-w-[720px] w-full text-sm border border-gray-800 rounded-xl overflow-hidden">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                    <tr>
                        <th class="px-5 py-3 text-left">Area</th>
                        <th class="px-5 py-3 text-left">UniWeb</th>
                        <th class="px-5 py-3 text-left">Typical market PG</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800 text-gray-300">
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Merchant + Admin console</td><td class="px-5 py-3">Strong — multi-rail, disputes, support queues</td><td class="px-5 py-3">Strong — often single-rail focused</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Keys</td><td class="px-5 py-3">Admin-only Partner Registry (merchant never pastes PG keys)</td><td class="px-5 py-3">Merchant often holds their own PG account</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Checkout</td><td class="px-5 py-3">Methods only — UPI, Card, NB (no partner brand on pay button)</td><td class="px-5 py-3">Brand-owned checkout (their logo on the page)</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">KYC to network</td><td class="px-5 py-3">Forward queue — honest staged until live partner API</td><td class="px-5 py-3">Often live onboarding API</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Disputes</td><td class="px-5 py-3">Admin-first queue on UniWeb</td><td class="px-5 py-3">Deep partner dispute APIs</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Success-rate route</td><td class="px-5 py-3">Parked — not sold as live</td><td class="px-5 py-3">Juspay-class orchestration</td></tr>
                    <tr><td class="px-5 py-3 font-medium text-gray-200">Extra products</td><td class="px-5 py-3">No NBFC, no customer PPI wallet, no white-label resale</td><td class="px-5 py-3">Often separate lending / wallet products</td></tr>
                </tbody>
            </table>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">P9-01 Packaging</div>
        <h2 class="company-title">Features are high. Polish must catch up.</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Educated empty lists</h3><p>If a list is empty, the next click is on the same page: create a link, QR, KYC upload, or ticket — not a blank table that looks broken.</p></div>
            <div class="company-card"><h3>Actionable errors</h3><p>Checkout and portal errors tell you what to do next. PHP and SQL text stay in the admin Error Log, not on the customer screen.</p></div>
            <div class="company-card"><h3>After core collect</h3><p>Payout automation, Route/split, POS and marketplace vendors wait until Test and Live collections are reliable. We do not sell those as live today.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Peers</div>
        <h2 class="company-title">What they do well — and our bar</h2>
        <div class="overflow-x-auto">
            <table class="min-w-[720px] w-full text-sm border border-gray-800 rounded-xl overflow-hidden">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                    <tr>
                        <th class="px-5 py-3 text-left">Peer</th>
                        <th class="px-5 py-3 text-left">They are known for</th>
                        <th class="px-5 py-3 text-left">UniWeb bar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($peers as $row): ?>
                    <tr>
                        <td class="px-5 py-4 font-semibold text-gray-200"><?= e($row['name']) ?></td>
                        <td class="px-5 py-4 text-gray-400"><?= e($row['they']) ?></td>
                        <td class="px-5 py-4 text-gray-300"><?= e($row['we']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-4">We do not independently hold an RBI Payment Aggregator or banking licence. Live money uses contracted partners. We do not offer a consumer PPI wallet or an NBFC loan product.</p>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-kicker">Do not build yet</div>
        <h2 class="company-title">Parked until Owner asks</h2>
        <div class="company-grid">
            <div class="company-card"><h3>Route / Easy Split</h3><p>Scaffold only. Live split after keys, commercial terms, and an explicit start.</p></div>
            <div class="company-card"><h3>POS terminals</h3><p>Online checkout, links and QR first. Card machines only for a real deal.</p></div>
            <div class="company-card"><h3>NBFC &amp; PPI wallet</h3><p>Hidden on purpose. Wrong licence. Not a UniWeb product.</p></div>
        </div>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Start in Test Mode. Compare on real workflows.</h2>
        <p>Create a link, scan a QR, watch Status. Live collections wait for KYC and partner activation.</p>
        <div class="flex flex-wrap gap-3">
            <a href="merchant_register.php" class="btn-primary px-6 py-3">Create Test account</a>
            <a href="roadmap.php" class="px-6 py-3 rounded-lg border border-gray-700">Roadmap</a>
        </div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
