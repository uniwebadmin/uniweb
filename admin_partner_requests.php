<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$partners = getBankingPartners();
$priorityKeys = ['payu', 'razorpay', 'razorpayx', 'cashfree', 'decentro', 'axis'];
$focusPartner = trim($_GET['partner'] ?? '');
$pageTitle = 'Banking Partner Requests';
require_once __DIR__ . '/header.php';
?>

<div class="mb-4">
    <a href="gateway_settings.php" class="text-sm text-gray-400 hover:text-white">← Gateway Settings</a>
</div>

<div class="bg-violet-500/10 border border-violet-500/30 rounded-xl p-5 mb-8 text-sm">
    <h2 class="font-semibold text-violet-300 mb-2">Production Email Directory — Send Tomorrow</h2>
    <p class="text-gray-400 text-xs mb-4">Copy each draft below or use <strong class="text-white">Open in Mail App</strong>. Ask for <strong class="text-white">pay-in + pay-out</strong> credentials, webhooks, KAM contact, and go-live checklist from every partner.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr><th class="text-left py-2 pr-3">Partner</th><th class="text-left py-2 pr-3">Email ID(s)</th><th class="text-left py-2">Products to request</th><th class="text-left py-2">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
            <?php foreach ($priorityKeys as $pk):
                if (!isset($partners[$pk])) continue;
                $pp = $partners[$pk];
                $emails = getPartnerContactEmails($pk);
            ?>
                <tr id="partner-<?= e($pk) ?>">
                    <td class="py-2.5 pr-3 font-medium text-white whitespace-nowrap"><?= e($pp['name']) ?></td>
                    <td class="py-2.5 pr-3">
                        <?php foreach ($emails as $em): ?>
                        <a href="mailto:<?= e($em) ?>" class="text-sky-400 block hover:underline"><?= e($em) ?></a>
                        <?php endforeach; ?>
                    </td>
                    <td class="py-2.5 pr-3 text-gray-400"><?= e($pp['use']) ?></td>
                    <td class="py-2.5 whitespace-nowrap">
                        <a href="#draft-<?= e($pk) ?>" class="text-brand-400 hover:text-brand-300 mr-3">Draft ↓</a>
                        <?php if ($emails): ?>
                        <a href="<?= e(partnerMailto($pk)) ?>" class="text-emerald-400 hover:text-emerald-300">Mail App</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-5 mb-8 text-sm">
    <h2 class="font-semibold text-emerald-300 mb-3">Charge Summary (MIN → MAX per txn)</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs text-gray-400">
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">Direct UPI P2M</strong><br>₹0 partner fee</div>
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">PayU/Razorpay UPI</strong><br>₹2–3 flat or ~2%+GST</div>
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">Cards</strong><br>1.75%–2.5%+GST</div>
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">Decentro KYC</strong><br>₹3–15 per verify</div>
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">Decentro Payout</strong><br>₹2–8 per IMPS</div>
        <div class="bg-dark-900/50 rounded-lg p-3"><strong class="text-white">UniWeb Commission</strong><br>~1.5% (your margin)</div>
    </div>
</div>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-5 mb-8 text-sm">
    <h2 class="font-semibold text-sky-300 mb-2">Sandbox vs Production</h2>
    <p class="text-gray-400 mb-3">Use <strong class="text-white">Sandbox / Test</strong> keys for testing now. Production keys, channel IDs, encryption keys, and IP whitelist are issued after partner approval. Axis and Digio can wait until those KAMs are ready.</p>
    <p class="text-amber-200/90 text-xs mb-3">UniWeb cannot invent partner API keys. Open each partner Sign Up / Dashboard link below, create a merchant or partner account with your company email, then paste sandbox keys into <a href="gateway_settings.php" class="text-sky-400 underline">Gateway Settings</a>. After Test Connection works, show partners your sandbox traffic and request Live keys.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr><th class="text-left py-2 pr-3">Product</th><th class="text-left py-2 pr-3">Ask partners for</th><th class="text-left py-2">Typical source</th></tr></thead>
            <tbody class="divide-y divide-gray-800 text-gray-400">
                <tr><td class="py-2 pr-3 text-white">UPI / P2M collect</td><td class="py-2 pr-3">Key ID + Secret (test), webhook secret</td><td class="py-2">Razorpay / Cashfree / PayU / Decentro sandbox</td></tr>
                <tr><td class="py-2 pr-3 text-white">Cards (CC/DC) + EMI + Netbanking + Wallet</td><td class="py-2 pr-3">Same PG test keys with those methods enabled</td><td class="py-2">PayU / Razorpay / Cashfree merchant dashboard → Test mode</td></tr>
                <tr><td class="py-2 pr-3 text-white">White-label / Route / Easy Split</td><td class="py-2 pr-3">Platform / partner MID, route or child keys</td><td class="py-2">Partner programme signup (not standard merchant keys)</td></tr>
                <tr><td class="py-2 pr-3 text-white">Payouts</td><td class="py-2 pr-3">Payout client ID/secret or payoutMerchantId</td><td class="py-2">RazorpayX / Cashfree Payouts / PayU Payouts / Decentro</td></tr>
                <tr><td class="py-2 pr-3 text-white">Virtual Account</td><td class="py-2 pr-3">VA / collection account credentials</td><td class="py-2">Decentro / Axis API portal (UAT)</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
<?php foreach ($partners as $key => $p): ?>
<div class="glass rounded-xl p-6" id="card-<?= e($key) ?>">
    <div class="flex items-start justify-between mb-4">
        <div>
            <h3 class="font-semibold text-lg"><?= e($p['name']) ?></h3>
            <p class="text-xs text-gray-500 mt-1"><?= e($p['use']) ?></p>
        </div>
        <?php if (isGatewayConfigured($key) || $key === 'decentro'): ?>
        <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2 py-1 rounded">Keys Set</span>
        <?php else: ?>
        <span class="text-xs bg-amber-500/20 text-amber-400 px-2 py-1 rounded">Pending</span>
        <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        <?php if (!empty($p['signup'])): ?><a href="<?= e($p['signup']) ?>" target="_blank" rel="noopener" class="bg-brand-600/20 text-brand-400 px-3 py-1.5 rounded-lg">Sign Up</a><?php endif; ?>
        <?php if (!empty($p['docs'])): ?><a href="<?= e($p['docs']) ?>" target="_blank" rel="noopener" class="glass px-3 py-1.5 rounded-lg text-gray-400">Docs</a><?php endif; ?>
        <?php if (!empty($p['dashboard'])): ?><a href="<?= e($p['dashboard']) ?>" target="_blank" rel="noopener" class="glass px-3 py-1.5 rounded-lg text-gray-400">Dashboard</a><?php endif; ?>
    </div>
  <?php
    $emails = getPartnerContactEmails($key);
    $draft = partnerProductionEmail($key);
  ?>
    <?php if ($emails): ?>
    <p class="text-xs text-gray-500 mb-2">Email ID<?= count($emails) > 1 ? 's' : '' ?>:
        <?php foreach ($emails as $i => $em): ?>
        <?php if ($i > 0): ?> · <?php endif; ?>
        <a href="mailto:<?= e($em) ?>" class="text-sky-400 hover:underline"><code><?= e($em) ?></code></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
    <textarea readonly rows="16" id="draft-<?= e($key) ?>" class="input-field text-xs font-mono leading-relaxed mb-3" aria-label="Partner email draft"><?= e($draft) ?></textarea>
    <div class="flex flex-wrap gap-2">
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('draft-<?= e($key) ?>').value);this.textContent='Copied!'" class="btn-primary text-xs px-4 py-2">Copy Email Draft</button>
        <?php if ($emails): ?>
        <a href="<?= e(partnerMailto($key)) ?>" class="glass text-xs px-4 py-2 rounded-lg text-sky-400">Open in Mail App</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="mt-8 glass rounded-xl p-6 text-sm text-gray-400">
    <h3 class="font-semibold text-white mb-3">PayU — Collections vs Payouts</h3>
    <ul class="space-y-2 text-xs list-disc list-inside">
        <li><strong class="text-gray-300">Payment Gateway:</strong> Collect from customers — Cards, UPI, Net Banking (onboarding.payu.in)</li>
        <li><strong class="text-gray-300">Split Settlement:</strong> Auto commission deduction — net amount to merchant</li>
        <li><strong class="text-gray-300">PayU Payouts (separate product):</strong> Transfer to beneficiaries via IMPS/NEFT/RTGS/UPI — <a href="https://docs.payu.in/docs/introduction-to-payouts" class="text-sky-400" target="_blank" rel="noopener">docs.payu.in/payouts</a></li>
        <li><strong class="text-gray-300">Settlement cycle:</strong> T+1 standard (negotiate same-day with KAM)</li>
        <li><strong class="text-gray-300">UniWeb wallet transfer:</strong> Internal ledger — automate bank transfer via PayU Payouts API in production</li>
    </ul>
    <p class="mt-3 text-xs">Email PayU: <a href="mailto:help@payu.in" class="text-sky-400">help@payu.in</a> · <a href="mailto:merchantcare@payu.in" class="text-sky-400">merchantcare@payu.in</a> · Request <code>payoutMerchantId</code> from your Key Account Manager for payout onboarding</p>
</div>

<div class="mt-6 glass rounded-xl p-6 text-sm text-gray-400">
    <h3 class="font-semibold text-white mb-3">Payout Partners — Comparison</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="text-gray-500"><tr><th class="text-left py-2">Partner</th><th class="text-left py-2">Best For</th><th class="text-left py-2">Modes</th><th class="text-left py-2">Settlement</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <tr><td class="py-2 text-violet-300">RazorpayX</td><td>Vendor/marketplace payouts</td><td>IMPS, NEFT, UPI</td><td>T+0 IMPS</td></tr>
                <tr><td class="py-2 text-sky-300">Cashfree Payouts</td><td>Bulk vendor + validation</td><td>IMPS, NEFT, UPI</td><td>Instant IMPS</td></tr>
                <tr><td class="py-2 text-brand-400">PayU Payouts</td><td>Refunds + B2B vendor</td><td>IMPS, NEFT, RTGS, UPI</td><td>T+0–T+1</td></tr>
                <tr><td class="py-2 text-cyan-300">Decentro</td><td>Full BaaS stack</td><td>IMPS, NEFT, UPI</td><td>API-driven</td></tr>
                <tr><td class="py-2 text-amber-300">Open Money</td><td>SME business account</td><td>IMPS, NEFT</td><td>Same day</td></tr>
                <tr><td class="py-2 text-gray-300">Easebuzz</td><td>PG + Payout combo</td><td>IMPS, NEFT</td><td>T+1</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs">In production, integrate 1–2 primary payout partners to automate merchant wallet → bank transfer.</p>
</div>

<div class="mt-6 glass rounded-xl p-6 text-sm text-gray-400">
    <h3 class="font-semibold text-white mb-3">Razorpay vs RazorpayX — Two Separate Emails</h3>
    <ul class="space-y-2 text-xs list-disc list-inside">
        <li><strong class="text-gray-300">Razorpay (Pay-in):</strong> Checkout + Route split — email <a href="mailto:partnerships@razorpay.com" class="text-sky-400">partnerships@razorpay.com</a> · support <a href="mailto:support@razorpay.com" class="text-sky-400">support@razorpay.com</a> · official <a href="mailto:contact@razorpay.com" class="text-sky-400">contact@razorpay.com</a></li>
        <li><strong class="text-gray-300">RazorpayX (Pay-out):</strong> Business account + Payouts API — same partnerships email + <a href="https://x.razorpay.com/" class="text-sky-400" target="_blank" rel="noopener">x.razorpay.com</a> dashboard · Partner signup: <a href="https://razorpay.com/x/partners/" class="text-sky-400" target="_blank" rel="noopener">razorpay.com/x/partners</a></li>
        <li><strong class="text-gray-300">Payroll only:</strong> <a href="mailto:xpayroll@razorpay.com" class="text-sky-400">xpayroll@razorpay.com</a> (not for merchant payouts)</li>
    </ul>
    <p class="mt-3 text-xs">Send <strong class="text-white">two separate emails</strong> tomorrow — one for Razorpay PG keys, one for RazorpayX payouts.</p>
</div>

<div class="mt-6 glass rounded-xl p-6 text-sm text-gray-400">
    <p><strong>To:</strong> <a href="mailto:support@decentro.tech" class="text-sky-400">support@decentro.tech</a> (production) · <a href="mailto:hello@decentro.tech" class="text-sky-400">hello@decentro.tech</a> (pricing)</p>
    <p class="mt-2">Docs: <a href="https://docs.decentro.tech/docs/api-basics" class="text-sky-400" target="_blank" rel="noopener">Sandbox → Production process</a></p>
</div>
<?php if ($focusPartner && isset($partners[$focusPartner])): ?>
<script>document.getElementById('card-<?= e($focusPartner) ?>')?.scrollIntoView({behavior:'smooth',block:'start'});</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
