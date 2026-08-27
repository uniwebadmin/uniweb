# UniWeb PHP SDK

Official PHP client for the [UniWeb Merchant API](https://uniweb.co.in/api_docs.php). UniWeb brand only — your keys talk to UniWeb; bank/PG secrets stay on UniWeb.

## Requirements

- PHP 8.1+
- `ext-json`, `ext-curl`

## Install

From this repository (until published on Packagist):

```bash
composer require uniweb/merchant-sdk:@dev
```

Or add to `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "vendor-path/uniweb1/sdk/php" }
  ],
  "require": {
    "uniweb/merchant-sdk": "*"
  }
}
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

use UniWeb\Client\Client;
use UniWeb\Client\ClientConfig;
use UniWeb\Client\Webhook;

$config = new ClientConfig(
    apiKey: 'uw_test_your_key',
    apiSecret: 'uws_your_secret',
    mode: ClientConfig::MODE_TEST,
    baseUrl: 'https://uniweb.co.in/api/v1/',
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

## Example 2 — Check payment status

```php
$status = $uniweb->checkStatus('TXN20260827123456');
echo $status['transaction']['status']; // pending | success | failed
```

## Example 3 — Verify webhook signature

```php
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_UNIWEB_SIGNATURE'] ?? '';

if (!Webhook::verifySignature($raw, $sig, $signingSecret)) {
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
// $event['event'] === payment.success | payment.failed | refund.completed
```

## Configuration

| Option | Description |
|--------|-------------|
| `apiKey` | `uw_test_…` or `uw_live_…` from Dashboard → API Settings |
| `apiSecret` | `uws_…` paired secret |
| `mode` | `test` or `live` — must match key prefix |
| `baseUrl` | Default `https://uniweb.co.in/api/v1/` |

## Methods

- `createPaymentLink(array $params)` — sends `Idempotency-Key` automatically
- `checkStatus(string $txnId)`
- `createRefund(array $params)` — sends `Idempotency-Key` automatically
- `getBalance()`
- `listTransactions(array $filters = [])`
- `listRefunds`, `listPaymentLinks`, `getPaymentLink`

## Errors

API failures throw `UniWeb\Client\Exception\ApiException` with:

- `errorCode` — stable machine id (e.g. `amount_out_of_range`)
- `httpStatus` — HTTP status code
- `getMessage()` — human-readable text

Special types: `AuthenticationException`, `RateLimitException` (`retryAfterSeconds` when present).

The SDK never logs your API secret.

## Source

Monorepo path: `sdk/php/` — [github.com/uniwebadmin/uniweb](https://github.com/uniwebadmin/uniweb/tree/main/sdk/php)
