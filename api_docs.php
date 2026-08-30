<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/includes/merchant_api_errors.php')) {
    require_once __DIR__ . '/includes/merchant_api_errors.php';
}
$pageTitle = 'UniWeb Merchant API';
$apiBase = APP_URL . '/api/v1/';
$apiErrorCatalog = function_exists('merchantApiErrorCatalog') ? merchantApiErrorCatalog() : [];
require_once __DIR__ . '/header.php';
?>
<div class="pt-24 pb-16 w-full max-w-4xl mx-auto px-4 min-w-0">
    <h1 class="text-3xl font-bold mb-2">UniWeb Merchant API</h1>
    <p class="text-gray-400 mb-4">Collect payments on your website or app. Create payment links, redirect customers to UniWeb checkout, poll status, and receive signed webhooks — all with your UniWeb merchant keys. Bank and payment-rail credentials stay on UniWeb; you never handle partner secrets.</p>
    <div class="flex flex-wrap gap-3 mb-6">
        <a href="<?= e(APP_URL) ?>/openapi.json" class="inline-flex items-center gap-2 text-sm text-sky-400 hover:underline" target="_blank" rel="noopener">OpenAPI 3.0 (openapi.json) →</a>
        <?php if (isLoggedIn()): ?>
        <a href="api_settings.php" class="inline-flex items-center gap-2 text-sm text-emerald-400 hover:underline">Your API Keys &amp; Webhooks →</a>
        <?php else: ?>
        <a href="login.php" class="inline-flex items-center gap-2 text-sm text-emerald-400 hover:underline">Login for API Keys →</a>
        <?php endif; ?>
    </div>

    <nav class="glass rounded-xl p-4 mb-8 text-xs text-gray-400 border border-gray-800">
        <span class="text-gray-300 font-medium">On this page:</span>
        <a href="#auth" class="ml-3 hover:text-brand-400">Authentication</a> ·
        <a href="#create-payment" class="hover:text-brand-400">Create payment</a> ·
        <a href="#check-status" class="hover:text-brand-400">Status</a> ·
        <a href="#webhooks" class="hover:text-brand-400">Webhooks</a> ·
        <a href="#sdk-libraries" class="hover:text-brand-400">SDK Libraries</a> ·
        <a href="#errors" class="hover:text-brand-400">Error codes</a> ·
        <a href="#test-live" class="hover:text-brand-400">Test vs Live</a> ·
        <a href="#endpoints" class="hover:text-brand-400">All endpoints</a>
    </nav>

    <div class="space-y-6">
        <div class="glass rounded-xl p-6 border border-brand-500/20">
            <h2 class="font-semibold text-brand-400 mb-3">Quick Start</h2>
            <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside mb-4">
                <li>Sign up and copy your API key (<code class="text-gray-300">uw_test_…</code> or <code class="text-gray-300">uw_live_…</code>) and secret (<code class="text-gray-300">uws_…</code>) from Dashboard → API Settings.</li>
                <li>POST JSON to <code class="text-gray-300"><?= e($apiBase) ?></code> with headers <code class="text-gray-300">X-API-Key</code>, <code class="text-gray-300">X-API-Secret</code>, and <code class="text-gray-300">Content-Type: application/json</code>.</li>
                <li>For write calls, add a unique <code class="text-gray-300">Idempotency-Key</code> header so retries never double-charge.</li>
                <li>Redirect the customer to the returned <code class="text-gray-300">payment_url</code> (UniWeb hosted checkout).</li>
                <li>Configure your webhook URL and verify <code class="text-gray-300">X-UniWeb-Signature</code> on every delivery.</li>
            </ol>
        </div>

        <div class="glass rounded-xl p-6" id="auth">
            <h2 class="font-semibold text-brand-400 mb-3">Authentication</h2>
            <code class="block bg-dark-900 p-4 rounded-lg text-sm font-mono mb-3">POST <?= e($apiBase) ?></code>
            <p class="text-xs text-gray-500 mb-4">Legacy alias <code class="text-gray-400"><?= e(APP_URL) ?>/api.php</code> accepts the same requests. Every JSON response includes <code class="text-gray-400">api_version: "v1"</code>.</p>
            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm text-left">
                    <thead><tr class="text-gray-500 border-b border-gray-800"><th class="py-2 pr-4">Header</th><th class="py-2 pr-4">Required</th><th class="py-2">Description</th></tr></thead>
                    <tbody class="text-gray-400">
                        <tr class="border-b border-gray-800/60"><td class="py-2 pr-4 font-mono text-gray-300">X-API-Key</td><td class="py-2 pr-4">Yes</td><td class="py-2">Merchant API key — <code class="text-gray-400">uw_test_…</code> (sandbox) or <code class="text-gray-400">uw_live_…</code> (live)</td></tr>
                        <tr class="border-b border-gray-800/60"><td class="py-2 pr-4 font-mono text-gray-300">X-API-Secret</td><td class="py-2 pr-4">Yes</td><td class="py-2">Paired secret — <code class="text-gray-400">uws_…</code></td></tr>
                        <tr class="border-b border-gray-800/60"><td class="py-2 pr-4 font-mono text-gray-300">Idempotency-Key</td><td class="py-2 pr-4">Write calls</td><td class="py-2">Unique string (max 100 chars) for <code class="text-gray-400">create_payment_link</code> and <code class="text-gray-400">create_refund</code></td></tr>
                        <tr><td class="py-2 pr-4 font-mono text-gray-300">Content-Type</td><td class="py-2 pr-4">Yes</td><td class="py-2"><code class="text-gray-400">application/json</code></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Mode is determined by the key prefix — not a separate header. Test keys only return test data and test checkout; live keys require completed KYC and live activation. Browser calls must allowlist your origin under API Settings.</p>
        </div>

        <div class="glass rounded-xl p-6 border border-emerald-500/20" id="create-payment">
            <h2 class="font-semibold text-emerald-400 mb-1">Create Payment Link</h2>
            <p class="text-xs text-brand-400 font-mono mb-3">action: create_payment_link · scope: links:write · Idempotency-Key required</p>
            <p class="text-sm text-gray-400 mb-3">Creates a one-time payment link. Redirect the customer to <code class="text-gray-300">payment_url</code> — UniWeb hosted checkout handles UPI, cards, and netbanking. No partner-branded buttons are shown to your customer.</p>
            <p class="text-xs text-gray-500 mb-1">Request body:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">{
  "action": "create_payment_link",
  "amount": 500,
  "description": "Order #123",
  "customer_phone": "9876543210",
  "customer_name": "Rahul Sharma"
}</pre>
            <p class="text-xs text-gray-500 mb-1">cURL:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">curl -X POST '<?= e($apiBase) ?>' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uw_test_your_key_here' \
  -H 'X-API-Secret: uws_your_secret_here' \
  -H 'Idempotency-Key: order-123-attempt-1' \
  -d '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}'</pre>
            <p class="text-xs text-gray-500 mb-1">Success response (HTTP 200):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">{
  "success": true,
  "api_version": "v1",
  "mode": "test",
  "link_id": "LNK20260826123456",
  "payment_url": "<?= e(APP_URL) ?>/checkout.php?link=LNK20260826123456",
  "amount": 500,
  "expires_at": "2026-08-27 18:30:00"
}</pre>
            <p class="text-xs text-gray-500 mb-1">Error example (HTTP 400):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300">{
  "success": false,
  "error_code": "amount_out_of_range",
  "error": "Amount must be between 1 and 200000000.",
  "api_version": "v1"
}</pre>
            <p class="text-xs text-gray-500 mt-3">Fields: <code class="text-gray-400">amount</code> (required, INR, 1–200000000) · <code class="text-gray-400">description</code> (optional, max 255) · <code class="text-gray-400">customer_phone</code> / <code class="text-gray-400">customer_name</code> (optional). Links expire after 24 hours.</p>
        </div>

        <div class="glass rounded-xl p-6" id="check-status">
            <h2 class="font-semibold text-brand-400 mb-1">Check Payment Status</h2>
            <p class="text-xs text-brand-400 font-mono mb-3">action: check_status · scope: transactions:read</p>
            <p class="text-sm text-gray-400 mb-3">Poll a transaction after checkout. Use the <code class="text-gray-300">txn_id</code> from webhooks or your dashboard. Status is scoped to your API key mode (test vs live).</p>
            <p class="text-xs text-gray-500 mb-1">Request body:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">{
  "action": "check_status",
  "txn_id": "TXN20260826123456"
}</pre>
            <p class="text-xs text-gray-500 mb-1">cURL:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">curl -X POST '<?= e($apiBase) ?>' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: uw_test_your_key_here' \
  -H 'X-API-Secret: uws_your_secret_here' \
  -d '{"action":"check_status","txn_id":"TXN20260826123456"}'</pre>
            <p class="text-xs text-gray-500 mb-1">Success response (HTTP 200):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">{
  "success": true,
  "api_version": "v1",
  "transaction": {
    "txn_id": "TXN20260826123456",
    "amount": "500.00",
    "status": "success",
    "payment_method": "upi",
    "utr": "123456789012",
    "created_at": "2026-08-26 18:05:22"
  }
}</pre>
            <p class="text-xs text-gray-500">Typical <code class="text-gray-400">status</code> values: <code class="text-gray-400">pending</code>, <code class="text-gray-400">success</code>, <code class="text-gray-400">failed</code>. Prefer webhooks for real-time updates; use polling as a fallback.</p>
        </div>

        <div class="glass rounded-xl p-6 border border-sky-500/20" id="webhooks">
            <h2 class="font-semibold text-sky-400 mb-2">Outbound Webhooks</h2>
            <p class="text-sm text-gray-400 mb-3">Set your HTTPS webhook URL and signing secret in Dashboard → API Settings. UniWeb POSTs JSON to your server when payments complete. Always verify the HMAC signature — never trust the body alone.</p>
            <p class="text-xs text-gray-500 mb-3">When you rotate your signing secret in API Settings, UniWeb keeps the previous secret valid for 48 hours so deliveries in flight still verify. Update your server to accept both secrets during that window, then drop the old one.</p>
            <p class="text-xs text-gray-500 mb-1">Delivery headers:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">Content-Type: application/json
X-UniWeb-Event: payment.success
X-UniWeb-Event-Id: EVT20260826123456
X-UniWeb-Signature: &lt;hmac-sha256-hex-of-raw-body&gt;
User-Agent: UniWeb-Webhook/1.0</pre>
            <p class="text-xs text-gray-500 mb-1">Payload body:</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">{
  "id": "EVT20260826123456",
  "event": "payment.success",
  "created_at": "2026-08-26T12:34:56+00:00",
  "data": {
    "txn_id": "TXN20260826123456",
    "amount": 500,
    "status": "success",
    "payment_method": "upi",
    "utr": "123456789012",
    "link_id": "LNK20260826123456"
  }
}</pre>
            <p class="text-xs text-gray-500 mb-1">Events: <code class="text-gray-400">payment.success</code> · <code class="text-gray-400">payment.failed</code> · <code class="text-gray-400">refund.completed</code> · <code class="text-gray-400">webhook.test</code> (from API Settings → Send Test Webhook).</p>
            <p class="text-xs text-gray-500 mb-1">Verify signature (PHP — copy-paste):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">&lt;?php
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_UNIWEB_SIGNATURE'] ?? '';
$signingSecret = 'your_webhook_signing_secret'; // from API Settings

$expected = hash_hmac('sha256', $raw, $signingSecret);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($raw, true);
// Handle $event['event'] and $event['data']
http_response_code(200);
echo 'ok';</pre>
            <p class="text-xs text-gray-500 mb-1">Verify signature (Node.js):</p>
            <pre class="bg-dark-900 p-4 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">const crypto = require('crypto');

function verifyUniWebWebhook(rawBody, signature, signingSecret) {
  const expected = crypto.createHmac('sha256', signingSecret).update(rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature || ''));
}

// Express example: use express.raw({ type: 'application/json' }) for the route
// const ok = verifyUniWebWebhook(req.body, req.get('X-UniWeb-Signature'), process.env.UNIWEB_WEBHOOK_SECRET);</pre>
            <p class="text-xs text-gray-500 mt-2">Failed deliveries retry with exponential backoff (up to 8 attempts). Return HTTP 2xx quickly; process asynchronously if needed. <code class="text-gray-400">X-UniWeb-Event-Id</code> is stable across retries — use it for idempotent handling on your side.</p>
        </div>

        <div class="glass rounded-xl p-6 border border-violet-500/20" id="sdk-libraries">
            <h2 class="font-semibold text-violet-300 mb-2">SDK Libraries</h2>
            <p class="text-sm text-gray-400 mb-3">Official UniWeb client libraries — same Merchant API as above, UniWeb brand only. No partner SDK wrappers; your customer never sees bank/PG product names.</p>
            <p class="text-xs text-gray-500 mb-4">Quick start: copy <code class="text-gray-400">uw_test_…</code> + <code class="text-gray-400">uws_…</code> from <?php if (isLoggedIn()): ?><a href="api_settings.php" class="text-emerald-400 hover:underline">API Settings</a><?php else: ?><a href="login.php" class="text-emerald-400 hover:underline">Dashboard → API Settings</a><?php endif; ?> → install an SDK below → call <code class="text-gray-400">createPaymentLink</code> → redirect to <code class="text-gray-400">payment_url</code>. Full README: <a href="https://github.com/uniwebadmin/uniweb/tree/main/sdk/php" class="text-sky-400 hover:underline" target="_blank" rel="noopener">sdk/php</a> · <a href="https://github.com/uniwebadmin/uniweb/tree/main/sdk/node" class="text-sky-400 hover:underline" target="_blank" rel="noopener">sdk/node</a>.</p>
            <div class="grid sm:grid-cols-2 gap-4 mb-6 text-sm min-w-0">
                <div class="rounded-lg border border-gray-800 p-4 min-w-0 max-w-full overflow-hidden">
                    <h3 class="font-semibold text-brand-400 mb-2">PHP SDK</h3>
                    <p class="text-xs text-gray-500 mb-2">Package: <code class="text-gray-300">uniweb/merchant-sdk</code></p>
                    <p class="text-xs text-gray-500 mb-3">Monorepo: <a href="https://github.com/uniwebadmin/uniweb/tree/main/sdk/php" class="text-sky-400 hover:underline" target="_blank" rel="noopener">github.com/uniwebadmin/uniweb/sdk/php</a></p>
                    <p class="text-xs text-gray-500 mb-1">Install (path — until Packagist publish):</p>
                    <pre class="api-pre bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 mb-3 max-w-full">composer config repositories.uniweb-merchant-sdk path ../uniweb/sdk/php
composer require uniweb/merchant-sdk:*</pre>
                    <p class="text-xs text-gray-500 mb-1">Create payment link:</p>
                    <pre class="api-pre bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 max-w-full">use UniWeb\Client\Client;
use UniWeb\Client\ClientConfig;

$uniweb = new Client(new ClientConfig(
    apiKey: 'uw_test_your_key_here',
    apiSecret: 'uws_your_secret_here',
    mode: ClientConfig::MODE_TEST,
));

$link = $uniweb->createPaymentLink([
    'amount' => 500,
    'description' => 'Order #123',
    'customer_phone' => '9876543210',
]);
header('Location: ' . $link['payment_url']);</pre>
                </div>
                <div class="rounded-lg border border-gray-800 p-4 min-w-0 max-w-full overflow-hidden">
                    <h3 class="font-semibold text-brand-400 mb-2">Node.js SDK</h3>
                    <p class="text-xs text-gray-500 mb-2">Package: <code class="text-gray-300">uniweb</code></p>
                    <p class="text-xs text-gray-500 mb-3">Monorepo: <a href="https://github.com/uniwebadmin/uniweb/tree/main/sdk/node" class="text-sky-400 hover:underline" target="_blank" rel="noopener">github.com/uniwebadmin/uniweb/sdk/node</a></p>
                    <p class="text-xs text-gray-500 mb-1">Install from Git (dist included):</p>
                    <pre class="api-pre bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 mb-3 max-w-full">npm install github:uniwebadmin/uniweb#main:sdk/node
# Local checkout: npm install /path/to/uniweb1/sdk/node</pre>
                    <p class="text-xs text-gray-500 mb-1">Create payment link:</p>
                    <pre class="api-pre bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 max-w-full">import { Client } from 'uniweb';

const uniweb = new Client({
  apiKey: 'uw_test_your_key_here',
  apiSecret: 'uws_your_secret_here',
  mode: 'test',
});

const link = await uniweb.createPaymentLink({
  amount: 500,
  description: 'Order #123',
  customer_phone: '9876543210',
});
console.log(link.payment_url);</pre>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-3">Methods (POST body <code class="text-gray-400">action</code> matches <a href="<?= e(APP_URL) ?>/openapi.json" class="text-sky-400 hover:underline" target="_blank" rel="noopener">openapi.json</a>): <code class="text-gray-400">createPaymentLink</code>, <code class="text-gray-400">checkStatus</code>, <code class="text-gray-400">createRefund</code>, <code class="text-gray-400">getBalance</code>, <code class="text-gray-400">listTransactions</code>, <code class="text-gray-400">listRefunds</code>, <code class="text-gray-400">listPaymentLinks</code>, <code class="text-gray-400">getPaymentLink</code>. Write calls send a unique <code class="text-gray-400">Idempotency-Key</code> header automatically. The SDK never logs your API secret.</p>
            <p class="text-xs text-gray-500 mb-1">Error handling — read stable <code class="text-gray-400">error_code</code>:</p>
            <pre class="bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">// PHP
try {
    $link = $uniweb->createPaymentLink(['amount' => 500]);
} catch (\UniWeb\Client\Exception\ApiException $e) {
    // $e->errorCode e.g. amount_out_of_range
}

// Node
try {
  await uniweb.createPaymentLink({ amount: 500 });
} catch (err) {
  if (err.errorCode) console.error(err.errorCode);
}</pre>
            <p class="text-xs text-gray-500 mb-1">Webhook verify — header <code class="text-gray-400">X-UniWeb-Signature</code> = HMAC-SHA256(raw JSON body, signing secret from API Settings):</p>
            <pre class="bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300 mb-4">// PHP SDK
use UniWeb\Client\Webhook;
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_UNIWEB_SIGNATURE'] ?? '';
if (!Webhook::verifySignature($raw, $sig, $signingSecret)) {
    http_response_code(401); exit;
}

// Node SDK — use raw body string, not parsed JSON
import { verifySignature } from 'uniweb';
const ok = verifySignature(rawBody, req.headers['x-uniweb-signature'], signingSecret);</pre>
            <p class="text-xs text-gray-500">During signing-secret rotation, pass the previous secret as the optional fourth argument (PHP) or third optional param (Node) for a 48-hour grace window — same as the raw HMAC examples in <a href="#webhooks" class="text-sky-400 hover:underline">Webhooks</a> above.</p>
        </div>

        <div class="glass rounded-xl p-6" id="test-live">
            <h2 class="font-semibold mb-3">Test vs Live Mode</h2>
            <div class="grid sm:grid-cols-2 gap-3 text-sm mb-4">
                <div class="rounded-lg border border-emerald-500/30 p-4">
                    <span class="text-emerald-400 font-medium">Test Mode</span>
                    <ul class="text-xs text-gray-500 mt-2 space-y-1 list-disc list-inside">
                        <li>Key prefix: <code class="text-gray-400">uw_test_…</code></li>
                        <li>Available immediately after signup — no KYC</li>
                        <li>No real money; checkout shows UniWeb Test Mode banner</li>
                        <li>Webhooks fire with test transactions</li>
                        <li>Rate limit: 120 requests/minute</li>
                    </ul>
                </div>
                <div class="rounded-lg border border-amber-500/30 p-4">
                    <span class="text-amber-400 font-medium">Live Mode</span>
                    <ul class="text-xs text-gray-500 mt-2 space-y-1 list-disc list-inside">
                        <li>Key prefix: <code class="text-gray-400">uw_live_…</code></li>
                        <li>Requires completed KYC + live activation</li>
                        <li>Real INR settlement through UniWeb rails</li>
                        <li>Separate webhook deliveries and transaction scope</li>
                        <li>Never mix test keys with live customer flows</li>
                    </ul>
                </div>
            </div>
            <p class="text-xs text-gray-500">A test key cannot capture live payments. Responses include <code class="text-gray-400">"mode": "test"</code> or live-scoped data matching your key. Rotate keys from API Settings if compromised.</p>
        </div>

        <div class="glass rounded-xl p-6" id="errors">
            <h2 class="font-semibold mb-3">Error Codes</h2>
            <p class="text-sm text-gray-400 mb-4">Every error response is JSON with stable <code class="text-gray-300">error_code</code> (for your code) and human-readable <code class="text-gray-300">error</code> (for logs). HTTP status matches the severity.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead><tr class="text-gray-500 border-b border-gray-800"><th class="py-2 pr-3">error_code</th><th class="py-2 pr-3">HTTP</th><th class="py-2">Message</th></tr></thead>
                    <tbody class="text-gray-400 font-mono">
                        <?php foreach ($apiErrorCatalog as $code => $meta): ?>
                        <tr class="border-b border-gray-800/60">
                            <td class="py-2 pr-3 text-brand-400"><?= e($code) ?></td>
                            <td class="py-2 pr-3"><?= (int)$meta['http'] ?></td>
                            <td class="py-2 font-sans"><?= e($meta['message']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 mt-4">HTTP summary: <code class="text-gray-400">200</code> success · <code class="text-gray-400">400</code> bad request (incl. <code class="text-gray-400">missing_idempotency_key</code>) · <code class="text-gray-400">401</code> auth (<code class="text-gray-400">auth_failed</code> = your API key; <code class="text-gray-400">auth_invalid</code> = partner rejected refund) · <code class="text-gray-400">403</code> mode/origin · <code class="text-gray-400">404</code> not found · <code class="text-gray-400">405</code> method · <code class="text-gray-400">409</code> idempotency conflict · <code class="text-gray-400">429</code> rate limit (see <code class="text-gray-400">Retry-After</code> header, seconds) · <code class="text-gray-400">500</code> internal · <code class="text-gray-400">503</code> partner unavailable.</p>
        </div>

        <div class="glass rounded-xl p-6" id="endpoints">
            <h2 class="font-semibold mb-3">All Endpoints</h2>
            <p class="text-xs text-gray-500 mb-4">All actions use POST to the same URL with an <code class="text-gray-400">action</code> field in the JSON body.</p>
            <?php
            $endpoints = [
                ['create_payment_link', 'Create Payment Link', '{"action":"create_payment_link","amount":500,"description":"Order #123","customer_phone":"9876543210"}', 'Write — Idempotency-Key required. Returns payment_url.', '#create-payment'],
                ['check_status', 'Check Status', '{"action":"check_status","txn_id":"TXN..."}', 'Read single transaction by txn_id.', '#check-status'],
                ['list_transactions', 'List Transactions', '{"action":"list_transactions","from":"2026-08-01","to":"2026-08-15","limit":20,"offset":0}', 'Optional date range (YYYY-MM-DD). Paginated — limit max 100.'],
                ['get_balance', 'Get Balance', '{"action":"get_balance"}', 'Collected and available settlement balance for current mode.'],
                ['create_refund', 'Create Refund', '{"action":"create_refund","txn_id":"TXN...","amount":100,"reason":"Customer request"}', 'Write — Idempotency-Key required. Omit amount for full refund.'],
                ['list_refunds', 'List Refunds', '{"action":"list_refunds","limit":20,"offset":0}', 'Paginated refund history.'],
                ['list_payment_links', 'List Payment Links', '{"action":"list_payment_links","limit":20,"offset":0}', 'Paginated links with view_count.'],
                ['get_payment_link', 'Get Payment Link', '{"action":"get_payment_link","link_id":"LNK..."}', 'Single link including payment_url.'],
            ];
            foreach ($endpoints as $ep):
                $action = $ep[0];
                $title = $ep[1];
                $body = $ep[2];
                $desc = $ep[3];
                $anchor = $ep[4] ?? '';
            ?>
            <div class="mb-5 pb-5 border-b border-gray-800 last:border-0 last:mb-0 last:pb-0">
                <h3 class="font-medium text-sm mb-1"><?= e($title) ?><?php if ($anchor): ?> <a href="<?= e($anchor) ?>" class="text-xs text-sky-500 hover:underline">↑</a><?php endif; ?></h3>
                <p class="text-xs text-brand-400 font-mono mb-2">action: <?= e($action) ?></p>
                <pre class="bg-dark-900 p-3 rounded-lg text-xs overflow-x-auto text-gray-300"><?= e($body) ?></pre>
                <p class="text-xs text-gray-500 mt-2"><?= e($desc) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-3">Rate Limits &amp; Idempotency</h2>
            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-brand-400 font-mono">120 req/min</span><p class="text-xs text-gray-500 mt-1">Per API credential. Short burst allowed.</p></div>
                <div class="rounded-lg border border-gray-800 p-3"><span class="text-amber-400 font-mono">429 + Retry-After</span><p class="text-xs text-gray-500 mt-1">Use exponential backoff on rate-limit responses.</p></div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Reusing the same Idempotency-Key with an identical body returns the original response. Reusing with a different body returns <code class="text-gray-400">409 idempotency_conflict</code>. Omitting the header on write actions returns <code class="text-gray-400">400 missing_idempotency_key</code>. Keys are scoped per merchant + mode and stored for 72 hours in <code class="text-gray-400">api_idempotency_keys</code>.</p>
        </div>

        <div class="glass rounded-xl p-6 border border-amber-500/20">
            <h2 class="font-semibold mb-2">Merchant onboarding API</h2>
            <p class="text-sm text-gray-400">Written exception (parked): there is <strong class="text-gray-300">no public REST</strong> action to create a merchant or poll KYC status. Onboarding stays on the UniWeb website — signup, admin invite, and KYC UI. When a named bank or fintech deal requires programmatic onboarding, this page and OpenAPI will be extended.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
