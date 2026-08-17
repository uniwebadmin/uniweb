# Add Partner Playbook

When Owner signs a new bank or PA (Payment Aggregator), the developer adds them using **only data + adapter code**. No new admin application. No `admin_partner_<name>.php` one-off page.

## Steps

### 0. Add partner from Registry UI (control-plane registration)

**Owner / Super-Admin can do this without a developer:**

1. Go to `admin_gateway_registry.php` → "Register Custom Gateway" section at bottom
2. Enter Partner Key (slug: `lowercase_letters_numbers_underscore`, 2–40 chars) and Display Name
3. Click "Register" → partner is created as **INACTIVE** (`is_active = 0`) with all methods **disabled**
4. Redirected to `admin_gateway_detail.php?partner={key}` — shared Detail page opens with tabs (Keys, Methods, Webhooks, Test, Logs)
5. Paste API keys → enable methods → Activate when ready

**What works without code:** registry row, method toggles, credential storage (encrypted), webhook URL display, audit logging.
**What still needs a developer:** checkout adapter (`includes/{partner}.php`) + webhook file (`{partner}_webhook.php`) for live payment routing. Until adapter exists, `get_available_pay_methods()` will not return methods for this partner even if enabled — because `isGatewayConfigured()` returns false (no credentials pattern match) and `isPartnerChargeable()` returns false (no chargeable path).

### 1. Insert row in `gateway_registry` (partners table)

```sql
INSERT INTO gateway_registry (gateway_key, gateway_name, is_active, supports_collection, supports_payout, supports_refund, supports_recurring, sort_order)
VALUES ('newpartner', 'New Partner Name', 0, 1, 1, 0, 0, 99);
```

- `is_active = 0` (inactive until keys + Owner commercial OK)
- `sort_order = 99` (appears last in registry; change to reorder)

### 2. Register in `getPartnerRegistry()` — `includes/partner_engine.php`

Add a new entry in the `$registry` array:

```php
'newpartner' => [
    'name' => 'New Partner Name',
    'type' => 'gateway', // or 'banking'
    'icon' => '🔗',
    'color' => 'sky',
    'use' => 'Collections + Split',
    'signup' => 'https://partner-portal-url',
    'docs' => 'https://docs.partner.com',
    'dashboard' => 'https://dashboard.partner.com',
    'email' => 'partner@api.partner.com',
    'admin_page' => 'admin_gateway_detail.php?partner=newpartner',
    'webhook' => APP_URL . '/newpartner_webhook.php',
    'env_key' => 'newpartner_environment',
    'config_keys' => [
        'newpartner_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'live' => 'Live']],
        'newpartner_api_key' => ['label' => 'API Key', 'type' => 'text'],
        'newpartner_api_secret' => ['label' => 'API Secret', 'type' => 'password'],
    ],
    'checklist' => [
        'Sign up on partner portal',
        'Enable required products (collections, split, etc.)',
        'Paste test keys — verify connection',
        'Production keys after partner call',
    ],
],
```

`seedPartnerMethods()` will auto-create `partner_methods` rows (all disabled).

### 3. Credential slots in `partner_credentials`

Handled automatically by `savePartnerCredentials()` in `includes/partner_control.php`. Keys are encrypted at rest via `sensitiveEncrypt()`. Only last4 is shown in UI.

### 4. Implement adapter

Create `includes/newpartner.php` with these functions (follow existing pattern in `includes/gateways.php`):

```php
// Test connection — called from admin_gateway_detail.php Test tab
function newpartnerTestConnection(): array {
    // Make a simple API call (e.g., get profile/balance)
    // Return ['ok' => bool, 'message' => string]
}

// Create order / pay — called from checkout.php
function newpartnerCreateOrder(float $amount, array $link, string $returnUrl): array {
    // Create payment order with partner
    // Return partner-specific order data
}

// Webhook verify + normalize — called from newpartner_webhook.php
function verifyNewpartnerWebhookSignature(string $rawBody, string $signature): bool {
    // Verify HMAC or other signature
}
```

Register the adapter in `includes/gateways.php` if it participates in checkout routing.

### 5. Webhook file

Create `newpartner_webhook.php` in repo root. Follow the pattern of `razorpay_webhook.php`:

```php
<?php
require_once __DIR__ . '/config.php';
if (!function_exists('recordWebhookEvent')) {
    require_once __DIR__ . '/includes/webhook_reliability.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('newpartner');
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_NEWPARTNER_SIGNATURE'] ?? '';

if (!verifyNewpartnerWebhookSignature($raw, $signature)) {
    if (financialTablesReady()) {
        registerGatewayEvent('newpartner', $_SERVER['HTTP_X_EVENT_ID'] ?? '', 'unknown', $raw, false);
    }
    logPgWebhook('newpartner', 'invalid_signature', null, null, null, '');
    jsonResponse(['error' => 'Invalid signature'], 401);
}

// Parse payload, normalize events, call captureVerifiedPaymentOrder or refund handler
// Use registerGatewayEvent() for idempotency
```

### 6. Partner appears automatically

- **Registry**: `admin_gateway_registry.php` — partner card appears automatically from `getPartnerRegistry()`
- **Configure**: Click "Configure" → opens `admin_gateway_detail.php?partner=newpartner` (same UI for all partners)
- **No one-off admin page needed**

### 7. Enable partner + methods

In `admin_gateway_detail.php`:
- Paste test keys → Save
- Click "Activate" to activate partner
- Go to "Methods" tab → toggle methods on/off
- Set priority (lower = preferred; default 50)
- Set min/max amount limits if needed

### 8. Add fail codes to `gateway_reason_maps`

In `admin_gateway_detail.php` → "Logs" tab → "Reason Maps" section:
- Add partner-specific failure codes with EN + HI messages
- These appear in merchant transaction list and customer payment status

### 9. Smoke test

1. Test connection: `admin_gateway_detail.php?partner=newpartner` → Test tab → verify "OK"
2. Create a test payment link → checkout → verify partner method appears
3. Complete test payment → verify webhook reaches server → transaction captured
4. Disable a method → verify it disappears from checkout
5. Check circuit breaker: `admin_circuit_breaker.php` shows partner status

## Existing partners following this path

All current partners use the same `admin_gateway_detail.php` UI:
- Razorpay, Cashfree, PayU, PhonePe, PineLabs, Worldline (gateways)
- Axis Bank, RBL Bank, Decentro (banking)

`admin_partner_decentro.php` is a **redirect only** → `admin_gateway_detail.php?partner=decentro`
`admin_axis.php` is a **testing utility** (test token, create test VA) — not the primary config UI
`admin_partner.php` is a **legacy detail page** — still works but `admin_gateway_detail.php` is the primary

## Multi-partner runtime rules

### Checkout method selection
- `get_available_pay_methods($merchantId)` in `includes/payment_methods.php` is the single source of truth
- It checks: merchant onboarding state → merchant-enabled methods → gateway active in registry → partner method enabled → credentials present → partner chargeable
- No hardcoded gateway in checkout — all enabled partners/methods appear

### Priority / routing when two partners enable same method
- `partner_methods.priority` field (default 50, lower = preferred)
- `createCardOrderWithSmartRouting()` in `includes/smart_routing.php` tries preferred gateway first, falls back to secondary on failure
- Health-based preference: `isGatewayHealthy()` checks last 3 outcomes in 10 min window
- Circuit breaker: `isCircuitBreakerAllowed()` skips gateways with open circuits (5 failures in 5 min → 60s cooldown)
- **No ML routing** — simple priority + health + circuit breaker

### Circuit breaker
- If a partner is in `open` state (5 consecutive failures), it is skipped automatically
- Admin can manually reset circuit at `admin_circuit_breaker.php`
- If no healthy partner is available, checkout shows error to customer
