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
$cronHealth = getCronHealthStatus();
$cronKey = autoAuditWatchdogKey();
$cronKeyMasked = strlen($cronKey) > 12 ? substr($cronKey, 0, 14) . '…' . substr($cronKey, -4) : $cronKey;
$pageTitle = 'Gateway Settings';
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
$settleCronKey = function_exists('getSettlementCronKey') ? getSettlementCronKey() : 'uniweb-settle';
$settleCronUrl = APP_URL . '/cron_settlements.php?key=' . rawurlencode($settleCronKey);
?>
<?php if (!empty($gatewayGaps)): ?>
<div class="glass rounded-xl p-5 mb-6 border border-amber-500/40 bg-amber-500/5 max-w-4xl">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <p class="font-semibold text-amber-300">Live launch — <?= count($gatewayGaps) ?> gateway(s) need keys</p>
        <a href="admin_website.php" class="text-xs text-sky-400">API Keys guide →</a>
    </div>
    <div class="grid sm:grid-cols-2 gap-2 text-xs">
        <?php foreach ($gatewayGaps as $gap): ?>
        <div class="rounded-lg border border-gray-800 px-3 py-2">
            <span class="text-amber-400">● <?= e($gap['label']) ?></span>
            <span class="text-gray-500 block mt-0.5"><?= e($gap['status']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="text-[10px] text-gray-600 mt-3">When partner production keys arrive: paste below → Save → Test Connection → set environment to live when PG approves. Blank password fields keep the existing secret.</p>
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
        <div>
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Cron URL (Hostinger → Advanced → Cron Jobs)</p>
            <code class="block text-sky-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg"><?= e($cronHealth['cron_url'] ?? '') ?></code>
        </div>
        <div>
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">wget command (every <?= (int)($cronHealth['interval_min'] ?? 10) ?> min)</p>
            <code class="block text-emerald-400 font-mono break-all bg-dark-900/60 p-3 rounded-lg">wget -q -O /dev/null "<?= e($cronHealth['cron_url'] ?? '') ?>"</code>
        </div>
        <p class="text-gray-500">Security: URL requires secret key · wrong key = 403 · failed attempts logged in Error Log.</p>
        <p class="text-gray-500">Cron key (masked): <span class="font-mono text-gray-400"><?= e($cronKeyMasked) ?></span></p>
        <div class="flex flex-wrap gap-2 pt-2">
            <a href="cron_auto_audit.php?key=<?= rawurlencode($cronKey) ?>&verbose=1" target="_blank" rel="noopener" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Test cron now ↗</a>
            <a href="migrate_release.php?key=<?= rawurlencode($cronKey) ?>" target="_blank" rel="noopener" class="text-xs px-3 py-2 rounded-lg border border-sky-700/60 text-sky-300 hover:text-white" title="Applies pending migrations/*.sql using this same cron key. Safe to re-run.">Apply pending migrations ↗</a>
            <a href="?rotate_cron_key=1&csrf=<?= e(csrfToken()) ?>" class="text-xs px-3 py-2 rounded-lg border border-amber-500/40 text-amber-400 hover:bg-amber-500/10" onclick="return confirm('Rotate cron key? You must update Hostinger cron URL after this.')">Rotate key</a>
        </div>
        <p class="text-[11px] text-gray-500">One-time after deploy: <strong class="text-gray-400 font-medium">Apply pending migrations</strong> uses the same watchdog key (never invent a new CRON_KEY). Expect JSON <code class="text-sky-400">ok: true</code>. Details in <code class="text-gray-400">migrations/README.md</code>.</p>
        <div class="pt-4 mt-2 border-t border-gray-800">
            <p class="text-[10px] text-gray-600 uppercase tracking-wide mb-1">Optional — Settlement batch cron (every 15 min)</p>
            <code class="block text-violet-300 font-mono break-all bg-dark-900/60 p-3 rounded-lg text-[11px]"><?= e($settleCronUrl) ?></code>
            <p class="text-gray-500 mt-2">Needed only if merchants use Scheduled Batch settlements. Details: <a href="admin_settlement_settings.php" class="text-sky-400">Settlement settings</a></p>
        </div>
    </div>
</div>
<div class="glass rounded-xl p-6">
    <h2 class="font-semibold mb-2">Gateway Status</h2>
    <p class="text-xs text-gray-500 mb-4">Save keys below, then run Test Connection. Primary gateway: <span class="text-brand-400 font-medium"><?= e(ucfirst($activePg)) ?></span></p>
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
                    <p class="text-[10px] text-brand-400 mt-1 uppercase tracking-wide">Checkout default</p>
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
    <h2 class="font-semibold mb-6">Platform Settings</h2>
    <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <?php
        $fields = [
            ['platform_name', 'Platform Name', 'text'],
            ['support_email', 'Support Email', 'email'],
            ['support_phone', 'Support Phone', 'text'],
            ['support_whatsapp', 'Support WhatsApp Number (with country code)', 'text'],
            ['min_settlement_amount', 'Min Settlement (₹)', 'number'],
            ['settlement_cycle', 'Settlement Cycle', 'text'],
            ['upi_mdr', 'UPI MDR (%)', 'number'],
            ['card_mdr', 'Card MDR (%)', 'number'],
            ['netbanking_mdr', 'Netbanking MDR (%)', 'number'],
            ['wallet_mdr', 'Wallet MDR (%)', 'number'],
            ['default_commission', 'Default Commission (%)', 'number'],
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
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">SMTP Email Settings</h3>
        <p class="text-xs text-gray-500">Leave SMTP host empty to use PHP mail(). For Hostinger use smtp.hostinger.com</p>
        <?php foreach ([
            ['smtp_host','SMTP Host','text'],['smtp_port','SMTP Port','number'],
            ['smtp_user','SMTP Username','text'],['smtp_pass','SMTP Password','password'],
            ['smtp_from_email','From Email','email'],['smtp_from_name','From Name','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Support & Social Channels</h3>
        <p class="text-xs text-gray-500">Links shown to merchants on Support → Connect with Admin.</p>
        <?php foreach ([
            ['support_instagram', 'Instagram URL', 'text'],
            ['support_telegram', 'Telegram URL', 'text'],
            ['support_facebook', 'Facebook URL', 'text'],
            ['support_twitter', 'Twitter / X URL', 'text'],
            ['support_linkedin', 'LinkedIn URL', 'text'],
            ['support_youtube', 'YouTube URL', 'text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">B2B Collection Engine</h3>
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
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Payment Gateways</h3>
        <p class="text-xs text-gray-500">Add API keys to enable real-time UPI, cards & international payments.</p>
        <div><label class="text-sm text-gray-400">Primary Payment Gateway</label>
            <select name="settings[active_payment_gateway]" class="input-field mt-1">
                <?php foreach (['razorpay'=>'Razorpay','cashfree'=>'Cashfree','payu'=>'PayU','manual'=>'Manual UPI Only'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= ($settingsMap['active_payment_gateway'] ?? 'razorpay') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ([
            ['razorpay_key_id','Razorpay Key ID','text'],['razorpay_key_secret','Razorpay Key Secret','password'],
            ['razorpay_webhook_secret','Razorpay Webhook Secret','password'],
            ['razorpay_environment','Razorpay Env (test/live)','text'],
            ['cashfree_app_id','Cashfree App ID','text'],['cashfree_secret_key','Cashfree Secret','password'],
            ['cashfree_environment','Cashfree Env (production/sandbox)','text'],
            ['payu_merchant_key','PayU Merchant Key','text'],['payu_merchant_salt','PayU Salt','password'],
            ['payu_environment','PayU Env (test/production)','text'],
            ['decentro_client_id','Decentro Client ID','text'],['decentro_client_secret','Decentro Client Secret','password'],
            ['phonepe_merchant_id','PhonePe Merchant ID','text'],['phonepe_salt_key','PhonePe Salt Key','password'],
            ['phonepe_salt_index','PhonePe Salt Index','text'],
            ['phonepe_environment','PhonePe Env (sandbox/production)','text'],
            ['pinelabs_merchant_id','Pine Labs Merchant ID','text'],
            ['pinelabs_access_code','Pine Labs Access Code','text'],
            ['pinelabs_secure_key','Pine Labs Secure Key','password'],
            ['pinelabs_environment','Pine Labs Env (sandbox/production)','text'],
            ['worldline_merchant_id','Worldline Merchant ID','text'],
            ['worldline_access_key','Worldline Access Key','text'],
            ['worldline_secret_key','Worldline Secret Key','password'],
            ['worldline_environment','Worldline Env (sandbox/production)','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <div class="rounded-xl border border-gray-800 bg-dark-900/50 p-4 text-xs text-gray-500 space-y-2">
            <p class="text-gray-400 font-medium text-sm mb-2">Webhook URLs (configure in PG dashboard)</p>
            <?php foreach (['razorpay' => pgWebhookUrl('razorpay'), 'cashfree' => pgWebhookUrl('cashfree'), 'payu' => pgWebhookUrl('payu')] as $gw => $url): ?>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-gray-400 w-16"><?= ucfirst($gw) ?>:</span>
                <code class="text-sky-400 break-all flex-1" id="wh-<?= $gw ?>"><?= e($url) ?></code>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('wh-<?= $gw ?>').textContent);this.textContent='Copied'" class="px-2 py-1 rounded bg-dark-800 text-gray-400 hover:text-white">Copy</button>
            </div>
            <?php endforeach; ?>
        </div>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Payout Partner Keys (licensed rail)</h3>
        <p class="text-xs text-gray-500 mb-2">Paste keys when a licensed payout partner is signed. Until then the payout module stays gated — no live money movement. Set <code class="text-gray-400">payout_live_enabled=1</code> only after compliance review.</p>
        <?php foreach ([
            ['razorpayx_key_id','RazorpayX Key ID','text'],['razorpayx_key_secret','RazorpayX Key Secret','password'],
            ['cashfree_payout_client_id','Cashfree Payouts Client ID','text'],['cashfree_payout_client_secret','Cashfree Payouts Client Secret','password'],
            ['payout_live_enabled','Enable live payout money movement (0/1)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Axis Bank (Virtual Account / Collections)</h3>
        <p class="text-xs text-gray-500"><a href="admin_axis.php" class="text-sky-400">Axis UAT Dashboard →</a> · Webhook: <?= e(axisWebhookUrl()) ?></p>
        <?php foreach ([
            ['axis_app_name','Axis App Name (Portal)','text'],
            ['axis_application_id','Axis Application UUID (Portal)','text'],
            ['axis_oauth_redirect','Axis OAuth Redirect URL','text'],
            ['axis_client_id','Axis Client ID','text'],['axis_client_secret','Axis Client Secret','password'],
            ['axis_api_key','Axis API Key (legacy)','text'],['axis_api_secret','Axis API Secret (legacy)','password'],
            ['axis_environment','Axis Env (uat/production)','text'],['axis_base_url','Axis Base URL','text'],
            ['axis_token_url','Axis Token URL (from portal docs)','text'],
            ['axis_channel_id','Axis Channel ID','text'],['axis_corporate_id','Axis Corporate / Customer Code','text'],
            ['axis_master_account','Axis Master Collection Account','text'],
            ['axis_va_ifsc','Axis VA IFSC','text'],
            ['axis_allow_mock','Allow Mock VA (0=real API only)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800 text-xl">RBL Bank</h3>
        <p class="text-xs text-gray-500">Sandbox keys for RBL Open Banking: Virtual Account, UPI Collection, Account Balance, Blob VA Statement, Corporate Payments.</p>
        <?php foreach ([
            ['rbl_app_name','RBL App Name','text'],
            ['rbl_client_id','RBL Client ID (API Key)','text'],
            ['rbl_client_secret','RBL Client Secret (API Secret)','password'],
            ['rbl_environment','RBL Env (sandbox/production)','text'],
            ['rbl_base_url','RBL Base URL (optional override)','text'],
            ['rbl_master_account','RBL Master / Corporate Account No','text'],
            ['rbl_corp_id','RBL Corp ID','text'],
            ['rbl_maker_id','RBL Maker ID','text'],
            ['rbl_checker_id','RBL Checker ID','text'],
            ['rbl_approver_id','RBL Approver ID','text'],
            ['rbl_va_enabled','Enable RBL VA (0/1)','number'],
            ['rbl_upi_collection_enabled','Enable RBL UPI Collection (0/1)','number'],
            ['rbl_payout_enabled','Enable RBL Payouts (0/1)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">KYC Verification (Decentro)</h3>
        <p class="text-xs text-gray-500">Auto-verify PAN, Aadhaar, GST, CIN, Udyam, IEC, Bank via Decentro API (staging/production).</p>
        <?php foreach ([
            ['decentro_client_id','Decentro Client ID','text'],['decentro_client_secret','Decentro Client Secret','password'],
            ['decentro_consumer_urn','Decentro Master Consumer URN','text'],
            ['decentro_module_secret','Decentro Module Secret','password'],
            ['decentro_provider_secret','Decentro Provider Secret','password'],
            ['decentro_base_url','Decentro Base URL','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Video KYC face-match partner (Digio)</h3>
        <p class="text-xs text-gray-500 mb-2">Owner-confirmed: UniWeb does <strong>not</strong> store Aadhaar/face biometrics. Paste Digio (or equivalent certified partner) keys when contracted. Until then Video KYC is manual review only.</p>
        <?php foreach ([
            ['digio_client_id','Digio Client ID','text'],
            ['digio_client_secret','Digio Client Secret','password'],
            ['digio_environment','Digio Env (sandbox/production)','text'],
            ['digio_face_match_enabled','Enable Digio face-match (0/1)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">Method Partner Automation</h3>
        <p class="text-xs text-gray-500 mb-2">Partner approve/reject hits this URL and turns merchant methods ON/OFF automatically.</p>
        <div class="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4 mb-3 text-xs space-y-2">
            <p class="text-[10px] text-gray-600 uppercase tracking-wide">Method partner webhook URL</p>
            <code class="block text-sky-400 font-mono break-all"><?= e(rtrim(APP_URL, '/') . '/method_partner_webhook.php') ?></code>
            <p class="text-gray-500">Auth header: <code class="text-gray-300">X-UniWeb-Method-Secret</code> (or query <code class="text-gray-300">?key=</code>). Body JSON: partner_ref + decision (approved|rejected).</p>
        </div>
        <?php foreach ([
            ['method_partner_webhook_secret','Method Partner Webhook Secret','password'],
            ['nbfc_partner_gateway','NBFC Partner Gateway (payu/razorpay/…)','text'],
            ['nbfc_live_enabled','NBFC live disbursement switch (0/1)','number'],
            ['instant_settlement_gateway','Instant Settlement Gateway','text'],
            ['payout_live_enabled','Payout live money switch (0/1)','number'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">SEO — Google Search Console</h3>
        <p class="text-xs text-gray-500 mb-2">Paste the HTML-tag verification token from Google Search Console (the <code class="text-gray-400">content</code> value only). It is rendered as <code class="text-gray-400">&lt;meta name="google-site-verification"&gt;</code> on every page via <code class="text-gray-400">header.php</code>. Setting key: <code class="text-gray-400">google_site_verification</code>.</p>
        <?php foreach ([
            ['google_site_verification','Google Search Console verification token','text'],
        ] as [$key,$label,$type]): renderGatewaySettingInput($key, $label, $type, $settingsMap); endforeach; ?>
        <h3 class="font-semibold text-brand-400 pt-4 border-t border-gray-800">WhatsApp & OTP</h3>
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
