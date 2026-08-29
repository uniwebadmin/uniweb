# UniWeb Merchant SDKs

Official client libraries for the UniWeb Merchant API. **UniWeb brand only** — no partner SDK wrappers.

| Language | Path | Package |
|----------|------|---------|
| PHP (primary) | [`sdk/php/`](php/) | `uniweb/merchant-sdk` (Composer path install) |
| Node.js | [`sdk/node/`](node/) | `uniweb` (npm from Git monorepo) |

- **API docs:** [uniweb.co.in/api_docs.php](https://uniweb.co.in/api_docs.php) → SDK Libraries
- **OpenAPI:** [openapi.json](https://uniweb.co.in/openapi.json)
- **Keys:** Dashboard → API Settings (`uw_test_…` + `uws_…`)

Both SDKs include payment link create, status check, refund, balance, list transactions, automatic `Idempotency-Key` on writes, webhook signature verification (`X-UniWeb-Signature`), and stable `error_code` mapped to typed errors.

## Quick start (test keys)

1. Copy keys from **API Settings**.
2. Install PHP or Node SDK (see README in each folder).
3. Call `createPaymentLink({ amount: 500, … })`.
4. Redirect customer to `payment_url` (UniWeb hosted checkout).

## Run shape tests (no live API)

```bash
php sdk/php/tests/RequestShapeTest.php
cd sdk/node && npm run build && npm test
```

Packagist and npm publish when Owner enables — until then, install from this monorepo.
