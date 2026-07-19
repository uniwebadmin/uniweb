<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureMerchantWebhookEngine();
ensureMerchantApiKeys((int)$merchant['id']);
$merchant = getMerchant();

if (isset($_GET['test_api']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $useTest = isDashboardTestMode($merchant) || ($_GET['mode'] ?? '') === 'test';
    if (($_GET['mode'] ?? '') === 'live') {
        $useTest = false;
    }
    $apiKey = $useTest ? ($merchant['test_api_key'] ?? '') : $merchant['api_key'];
    if (!$apiKey) {
        flash('error', 'No API key available for this mode.');
    } else {
        $ch = curl_init(APP_URL . '/api.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode(['action' => 'get_balance']),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($http === 200 && !empty($data['success'])) {
            $bal = $data['balance'] ?? $data;
            $msg = 'API OK (' . ($useTest ? 'test' : 'live') . ' key). Available: ' . formatMoney((float)($bal['available'] ?? 0));
            flash('success', $msg);
        } else {
            $err = is_array($data) ? ($data['error'] ?? 'HTTP ' . $http) : 'HTTP ' . $http;
            flash('error', 'API test failed: ' . $err);
        }
    }
    redirect('api_settings.php');
}

if (isset($_GET['regenerate']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $mode = ($_GET['regenerate'] === 'test') ? 'test' : 'live';
    if ($mode === 'live' && !merchantCanGoLive($merchant)) {
        flash('error', 'Live API key is locked until KYC is approved.');
    } else {
        $result = regenerateMerchantApiKey((int)$merchant['id'], $mode);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ucfirst($mode) . ' API key regenerated. Old key deactivated — update your integration. A confirmation email was sent.'
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
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        flash('error', 'Invalid webhook URL.');
    } else {
        $secret = trim($_POST['webhook_signing_secret'] ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }
        getDB()->prepare('UPDATE merchants SET webhook_url = ?, webhook_signing_secret = ? WHERE id = ?')
            ->execute([$url ?: null, $secret, $merchant['id']]);
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
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm text-gray-400">Account: <?= accountModeBadge($merchant) ?> · <span class="font-mono text-sky-400 text-xs">MID: <?= e($merchant['merchant_code'] ?? '') ?></span></p>
            <?php if ($canLive): ?>
            <span class="text-xs text-gray-600">· Use <?= renderMerchantModeToggle($merchant, 'header') ?> to switch keys</span>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-3">
            <a href="api_settings.php?test_api=1&mode=<?= $viewTest ? 'test' : 'live' ?>&csrf=<?= e(csrfToken()) ?>" class="px-3 py-1.5 rounded-lg text-xs bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/30">Test <?= $viewTest ? 'Test' : 'Live' ?> API</a>
            <a href="api_docs.php" class="text-sm text-brand-400 hover:underline">Full API Docs →</a>
        </div>
    </div>
    <div class="glass rounded-xl p-6 border <?= $viewTest ? 'border-amber-500/40 ring-1 ring-amber-500/20' : 'border-gray-800 opacity-70' ?>">
        <h2 class="font-semibold mb-4 text-amber-400">Test API Keys (Sandbox) <?= $viewTest ? '· Active' : '' ?></h2>
        <p class="text-xs text-gray-500 mb-4">Use in Test Mode — like Razorpay test keys. No real money.</p>
        <div class="space-y-4 text-sm">
            <div><label class="text-gray-500 text-xs">Test API Key</label>
                <div class="flex gap-2 mt-1"><input type="text" readonly value="<?= e($merchant['test_api_key'] ?? '') ?>" class="input-field font-mono text-xs flex-1" id="testApiKey">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('testApiKey').value);this.textContent='Copied'" class="px-3 py-2 bg-amber-600/20 text-amber-400 rounded-lg text-xs">Copy</button></div>
            </div>
            <div><label class="text-gray-500 text-xs">Test API Secret</label>
                <input type="password" readonly value="<?= e($merchant['test_api_secret'] ?? '') ?>" class="input-field font-mono text-xs mt-1" id="testApiSecret">
            </div>
            <a href="api_settings.php?regenerate=test&csrf=<?= e(csrfToken()) ?>" class="inline-block text-xs text-amber-400 hover:text-amber-300 border border-amber-500/30 px-3 py-1.5 rounded-lg" onclick="return confirm('Regenerate Test API key? The old test key will stop working immediately.')">↻ Regenerate Test Key</a>
        </div>
    </div>
    <div class="glass rounded-xl p-6 border <?= !$viewTest && $canLive ? 'border-emerald-500/40 ring-1 ring-emerald-500/20' : 'border-gray-800' ?>">
        <h2 class="font-semibold mb-4"><?= $canLive ? 'Live API Credentials' : 'Live API Credentials (locked until KYC approved)' ?> <?= (!$viewTest && $canLive) ? '· Active' : '' ?></h2>
        <div class="space-y-4 text-sm">
            <div><label class="text-gray-500 text-xs">API Key</label>
                <div class="flex gap-2 mt-1"><input type="text" readonly value="<?= e($merchant['api_key']) ?>" class="input-field font-mono text-xs flex-1" id="apiKey" <?= !$canLive ? 'disabled' : '' ?>>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('apiKey').value);this.textContent='Copied'" class="px-3 py-2 bg-brand-600/20 text-brand-400 rounded-lg text-xs">Copy</button></div>
            </div>
            <div><label class="text-gray-500 text-xs">API Secret</label>
                <div class="flex gap-2 mt-1"><input type="password" readonly value="<?= e($merchant['api_secret']) ?>" class="input-field font-mono text-xs flex-1" id="apiSecret" <?= !$canLive ? 'disabled' : '' ?>>
                <button type="button" onclick="document.getElementById('apiSecret').type=document.getElementById('apiSecret').type==='password'?'text':'password'" class="px-3 py-2 bg-dark-800 text-gray-400 rounded-lg text-xs">Show</button></div>
            </div>
            <div><label class="text-gray-500 text-xs">Merchant ID</label><p class="font-mono text-brand-400 mt-1"><?= e($merchant['merchant_code']) ?></p></div>
            <?php if ($canLive): ?>
            <a href="api_settings.php?regenerate=live&csrf=<?= e(csrfToken()) ?>" class="inline-block text-xs text-red-400 hover:text-red-300 border border-red-500/30 px-3 py-1.5 rounded-lg" onclick="return confirm('Regenerate LIVE API key? The old key will stop working immediately — make sure you update your integration right after. A confirmation email will be sent to you.')">↻ Regenerate Live Key</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Outbound Webhooks</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-gray-500 text-xs">Your Webhook URL</label>
                <input type="url" name="webhook_url" value="<?= e($merchant['webhook_url'] ?? '') ?>" placeholder="https://yoursite.com/webhooks/uniweb" class="input-field mt-1 text-sm">
            </div>
            <div><label class="text-gray-500 text-xs">Signing Secret</label>
                <input type="text" name="webhook_signing_secret" value="<?= e($merchant['webhook_signing_secret'] ?? '') ?>" class="input-field font-mono text-xs mt-1" placeholder="Auto-generated if empty">
            </div>
            <button type="submit" class="btn-primary text-sm">Save Webhook Settings</button>
        </form>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="api_settings.php?test_webhook=1&csrf=<?= e(csrfToken()) ?>" class="px-3 py-1.5 rounded-lg text-xs bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Send Test Webhook</a>
        </div>
        <div class="mt-4 text-xs text-gray-500 space-y-2">
            <p>We POST JSON with header <code class="text-gray-400">X-UniWeb-Signature</code> = HMAC-SHA256(body, signing secret).</p>
            <p>Verify: <code class="text-gray-400">hash_hmac('sha256', $rawBody, $secret)</code></p>
            <p>Events: <code class="text-gray-400">webhook.test</code>, <code class="text-gray-400">payment.success</code>, <code class="text-gray-400">payment.failed</code>, <code class="text-gray-400">refund.completed</code></p>
        </div>
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
        <p class="text-xs text-gray-500 mt-2">Header: <code class="text-gray-400">X-API-Key: your_api_key</code></p>
        <?php
        $sampleKey = $viewTest ? ($merchant['test_api_key'] ?? 'uk_test_...') : $merchant['api_key'];
        $curlSample = "curl -X POST '" . APP_URL . "/api.php' \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-API-Key: " . $sampleKey . "' \\\n  -d '{\"action\":\"get_balance\"}'";
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
