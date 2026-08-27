# UniWeb Node.js SDK

Official Node.js client for the [UniWeb Merchant API](https://uniweb.co.in/api_docs.php).

## Requirements

- Node.js 18+ (native `fetch`)

## Install

From this monorepo (until published on npm):

```bash
npm install /path/to/uniweb1/sdk/node
```

Or from Git:

```bash
npm install github:uniwebadmin/uniweb#main:sdk/node
```

Build TypeScript (if installing from source):

```bash
cd sdk/node && npm run build
```

## Quick start

```javascript
import { Client } from 'uniweb';

const uniweb = new Client({
  apiKey: 'uw_test_your_key',
  apiSecret: 'uws_your_secret',
  mode: 'test',
  baseUrl: 'https://uniweb.co.in/api/v1/',
});

const link = await uniweb.createPaymentLink({
  amount: 500,
  description: 'Order #1001',
  customer_phone: '9876543210',
});

console.log(link.payment_url);
```

## Example 2 — Check status

```javascript
const result = await uniweb.checkStatus('TXN20260827123456');
console.log(result.transaction.status);
```

## Example 3 — Verify webhook

```javascript
import { verifySignature } from 'uniweb';

const ok = verifySignature(rawBody, req.headers['x-uniweb-signature'], signingSecret);
if (!ok) {
  res.statusCode = 401;
  res.end();
  return;
}
```

## API

Same methods as the PHP SDK: `createPaymentLink`, `checkStatus`, `createRefund`, `getBalance`, `listTransactions`, plus list/get payment links and refunds.

Write calls automatically include a unique `Idempotency-Key` header.

Errors throw `UniWebError` with `errorCode` and `httpStatus`. Special: `AuthenticationError`, `RateLimitError`.

Secrets are sent only in request headers — never logged by the SDK.

## Source

Monorepo path: `sdk/node/` — TypeScript types included in `dist/index.d.ts`.
