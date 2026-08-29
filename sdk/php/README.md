# UniWeb PHP SDK

Official PHP client for the [UniWeb Merchant API](https://uniweb.co.in/api_docs.php). UniWeb brand only — your keys talk to UniWeb; bank and payment-rail secrets stay on UniWeb.

## Requirements

- PHP 8.1+
- `ext-json`, `ext-curl`

## Get API keys

1. Sign up at [uniweb.co.in](https://uniweb.co.in/).
2. Open **Dashboard → API Settings**.
3. Copy your test key (`uw_test_…`) and secret (`uws_…`).

Test keys work immediately — no KYC. Live keys (`uw_live_…`) require completed KYC.

## Install

From this monorepo (until published on Packagist):

```bash
composer config repositories.uniweb-merchant-sdk path /path/to/uniweb1/sdk/php
composer require uniweb/merchant-sdk:*
```

Or add to `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "../uniweb1/sdk/php" }
  ],
  "require": {
    "uniweb/merchant-sdk": "*"
  }
}
```

Then run `composer update uniweb/merchant-sdk`.

## Quick start — create payment link

```php
<?php
require 'vendor/autoload.php';

use UniWeb\Client\Client;
use UniWeb\Client\ClientConfig;

$config = new ClientConfig(
    apiKey: 'uw_test_your_key_here',
    apiSecret: 'uws_your_secret_here',
    mode: ClientConfig::MODE_TEST,
);

$uniweb = new Client($config);

$link = $uniweb->createPaymentLink([
    'amount' => 500,
    'description' => 'Order #1001',
    'customer_phone' => '9876543210',
]);

header('Location: ' . $link['payment_url']);
exit;
```

Base URL defaults to `https://uniweb.co.in/api/v1/`. Override in `ClientConfig` only for local dev.

## Check payment status

```php
$status = $uniweb->checkStatus('TXN20260827123456');
echo $status['transaction']['status']; // pending | success | failed
```

## Create refund

```php
$refund = $uniweb->createRefund([
    'txn_id' => 'TXN20260827123456',
    'amount' => 100,
    'reason' => 'Customer request',
]);
// Omit amount for a full refund
```

## Verify webhook signature

UniWeb POSTs to your HTTPS URL with header `X-UniWeb-Signature`.

```php
use UniWeb\Client\Webhook;

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_UNIWEB_SIGNATURE'] ?? '';

if (!Webhook::verifySignature($raw, $sig, $signingSecret)) {
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
// $event['event'] — payment.success | payment.failed | refund.completed
http_response_code(200);
echo 'ok';
```

During secret rotation, pass the previous secret as the fourth argument to accept both for 48 hours.

## Configuration

| Option | Description |
|--------|-------------|
| `apiKey` | `uw_test_…` or `uw_live_…` from API Settings |
| `apiSecret` | `uws_…` paired secret |
| `mode` | `test` or `live` — must match key prefix |
| `baseUrl` | Default `https://uniweb.co.in/api/v1/` |

## Methods

All map to POST `action` values in [openapi.json](https://uniweb.co.in/openapi.json):

| Method | API action | Idempotency-Key |
|--------|------------|-----------------|
| `createPaymentLink($params)` | `create_payment_link` | Auto |
| `checkStatus($txnId)` | `check_status` | — |
| `createRefund($params)` | `create_refund` | Auto |
| `getBalance()` | `get_balance` | — |
| `listTransactions($filters)` | `list_transactions` | — |
| `listRefunds($filters)` | `list_refunds` | — |
| `listPaymentLinks($filters)` | `list_payment_links` | — |
| `getPaymentLink($linkId)` | `get_payment_link` | — |

Request fields: `amount`, `description`, `customer_phone`, `customer_name` (payment link); `txn_id`, `amount`, `reason` (refund).

## Errors

API failures throw `UniWeb\Client\Exception\ApiException` with:

- `errorCode` — stable machine id (e.g. `amount_out_of_range`)
- `httpStatus` — HTTP status code
- `getMessage()` — human-readable text

```php
use UniWeb\Client\Exception\ApiException;
use UniWeb\Client\Exception\AuthenticationException;
use UniWeb\Client\Exception\RateLimitException;

try {
    $link = $uniweb->createPaymentLink(['amount' => 500]);
} catch (AuthenticationException $e) {
    // missing_credentials, auth_failed
} catch (RateLimitException $e) {
    $retry = $e->retryAfterSeconds;
} catch (ApiException $e) {
    $code = $e->errorCode;
}
```

The SDK never logs your API secret.

## Tests (no live API)

```bash
php sdk/php/tests/RequestShapeTest.php
```

## Source

Monorepo: [github.com/uniwebadmin/uniweb/tree/main/sdk/php](https://github.com/uniwebadmin/uniweb/tree/main/sdk/php)
