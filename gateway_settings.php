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
    saveGatewaySettingsPreservingSecrets($_POST['settings'] ?? [], $db);
    flash('success', 'Settings saved.');
    redirect('gateway_settings.php');
}

$settings = $db->query('SELECT * FROM gateway_settings ORDER BY setting_key')->fetchAll();
$settingsMap = array_column($settings, 'setting_value', 'setting_key');
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
            <p class="text-xs text-gray-400 mt-1">Paste Razorpay / Cashfree / PayU keys only under <a href="admin_gateway_registry.php" class="text-sky-400 underline">Partner Registry → Partner Detail → Keys</a>. This page is platform-wide only: SMTP, cron, email templates, SEO, WhatsApp, and the collection-mode template for <strong>new merchants</strong>. This page does not accept live PG API keys.</p>
        </div>
        <a href="admin_gateway_registry.php" class="shrink-0 text-xs px-4 py-2 rounded-lg bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Partner Registry →</a>
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
        <p class="text-[11px] text-gray-500">After each website update: click <strong class="text-gray-400 font-medium">Apply pending migrations</strong> once. Expect JSON <code class="text-sky-400">ok: true</code>. Do not drop the database.</p>
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
            ['settlement_cycle', 'Settlement Cycle', 'text'],
            ['upi_mdr', 'UPI MDR (%) — platform default, overridden by Partner Detail', 'number'],
            ['card_mdr', 'Card MDR (%) — platform default, overridden by Partner Detail', 'number'],
            ['netbanking_mdr', 'Netbanking MDR (%) — platform default, overridden by Partner Detail', 'number'],
            ['wallet_mdr', 'Wallet MDR (%) — platform default, overridden by Partner Detail', 'number'],
            ['default_commission', 'Default Commission (%) — platform default, overridden by Partner Detail', 'number'],
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
        <p class="text-xs text-gray-500 mb-3">These defaults apply to <strong>new merchants only</strong> and do not override per-partner commercial or split settings in Partner Detail.</p>
        <div><label class="text-sm text-gray-400">Default Collection Mode (new merchants)</label>
            <select name="settings[default_collection_mode]" class="input-field mt-1">
                <?php foreach (getCollectionModes() as $k => $label): ?>
                <option value="<?= $k ?>" <?= ($settingsMap['default_collection_mode'] ?? 'direct_upi') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label class="text-sm text-gray-400">Platform Margin (%)</label>
            <input type="number" step="0.01" name="settings[platform_margin_pct]" value="<?= e($settingsMap['platform_margin_pct'] ?? '0.10') ?>" class="input-field mt-1">
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
            <p class="font-medium text-sky-300">Partner API keys, methods, MDR & Split → Partner Registry</p>
            <p>Per-partner credentials (Razorpay, Cashfree, PayU, PhonePe, Pine Labs, Worldline, Axis, RBL, Decentro, Digio) are managed in the <a href="admin_gateway_registry.php" class="text-sky-400 underline">Partner Registry</a>. Click any partner → Configure to add keys, enable methods, set MDR, and configure split.</p>
            <p class="text-xs text-gray-500">This page does NOT accept live PG API keys. Platform-wide settings (SMTP, WhatsApp, SEO, cron) remain here. Method partner webhook URL is configured in Partner Detail → Webhooks tab.</p>
            <p class="text-xs text-gray-600">Method partner webhook endpoint: <code class="text-gray-400"><?= e(rtrim(APP_URL, '/')) ?>/method_partner_webhook.php</code></p>
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
