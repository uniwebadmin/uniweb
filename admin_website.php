<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/includes/platform_api.php';

requireSuperAdmin();

try {
    $health = platformHealthSummary();
    $gatewayKeys = getPlatformGatewayKeyStatus();
    $merchantKeys = getMerchantApiKeyRows(80);
    $webStats = getWebsitePlatformStats();
    $activePg = getSetting('active_payment_gateway', 'razorpay');
    $selfCheck = runAdminPlatformSelfChecks();
} catch (Throwable $e) {
    logPlatformError('error', 'admin_website.php: ' . $e->getMessage(), ['file' => __FILE__]);
    flash('error', 'Could not load API keys page: ' . $e->getMessage());
    redirect('admin_dashboard.php');
}

$pageTitle = 'Platform API guide';

require_once __DIR__ . '/header.php';

?>



<?php if (!$selfCheck['ok']): ?>

<div class="glass rounded-xl p-5 mb-6 border border-amber-500/40 bg-amber-500/5">

    <h2 class="font-semibold text-amber-300 mb-2">Self-check found <?= (int)$selfCheck['failed'] ?> issue(s)</h2>

    <ul class="text-sm space-y-2">

        <?php foreach ($selfCheck['checks'] as $c): if ($c['ok']) continue; ?>

        <li class="text-gray-300">

            <span class="text-amber-400">●</span> <?= e($c['label']) ?> — <?= e($c['detail']) ?>

            <?php if (!empty($c['fix'])): ?><span class="text-gray-500"> · Fix: <code class="text-xs"><?= e($c['fix']) ?></code></span><?php endif; ?>

        </li>

        <?php endforeach; ?>

    </ul>

    <p class="text-xs text-gray-500 mt-3">Full diagnostics: <a href="admin_link_audit.php" class="text-sky-400">Link Audit →</a></p>

</div>

<?php endif; ?>

<div class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100" role="status">
    <p class="font-semibold text-amber-200">Status / merchant API overview — partner PG keys are not pasted here.</p>
    <p class="text-xs text-amber-100/80 mt-1">Paste Razorpay / Cashfree / PayU / Axis keys only in <strong class="text-white">Partner Registry → partner → Keys</strong>. This page shows website health and UniWeb merchant API keys (<code class="text-white">uk_…</code>).</p>
    <p class="mt-3 flex flex-wrap gap-2">
        <a href="admin_gateway_registry.php" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-xs font-medium">Open Partner Registry →</a>
        <a href="gateway_settings.php" class="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:text-white text-xs">Platform Settings (SMTP / cron)</a>
    </p>
</div>

<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">

    <div>

        <h1 class="text-xl font-bold">Platform API guide</h1>
        <p class="text-sm text-gray-400 mt-1">Website status + UniWeb merchant API keys (<code class="text-gray-300">uk_…</code>) — not partner PG keys</p>

    </div>

    <div class="flex flex-wrap gap-2">

        <a href="<?= e(APP_URL) ?>" target="_blank" rel="noopener" class="glass px-4 py-2 rounded-xl text-sm text-sky-400 hover:text-sky-300">Open Website ↗</a>

        <a href="admin_gateway_registry.php" class="btn-primary text-sm px-4 py-2">Partner Registry (PG keys) →</a>

        <a href="gateway_settings.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Platform Settings</a>

        <a href="admin_platform_status.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Platform Status</a>

        <a href="api_docs.php" target="_blank" rel="noopener" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">API Docs ↗</a>

    </div>

</div>



<div class="glass rounded-xl p-6 mb-8 border border-brand-500/30 bg-brand-500/5">

    <h2 class="text-lg font-bold text-brand-300 mb-3">How API Keys Work — Two Layers</h2>

    <div class="grid lg:grid-cols-2 gap-6 text-sm">

        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-5">

            <p class="text-xs font-semibold uppercase tracking-wide text-violet-300 mb-2">Layer 1 — Platform (YOU / Admin)</p>

            <p class="text-gray-400 text-xs leading-relaxed mb-3">Razorpay, Cashfree, PayU give <strong class="text-white">one parent merchant account</strong> to UniWeb. Keys live in <a href="admin_gateway_registry.php" class="text-sky-400">Partner Registry → Partner Detail → Keys</a> (encrypted). Used for checkout, UPI, cards, split settlement.</p>

            <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">

                <li>Received from: PayU / Razorpay / Cashfree after onboarding</li>

                <li>Stored in: encrypted Partner Registry credentials</li>

                <li><strong class="text-amber-400">Never give these to merchants</strong></li>

            </ul>

        </div>

        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-5">

            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-300 mb-2">Layer 2 — Merchant (each shop)</p>

            <p class="text-gray-400 text-xs leading-relaxed mb-3">Each merchant gets a <strong class="text-white">UniWeb API key</strong> (<code class="text-gray-400">uk_…</code>). They call <code class="text-gray-400"><?= e(APP_URL) ?>/api.php</code> — UniWeb routes payment using Layer 1 keys.</p>

            <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">

                <li>Auto-created on signup (<code>uk_</code> live + <code>test_</code> sandbox)</li>

                <li>Merchant sees keys in: API Settings portal</li>

                <li>They integrate <strong class="text-white">with UniWeb</strong>, not Razorpay directly</li>

            </ul>

        </div>

    </div>

    <div class="mt-5 p-4 rounded-xl bg-dark-900/60 border border-gray-800 text-xs text-gray-400 font-mono leading-relaxed">

        Customer → Merchant app/website → <span class="text-emerald-400">POST /api.php</span> (merchant UniWeb key)

        → UniWeb creates payment link → <span class="text-violet-400">Checkout</span> (platform Razorpay/PayU key)

        → Payment success → Merchant wallet → Bank settlement

    </div>

</div>



<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">

    <div class="glass rounded-xl p-4 border border-gray-800">

        <p class="text-xs text-gray-500">Website</p>

        <p class="text-sm font-mono text-sky-400 mt-1 truncate"><?= e(parse_url(APP_URL, PHP_URL_HOST) ?: 'uniweb.co.in') ?></p>

        <p class="text-[10px] text-emerald-400 mt-1">● Live</p>

    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">

        <p class="text-xs text-gray-500">System Health</p>

        <p class="text-2xl font-bold text-emerald-400"><?= (int)$health['pct'] ?>%</p>

    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">

        <p class="text-xs text-gray-500">Active PG</p>

        <p class="text-lg font-semibold text-brand-400 capitalize"><?= e($activePg) ?></p>

    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">

        <p class="text-xs text-gray-500">Merchants with API keys</p>

        <p class="text-2xl font-bold"><?= $webStats['with_api_keys'] ?>/<?= $webStats['merchants'] ?></p>

    </div>

    <div class="glass rounded-xl p-4 border border-gray-800">

        <p class="text-xs text-gray-500">Merchant websites</p>

        <p class="text-2xl font-bold"><?= $webStats['with_website'] ?> <span class="text-xs text-gray-500 font-normal">(<?= $webStats['website_verified'] ?> verified)</span></p>

    </div>

</div>



<div class="glass rounded-xl overflow-hidden mb-8">

    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap justify-between gap-3 items-center">

        <div>

            <h2 class="font-semibold">Platform Payment Gateway Keys (status only)</h2>

            <p class="text-xs text-gray-500 mt-1">Read-only status — paste keys in <a href="admin_gateway_registry.php" class="text-sky-400 hover:underline">Partner Registry → Keys</a>, not on this page</p>

        </div>

        <a href="admin_partner_requests.php" class="text-xs text-sky-400">Partner email drafts →</a>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-sm">

            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">

                <tr>

                    <th class="px-5 py-3 text-left">Gateway</th>

                    <th class="px-5 py-3 text-left">Environment</th>

                    <th class="px-5 py-3 text-left">Key (masked)</th>

                    <th class="px-5 py-3 text-left">Status</th>

                    <th class="px-5 py-3 text-left">Action</th>

                </tr>

            </thead>

            <tbody class="divide-y divide-gray-800">

                <?php foreach ($gatewayKeys as $g): ?>

                <tr<?= uiRowClick('admin_gateway_detail.php?partner=' . urlencode((string)$g['id']) . '&tab=keys') ?>>

                    <td class="px-5 py-3 font-medium">

                        <a href="admin_gateway_detail.php?partner=<?= e($g['id']) ?>&tab=keys" class="text-white hover:text-sky-300"><?= e($g['name']) ?></a>

                    </td>

                    <td class="px-5 py-3 text-xs uppercase text-gray-400"><?= e($g['env']) ?></td>

                    <td class="px-5 py-3 font-mono text-xs text-gray-400"><?= e($g['key_masked']) ?></td>

                    <td class="px-5 py-3">

                        <?php if ($g['configured']): ?>

                        <span class="text-xs text-emerald-400">● Configured</span>

                        <?php else: ?>

                        <span class="text-xs text-amber-400">○ Awaiting keys</span>

                        <?php endif; ?>

                    </td>

                    <td class="px-5 py-3"<?= uiStopClick() ?>>

                        <a href="admin_gateway_detail.php?partner=<?= e($g['id']) ?>&tab=keys" class="text-xs text-brand-400">Partner Keys</a>

                        · <a href="admin_partners.php" class="text-xs text-gray-500">Partners</a>

                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>



<div class="glass rounded-xl overflow-hidden mb-8">

    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap justify-between gap-3 items-center">

        <div>

            <h2 class="font-semibold">Merchant UniWeb API Keys</h2>

            <p class="text-xs text-gray-500 mt-1">Each column opens the right page — code → view, mode/keys → edit, website → merchant site or edit</p>

        </div>

        <input type="search" id="merchant-key-search" placeholder="Search code, name, email…" class="input-field text-sm max-w-xs py-2" autocomplete="off">

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-sm">

            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">

                <tr>

                    <th class="px-5 py-3 text-left">Merchant</th>

                    <th class="px-5 py-3 text-left">Mode</th>

                    <th class="px-5 py-3 text-left">Live API Key</th>

                    <th class="px-5 py-3 text-left">Test API Key</th>

                    <th class="px-5 py-3 text-left">Split / Sub-ID</th>

                    <th class="px-5 py-3 text-left">Website</th>

                    <th class="px-5 py-3 text-left">Actions</th>

                </tr>

            </thead>

            <tbody class="divide-y divide-gray-800">

                <?php if (empty($merchantKeys)): ?>

                <tr><td colspan="7" class="px-5 py-12 text-center text-gray-500">No merchants yet</td></tr>

                <?php else: foreach ($merchantKeys as $m):

                    $mid = (int)$m['id'];

                ?>

                <tr class="hover:bg-white/5 merchant-key-row" data-search="<?= e(strtolower($m['merchant_code'] . ' ' . $m['business_name'] . ' ' . ($m['email'] ?? ''))) ?>">

                    <td class="px-5 py-3">

                        <a href="<?= e(adminMerchantUrl($mid)) ?>" class="font-mono text-sky-400 hover:underline text-xs"><?= e($m['merchant_code']) ?></a>

                        <p class="text-xs text-gray-500 truncate max-w-[180px]">

                            <a href="<?= e(adminMerchantUrl($mid)) ?>" class="hover:text-sky-300"><?= e($m['business_name']) ?></a>

                        </p>

                    </td>

                    <td class="px-5 py-3 text-xs">

                        <a href="<?= e(adminMerchantEditUrl($mid)) ?>" class="hover:underline <?= $m['live'] ? 'text-emerald-400' : 'text-amber-400' ?>">

                            <?= $m['live'] ? 'Live' : 'Test' ?>

                        </a>

                    </td>

                    <td class="px-5 py-3 font-mono text-xs">

                        <?php if ($m['has_live_keys']): ?>

                        <a href="<?= e(adminMerchantApiUrl($mid)) ?>" class="text-brand-400 hover:underline"><?= e($m['api_key_masked']) ?></a>

                        <?php else: ?>

                        <a href="<?= e(adminMerchantApiUrl($mid)) ?>" class="text-gray-600 hover:text-gray-400">— add keys</a>

                        <?php endif; ?>

                    </td>

                    <td class="px-5 py-3 font-mono text-xs">

                        <?php if ($m['has_test_keys']): ?>

                        <a href="<?= e(adminMerchantApiUrl($mid)) ?>" class="text-amber-400/90 hover:underline"><?= e($m['test_api_key_masked']) ?></a>

                        <?php else: ?>

                        <a href="<?= e(adminMerchantApiUrl($mid)) ?>" class="text-gray-600 hover:text-gray-400">—</a>

                        <?php endif; ?>

                    </td>

                    <td class="px-5 py-3 text-[10px] text-gray-500">

                        <a href="<?= e(adminMerchantEditUrl($mid)) ?>" class="hover:text-sky-300">

                            <?= $m['split_ids'] ? e(implode(', ', $m['split_ids'])) : '—' ?>

                        </a>

                    </td>

                    <td class="px-5 py-3 text-xs">

                        <?php if ($m['website_url']): ?>

                        <a href="<?= e($m['website_url']) ?>" target="_blank" rel="noopener" class="<?= $m['website_status'] === 'verified' ? 'text-emerald-400' : 'text-sky-300' ?> hover:underline"><?= e($m['website_status']) ?> ↗</a>

                        <?php else: ?>

                        <a href="<?= e(adminMerchantWebsiteUrl($mid)) ?>" class="text-gray-600 hover:text-gray-400">not set</a>

                        <?php endif; ?>

                    </td>

                    <td class="px-5 py-3 whitespace-nowrap text-xs">

                        <a href="<?= e(adminMerchantUrl($mid)) ?>" class="text-emerald-400 hover:underline">View</a>

                        · <a href="<?= e(adminMerchantEditUrl($mid)) ?>" class="text-brand-400 hover:underline">Edit</a>

                        · <a href="<?= e(adminMerchantRefundsUrl($mid)) ?>" class="text-amber-400 hover:underline">Refunds</a>

                        · <a href="<?= e(adminMerchantTransactionsUrl($mid)) ?>" class="text-gray-400 hover:underline">Txns</a>

                    </td>

                </tr>

                <?php endforeach; endif; ?>

            </tbody>

        </table>

    </div>

</div>



<div class="grid lg:grid-cols-2 gap-6">

    <div class="glass rounded-xl p-6 border border-gray-800">

        <h3 class="font-semibold mb-3">When you get Razorpay / Cashfree keys</h3>

        <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">

            <li>Email partners (Partner Requests page) — ask for production keys</li>

            <li>Paste in <a href="admin_gateway_registry.php" class="text-sky-400">Partner Registry → Partner Detail → Keys</a> → Test connection</li>

            <li>Set environment to <strong class="text-white">live</strong> when approved</li>

            <li>Submit each merchant to gateway (Gateway Submit) for sub-merchant / Route ID</li>

            <li>Paste <code class="text-gray-400">razorpay_linked_account_id</code> etc. in Edit Merchant</li>

        </ol>

    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">

        <h3 class="font-semibold mb-3">What merchants get from you</h3>

        <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">

            <li>Merchant portal login (email + password)</li>

            <li><strong class="text-white">UniWeb API key</strong> from their API Settings page</li>

            <li>Payment links + checkout URLs from dashboard</li>

            <li>Webhooks: they set their URL; UniWeb signs with their secret</li>

            <li>They do <strong class="text-white">not</strong> need Razorpay dashboard access (aggregator model)</li>

        </ol>

        <p class="text-xs text-gray-600 mt-4">API endpoint: <code class="text-brand-400"><?= e(APP_URL) ?>/api.php</code> · Header: <code>X-API-Key</code></p>

    </div>

</div>



<script>
(function(){
    var q = document.getElementById('merchant-key-search');
    if (!q) return;
    q.addEventListener('input', function(){
        var term = (q.value || '').toLowerCase().trim();
        document.querySelectorAll('.merchant-key-row').forEach(function(row){
            var hay = row.getAttribute('data-search') || '';
            row.style.display = (!term || hay.indexOf(term) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

