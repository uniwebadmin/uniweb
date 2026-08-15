<?php
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); }
if (function_exists('opcache_invalidate')) { opcache_invalidate(__DIR__ . '/config.php', true); }
require_once __DIR__ . '/config.php';
$visitorRegion = detectVisitorCountry();
$isIntl = $visitorRegion === 'International';
$marginNote = getPlatformMarginPct();
$cardMdr = getMdrWithMargin('card_debit');
$nbMdr = getMdrWithMargin('netbanking');
$walletMdr = getMdrWithMargin('wallet');
$publicStats = getPublicStats();
$pageTitle = 'Digital Fintech Payment Solutions';
$pageDescription = 'UniWeb — best payment gateway for Indian merchants. UPI, QR code payments, payment links, cards, net banking, API, KYC and settlements. Start free in Test Mode.';
$pageKeywords = 'payment gateway India, best payment gateway, UPI payment gateway, payment aggregator, QR payment India, fintech platform, merchant onboarding India';
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
            Test Mode Available · Live Activation After Approval
        </div>
        <h1 class="text-4xl sm:text-5xl lg:text-7xl font-extrabold leading-tight mb-6 animate-in">
            <?= __('hero_title') ?><br><span class="gradient-text"><?= __('hero_highlight') ?></span>
        </h1>
        <p class="text-lg sm:text-xl text-gray-400 max-w-2xl mx-auto mb-10 leading-relaxed">
            <?= __('hero_sub') ?>
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-16">
            <a href="merchant_register.php" class="w-full sm:w-auto bg-brand-600 hover:bg-brand-500 text-white px-10 py-4 rounded-xl font-semibold text-lg transition shadow-lg shadow-brand-600/25">Start Accepting Payments →</a>
            <a href="tour_videos.php" class="w-full sm:w-auto glass text-gray-200 hover:text-white px-8 py-4 rounded-xl font-semibold text-lg transition border border-gray-700">Watch Platform Tour</a>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 max-w-3xl mx-auto">
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= formatPublicVolume($publicStats['volume']) ?></div><div class="text-xs text-gray-500 mt-1">Verified Live Volume</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['merchants']) ?></div><div class="text-xs text-gray-500 mt-1">Live Merchants</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['partners'] ?? 0) ?></div><div class="text-xs text-gray-500 mt-1">Live Partners</div></div>
            <div><div class="text-2xl sm:text-3xl font-bold text-brand-400"><?= number_format($publicStats['transactions']) ?></div><div class="text-xs text-gray-500 mt-1">Verified Live Payments</div></div>
        </div>
        <p class="text-center text-xs text-gray-600 mt-6"><a href="api_docs.php" class="text-sky-400 hover:underline">OpenAPI docs</a> · HMAC webhook signing · Self-hosted QR · Compiled CSS</p>
    </div>
</section>

<section id="demo" class="py-16 sm:py-20 bg-gradient-to-b from-dark-950 via-violet-950/15 to-dark-950">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="text-center mb-10">
            <p class="text-violet-400 text-sm font-semibold uppercase tracking-wider mb-2">Platform Preview</p>
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">See <span class="gradient-text">UniWeb</span> in Action</h2>
            <p class="text-gray-400 text-sm sm:text-base max-w-xl mx-auto">Multi-gateway checkout, merchant dashboard, settlements, and KYC — built for Indian businesses.</p>
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
            <h2 class="text-3xl sm:text-4xl font-bold mb-4">Why Choose <span class="gradient-text"><?= APP_NAME ?></span>?</h2>
            <p class="text-gray-400 max-w-xl mx-auto">Complete payment infrastructure for Indian merchants — from QR to API.</p>
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
            <h2 class="text-3xl font-bold mb-4">Transparent <span class="gradient-text">Pricing</span></h2>
            <p class="text-gray-400 max-w-xl mx-auto">Final rates, taxes, reserves and settlement schedules are provided in the approved merchant commercial schedule.</p>
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

        <div class="grid md:grid-cols-3 gap-8 max-w-5xl mx-auto">
            <div class="glass rounded-2xl p-8 card-hover">
                <h3 class="text-lg font-semibold text-gray-300">Starter</h3>
                <p class="text-gray-500 text-sm mt-1">For new businesses</p>
                <div class="my-6 space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">UPI / QR</span><span class="font-bold text-brand-400">0%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Cards (Debit/Credit)</span><span class="font-bold"><?= formatMdr($cardMdr, false, true) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Netbanking</span><span class="font-bold"><?= formatMdr($nbMdr, false, true) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Wallets</span><span class="font-bold"><?= formatMdr($walletMdr) ?></span></div>
                    <div class="flex justify-between border-t border-gray-800 pt-3"><span class="text-gray-400">Settlement</span><span class="text-brand-400">T+1 Free</span></div>
                </div>
                <ul class="space-y-2 text-xs text-gray-500 mb-8">
                    <li>✓ Payment Links & QR</li><li>✓ Basic Dashboard</li><li>✓ Email Support</li>
                </ul>
                <a href="merchant_register.php" class="block text-center border border-brand-500/30 text-brand-400 hover:bg-brand-500/10 py-3 rounded-xl transition">Get Started</a>
            </div>
            <div class="bg-gradient-to-b from-brand-600/20 to-dark-900 border-2 border-brand-500/40 rounded-2xl p-8 card-hover relative">
                <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-brand-500 text-dark-900 text-xs font-bold px-3 py-1 rounded-full">MOST POPULAR</div>
                <h3 class="text-lg font-semibold text-brand-400">Business</h3>
                <p class="text-gray-500 text-sm mt-1">For growing merchants</p>
                <div class="my-6 space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">UPI / QR</span><span class="font-bold text-brand-400">0%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Cards</span><span class="font-bold text-brand-400">0.9%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Netbanking</span><span class="font-bold text-brand-400">0.9%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Wallets</span><span class="font-bold">1.25%</span></div>
                    <div class="flex justify-between border-t border-gray-800 pt-3"><span class="text-gray-400">Settlement</span><span class="text-brand-400">Same Day</span></div>
                </div>
                <ul class="space-y-2 text-xs text-gray-500 mb-8">
                    <li>✓ Everything in Starter</li><li>✓ API & Webhooks</li><li>✓ Priority Support</li><li>✓ Invoices & Reports</li>
                </ul>
                <a href="merchant_register.php" class="block text-center bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold transition">Get Started</a>
            </div>
            <div class="glass rounded-2xl p-8 card-hover">
                <h3 class="text-lg font-semibold text-gray-300">Enterprise</h3>
                <p class="text-gray-500 text-sm mt-1">High-volume businesses</p>
                <div class="my-6 space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">UPI / QR</span><span class="font-bold text-brand-400">0%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Cards</span><span class="font-bold">Custom</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Netbanking</span><span class="font-bold">Custom</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Wallets</span><span class="font-bold">Custom</span></div>
                    <div class="flex justify-between border-t border-gray-800 pt-3"><span class="text-gray-400">Settlement</span><span class="text-brand-400">Instant</span></div>
                </div>
                <ul class="space-y-2 text-xs text-gray-500 mb-8">
                    <li>✓ Dedicated Account Manager</li><li>✓ Custom Integration</li><li>✓ SLA Guarantee</li>
                </ul>
                <a href="contact.php" class="block text-center border border-gray-600 text-gray-300 hover:bg-white/5 py-3 rounded-xl transition">Contact Sales</a>
            </div>
        </div>
        <p class="text-center text-xs text-gray-600 mt-8">Published rates are available only after commercial approval and may vary by payment method, risk category and partner.</p>
        <?php else: ?>
        <div class="glass rounded-2xl p-8 max-w-3xl mx-auto text-center border border-brand-500/20">
            <h3 class="text-xl font-semibold mb-3">Commercial pricing is approval-based</h3>
            <p class="text-sm text-gray-400 leading-relaxed mb-6">Create a Test Mode account to evaluate the platform. Live rates and settlement schedules are shared after business review, KYC and payment-partner activation.</p>
            <a href="contact.php" class="btn-primary inline-block px-7 py-3">Request a commercial proposal</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="py-20 bg-dark-900/50">
    <div class="max-w-4xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-4">Ready to Accept Digital Payments?</h2>
        <p class="text-gray-400 mb-8">Join merchants across India. Setup takes less than 2 minutes.</p>
        <a href="merchant_register.php" class="inline-block bg-brand-600 hover:bg-brand-500 text-white px-10 py-4 rounded-xl font-semibold text-lg transition">Create Free Account →</a>
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
