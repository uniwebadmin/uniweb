# UniWeb Node.js SDK

Official Node.js client for the [UniWeb Merchant API](https://uniweb.co.in/api_docs.php). UniWeb brand only — no partner SDK wrappers.

## Requirements

- Node.js 18+ (native `fetch`)

## Get API keys

1. Sign up at [uniweb.co.in](https://uniweb.co.in/).
2. Open **Dashboard → API Settings**.
3. Copy your test key (`uw_test_…`) and secret (`uws_…`).

Test keys work immediately. Live keys (`uw_live_…`) require completed KYC.

## Install

From Git (compiled `dist/` included):

```bash
npm install github:uniwebadmin/uniweb#main:sdk/node
```

Local checkout:

```bash
npm install /path/to/uniweb1/sdk/node
```

If you edit TypeScript source, rebuild:

```bash
cd sdk/node && npm run build
```

## Quick start — create payment link

```javascript
import { Client } from 'uniweb';

const uniweb = new Client({
  apiKey: 'uw_test_your_key_here',
  apiSecret: 'uws_your_secret_here',
  mode: 'test',
});

const link = await uniweb.createPaymentLink({
  amount: 500,
  description: 'Order #1001',
  customer_phone: '9876543210',
});

console.log(link.payment_url);
```

Default base URL: `https://uniweb.co.in/api/v1/`.

## Check payment status

```javascript
const result = await uniweb.checkStatus('TXN20260827123456');
console.log(result.transaction.status); // pending | success | failed
```

## Create refund

```javascript
const refund = await uniweb.createRefund({
  txn_id: 'TXN20260827123456',
  amount: 100,
  reason: 'Customer request',
});
// Omit amount for a full refund
```

## Verify webhook signature

Header: `X-UniWeb-Signature` = HMAC-SHA256(raw JSON body, signing secret from API Settings).

Use the **raw body string** — do not verify against re-serialized JSON.

```javascript
import { verifySignature } from 'uniweb';

const rawBody = /* Buffer or string from express.raw() */;
const sig = req.headers['x-uniweb-signature'];

if (!verifySignature(rawBody, sig, process.env.UNIWEB_WEBHOOK_SECRET)) {
  res.statusCode = 401;
  res.end();
  return;
}
```

During secret rotation, pass `previousSecret` as the optional fourth argument for a 48-hour grace window.

Express tip: `express.raw({ type: 'application/json' })` on the webhook route.

## API methods

Same `action` values as [openapi.json](https://uniweb.co.in/openapi.json):

| Method | API action | Idempotency-Key |
|--------|------------|-----------------|
| `createPaymentLink(params)` | `create_payment_link` | Auto |
| `checkStatus(txnId)` | `check_status` | — |
| `createRefund(params)` | `create_refund` | Auto |
| `getBalance()` | `get_balance` | — |
| `listTransactions(filters)` | `list_transactions` | — |
| `listRefunds(filters)` | `list_refunds` | — |
| `listPaymentLinks(filters)` | `list_payment_links` | — |
| `getPaymentLink(linkId)` | `get_payment_link` | — |

## Errors

Failures throw `UniWebError` with `errorCode` and `httpStatus`:

```javascript
import { AuthenticationError, RateLimitError, UniWebError } from 'uniweb';

try {
  await uniweb.createPaymentLink({ amount: 500 });
} catch (err) {
  if (err instanceof AuthenticationError) { /* auth */ }
  else if (err instanceof RateLimitError) { /* err.retryAfterSeconds */ }
  else if (err instanceof UniWebError) { console.error(err.errorCode); }
}
```

Secrets are sent only in request headers — never logged by the SDK.

## Tests (no live API)

```bash
cd sdk/node && npm run build && npm test
```

## Source

Monorepo: [github.com/uniwebadmin/uniweb/tree/main/sdk/node](https://github.com/uniwebadmin/uniweb/tree/main/sdk/node) — TypeScript types in `dist/index.d.ts`.
