<?php
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); }
if (function_exists('opcache_invalidate')) { opcache_invalidate(__DIR__ . '/config.php', true); }
require_once __DIR__ . '/config.php';
$publicStats = getPublicStats();
$pageTitle = 'Digital Fintech Payment Solutions';
$pageDescription = 'UniWeb — payment links, QR and UPI for Indian merchants. Cards and other rails appear only after partner keys and merchant activation. Start free in Test Mode.';
$pageKeywords = 'payment gateway India, UPI payment gateway, QR payment India, payment links, merchant KYC India, fintech platform';
$canonicalUrl = APP_URL . '/';
if (!is_file(__DIR__ . '/header.php')) {
    http_response_code(503);
    header('Retry-After: 5');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'UniWeb is updating. Refresh in a few seconds.';
    exit;
}
require_once __DIR__ . '/header.php';
?>

<section class="public-hero relative pt-32 pb-24 overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-900/20 via-dark-950 to-cyan-900/10"></div>
    <div class="absolute top-20 left-1/4 w-72 h-72 bg-brand-500/10 rounded-full blur-3xl"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div class="inline-flex items-center gap-2 bg-brand-500/10 border border-brand-500/20 rounded-full px-4 py-1.5 text-sm text-brand-400 mb-8 animate-in">
            <span class="w-2 h-2 bg-brand-400 rounded-full animate-pulse"></span>
            Collect with UPI, QR &amp; links · Test free · Live after KYC
        </div>
        <h1 class="text-4xl sm:text-5xl lg:text-7xl font-extrabold leading-tight mb-6 animate-in">
            <?= __('hero_title') ?><br><span class="gradient-text"><?= __('hero_highlight') ?></span>
        </h1>
        <p class="text-lg sm:text-xl text-gray-400 max-w-2xl mx-auto mb-10 leading-relaxed">
            <?= __('hero_sub') ?>
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-16">
            <a href="merchant_register.php" class="w-full sm:w-auto bg-brand-600 hover:bg-brand-500 text-white px-10 py-4 rounded-xl font-semibold text-lg transition shadow-lg shadow-brand-600/25">Start Test Mode — free →</a>
            <a href="contact.php" class="w-full sm:w-auto glass text-gray-200 hover:text-white px-8 py-4 rounded-xl font-semibold text-lg transition border border-gray-700">Talk to sales</a>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 max-w-3xl mx-auto">
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= formatPublicVolume($publicStats['volume']) ?></div><div class="text-xs text-gray-500 mt-1">Verified Live Volume</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['merchants']) ?></div><div class="text-xs text-gray-500 mt-1">Live Merchants</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['partners'] ?? 0) ?></div><div class="text-xs text-gray-500 mt-1">Live Partners</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['transactions']) ?></div><div class="text-xs text-gray-500 mt-1">Verified Live Payments</div></div>
        </div>
        <p class="text-center text-xs text-gray-600 mt-6"><a href="trust.php" class="text-sky-400 hover:underline">Trust centre</a> · <a href="status.php" class="text-sky-400 hover:underline">System status</a> · <a href="api_docs.php" class="text-sky-400 hover:underline">API docs</a></p>
    </div>
</section>

<section class="py-10 border-y border-gray-800/80 bg-dark-900/40">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <?php require __DIR__ . '/includes/trust_strip.php'; ?>
    </div>
</section>

<section id="pillars" class="py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <p class="text-brand-400 text-sm font-semibold uppercase tracking-wider mb-2">What UniWeb is for</p>
            <h2 class="text-3xl sm:text-4xl font-bold mb-4">Collect. Operate. Settle.</h2>
            <p class="text-gray-400 max-w-2xl mx-auto">Built for Indian shops, websites and APIs — not a consumer wallet, not an NBFC loan product.</p>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
            <div class="glass rounded-2xl p-8">
                <h3 class="text-xl font-semibold mb-3">Collect</h3>
                <p class="text-gray-400 text-sm leading-relaxed mb-4">Payment links, counter QR, hosted checkout and UPI journeys. Share on WhatsApp. Test with Instant Test Pay before Live rails are on.</p>
                <a href="solutions.php#links" class="text-sm text-brand-400">See collection tools →</a>
            </div>
            <div class="glass rounded-2xl p-8">
                <h3 class="text-xl font-semibold mb-3">Operate</h3>
                <p class="text-gray-400 text-sm leading-relaxed mb-4">KYC, video review, staff roles, refunds, disputes and a named grievance path. Test Mode and Live Mode stay separate.</p>
                <a href="trust.php" class="text-sm text-brand-400">Trust &amp; security →</a>
            </div>
            <div class="glass rounded-2xl p-8">
                <h3 class="text-xl font-semibold mb-3">Settle</h3>
                <p class="text-gray-400 text-sm leading-relaxed mb-4">Settlement balance, batch tracking and UTR on the commercial schedule you approve. Timing follows banks and partners — we do not invent instant settlement.</p>
                <a href="pricing.php" class="text-sm text-brand-400">How fees work →</a>
            </div>
        </div>
    </div>
</section>

<section id="demo" class="py-16 sm:py-20 bg-gradient-to-b from-dark-950 via-violet-950/15 to-dark-950">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="text-center mb-10">
            <p class="text-violet-400 text-sm font-semibold uppercase tracking-wider mb-2">Platform Preview</p>
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">See <span class="gradient-text">UniWeb</span> in Action</h2>
            <p class="text-gray-400 text-sm sm:text-base max-w-xl mx-auto">Checkout, merchant dashboard, settlements and KYC — partner methods show only when enabled for that merchant.</p>
        </div>


        <div class="flex flex-wrap justify-center gap-3">
            <a href="tour_videos.php" class="inline-flex items-center gap-2 bg-violet-600 hover:bg-violet-500 text-white px-6 py-3 rounded-xl font-semibold text-sm sm:text-base">
                <span>▶</span> Play Full Tour
            </a>
            <a href="merchant_register.php" class="inline-flex items-center gap-2 bg-sky-600 hover:bg-sky-500 text-white px-6 py-3 rounded-xl font-semibold text-sm sm:text-base">Get Started Free</a>
        </div>
    </div>
</section>

<section id="features" class="py-20 bg-dark-900/50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl sm:text-4xl font-bold mb-4">Why merchants pick <span class="gradient-text"><?= APP_NAME ?></span></h2>
            <p class="text-gray-400 max-w-xl mx-auto">Practical payment operations — not a feature dump that hides partner and KYC gates.</p>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
            <?php
            $features = [
                ['Flexible QR Journeys', 'Create test QR and payment-link journeys; live collection is enabled only after merchant and partner approval.', 'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h4'],
                ['Partner-Based Payments', 'Domestic payment methods are made available according to each merchant’s approved partner configuration.', 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['Structured KYC Review', 'Entity-specific document collection, video submission and admin review with partner verification where activated.', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['Settlement Tracking', 'Track batches, adjustments and bank references. Actual timing follows the activated commercial schedule.', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['Layered Security Controls', 'Secure sessions, signed provider webhooks, role-based access and auditable operational workflows.', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622'],
                ['Payment Links', 'Create shareable payment links via WhatsApp, SMS, or email. No website needed.', 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
                ['Real-time Dashboard', 'Track transactions, settlements, disputes, and analytics in real-time.', 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['REST API & Webhooks', 'Integrate payments into your app with our developer-friendly API and real-time webhooks.', 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4'],
            ];
            foreach ($features as [$t,$d,$i]): ?>
            <div class="glass rounded-2xl p-8 card-hover">
                <div class="w-12 h-12 bg-brand-500/10 rounded-xl flex items-center justify-center mb-5">
                    <svg class="w-6 h-6 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= $i ?>"/></svg>
                </div>
                <h3 class="text-xl font-semibold mb-3"><?= $t ?></h3>
                <p class="text-gray-400 text-sm leading-relaxed"><?= $d ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="pricing" class="py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl font-bold mb-4">Honest <span class="gradient-text">pricing</span></h2>
            <p class="text-gray-400 max-w-xl mx-auto">No “0% forever” card. Test Mode is free. Live fees are partner MDR + UniWeb commission + GST in your commercial schedule — not a white-label package for sale.</p>
        </div>

        <?php $publicPricingApproved = getSetting('public_pricing_approved', '0') === '1'; ?>
        <?php if ($publicPricingApproved): ?>
        <div class="glass rounded-2xl overflow-hidden mb-12 max-w-4xl mx-auto">
            <div class="px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">All Payment Modes & MDR</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                        <tr>
                            <th class="px-5 py-3 text-left">Payment Mode</th>
                            <th class="px-5 py-3 text-left">Base MDR</th>
                            <th class="px-5 py-3 text-left">Merchant Rate</th>
                            <th class="px-5 py-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php foreach (getPaymentModes() as $mode => $info):
                            $base = getBaseMdr($mode);
                            $total = getMdrWithMargin($mode);
                            $custom = $info['custom'];
                            $gst = !empty($info['gst']);
                        ?>
                        <tr class="hover:bg-white/5">
                            <td class="px-5 py-3"><?= $info['icon'] ?> <?= e($info['label']) ?></td>
                            <td class="px-5 py-3 text-gray-400"><?= $custom ? '—' : formatMdr($base, false, $gst) ?></td>
                            <td class="px-5 py-3 font-semibold text-brand-400"><?= formatMdr($total, $custom, $gst) ?></td>
                            <td class="px-5 py-3">
                                <?php if ($custom): ?>
                                <a href="contact.php" class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1 rounded-lg">Contact Sales Team</a>
                                <?php else: ?>
                                <a href="merchant_register.php" class="text-xs text-gray-400">Get Started</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-8 max-w-5xl mx-auto mb-10">
            <div class="glass rounded-2xl p-8">
                <h3 class="text-lg font-semibold text-gray-200">Test Mode</h3>
                <p class="text-gray-500 text-sm mt-1">Shops, websites, developers evaluating the console</p>
                <p class="text-3xl font-bold text-brand-400 my-4">₹0</p>
                <p class="text-sm text-gray-400 mb-6">Instant Test Pay, links, QR and API sandbox. No real money movement.</p>
                <a href="merchant_register.php" class="block text-center border border-brand-500/30 text-brand-400 hover:bg-brand-500/10 py-3 rounded-xl transition">Create Test account</a>
            </div>
            <div class="bg-gradient-to-b from-brand-600/20 to-dark-900 border-2 border-brand-500/40 rounded-2xl p-8">
                <h3 class="text-lg font-semibold text-brand-400">Live — SME</h3>
                <p class="text-gray-500 text-sm mt-1">After KYC, agreement and partner activation</p>
                <p class="text-sm text-gray-300 my-4 leading-relaxed">Partner MDR + UniWeb platform fee + GST. Settlement on the written T+N schedule — not a public “same day” promise.</p>
                <a href="contact.php" class="block text-center bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold transition">Request commercial terms</a>
            </div>
            <div class="glass rounded-2xl p-8">
                <h3 class="text-lg font-semibold text-gray-200">High volume</h3>
                <p class="text-gray-500 text-sm mt-1">Custom MCC, reserves and rails</p>
                <p class="text-sm text-gray-400 my-4 leading-relaxed">Negotiated schedule, named support, and partner routing. We do not publish a fake 0% UPI rate card.</p>
                <a href="contact.php" class="block text-center border border-gray-600 text-gray-300 hover:bg-white/5 py-3 rounded-xl transition">Talk to sales</a>
            </div>
        </div>
        <p class="text-center text-xs text-gray-600 mt-2 mb-8">Your Merchant Portal schedule is the source of truth. Website numbers are illustrative only when a public MDR table is approved.</p>
        <?php else: ?>
        <div class="glass rounded-2xl p-8 max-w-3xl mx-auto text-center border border-brand-500/20">
            <h3 class="text-xl font-semibold mb-3">Live rates are written, not guessed</h3>
            <p class="text-sm text-gray-400 leading-relaxed mb-6">Use Test Mode now. After KYC we share partner MDR, UniWeb platform fee, GST and settlement T+N in a commercial schedule. Public MDR tables appear here only when approved for publication.</p>
            <a href="contact.php" class="btn-primary inline-block px-7 py-3">Request a commercial proposal</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="py-20 bg-dark-900/50">
    <div class="max-w-4xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-4">Start in Test Mode. Go Live when you are ready.</h2>
        <p class="text-gray-400 mb-8">Account setup takes a few minutes. Live collections wait for KYC, agreement and partner activation.</p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="merchant_register.php" class="inline-block bg-brand-600 hover:bg-brand-500 text-white px-10 py-4 rounded-xl font-semibold text-lg transition">Create Test account →</a>
            <a href="contact.php" class="inline-block glass border border-gray-700 text-gray-200 px-8 py-4 rounded-xl font-semibold">Request commercial terms</a>
        </div>
    </div>
</section>

<section class="py-8 border-y border-gray-800/80 bg-dark-900/40">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <?php require __DIR__ . '/includes/trust_strip.php'; ?>
        <p class="text-center text-[11px] text-gray-600 mt-5 max-w-3xl mx-auto">
            <?= e(COMPANY_LEGAL_NAME) ?> · GST <?= COMPANY_GST ?> · CIN <?= COMPANY_CIN ?>
        </p>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
