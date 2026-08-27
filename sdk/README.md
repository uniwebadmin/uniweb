# UniWeb Merchant SDKs (Phase 1)

Official client libraries for the UniWeb Merchant API. **UniWeb brand only** — no partner SDK wrappers.

| Language | Path | Package |
|----------|------|---------|
| PHP (primary) | [`sdk/php/`](php/) | `uniweb/merchant-sdk` (Composer) |
| Node.js | [`sdk/node/`](node/) | `uniweb` (npm) |

Live API base URL: `https://uniweb.co.in/api/v1/`

Documentation: [api_docs.php](https://uniweb.co.in/api_docs.php) → SDK Libraries section.

Both SDKs include:

- Payment link create, status check, refund, balance, list transactions
- Automatic `Idempotency-Key` on write calls
- Webhook signature verification (`X-UniWeb-Signature`)
- Stable `error_code` mapped to exceptions

Run shape tests (no live API):

```bash
php sdk/php/tests/RequestShapeTest.php
cd sdk/node && npm run build && npm test
```
