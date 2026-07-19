<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'API Documentation';
require_once __DIR__ . '/header.php';
?>
<div class="pt-24 pb-16 max-w-4xl mx-auto px-4">
    <h1 class="text-3xl font-bold mb-2">API Documentation</h1>
    <p class="text-gray-400 mb-4">UniWeb REST API for developers — payment links, transactions, refunds, webhooks.</p>
    <a href="<?= APP_URL ?>/openapi.json" class="inline-flex items-center gap-2 text-sm text-sky-400 hover:underline mb-4" target="_blank">OpenAPI 3.0 Specification (openapi.json) →</a>
    <?php if (isLoggedIn()): ?>
    <a href="api_settings.php" class="inline-flex items-center gap-2 text-sm text-emerald-400 hover:underline mb-8 ml-4">Your API Keys →</a>
    <?php else: ?>
    <a href="login.php" class="inline-flex items-center gap-2 text-sm text-emerald-400 hover:underline mb-8 ml-4">Login for API Keys →</a>
    <?php endif; ?>

    <div class="space-y-6">
        <div class="glass rounded-xl p-6 border border-brand-500/20">
            <h2 class="font-semibold text-brand-400 mb-3">Quick Start</h2>
            <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside mb-4">
                <li>Sign up and complete KYC (or use Test Mode with test API keys).</li>
                <li>Copy your API key from Dashboard → API Settings.</li>
                <li>POST JSON to <code class="text-gray-300"><?= APP_URL ?>/api.php</code> with header <code class="text-gray-300">X-API-Key</code>.</li>
                <li>Create a payment link and redirect customers to <code class="text-gray-300">payment_url</code>.</li>
                <li>Configure your webhook URL to receive <code class="text-gray-300">payment.success</code> events.</li>
            </ol>
            <p class="text-xs text-gray-500 mb-2">Example — get wallet balance:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300">curl -X POST '<?= APP_URL ?>/api.php' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uk_your_key_here' \
  -d '{"action":"get_balance"}'</pre>
            <p class="text-xs text-gray-500 mb-2 mt-4">Example — create payment link:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300">curl -X POST '<?= APP_URL ?>/api.php' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uk_your_key_here' \
  -d '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}'</pre>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold text-brand-400 mb-3">Base URL</h2>
            <code class="block bg-dark-900 p-4 rounded-lg text-sm font-mono">POST <?= APP_URL ?>/api.php</code>
            <p class="text-sm text-gray-500 mt-3">Header: <code class="text-gray-400">X-API-Key: uk_your_key</code></p>
        </div>

        <?php
        $endpoints = [
            ['create_payment_link', 'Create Payment Link', '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}', 'Returns payment_url on success'],
            ['check_status', 'Check Status', '{"action":"check_status","txn_id":"TXN..."}', 'Transaction status and UTR'],
            ['list_transactions', 'List Transactions', '{"action":"list_transactions","limit":20}', 'Recent transactions (max 50)'],
            ['get_balance', 'Get Balance', '{"action":"get_balance"}', 'Collected, settled, available balance'],
            ['create_refund', 'Create Refund', '{"action":"create_refund","txn_id":"TXN...","amount":100,"reason":"Customer request"}', 'Amount optional — full refund if omitted'],
            ['list_refunds', 'List Refunds', '{"action":"list_refunds","limit":20}', 'Refund history'],
            ['list_payment_links', 'List Payment Links', '{"action":"list_payment_links","limit":20}', 'Includes view_count analytics'],
            ['get_payment_link', 'Get Payment Link', '{"action":"get_payment_link","link_id":"LNK..."}', 'Single link with payment_url'],
        ];
        foreach ($endpoints as [$action,$title,$body,$desc]):
        ?>
        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-1"><?= $title ?></h2>
            <p class="text-xs text-brand-400 font-mono mb-3">action: <?= $action ?></p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300"><?= e($body) ?></pre>
            <p class="text-sm text-gray-500 mt-2"><?= $desc ?></p>
        </div>
        <?php endforeach; ?>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Outbound Webhooks (to your server)</h2>
            <p class="text-sm text-gray-400 mb-3">Configure your webhook URL in Dashboard → API Settings. UniWeb POSTs JSON on payment events.</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs text-gray-300 overflow-x-auto">{
  "event": "payment.success",
  "timestamp": 1710000000,
  "data": { "txn_id": "TXN...", "amount": 500, "utr": "..." }
}</pre>
            <p class="text-sm text-gray-500 mt-3">Verify signature header <code class="text-gray-400">X-UniWeb-Signature</code>:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs text-gray-300 mt-2 overflow-x-auto">hash_hmac('sha256', $rawRequestBody, $your_webhook_signing_secret)</pre>
            <p class="text-xs text-gray-500 mt-2">Events: payment.success · payment.failed · refund.completed · webhook.test</p>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Gateway Webhooks (Admin)</h2>
            <ul class="text-sm font-mono text-gray-300 space-y-1">
                <li>Razorpay: <?= e(pgWebhookUrl('razorpay')) ?></li>
                <li>Cashfree: <?= e(pgWebhookUrl('cashfree')) ?></li>
                <li>PayU: <?= e(pgWebhookUrl('payu')) ?></li>
                <li>Axis VA: <?= e(axisWebhookUrl()) ?></li>
            </ul>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Response Codes</h2>
            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-brand-400 font-mono">200</span> — Success (<code class="text-gray-400">success: true</code>)</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">400</span> — Bad request (missing/invalid fields)</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">401</span> — Invalid or missing API key</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">403</span> — Test/live mode mismatch (use test key in Test Mode)</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">404</span> — Transaction or link not found</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">405</span> — Only POST is allowed</div>
            </div>
            <p class="text-xs text-gray-500 mt-3">All responses are JSON. Errors include an <code class="text-gray-400">error</code> string.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
