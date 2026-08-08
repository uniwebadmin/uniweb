<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'API Documentation';
require_once __DIR__ . '/header.php';
?>
<div class="pt-24 pb-16 w-full max-w-4xl mx-auto px-4 min-w-0">
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
                <li>Copy your API key (<code class="text-gray-300">uw_test_…</code> / <code class="text-gray-300">uw_live_…</code>) and secret (<code class="text-gray-300">uws_…</code>) from Dashboard → API Settings.</li>
                <li>POST JSON to <code class="text-gray-300"><?= APP_URL ?>/api/v1/</code> with headers <code class="text-gray-300">X-API-Key</code> and <code class="text-gray-300">X-API-Secret</code>.</li>
                <li>Add an <code class="text-gray-300">Idempotency-Key</code> header for write calls (<code class="text-gray-300">create_payment_link</code>, <code class="text-gray-300">create_refund</code>) so retries never double-charge.</li>
                <li>Create a payment link and redirect customers to <code class="text-gray-300">payment_url</code>.</li>
                <li>Configure your webhook URL to receive <code class="text-gray-300">payment.success</code> events.</li>
            </ol>
            <p class="text-xs text-gray-500 mb-2">Example — get wallet balance (read call, no idempotency key needed):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300">curl -X POST '<?= APP_URL ?>/api/v1/' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uw_test_your_key_here' \
  -H 'X-API-Secret: uws_your_secret_here' \
  -d '{"action":"get_balance"}'</pre>
            <p class="text-xs text-gray-500 mb-2 mt-4">Example — create payment link (write call, send a unique Idempotency-Key):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300">curl -X POST '<?= APP_URL ?>/api/v1/' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uw_test_your_key_here' \
  -H 'X-API-Secret: uws_your_secret_here' \
  -H 'Idempotency-Key: order-123-attempt-1' \
  -d '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}'</pre>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold text-brand-400 mb-3">Base URL &amp; Authentication</h2>
            <code class="block bg-dark-900 p-4 rounded-lg text-sm font-mono">POST <?= APP_URL ?>/api/v1/</code>
            <p class="text-xs text-gray-500 mt-2">Backward compatible — <code class="text-gray-400"><?= APP_URL ?>/api.php</code> also works. Version <code class="text-gray-400">v1</code> is included in every response as <code class="text-gray-400">api_version</code>.</p>
            <ul class="text-sm text-gray-400 mt-3 space-y-1.5">
                <li><code class="text-gray-300">X-API-Key</code> — your key, e.g. <code class="text-gray-400">uw_test_…</code> or <code class="text-gray-400">uw_live_…</code> <span class="text-gray-600">(required)</span></li>
                <li><code class="text-gray-300">X-API-Secret</code> — your secret, e.g. <code class="text-gray-400">uws_…</code> <span class="text-gray-600">(required)</span></li>
                <li><code class="text-gray-300">Idempotency-Key</code> — unique per write request <span class="text-gray-600">(required for create_payment_link &amp; create_refund)</span></li>
                <li><code class="text-gray-300">Content-Type: application/json</code></li>
            </ul>
            <p class="text-xs text-gray-500 mt-3">Test keys only work in Test Mode; live keys require completed KYC and an activated account. Rate limited — a <code class="text-gray-400">429</code> response includes a <code class="text-gray-400">Retry-After</code> header (seconds). If you send requests from a browser, allowlist your origin under Dashboard → API Settings.</p>
        </div>

        <?php
        $endpoints = [
            ['create_payment_link', 'Create Payment Link', '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}', 'Write call — send a unique Idempotency-Key header. Returns payment_url on success'],
            ['check_status', 'Check Status', '{"action":"check_status","txn_id":"TXN..."}', 'Transaction status and UTR'],
            ['list_transactions', 'List Transactions', '{"action":"list_transactions","limit":20,"offset":0}', 'Paginated — use limit (max 100) and offset. Response includes has_more, total_count.'],
            ['get_balance', 'Get Balance', '{"action":"get_balance"}', 'Collected, settled, available balance'],
            ['create_refund', 'Create Refund', '{"action":"create_refund","txn_id":"TXN...","amount":100,"reason":"Customer request"}', 'Write call — send a unique Idempotency-Key header. Amount optional — full refund if omitted'],
            ['list_refunds', 'List Refunds', '{"action":"list_refunds","limit":20,"offset":0}', 'Paginated — use limit (max 100) and offset. Response includes has_more, total_count.'],
            ['list_payment_links', 'List Payment Links', '{"action":"list_payment_links","limit":20,"offset":0}', 'Paginated — use limit (max 100) and offset. Includes view_count analytics. Response includes has_more, total_count.'],
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
            <h2 class="font-semibold mb-3">Sandbox / Test Mode</h2>
            <p class="text-sm text-gray-400 mb-3">Every merchant gets a Test Mode API key (<code class="text-gray-300">uw_test_…</code>) immediately after signup — no KYC needed. Test Mode lets you integrate and verify your code without real money.</p>
            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-emerald-400 font-medium">Test Mode</span><p class="text-xs text-gray-500 mt-1">No real charges. Payment links return a test checkout page. Webhooks fire with test data. Rate limited at 120 req/min.</p></div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-amber-400 font-medium">Live Mode</span><p class="text-xs text-gray-500 mt-1">Requires completed KYC + Live activation. Real money flows. Use <code class="text-gray-400">uw_live_…</code> keys.</p></div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Switch between Test and Live by using the respective API key. The mode is determined by the key prefix, not a separate header.</p>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Rate Limits</h2>
            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-brand-400 font-mono">120 req/min</span><p class="text-xs text-gray-500 mt-1">Per API credential. Burst up to 10 requests allowed.</p></div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-amber-400 font-mono">429 + Retry-After</span><p class="text-xs text-gray-500 mt-1">Returned when limit exceeded. <code class="text-gray-400">Retry-After</code> header gives wait time in seconds.</p></div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Use exponential backoff on 429 responses. Idempotency-Key ensures retries never double-charge.</p>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Response Codes</h2>
            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-brand-400 font-mono">200</span> — Success (<code class="text-gray-400">success: true</code>)</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">400</span> — Bad request (missing/invalid fields or JSON)</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">401</span> — Invalid or missing API key/secret</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">403</span> — Mode mismatch or origin not allowed</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">404</span> — Transaction or link not found</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-red-400 font-mono">405</span> — Only POST is allowed</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-amber-400 font-mono">409</span> — Idempotency-Key reused with a different payload</div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-amber-400 font-mono">429</span> — Rate limited (see <code class="text-gray-400">Retry-After</code>)</div>
            </div>
            <p class="text-xs text-gray-500 mt-3">All responses are JSON. Errors include an <code class="text-gray-400">error</code> string.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
