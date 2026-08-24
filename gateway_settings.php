<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/whatsapp_webhooks.php';
require_once __DIR__ . '/includes/checkout_mode_banner.php';
requireSuperAdmin();
$db = getDB();

if (isset($_GET['test_gateway']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $gw = preg_replace('/[^a-z_]/', '', (string)$_GET['test_gateway']);
    if ($gw === 'axis') {
        $axis = axisTestConnection();
        flash(!empty($axis['token_ok']) ? 'success' : 'error', (string)($axis['message'] ?? 'Axis test finished.'));
    } elseif (in_array($gw, ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'pinelabs', 'rbl'], true)) {
        $result = testGatewayConnection($gw);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } else {
        flash('error', 'Unknown gateway.');
    }
    redirect('gateway_settings.php');
}

if (isset($_GET['test_smtp']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $to = trim((string)($_GET['email'] ?? ''));
    if ($to === '') {
        $to = trim(getSetting('support_email', COMPANY_SUPPORT_EMAIL));
    }
    $result = sendSmtpTestEmail($to);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('gateway_settings.php');
}

if (isset($_GET['test_whatsapp']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $result = testWhatsAppConnection();
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('gateway_settings.php');
}

if (isset($_GET['test_whatsapp_otp']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $phone = trim((string)($_GET['phone'] ?? COMPANY_PHONE));
    $result = testWhatsAppOtpDelivery($phone);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('gateway_settings.php');
}

if (isset($_GET['rotate_cron_key']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $newKey = rotateCronWatchdogKey();
    flash('warning', 'Cron key rotated. Update Hostinger Cron Job URL with the new key below, then test.');
    redirect('gateway_settings.php#cron-security');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    require_once __DIR__ . '/includes/checkout_mode_banner.php';
    $payoutLiveBefore = trim((string)getSetting('payout_live_enabled', '0'));
    $recurringBefore = trim((string)getSetting('recurring_autopay_approved', '0'));
    saveGatewaySettingsPreservingSecrets($_POST['settings'] ?? [], $db);
    $payoutLiveAfter = trim((string)(($_POST['settings']['payout_live_enabled'] ?? '0')));
    if ($payoutLiveAfter === '1' && $payoutLiveBefore !== '1') {
        if (!function_exists('onPayoutRailUnlocked') && is_file(__DIR__ . '/includes/payout_workflow.php')) {
            require_once __DIR__ . '/includes/payout_workflow.php';
        }
        if (function_exists('onPayoutRailUnlocked')) {
            try {
                onPayoutRailUnlocked();
            } catch (Throwable $e) {
                error_log('onPayoutRailUnlocked (gateway_settings): ' . $e->getMessage());
            }
        }
    }
    $recurringAfter = trim((string)(($_POST['settings']['recurring_autopay_approved'] ?? '0')));
    if ($recurringAfter === '1' && $recurringBefore !== '1') {
        if (!function_exists('onRecurringRailUnlocked') && is_file(__DIR__ . '/includes/recurring_workflow.php')) {
            require_once __DIR__ . '/includes/recurring_workflow.php';
        }
        if (function_exists('onRecurringRailUnlocked')) {
            try {
                onRecurringRailUnlocked();
            } catch (Throwable $e) {
                error_log('onRecurringRailUnlocked (gateway_settings): ' . $e->getMessage());
            }
        }
    }
    $cyclePosted = trim((string)(($_POST['settings']['settlement_cycle'] ?? '')));
    if ($cyclePosted !== '' && function_exists('syncSettlementCycleSetting')) {
        syncSettlementCycleSetting($cyclePosted);
    }
    flash('success', 'Settings saved.');
    redirect('gateway_settings.php');
}

$settings = $db->query('SELECT * FROM gateway_settings ORDER BY setting_key')->fetchAll();
$settingsMap = array_column($settings, 'setting_value', 'setting_key');
if (is_file(__DIR__ . '/includes/partner_keys_workflow.php')) {
    require_once __DIR__ . '/includes/partner_keys_workflow.php';
}
$partnerKeysPlane = function_exists('partnerKeysPlaneReport') ? partnerKeysPlaneReport() : null;
if (empty($settingsMap['db_backup_email'])) {
    $settingsMap['db_backup_email'] = 'startelecom620@gmail.com';
}

function settingsSectionColorClass(string $color): string
{
    return match ($color) {
        'sky' => 'text-sky-400 border-sky-500/40',
        'emerald' => 'text-emerald-400 border-emerald-500/40',
        'green' => 'text-green-400 border-green-500/40',
        'amber' => 'text-amber-400 border-amber-500/40',
        'rose' => 'text-rose-400 border-rose-500/40',
        'violet' => 'text-violet-400 border-violet-500/40',
        'teal' => 'text-teal-400 border-teal-500/40',
        'cyan' => 'text-cyan-400 border-cyan-500/40',
        'indigo' => 'text-indigo-400 border-indigo-500/40',
        'fuchsia' => 'text-fuchsia-400 border-fuchsia-500/40',
        'slate' => 'text-slate-300 border-slate-500/40',
        default => 'text-brand-400 border-brand-500/40',
    };
}

function settingsMainHeading(string $title, string $color = 'brand'): string
{
    $c = settingsSectionColorClass($color);
    return '<h2 class="text-center text-2xl font-bold ' . $c . ' mb-4 pb-3 border-b ' . $c . '">' . e($title) . '</h2>';
}

function settingsSectionHeading(string $title, string $color = 'brand', string $size = 'text-lg'): string
{
    $c = settingsSectionColorClass($color);
    return '<h3 class="text-center ' . $size . ' font-bold ' . $c . ' pt-5 pb-3 mb-5 border-t-4 ' . $c . '">' . e($title) . '</h3>';
}

$cronHealth = getCronHealthStatus();
$cronKey = autoAuditWatchdogKey();
$cronKeyMasked = strlen($cronKey) > 12 ? substr($cronKey, 0, 14) . '…' . substr($cronKey, -4) : $cronKey;
$cronFullUrl = (string)($cronHealth['cron_url'] ?? (rtrim(APP_URL, '/') . '/cron_auto_audit.php?key=' . rawurlencode($cronKey)));
$cronWgetCmd = 'wget -q -O /dev/null "' . $cronFullUrl . '"';
$backupUrl = rtrim(APP_URL, '/') . '/cron_db_backup.php?key=' . rawurlencode($cronKey);
$backupWgetCmd = 'wget -q -O /dev/null "' . $backupUrl . '"';
$pageTitle = 'Platform Settings';
require_once __DIR__ . '/header.php';
$activePg = $settingsMap['active_payment_gateway'] ?? 'razorpay';
$gatewayGaps = function_exists('getGatewaySetupGaps') ? getGatewaySetupGaps() : [];
if (!function_exists('methodKeysReadinessReport')) {
    require_once __DIR__ . '/includes/method_keys_workflow.php';
}
$methodKeysReport = methodKeysReadinessReport();
$gatewayCards = [
    ['id' => 'razorpay', 'label' => 'Razorpay', 'test' => true],
    ['id' => 'cashfree', 'label' => 'Cashfree', 'test' => true],
    ['id' => 'payu', 'label' => 'PayU', 'test' => true],
    ['id' => 'phonepe', 'label' => 'PhonePe', 'test' => true, 'checkout' => false, 'note' => 'Keys stored now · checkout enabled in a later release'],
    ['id' => 'pinelabs', 'label' => 'Pine Labs Plural', 'test' => true, 'checkout' => false, 'note' => 'Paste keys when received · sandbox stub only · checkout stays on roadmap'],
    ['id' => 'worldline', 'label' => 'Worldline', 'test' => false, 'checkout' => false, 'note' => 'Paste keys when received · checkout stays on roadmap'],
    ['id' => 'axis', 'label' => 'Axis Bank', 'test' => true],
    ['id' => 'rbl', 'label' => 'RBL Bank', 'test' => true, 'checkout' => false, 'note' => 'Paste sandbox keys · VA + UPI Collection + Payouts'],
    ['id' => 'decentro', 'label' => 'Decentro KYC', 'test' => true],
];
?>
<div class="glass rounded-xl p-5 mb-6 border border-sky-500/40 bg-sky-500/5 max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-sky-300 text-sm">Money & partner keys live in Partner Registry</p>
            <p class="text-xs text-gray-400 mt-1">Paste Razorpay / Cashfree / PayU / Decentro / Axis keys only under <a href="admin_gateway_registry.php" class="text-sky-400 underline">Partner Registry → Partner Detail → Keys</a> (Test first, then Live). Merchants never see partner secrets. This page is platform-wide only: SMTP, cron, email, SEO, WhatsApp — it does not accept live PG API keys.</p>
        </div>
        <a href="admin_gateway_registry.php" class="shrink-0 text-xs px-4 py-2 rounded-lg bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Partner Registry →</a>
    </div>
    <?php if (!empty($partnerKeysPlane['legacy_plaintext'])): ?>
    <div class="mt-3 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-200">
        <p class="font-semibold text-red-300">Legacy plaintext partner keys found in gateway_settings</p>
        <p class="mt-1">Move to Partner Registry (encrypted). Stale rows: <?= e(implode(', ', $partnerKeysPlane['legacy_plaintext'])) ?>. Saving this page or opening Partner Registry will auto-wipe after migration.</p>
        <p class="text-[10px] text-gray-500 mt-1">Pine Labs: use <code class="text-gray-400">pinelabs_merchant_id</code>, <code class="text-gray-400">pinelabs_access_code</code>, <code class="text-gray-400">pinelabs_secure_key</code> — not pinelabs_api_key.</p>
    </div>
    <?php endif; ?>
</div>
<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/25 max-w-4xl text-xs text-gray-400">
    <p class="font-semibold text-emerald-300 text-sm mb-2">Live corridor (soft launch) — do these before advertise</p>
    <ul class="space-y-1 list-disc list-inside">
        <li>CR-01 on live Hostinger <code class="text-gray-500">config.php</code> (notifications) — never overwrite DB password / encryption key.</li>
        <li>Below: <strong class="text-gray-300">Apply pending migrations</strong> → <code class="text-sky-300">ok: true</code> (incl. <strong class="text-gray-300">072</strong> method key normalize).</li>
        <li>Partner Test keys + Test Connection → merchant Test Mode → <strong class="text-gray-300">Instant Test Pay</strong> once.</li>
        <li>SMTP section + backup notify email on this page.</li>
    </ul>
</div>
<div class="glass rounded-xl p-4 mb-6 border border-violet-500/30 max-w-4xl text-xs">
    <p class="font-semibold text-violet-300 text-sm mb-2">Method key aliases (Audit #10 · migration 072)</p>
    <p class="text-gray-400 mb-3"><?= e($methodKeysReport['message']) ?></p>
    <div class="grid sm:grid-cols-2 gap-3">
        <div class="rounded-lg border border-emerald-500/25 bg-emerald-950/20 p-3">
            <p class="text-[10px] uppercase text-emerald-400 mb-2">Canonical examples</p>
            <?php foreach ($methodKeysReport['examples'] as $ex): ?>
            <p class="font-mono text-[11px] text-gray-400 mb-1">
                <span class="text-amber-300"><?= e($ex['alias']) ?></span>
                → <span class="text-emerald-300"><?= e($ex['canonical']) ?></span>
                <?php if ($ex['registry'] !== $ex['canonical']): ?>
                <span class="text-gray-600"> (registry: <?= e($ex['registry']) ?>)</span>
                <?php endif; ?>
            </p>
            <?php endforeach; ?>
        </div>
        <div class="rounded-lg border border-slate-600/40 bg-slate-900/30 p-3">
            <p class="text-[10px] uppercase text-slate-400 mb-2">Single writers (always normalize)</p>
            <ul class="text-[11px] text-gray-500 space-y-1 list-disc list-inside">
                <?php foreach ($methodKeysReport['writers'] as $fn): ?>
                <li><code class="text-gray-400"><?= e($fn) ?>()</code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php if (!empty($gatewayGaps)): ?>
<div class="glass rounded-xl p-5 mb-6 border border-amber-500/40 bg-amber-500/5 max-w-4xl">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <p class="font-semibold text-amber-300">Live launch — <?= count($gatewayGaps) ?> gateway(s) need keys</p>
        <a href="admin_gateway_registry.php" class="text-xs text-sky-400">Partner Registry → Keys</a>
    </div>
    <div class="grid sm:grid-cols-2 gap-2 text-xs">
        <?php foreach ($gatewayGaps as $gap): ?>
        <div class="rounded-lg border border-gray-800 px-3 py-2">
            <span class="text-amber-400">● <?= e($gap['label']) ?></span>
            <span class="text-gray-500 block mt-0.5"><?= e($gap['status']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="text-[10px] text-gray-600 mt-3">When partner production keys arrive: add them in <a href="admin_gateway_registry.php" class="text-sky-400">Partner Registry → Partner Detail → Keys</a>, then use Test Connection above. This page does not accept live PG API keys.</p>
</div>
<?php else: ?>
<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/30 bg-emerald-500/5 max-w-4xl">
    <p class="text-sm text-emerald-300">● Critical payment gateways configured — ready for live checkout tests.</p>
</div>
<?php endif; ?>
<div class="max-w-4xl space-y-6">
<div id="cron-security" class="glass rounded-xl p-6 border <?= !empty($cronHealth['live']) ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-amber-500/40 bg-amber-500/5' ?>">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h2 class="font-semibold <?= !empty($cronHealth['live']) ? 'text-emerald-300' : 'text-amber-300' ?>">
                <?= !empty($cronHealth['live']) ? '● Cron 24/7 — Running' : '○ Cron — needs Hostinger job' ?>
            </h2>
            <p class="text-xs text-gray-500 mt-1"><?= e(cronHealthLabel($cronHealth)) ?></p>
            <?php if (!empty($cronHealth['last_cron_at'])): ?>
            <p class="text-[10px] text-gray-600 mt-1">Last server cron: <?= e((string)$cronHealth['last_cron_at']) ?> · <?= (int)($cronHealth['runs_24h'] ?? 0) ?> runs in 24h</p>
            <?php endif; ?>
        </div>
        <a href="admin_watchdog.php?tab=auto" class="text-xs text-sky-400">Watchdog → Auto Audit</a>
    </div>
    <div class="space-y-3 text-xs">
        <p class="text-gray-300">This cron key is <strong class="text-white">made by UniWeb</strong>. You do not get it from a bank, PayU, Razorpay, or email. Partner API keys are a different thing (Partner Registry).</p>
        <div class="rounded-lg border border-gray-800 bg-dark-900/40 p-3 space-y-1.5 text-gray-400">
            <p class="text-gray-200 font-medium">What Hostinger needs</p>
            <p><span class="text-emerald-400">Required — 1 job:</span> every 10 minutes → Watchdog + KYC + settlements + recurring + partner queue (all inside this one URL).</p>
            <p><span class="text-sky-400">Not a cron:</span> database updates → use <strong class="text-gray-300">Apply pending migrations</strong> below (one click after each deploy).</p>
            <p><span class="text-amber-400">Deploy code:</span> Hostinger → Git → <strong class="text-gray-300">Pull</strong> on the live branch after each merge. Do not rely on SFTP/FTP upload alone — cron and PHP only update when the server repo updates.</p>
            <p><span class="text-gray-500">Optional:</span> daily backup job (2:00 AM). Bank statement fetch only if you use bank files.</p>
        </div>
        <div>
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Masked URL (safe to screenshot)</p>
            <code class="block text-sky-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e(function_exists('maskCronUrl') ? maskCronUrl($cronFullUrl) : $cronFullUrl) ?></code>
        </div>
        <details class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3">
            <summary class="cursor-pointer text-amber-300 font-medium">Show full Hostinger command (copy this)</summary>
            <p class="text-gray-500 mt-2 mb-2">Hostinger → Advanced → Cron Jobs → create job → paste command. Schedule: every 10 minutes.</p>
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Command</p>
            <code id="hostinger-cron-cmd" class="block text-emerald-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e($cronWgetCmd) ?></code>
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mt-3 mb-1">Or paste this URL in Hostinger “URL to fetch”</p>
            <code id="hostinger-cron-url" class="block text-sky-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e($cronFullUrl) ?></code>
            <div class="flex flex-wrap gap-2 mt-3">
                <button type="button" class="text-xs px-3 py-2 rounded-lg border border-emerald-500/40 text-emerald-300" onclick="navigator.clipboard.writeText(document.getElementById('hostinger-cron-cmd').innerText).then(function(){this.textContent='Copied';}.bind(this))">Copy command</button>
                <button type="button" class="text-xs px-3 py-2 rounded-lg border border-sky-500/40 text-sky-300" onclick="navigator.clipboard.writeText(document.getElementById('hostinger-cron-url').innerText).then(function(){this.textContent='Copied';}.bind(this))">Copy URL</button>
            </div>
            <details class="mt-4 rounded-lg border border-violet-500/30 bg-violet-500/5 p-3">
                <summary class="cursor-pointer text-violet-300 font-medium">Backup cron — daily 2:00 AM (copy this)</summary>
                <p class="text-gray-500 mt-2 mb-2">This is a <strong class="text-gray-300">second</strong> Hostinger job. Do not replace the 10-minute job. Schedule: once a day at 02:00.</p>
                <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Command</p>
                <code id="hostinger-backup-cmd" class="block text-violet-300 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e($backupWgetCmd) ?></code>
                <p class="text-[10px] text-gray-600 uppercase tracking-wide mt-3 mb-1">Or paste this URL in Hostinger “URL to fetch”</p>
                <code id="hostinger-backup-url" class="block text-sky-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e($backupUrl) ?></code>
                <div class="flex flex-wrap gap-2 mt-3">
                    <button type="button" class="text-xs px-3 py-2 rounded-lg border border-violet-500/40 text-violet-300" onclick="navigator.clipboard.writeText(document.getElementById('hostinger-backup-cmd').innerText).then(function(){this.textContent='Copied';}.bind(this))">Copy backup command</button>
                    <button type="button" class="text-xs px-3 py-2 rounded-lg border border-sky-500/40 text-sky-300" onclick="navigator.clipboard.writeText(document.getElementById('hostinger-backup-url').innerText).then(function(){this.textContent='Copied';}.bind(this))">Copy backup URL</button>
                </div>
                <p class="text-gray-500 mt-3">Keeps last 7 daily copies of the <strong class="text-gray-300">database</strong> and emails that copy to Backup notify email. Full website restore is Hostinger → Files → Backups — Gmail cannot hold the whole site.</p>
            </details>
        </details>
        <p class="text-gray-500">Security: URL requires secret key · wrong key = 403 · failed attempts logged in Error Log.</p>
        <p class="text-gray-500">Cron key (masked): <span class="font-mono text-gray-400"><?= e($cronKeyMasked) ?></span></p>
        <div class="flex flex-wrap gap-2 pt-2">
            <form method="POST" action="cron_auto_audit.php" target="_blank" rel="noopener" class="inline">
                <input type="hidden" name="key" value="<?= e($cronKey) ?>">
                <input type="hidden" name="verbose" value="1">
                <button type="submit" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Test cron now ↗</button>
            </form>
            <form method="POST" action="migrate_release.php" target="_blank" rel="noopener" class="inline">
                <input type="hidden" name="key" value="<?= e($cronKey) ?>">
                <button type="submit" class="text-xs px-3 py-2 rounded-lg border border-sky-700/60 text-sky-300 hover:text-white" title="Applies pending migrations/*.sql using this same cron key. Safe to re-run.">Apply pending migrations ↗</button>
            </form>
            <a href="?rotate_cron_key=1&csrf=<?= e(csrfToken()) ?>" class="text-xs px-3 py-2 rounded-lg border border-amber-500/40 text-amber-400 hover:bg-amber-500/10" onclick="return confirm('Rotate cron key? You must update Hostinger cron URL after this.')">Rotate key</a>
        </div>
        <p class="text-[11px] text-gray-500">After each website update: click <strong class="text-gray-400 font-medium">Apply pending migrations</strong> once. Expect JSON <code class="text-sky-400">ok: true</code>. Includes schema such as <strong class="text-gray-400">062</strong> (PII column width) and <strong class="text-gray-400">063</strong> (payment link Fixed/Open). Do not drop the database. Then open <a href="admin_encrypt_pii.php" class="text-sky-400 hover:underline">Encrypt PII Backfill</a> if plaintext remains.</p>
        <div class="pt-4 mt-2 border-t border-gray-800">
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Already included in the 10-minute job — extra Hostinger jobs not required</p>
            <p class="text-gray-500">Watchdog, auto KYC, settlements, recurring mandates, partner forward, payout queue.</p>
        </div>
    </div>
</div>
<div class="glass rounded-xl p-6">
    <?= settingsMainHeading('Gateway Status') ?>
    <p class="text-xs text-gray-500 mb-4">Test connection for gateways configured in <a href="admin_gateway_registry.php" class="text-sky-400">Partner Registry</a>. Default checkout gateway (new merchants only): <span class="text-brand-400 font-medium"><?= e(ucfirst($activePg)) ?></span></p>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach ($gatewayCards as $card):
            $configured = isGatewayConfigured($card['id']);
            $isActive = $card['id'] === $activePg;
            $checkoutReady = ($card['checkout'] ?? true) !== false;
            $cardClass = !$checkoutReady
                ? 'border-amber-500/30 bg-amber-500/5'
                : ($configured ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-gray-800 bg-dark-900/40');
            $statusClass = !$checkoutReady ? 'text-amber-400' : ($configured ? 'text-emerald-400' : 'text-gray-500');
        ?>
        <div class="rounded-xl border p-4 <?= $cardClass ?>">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="font-medium text-sm">
                        <?= e($card['label']) ?>
                        <?php if (!$checkoutReady): ?><span class="ml-1 align-middle text-[9px] uppercase tracking-wide text-amber-400 border border-amber-500/40 rounded px-1 py-0.5">Roadmap</span><?php endif; ?>
                    </p>
                    <p class="text-xs mt-1 <?= $statusClass ?>"><?= e(gatewayStatusLabel($card['id'])) ?></p>
                    <?php if (!$checkoutReady && !empty($card['note'])): ?>
                    <p class="text-[10px] text-gray-500 mt-1"><?= e($card['note']) ?></p>
                    <?php elseif ($isActive && $card['id'] !== 'decentro' && $card['id'] !== 'axis'): ?>
                    <p class="text-[10px] text-brand-400 mt-1 uppercase tracking-wide">New-merchant template</p>
                    <?php endif; ?>
                </div>
                <?php if ($card['test']): ?>
                <a href="gateway_settings.php?test_gateway=<?= e($card['id']) ?>&csrf=<?= e(csrfToken()) ?>" class="shrink-0 px-3 py-2 rounded-lg text-xs font-medium bg-brand-600/20 text-brand-400 hover:bg-brand-600/30">Test Connection</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="glass rounded-xl p-6">
    <?= settingsMainHeading('Platform Settings') ?>
    <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <?php
        $fields = [
            ['platform_name', 'Platform Name', 'text'],
            ['support_email', 'Support Email', 'email'],
            ['db_backup_email', 'Backup notify email (database copy)', 'email'],
            ['support_phone', 'Support Phone', 'text'],
            ['support_whatsapp', 'Support WhatsApp Number (with country code)', 'text'],
            ['min_settlement_amount', 'Min Settlement (₹)', 'number'],
            // settlement_cycle rendered as select below
            ['upi_mdr', 'UPI MDR (%) — platform default (overridden by Partner Detail → Commercial)', 'number'],
            ['card_mdr', 'Card MDR (%) — platform default (overridden by Partner Detail → Commercial)', 'number'],
            ['netbanking_mdr', 'Netbanking MDR (%) — platform default (overridden by Partner Detail → Commercial)', 'number'],
            ['wallet_mdr', 'Wallet MDR (%) — platform default (overridden by Partner Detail → Commercial)', 'number'],
            ['default_commission', 'Default UniWeb commission (%) — on successful txns; overridden by Partner Detail / merchant schedule', 'number'],
            ['aml_high_value_threshold', 'AML High Value Threshold (₹)', 'number'],
            ['maintenance_mode', 'Maintenance Mode (0/1)', 'number'],
            ['auto_audit_interval_minutes', 'Auto-audit interval (minutes, 5–120)', 'number'],
        ];
        foreach ($fields as [$key, $label, $type]):
            $attrs = gatewaySettingFieldAttrs($key, $settingsMap, $type);
        ?>
        <div><label class="text-sm text-gray-400"><?= $label ?></label>
            <input type="<?= e($attrs['type']) ?>" name="settings[<?= $key ?>]" value="<?= e($attrs['value']) ?>" placeholder="<?= e($attrs['placeholder']) ?>" class="input-field mt-1" <?= $attrs['autocomplete'] ? 'autocomplete="' . e($attrs['autocomplete']) . '"' : '' ?> <?= $type==='number' ? 'step="0.01"' : '' ?>>
        </div>
        <?php endforeach; ?>
        <?php
        $cycleOpts = function_exists('getSettlementCycleOptions') ? getSettlementCycleOptions() : ['T+0' => ['label' => 'T+0'], 'T+1' => ['label' => 'T+1'], 'T+2' => ['label' => 'T+2']];
        $cycleVal = strtoupper(trim((string)($settingsMap['settlement_cycle'] ?? 'T+1')));
        if (!isset($cycleOpts[$cycleVal])) {
            $cycleVal = 'T+1';
        }
        ?>
        <div>
            <label class="text-sm text-gray-400">Settlement Cycle (T+0 / T+1 / T+2)</label>
            <select name="settings[settlement_cycle]" class="input-field mt-1">
                <?php foreach ($cycleOpts as $code => $meta): ?>
                <option value="<?= e($code) ?>" <?= $cycleVal === $code ? 'selected' : '' ?>><?= e($meta['label'] ?? $code) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="text-[11px] text-gray-600 mt-1">Binds to Settlement Engine batch timing. Prefer editing under <a href="admin_settlement_settings.php" class="text-sky-400 hover:underline">Settlement Engine</a>. Owner default: T+1.</p>
        </div>
        <?= settingsSectionHeading('SMTP Email Settings', 'amber') ?>
        <p class="text-xs text-gray-500">Leave SMTP host empty to use PHP mail(). For Hostinger use smtp.hostinger.com</p>
        <?php foreach ([
            ['smtp_host','SMTP Host','text'],['smtp_port','SMTP Port','number'],
            ['smtp_user','SMTP Username','text'],['smtp_pass','SMTP Password','password'],
            ['smtp_from_email','From Email','email'],['smtp_from_name','From Name','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <?= settingsSectionHeading('Support & Social Channels', 'fuchsia') ?>
        <p class="text-xs text-gray-500">Links shown to merchants on Support → Connect with Admin.</p>
        <?php foreach ([
            ['support_instagram', 'Instagram URL', 'text'],
            ['support_telegram', 'Telegram URL', 'text'],
            ['support_facebook', 'Facebook URL', 'text'],
            ['support_twitter', 'Twitter / X URL', 'text'],
            ['support_linkedin', 'LinkedIn URL', 'text'],
            ['support_youtube', 'YouTube URL', 'text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <?= settingsSectionHeading('B2B Collection Engine (template for new merchants)', 'teal') ?>
        <p class="text-xs text-gray-500 mb-3">Defaults for <strong>new merchants only</strong>. UniWeb revenue is <strong>commission on successful collections</strong> — not a sold white-label package. Per-partner MDR and margin live in Partner Detail → Commercial. PayU Split / Razorpay Route / Cashfree Easy Split stay <strong class="text-gray-300">parked</strong> (not offered as templates).</p>
        <div><label class="text-sm text-gray-400">Default Collection Mode (new merchants)</label>
            <select name="settings[default_collection_mode]" class="input-field mt-1">
                <?php
                $adminModeCurrent = (string)($settingsMap['default_collection_mode'] ?? 'direct_upi');
                $adminModes = function_exists('getAdminTemplateCollectionModes')
                    ? getAdminTemplateCollectionModes($adminModeCurrent)
                    : getCollectionModes();
                foreach ($adminModes as $k => $label):
                ?>
                <option value="<?= e($k) ?>" <?= $adminModeCurrent === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="text-[11px] text-gray-600 mt-1">Live choices: Direct UPI, Platform PG, Axis VA. Route/Split SDK is not live.</p>
        </div>
        <div><label class="text-sm text-gray-400">Platform margin (%) — UniWeb commission default</label>
            <input type="number" step="0.01" name="settings[platform_margin_pct]" value="<?= e($settingsMap['platform_margin_pct'] ?? '0.10') ?>" class="input-field mt-1" title="Default UniWeb cut on successful collections">
            <p class="text-[11px] text-gray-600 mt-1">Used when a merchant has no custom schedule. Partner Detail MDR still overrides method pricing.</p>
        </div>
        <?= settingsSectionHeading('Payment Gateway Selection (template for new merchants)', 'slate') ?>
        <p class="text-xs text-gray-500 mb-3">This sets the default checkout gateway for <strong>new merchants only</strong>. Per-merchant gateway routing and live API keys are managed in <a href="admin_gateway_registry.php" class="text-sky-400">Partner Registry → Partner Detail → Keys</a>.</p>
        <div><label class="text-sm text-gray-400">Default Payment Gateway (new merchants)</label>
            <select name="settings[active_payment_gateway]" class="input-field mt-1">
                <?php foreach (['razorpay'=>'Razorpay','cashfree'=>'Cashfree','payu'=>'PayU','manual'=>'Manual UPI Only'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= ($settingsMap['active_payment_gateway'] ?? 'razorpay') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rounded-xl border border-sky-500/30 bg-sky-500/5 p-4 my-4 text-sm text-gray-400 space-y-2">
            <p class="font-medium text-sky-300">Partner keys, methods &amp; MDR → Partner Registry</p>
            <p>Per-partner credentials and <strong class="text-gray-300">commission math (Partner MDR + UniWeb margin)</strong> are managed in the <a href="admin_gateway_registry.php" class="text-sky-400 underline">Partner Registry</a> → Configure → Commercial. This page does not sell or configure a white-label product.</p>
            <p class="text-xs text-gray-500">This page does NOT accept live PG API keys. Platform-wide settings (SMTP, WhatsApp, SEO, cron) remain here. Method partner webhook URL is configured in Partner Detail → Webhooks tab.</p>
            <p class="text-xs text-gray-600">Method partner webhook endpoint: <code class="text-gray-400"><?= e(rtrim(APP_URL, '/')) ?>/method_partner_webhook.php</code></p>
        </div>
        <?php
        if (!function_exists('getRecurringReadinessChecklist') && is_file(__DIR__ . '/includes/mandates.php')) {
            require_once __DIR__ . '/includes/mandates.php';
        }
        if (!function_exists('getRouteSplitReadinessChecklist') && is_file(__DIR__ . '/includes/split_settlement.php')) {
            require_once __DIR__ . '/includes/split_settlement.php';
        }
        if (!function_exists('routeSplitReadinessReport')) {
            require_once __DIR__ . '/includes/route_split_workflow.php';
        }
        if (!function_exists('cloudModulesAutoKycReadinessReport')) {
            require_once __DIR__ . '/includes/cloud_modules_workflow.php';
        }
        if (!function_exists('registryKindReadinessReport')) {
            require_once __DIR__ . '/includes/registry_kind_workflow.php';
        }
        if (!function_exists('gatewaySubmissionsReadinessReport')) {
            require_once __DIR__ . '/includes/gateway_submissions_workflow.php';
        }
        if (!function_exists('holdWindowReadinessReport')) {
            require_once __DIR__ . '/includes/hold_window_workflow.php';
        }
        if (!function_exists('autoKycRiskReadinessReport')) {
            require_once __DIR__ . '/includes/auto_kyc_risk_workflow.php';
        }
        if (!function_exists('getPhase11RouteDecisionLog') && is_file(__DIR__ . '/includes/smart_routing.php')) {
            require_once __DIR__ . '/includes/smart_routing.php';
        }
        $recurringReady = function_exists('getRecurringReadinessChecklist') ? getRecurringReadinessChecklist() : ['items' => [], 'done' => 0, 'total' => 0, 'ready' => false];
        $routeSplitReady = function_exists('getRouteSplitReadinessChecklist') ? getRouteSplitReadinessChecklist() : ['items' => [], 'done' => 0, 'total' => 0, 'ready' => false, 'phase' => 'parked'];
        $routeSplitReport = routeSplitReadinessReport();
        $autoKycCloudReport = cloudModulesAutoKycReadinessReport();
        $registryKindReport = registryKindReadinessReport();
        $gatewaySubmissionsReport = gatewaySubmissionsReadinessReport();
        $holdWindowReport = holdWindowReadinessReport();
        $autoKycRiskReport = autoKycRiskReadinessReport();
        $payoutLiveOn = ($settingsMap['payout_live_enabled'] ?? '0') === '1';
        $recurringOn = ($settingsMap['recurring_autopay_approved'] ?? '0') === '1';
        $routeSplitOn = ($settingsMap['route_split_live_enabled'] ?? '0') === '1';
        $phase11RouteLog = function_exists('getPhase11RouteDecisionLog') ? getPhase11RouteDecisionLog(10) : [];
        ?>
        <div id="live-money-switches" class="rounded-xl border border-violet-500/40 bg-violet-500/5 p-5 my-4 space-y-4">
            <?= settingsSectionHeading('Live Money Switches', 'violet', 'text-base') ?>
            <p class="text-xs text-gray-500 -mt-3">Same pattern as Razorpay / Cashfree: paste partner keys in Registry first, then turn ON after compliance review. Default OFF — no live money until you enable.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="rounded-lg border border-gray-800 bg-dark-900/40 p-4">
                    <label class="text-sm text-gray-300 font-medium">Payout live money (RazorpayX / Cashfree Payouts)</label>
                    <select name="settings[payout_live_enabled]" class="input-field mt-2">
                        <option value="0" <?= !$payoutLiveOn ? 'selected' : '' ?>>OFF — scaffold / test UTR only</option>
                        <option value="1" <?= $payoutLiveOn ? 'selected' : '' ?>>ON — real bank transfers (needs Registry keys)</option>
                    </select>
                    <p class="text-[11px] text-gray-600 mt-2">Requires partner payout keys + merchant checker approval.</p>
                </div>
                <div class="rounded-lg border border-gray-800 bg-dark-900/40 p-4">
                    <label class="text-sm text-gray-300 font-medium">Recurring / AutoPay (UPI Autopay + eNACH)</label>
                    <select name="settings[recurring_autopay_approved]" class="input-field mt-2">
                        <option value="0" <?= !$recurringOn ? 'selected' : '' ?>>OFF — merchants see gated message</option>
                        <option value="1" <?= $recurringOn ? 'selected' : '' ?>>ON — mandate registration + live debits allowed</option>
                    </select>
                    <p class="text-[11px] text-gray-600 mt-2">Create mandate → customer UPI approval → cron debits.</p>
                </div>
                <div class="rounded-lg border border-gray-800 bg-dark-900/40 p-4 sm:col-span-2 lg:col-span-1">
                    <label class="text-sm text-gray-300 font-medium">Phase 11 Route / Smart partner routing</label>
                    <select name="settings[route_split_live_enabled]" class="input-field mt-2">
                        <option value="0" <?= !$routeSplitOn ? 'selected' : '' ?>>OFF — parked · fixed partner checkout (default)</option>
                        <option value="1" <?= $routeSplitOn ? 'selected' : '' ?>>ON — smart routing + Route/Split APIs when partner config live</option>
                    </select>
                    <p class="text-[11px] text-gray-600 mt-2">Default OFF — zero effect on live payments. When ON: health + priority partner pick at checkout; capture split when partner route_status=live. One click — no redeploy.</p>
                </div>
            </div>
            <?php if (!empty($routeSplitReady['items'])): ?>
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs">
                <p class="font-medium text-amber-300 mb-1">Phase 11 — <?= !empty($routeSplitReport['parked']) ? 'PARKED (default)' : 'Owner switch ON' ?></p>
                <p class="text-gray-500 mb-2"><?= e($routeSplitReport['disclaimer'] ?? '') ?></p>
                <p class="font-medium text-amber-300 mb-2">Route / Split readiness — <?= (int)$routeSplitReady['done'] ?>/<?= (int)$routeSplitReady['total'] ?> · Phase: <?= e($routeSplitReady['phase'] ?? 'parked') ?></p>
                <p class="text-gray-500 mb-2"><?= e($routeSplitReport['message'] ?? '') ?></p>
                <ul class="space-y-1">
                    <?php foreach ($routeSplitReady['items'] as $item): ?>
                    <li class="<?= !empty($item['ok']) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= !empty($item['ok']) ? '●' : '○' ?> <?= e($item['label']) ?>
                        <?php if (!empty($item['action']) && empty($item['ok'])): ?>
                        · <a href="<?= e($item['action']) ?>" class="text-sky-400 underline">Open</a>
                        <?php endif; ?>
                        <?php if (!empty($item['note'])): ?>
                        <span class="block text-gray-600 text-[10px] mt-0.5"><?= e($item['note']) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if ($routeSplitOn || !empty($phase11RouteLog)): ?>
            <div class="rounded-lg border border-gray-800 bg-dark-900/40 p-3 text-xs">
                <p class="font-medium text-gray-300 mb-2">Phase 11 routing log (honest partner choice)</p>
                <?php if (!$routeSplitOn): ?>
                <p class="text-amber-300/90 mb-2">Parked — turn ON when 2+ partners live. No routing while OFF.</p>
                <?php endif; ?>
                <?php if (empty($phase11RouteLog)): ?>
                <p class="text-gray-600">No routing decisions yet. When ON, checkout logs which partner was chosen and why.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-[10px] text-left">
                        <thead><tr class="text-gray-500 border-b border-gray-800"><th class="py-1 pr-2">Time</th><th class="py-1 pr-2">Partner</th><th class="py-1 pr-2">Outcome</th><th class="py-1">Reason</th></tr></thead>
                        <tbody>
                        <?php foreach ($phase11RouteLog as $logRow): ?>
                        <tr class="border-b border-gray-900/80">
                            <td class="py-1 pr-2 text-gray-500 whitespace-nowrap"><?= e(substr((string)($logRow['created_at'] ?? ''), 0, 16)) ?></td>
                            <td class="py-1 pr-2 text-sky-300"><?= e((string)($logRow['chosen_partner'] ?? '—')) ?></td>
                            <td class="py-1 pr-2"><?= e((string)($logRow['outcome'] ?? '')) ?></td>
                            <td class="py-1 text-gray-400"><?= e((string)($logRow['reason'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($recurringReady['items'])): ?>
            <div class="rounded-lg border border-gray-800 p-3 text-xs">
                <p class="font-medium text-violet-300 mb-2">Recurring readiness — <?= (int)$recurringReady['done'] ?>/<?= (int)$recurringReady['total'] ?> <?= !empty($recurringReady['ready']) ? '· Ready for live' : '' ?></p>
                <ul class="space-y-1">
                    <?php foreach ($recurringReady['items'] as $item): ?>
                    <li class="<?= !empty($item['ok']) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= !empty($item['ok']) ? '●' : '○' ?> <?= e($item['label']) ?>
                        <?php if (!empty($item['action']) && empty($item['ok'])): ?>
                        · <a href="<?= e($item['action']) ?>" class="text-sky-400 underline"><?= e(basename((string)$item['action'])) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($item['note'])): ?>
                        <span class="block text-gray-600 font-mono text-[10px] mt-0.5"><?= e($item['note']) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if (!empty($autoKycCloudReport['checks'])): ?>
            <div class="rounded-lg border border-sky-500/30 bg-sky-500/5 p-3 text-xs">
                <p class="font-medium text-sky-300 mb-1">Auto KYC — local laptop + cron (not cloud agents)</p>
                <p class="text-gray-500 mb-2"><?= e($autoKycCloudReport['policy'] ?? '') ?></p>
                <p class="<?= !empty($autoKycCloudReport['ok']) ? 'text-emerald-400' : 'text-amber-400' ?> mb-2"><?= e($autoKycCloudReport['message'] ?? '') ?></p>
                <ul class="space-y-1">
                    <?php
                    $autoKycLabels = [
                        'work_policy_local' => 'Work policy: local laptop only',
                        'cloud_bridge_file' => 'cloud_modules.php bridge file',
                        'auto_kyc_in_bridge' => 'auto_kyc.php listed in bridge',
                        'forward_queue_in_bridge' => 'partner_forward_queue.php in bridge',
                        'auto_kyc_file' => 'includes/auto_kyc.php present',
                        'kyc_workflow_file' => 'kyc_workflow.php present',
                        'cron_script' => 'cron_auto_kyc.php present',
                        'engine_loadable' => 'runAutoKycEngine loadable',
                    ];
                    foreach ($autoKycCloudReport['checks'] as $ck => $ok):
                        $lbl = $autoKycLabels[$ck] ?? $ck;
                    ?>
                    <li class="<?= !empty($ok) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= !empty($ok) ? '●' : '○' ?> <?= e($lbl) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-gray-600 mt-2"><a href="admin_auto_kyc.php" class="text-sky-400 underline">Auto KYC Engine</a> · Schedule Hostinger cron every 10 min</p>
            </div>
            <?php endif; ?>
            <?php if (!empty($registryKindReport['checks'])): ?>
            <div class="rounded-lg border border-violet-500/30 bg-violet-500/5 p-3 text-xs">
                <p class="font-medium text-violet-300 mb-1">Registry kind — methods vs partners (migration 066)</p>
                <p class="text-gray-500 mb-2"><?= e(registryKindDisclaimer()) ?></p>
                <p class="<?= !empty($registryKindReport['ok']) ? 'text-emerald-400' : 'text-amber-400' ?> mb-2"><?= e($registryKindReport['message'] ?? '') ?></p>
                <ul class="space-y-1">
                    <?php
                    $rkLabels = [
                        'migration_column' => 'registry_kind column (066)',
                        'method_keys_defined' => 'paymentMethodRegistryKeys()',
                        'partner_registry_defined' => 'getPartnerRegistry()',
                        'partner_list_filter' => 'Partner Registry filter (partners only)',
                        'backfill_helper' => 'backfillGatewayRegistryKinds()',
                        'kind_clause_helper' => 'gatewayRegistryKindClause()',
                        'no_overlap' => 'No method/partner key overlap',
                    ];
                    foreach ($registryKindReport['checks'] as $ck => $ok):
                        $lbl = $rkLabels[$ck] ?? $ck;
                    ?>
                    <li class="<?= !empty($ok) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= !empty($ok) ? '●' : '○' ?> <?= e($lbl) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-gray-600 mt-2"><a href="admin_gateway_registry.php" class="text-sky-400 underline">Partner Registry</a> · <?= (int)($registryKindReport['partner_count'] ?? 0) ?> partners · <?= count($registryKindReport['method_keys'] ?? []) ?> method rails</p>
            </div>
            <?php endif; ?>
            <?php if (!empty($gatewaySubmissionsReport['checks'])): ?>
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/5 p-3 text-xs">
                <p class="font-medium text-rose-300 mb-1">Gateway submissions — VARCHAR partners (migration 067)</p>
                <p class="text-gray-500 mb-2"><?= e(gatewaySubmissionsDisclaimer()) ?></p>
                <p class="<?= !empty($gatewaySubmissionsReport['ok']) ? 'text-emerald-400' : 'text-amber-400' ?> mb-2"><?= e($gatewaySubmissionsReport['message'] ?? '') ?></p>
                <p class="text-gray-600"><a href="admin_gateway_submit.php" class="text-sky-400 underline">Multi-Gateway Forward</a> · <?= count($gatewaySubmissionsReport['allowed_keys'] ?? []) ?> submission partners</p>
            </div>
            <?php endif; ?>
            <?php if (!empty($holdWindowReport['checks'])): ?>
            <div class="rounded-lg border border-cyan-500/30 bg-cyan-500/5 p-3 text-xs">
                <p class="font-medium text-cyan-300 mb-1">KYC hold window — code <?= e(holdWindowCodeMorningTime()) ?> IST vs docs <?= e(holdWindowDocReferenceTime()) ?></p>
                <p class="text-gray-500 mb-2"><?= e($holdWindowReport['message'] ?? '') ?></p>
                <?php if (!empty($holdWindowReport['next_sample'])): ?>
                <p class="text-gray-600"><?= e($holdWindowReport['next_sample']) ?></p>
                <?php endif; ?>
                <p class="text-gray-600 mt-2"><a href="admin_forward_queue.php" class="text-sky-400 underline">KYC Forward Queue</a></p>
            </div>
            <?php endif; ?>
            <?php if (!empty($autoKycRiskReport['checks'])): ?>
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3 text-xs">
                <p class="font-medium text-emerald-300 mb-1">Auto-KYC risk — fail-closed + manual assist</p>
                <p class="text-gray-500 mb-2"><?= e($autoKycRiskReport['message'] ?? '') ?></p>
                <p class="text-gray-600">Threshold: <?= (int)($autoKycRiskReport['threshold'] ?? 3) ?> verify_failed · <a href="admin_auto_kyc.php" class="text-sky-400 underline">Auto KYC Engine</a> · <a href="admin_kyc.php" class="text-sky-400 underline">Manual KYC Review</a></p>
            </div>
            <?php endif; ?>
        </div>
        <?= settingsSectionHeading('SEO — Google Search Console', 'emerald') ?>
        <p class="text-xs text-gray-500 mb-2">Paste the HTML-tag verification token from Google Search Console (the <code class="text-gray-400">content</code> value only). It is rendered as <code class="text-gray-400">&lt;meta name="google-site-verification"&gt;</code> on every page via <code class="text-gray-400">header.php</code>. Setting key: <code class="text-gray-400">google_site_verification</code>.</p>
        <?php foreach ([
            ['google_site_verification','Google Search Console verification token','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <?= settingsSectionHeading('WhatsApp & OTP', 'green') ?>
        <p class="text-xs text-gray-500 mb-2">SMS disabled — use WhatsApp for OTP login and merchant alerts.</p>
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 mb-4 text-xs text-amber-200/90">
            <p class="font-medium text-amber-300 mb-1">Meta business verification pending?</p>
            <p class="text-amber-200/80 leading-relaxed">Until Meta approves your business + <code class="text-amber-100">uniweb_otp</code> template, OTP may only work for <strong>test phone numbers</strong> added in Meta API Setup. Plain-text fallback is enabled automatically.</p>
        </div>
        <?php foreach ([
            ['whatsapp_enabled','WhatsApp Enabled (0/1)','number'],['whatsapp_api_token','WhatsApp API Token','password'],
            ['whatsapp_phone_id','WhatsApp Phone ID','text'],['whatsapp_business_account_id','WhatsApp Business Account ID','text'],
            ['whatsapp_business_portfolio_id','Meta Business Portfolio ID','text'],
            ['whatsapp_otp_template_name','OTP Template Name','text'],
            ['whatsapp_otp_template_lang','OTP Template Language','text'],
            ['whatsapp_use_otp_template','Use OTP Template (0/1)','number'],
            ['whatsapp_webhook_verify_token','WhatsApp Webhook Verify Token','text'],
            ['whatsapp_api_url','WhatsApp API URL (optional override)','text'],
            ['otp_login_enabled','OTP Login Enabled (0/1)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <div class="rounded-xl border border-gray-800 bg-dark-900/50 p-4">
            <p class="text-gray-400 font-medium text-sm mb-2">Meta Webhook (Step 2)</p>
            <p class="text-xs text-gray-500 mb-2">Paste these in Meta Developer → WhatsApp → Configuration → Webhooks.</p>
            <div class="space-y-2 text-xs">
                <div>
                    <p class="text-gray-500">Callback URL</p>
                    <p class="font-mono text-emerald-400 break-all"><?= e(whatsappWebhookCallbackUrl()) ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Verify token</p>
                    <p class="font-mono text-emerald-400"><?= e(whatsappWebhookVerifyToken()) ?></p>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-800 bg-dark-900/50 p-4">
            <p class="text-gray-400 font-medium text-sm mb-2">Test WhatsApp</p>
            <p class="text-xs text-gray-500 mb-3">Connection check + send a test OTP to your phone (<?= e(COMPANY_PHONE) ?>).</p>
            <div class="flex flex-wrap gap-2">
                <a href="gateway_settings.php?test_whatsapp=1&csrf=<?= e(csrfToken()) ?>" class="inline-flex px-4 py-2.5 rounded-lg text-sm bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/30">Test Connection</a>
                <a href="gateway_settings.php?test_whatsapp_otp=1&csrf=<?= e(csrfToken()) ?>" class="inline-flex px-4 py-2.5 rounded-lg text-sm bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Send Test OTP</a>
            </div>
        </div>
        <button type="submit" class="btn-primary px-6 py-2.5">Save Settings</button>
    </form>
    <div class="rounded-xl border border-gray-800 bg-dark-900/50 p-4 mt-6">
        <p class="text-gray-400 font-medium text-sm mb-2">Test Email Delivery</p>
        <p class="text-xs text-gray-500 mb-3">Save SMTP settings first, then send a test message (uses PHP mail() if SMTP host is empty).</p>
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="test_smtp" value="1">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <div class="flex-1 min-w-[200px]">
                <label class="text-xs text-gray-500">Send to</label>
                <input type="email" name="email" value="<?= e($settingsMap['support_email'] ?? COMPANY_SUPPORT_EMAIL) ?>" class="input-field mt-1 text-sm">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-lg text-sm bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Send Test Email</button>
        </form>
    </div>
</div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
