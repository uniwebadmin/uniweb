# UniWeb ΓÇö market comparison (Phase 9 reference)

**Status:** quality bar only. Do **not** implement peer parity (Route, Easy Split, POS, orchestrator, PPI, NBFC) unless the Owner explicitly says start.

**Public page:** `compare.php`  
**Audit ticket:** P9-01 in `DEEP_AUDIT_ORDERED.md`

## What UniWeb is competing on

Merchant console for Indian **collections**: payment links, QR, hosted checkout, KYC, settlements, HMAC webhooks. Live money moves on **licensed partner rails** (Razorpay, Cashfree, PayU, Axis, Decentro when keyed). Test Mode is free and must never look like a live capture.

## Peer bar

| Peer | They do well | UniWeb bar | Build now? |
|------|----------------|------------|------------|
| Razorpay | Docs, Links, QR, Route, trust UX | Match **links/QR reliability**. Trust copy already on public site. | Route **only when Owner asks** + keys |
| Cashfree | Payouts, verification, marketplace split | Collect first. **Payout polish after core collect** | No Easy Split product yet |
| PayU | India coverage, cards | Credentials + methods must be **hard** (no fake coverage) | Partner rail, not a clone |
| Juspay | Orchestration reliability | Clarify story; **measure uptime** on `status.php` | No second orchestrator app |
| Stripe | Docs, webhooks, test mode | Webhook + API doc quality (`api_docs.php`, OpenAPI, `uw_test_` / `uw_live_`) | Docs polish, not Stripe catalogue |
| Worldline | Acquiring POS + online | **Online-first** (checkout, links, QR) | POS only if Owner adds a deal |
| Decentro | Banking / UPI APIs | **Sandbox vs live labels** always clear | Partner API, not in-house bank |

## P9-01 Packaging gap

**Problem:** Many screens exist; empty tables and raw PHP errors feel broken.  
**Expectation:** Educated empty states (next click) and actionable errors (what to do, not SQLSTATE).  
**Action:** Polish after Phase 0ΓÇô2 green. Empty states shipped in Phase 8. Actionable `userFacingError()` hides internals from merchants/customers.

## Never from this comparison

- Consumer **PPI wallet**
- **NBFC** lending product in menus
- Claiming UniWeb independently holds an **RBI PA / banking licence**
- Public **0% UPI forever** or **instant settlement** as fact
- Live Route SDK / marketplace split without Owner + commercial + keys

## Owner clicks (when comparing on live)

1. Create Test payment link + QR ΓÇö same reliability bar as Razorpay Links/QR.  
2. Open `status.php` ΓÇö Juspay-style honesty is uptime, not a second product.  
3. Open `api_docs.php` ΓÇö Stripe-style test vs live keys.  
4. Do **not** enable Route, POS, or payout automation from this document alone.
