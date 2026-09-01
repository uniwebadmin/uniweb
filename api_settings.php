<?php
require_once __DIR__ . '/config.php';
requireLogin();
requireMerchantTeamCapability('api');
$merchant = getMerchant();
ensureMerchantWebhookEngine();
ensureMerchantApiKeys((int)$merchant['id']);
$merchant = getMerchant();

if (isset($_GET['regenerate']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $mode = ($_GET['regenerate'] === 'test') ? 'test' : 'live';
    if ($mode === 'live' && !merchantCanGoLive($merchant)) {
        flash('error', 'Live API key is locked until KYC is approved.');
    } else {
        $result = regenerateMerchantApiKey((int)$merchant['id'], $mode);
        if (!empty($result['ok'])) {
            $_SESSION['new_api_credential'] = [
                'key' => $result['key'],
                'secret' => $result['secret'],
                'mode' => $result['mode'],
            ];
        }
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ucfirst($mode) . ' API credential regenerated. Copy the key and secret from the one-time panel.'
            : ($result['error'] ?? 'Failed to regenerate key.'));
    }
    redirect('api_settings.php');
}

if (isset($_GET['test_webhook']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $result = sendMerchantWebhookTest((int)$merchant['id']);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('api_settings.php');
}

if (isset($_GET['retry_webhook']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $logId = (int)($_GET['id'] ?? 0);
    $result = retryMerchantWebhookLog($logId, (int)$merchant['id']);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('api_settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $url = trim($_POST['webhook_url'] ?? '');
    $destination = $url !== '' ? publicWebhookDestination($url) : ['ok' => true];
    if (empty($destination['ok'])) {
        flash('error', $destination['error'] ?? 'Invalid webhook URL.');
    } else {
        $secret = trim($_POST['webhook_signing_secret'] ?? '');
        $currentSecret = trim((string)($merchant['webhook_signing_secret'] ?? ''));
        if ($secret !== '' && strlen($secret) < 32) {
            flash('error', 'Webhook signing secret must be at least 32 characters.');
            redirect('api_settings.php');
        }
        if ($secret === '' && $currentSecret === '') {
            $secret = bin2hex(random_bytes(32));
            $_SESSION['new_webhook_secret'] = $secret;
        }
        if ($secret !== '' && !hash_equals($currentSecret, $secret)) {
            getDB()->prepare('UPDATE merchants SET webhook_url=?,webhook_signing_secret_previous=?,webhook_signing_secret=?,webhook_secret_rotated_at=NOW() WHERE id=?')
                ->execute([$url ?: null, $currentSecret ?: null, $secret, $merchant['id']]);
            $_SESSION['new_webhook_secret'] = $secret;
        } else {
            getDB()->prepare('UPDATE merchants SET webhook_url=? WHERE id=?')->execute([$url ?: null, $merchant['id']]);
        }
        flash('success', 'Webhook settings saved.');
        redirect('api_settings.php');
    }
}

$merchant = getMerchant();
$pageTitle = 'API Settings';
$canLive = merchantCanGoLive($merchant);
$viewTest = isDashboardTestMode($merchant);
$webhookSecret = $merchant['webhook_signing_secret'] ?? '';
if ($webhookSecret === '') {
    $webhookSecret = merchantWebhookSecret($merchant);
}
$webhookLogs = getMerchantWebhookLogs((int)$merchant['id'], 25);
$credentialRows = [];
if (financialTablesReady()) {
    $credentialSt = getDB()->prepare("SELECT mode,key_prefix,scopes,last_used_at,created_at FROM api_credentials WHERE merchant_id=? AND status='active' ORDER BY mode,created_at DESC");
    $credentialSt->execute([(int)$merchant['id']]);
    $credentialRows = $credentialSt->fetchAll();
}
$credentialByMode = [];
foreach ($credentialRows as $row) {
    $credentialByMode[$row['mode']] ??= $row;
}
$newCredential = $_SESSION['new_api_credential'] ?? null;
unset($_SESSION['new_api_credential']);
$newWebhookSecret = $_SESSION['new_webhook_secret'] ?? null;
unset($_SESSION['new_webhook_secret']);
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="rounded-xl border border-sky-500/25 bg-sky-500/5 px-4 py-3 text-xs text-gray-400">
        <strong class="text-sky-300">UniWeb merchant API keys</strong> — for your website / server. These are <em class="not-italic text-gray-300">not</em> Razorpay, Cashfree, PayU or Decentro partner keys. Partner rails stay with UniWeb Admin only.
    </div>
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm text-gray-400">Account: <?= accountModeBadge($merchant) ?> · <span class="font-mono text-sky-400 text-xs">MID: <?= e($merchant['merchant_code'] ?? '') ?></span></p>
            <?php if ($canLive): ?>
            <span class="text-xs text-gray-600">· Use <?= renderMerchantModeToggle($merchant, 'header') ?> to switch keys</span>
            <?php endif; ?>
        </div>
        <a href="api_docs.php" class="text-sm text-brand-400 hover:underline">Full API Docs →</a>
    </div>
    <?php if ($newCredential): ?>
    <div class="glass rounded-xl p-6 border border-emerald-500/50">
        <h2 class="font-semibold text-emerald-400 mb-2">Copy your <?= e(ucfirst($newCredential['mode'])) ?> credential now</h2>
        <p class="text-xs text-gray-500 mb-4">The secret is stored only as a one-way hash and cannot be shown again.</p>
        <label class="text-xs text-gray-500">API Key</label>
        <input readonly class="input-field font-mono text-xs mt-1 mb-3" value="<?= e($newCredential['key']) ?>">
        <label class="text-xs text-gray-500">API Secret</label>
        <input readonly class="input-field font-mono text-xs mt-1" value="<?= e($newCredential['secret']) ?>">
    </div>
    <?php endif; ?>
    <div class="glass rounded-xl p-6 border <?= $viewTest ? 'border-amber-500/40 ring-1 ring-amber-500/20' : 'border-gray-800 opacity-70' ?>">
        <h2 class="font-semibold mb-4 text-amber-400">Test API Keys (Sandbox) <?= $viewTest ? '· Active' : '' ?></h2>
        <p class="text-xs text-gray-500 mb-4">Use in Test Mode — like Razorpay test keys. No real money.</p>
        <div class="space-y-4 text-sm">
            <div><label class="text-gray-500 text-xs">Active credential</label>
                <p class="font-mono text-sm text-amber-300 mt-1"><?= e(($credentialByMode['test']['key_prefix'] ?? 'Not created') . (isset($credentialByMode['test']) ? '…' : '')) ?></p>
                <p class="text-[11px] text-gray-600 mt-1">Secret is never stored in recoverable form.</p>
            </div>
            <a href="api_settings.php?regenerate=test&csrf=<?= e(csrfToken()) ?>" class="inline-block text-xs text-amber-400 hover:text-amber-300 border border-amber-500/30 px-3 py-1.5 rounded-lg" onclick="return confirm('Regenerate Test API key? The old test key will stop working immediately.')">↻ Regenerate Test Key</a>
        </div>
    </div>
    <div class="glass rounded-xl p-6 border <?= !$viewTest && $canLive ? 'border-emerald-500/40 ring-1 ring-emerald-500/20' : 'border-gray-800' ?>">
        <h2 class="font-semibold mb-4"><?= $canLive ? 'Live API Credentials' : 'Live API Credentials (locked until KYC approved)' ?> <?= (!$viewTest && $canLive) ? '· Active' : '' ?></h2>
        <div class="space-y-4 text-sm">
            <div><label class="text-gray-500 text-xs">Active credential</label>
                <p class="font-mono text-sm text-brand-400 mt-1"><?= e(($credentialByMode['live']['key_prefix'] ?? 'Not created') . (isset($credentialByMode['live']) ? '…' : '')) ?></p>
                <p class="text-[11px] text-gray-600 mt-1">Secret is never stored in recoverable form.</p>
            </div>
            <div><label class="text-gray-500 text-xs">Merchant ID</label><p class="font-mono text-brand-400 mt-1"><?= e($merchant['merchant_code']) ?></p></div>
            <?php if ($canLive): ?>
            <a href="api_settings.php?regenerate=live&csrf=<?= e(csrfToken()) ?>" class="inline-block text-xs text-red-400 hover:text-red-300 border border-red-500/30 px-3 py-1.5 rounded-lg" onclick="return confirm('Regenerate LIVE API key? The old key will stop working immediately — make sure you update your integration right after. A confirmation email will be sent to you.')">↻ Regenerate Live Key</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="glass rounded-xl p-6 border border-violet-500/20">
        <h2 class="font-semibold mb-2">Fast QR API (bulk create)</h2>
        <p class="text-xs text-gray-500 mb-3">High-volume QR creation uses the same <strong class="text-gray-300">Test / Live API key</strong> from this page — header <code class="text-gray-400">X-API-Key</code> plus <code class="text-gray-400">Idempotency-Key</code> on every POST. Requires <code class="text-gray-400">links:write</code> scope (included by default).</p>
        <p class="text-xs text-gray-500 mb-2">Endpoint: <code class="text-gray-400"><?= e(rtrim(APP_URL, '/')) ?>/api_qr_create.php</code></p>
        <p class="text-xs text-gray-600">See <a href="qr_code.php" class="text-sky-400 hover:underline">QR Generator</a> → High-Volume Wizard, or <a href="api_docs.php" class="text-sky-400 hover:underline">API Docs</a>.</p>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Outbound Webhooks</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-gray-500 text-xs">Your Webhook URL</label>
                <input type="url" name="webhook_url" value="<?= e($merchant['webhook_url'] ?? '') ?>" placeholder="https://yoursite.com/webhooks/uniweb" class="input-field mt-1 text-sm">
            </div>
            <div><label class="text-gray-500 text-xs">Signing Secret</label>
                <input type="password" name="webhook_signing_secret" value="" class="input-field font-mono text-xs mt-1" placeholder="<?= $webhookSecret !== '' ? 'Configured — enter a new value to rotate' : 'Auto-generated if empty' ?>">
                <?php if ($newWebhookSecret): ?><p class="text-xs text-emerald-400 mt-2">Copy this signing secret now: <code><?= e($newWebhookSecret) ?></code></p><?php endif; ?>
            </div>
            <button type="submit" class="btn-primary text-sm">Save Webhook Settings</button>
        </form>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="api_settings.php?test_webhook=1&csrf=<?= e(csrfToken()) ?>" class="px-3 py-1.5 rounded-lg text-xs bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Send Test Webhook</a>
        </div>
        <div class="mt-4 text-xs text-gray-500 space-y-2">
            <p>We POST JSON with header <code class="text-gray-400">X-UniWeb-Signature</code> = HMAC-SHA256(body, signing secret).</p>
            <p>Events: <code class="text-gray-400">webhook.test</code>, <code class="text-gray-400">payment.success</code>, <code class="text-gray-400">payment.failed</code>, <code class="text-gray-400">refund.completed</code></p>
            <p>Failed rows below have Retry. Platform retries also run inside the existing 10-minute auto-audit — no extra cron.</p>
        </div>
        <?php
        $hmacSnippet = <<<'PHP'
<?php
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_UNIWEB_SIGNATURE'] ?? '';
$ok  = hash_equals(hash_hmac('sha256', $raw, $signingSecret), $sig);
if (!$ok) { http_response_code(401); exit; }
$event = json_decode($raw, true);
PHP;
        ?>
        <p class="mt-4 text-xs text-gray-400 font-semibold">Copy: verify HMAC in PHP</p>
        <pre class="mt-2 bg-dark-900 rounded-lg p-4 text-xs text-gray-400 overflow-x-auto" id="webhook-hmac-snippet"><?= e($hmacSnippet) ?></pre>
        <button type="button" class="mt-2 text-xs text-sky-400 hover:underline" onclick="navigator.clipboard.writeText(document.getElementById('webhook-hmac-snippet').innerText); this.textContent='Copied'; setTimeout(()=>this.textContent='Copy snippet',2000)">Copy snippet</button>
        <?php if (!empty($webhookLogs)): ?>
        <div class="mt-6 border-t border-gray-800 pt-4">
            <h3 class="text-sm font-semibold mb-3">Delivery Log</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-gray-500 text-left border-b border-gray-800">
                            <th class="py-2 pr-3">Time</th>
                            <th class="py-2 pr-3">Event</th>
                            <th class="py-2 pr-3">HTTP</th>
                            <th class="py-2 pr-3">Response</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhookLogs as $log):
                            $code = (int)($log['response_code'] ?? 0);
                            $ok = merchantWebhookDeliveryOk($code ?: null);
                        ?>
                        <tr class="border-b border-gray-800/60">
                            <td class="py-2 pr-3 text-gray-500 whitespace-nowrap"><?= e($log['created_at'] ?? '') ?></td>
                            <td class="py-2 pr-3 font-mono text-brand-400"><?= e($log['event_type'] ?? '') ?></td>
                            <td class="py-2 pr-3 <?= $ok ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $code ?: '—' ?></td>
                            <td class="py-2 pr-3 text-gray-500 max-w-[200px] truncate" title="<?= e($log['response_body'] ?? '') ?>"><?= e(mb_substr((string)($log['response_body'] ?? ''), 0, 80)) ?></td>
                            <td class="py-2 text-right">
                                <?php if (!$ok): ?>
                                <a href="api_settings.php?retry_webhook=1&id=<?= (int)$log['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="text-sky-400 hover:underline">Retry</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <p class="mt-4 text-xs text-gray-600">No webhook deliveries yet. Save a URL and send a test webhook.</p>
        <?php endif; ?>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">API Endpoint</h2>
        <code class="block bg-dark-900 rounded-lg p-4 text-sm text-brand-400 font-mono">POST <?= APP_URL ?>/api.php</code>
        <p class="text-xs text-gray-500 mt-2">Required headers: <code class="text-gray-400">X-API-Key</code>, <code class="text-gray-400">X-API-Secret</code>, and <code class="text-gray-400">Idempotency-Key</code> for writes.</p>
        <?php
        $curlSample = "curl -X POST '" . APP_URL . "/api.php' \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-API-Key: uw_test_...' \\\n  -H 'X-API-Secret: uws_...' \\\n  -d '{\"action\":\"get_balance\"}'";
        ?>
        <pre class="mt-4 bg-dark-900 rounded-lg p-4 text-xs text-gray-400 overflow-x-auto"><?= e($curlSample) ?></pre>
        <a href="<?= APP_URL ?>/openapi.json" class="inline-block mt-3 text-xs text-sky-400 hover:underline" target="_blank">OpenAPI 3.0 spec (openapi.json) →</a>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Available Actions</h2>
        <div class="space-y-4 text-sm">
            <?php foreach ([
                ['create_payment_link', '{ "action": "create_payment_link", "amount": 500, "description": "Order #123" }'],
                ['check_status', '{ "action": "check_status", "txn_id": "TXN..." }'],
                ['list_transactions', '{ "action": "list_transactions", "limit": 20 }'],
                ['get_balance', '{ "action": "get_balance" }'],
                ['create_refund', '{ "action": "create_refund", "txn_id": "TXN...", "amount": 100 }'],
                ['list_refunds', '{ "action": "list_refunds", "limit": 20 }'],
                ['list_payment_links', '{ "action": "list_payment_links", "limit": 20 }'],
                ['get_payment_link', '{ "action": "get_payment_link", "link_id": "LNK..." }'],
            ] as [$action, $example]): ?>
            <div class="border border-gray-800 rounded-lg p-4">
                <p class="font-mono text-brand-400 text-xs mb-2"><?= $action ?></p>
                <pre class="text-xs text-gray-400 overflow-x-auto"><?= e($example) ?></pre>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
