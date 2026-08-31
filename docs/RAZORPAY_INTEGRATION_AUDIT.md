# Razorpay integration audit (repo truth)

Audit date: 2026-08-30. No secret values in this document.

---

## 1) Keys / config load

| Layer | Path |
|-------|------|
| Canonical store | Partner Registry → `admin_gateway_detail.php?partner=razorpay` |
| Read at runtime | `includes/partner_control.php` → `getPartnerSetting('razorpay', …)` |
| Webhook secrets | `partnerWebhookSecretCandidates('razorpay')` in `includes/webhook_secret_rotation.php` |
| Not used for PG secrets | Legacy `gateway_settings.php` key paste (Plane B = Registry only) |

---

## 2) Checkout / order / capture

| Step | Function / file |
|------|-----------------|
| Create order | `createRazorpayOrder()` / `createRazorpayOrderWithRoute()` — `includes/gateways.php` |
| Checkout UI | `checkout.php` (UniWeb-branded methods pool) |
| Return verify | `payment_verify.php` — signature via `verifyRazorpayPayment()` |
| Server fetch | `fetchRazorpayPayment()` before capture |
| Canonical capture | `captureVerifiedPaymentOrder()` — `includes/financial_integrity.php` |
| Unified reconcile entry | `applyPartnerPaymentReconcile()` — `includes/payment_reconcile.php` |
| Ledger | `finalizeSuccessfulPaymentTransaction()` → `postPrimaryPaymentCaptureLedger()` |

Route order create exists (`createRazorpayOrderWithRoute`) but **Phase 11 transfer execution = PARKED** unless Owner switch + commercial complete.

---

## 3) Webhook

| Item | Detail |
|------|--------|
| Endpoint | `https://<domain>/razorpay_webhook.php` |
| Health | GET → `pgWebhookHealthResponse('razorpay')` |
| Signature | Header **`X-Razorpay-Signature`** · HMAC-SHA256 hex on **raw body** |
| Central verify | `pgWebhookVerifyPartner('razorpay', …)` |
| Events handled | `payment.captured`, `order.paid`, `payment.failed`, refunds, RazorpayX payouts, transfer.* (scaffold), mandates |
| Invalid sig | **401 JSON** — body not processed |
| Duplicate | `registerGatewayEvent` + `recordWebhookEvent` → **200 duplicate** |

---

## 4) Refunds

| Item | Status |
|------|--------|
| Create | `createRazorpayRefund()` — `includes/gateways.php` |
| Fetch status | `fetchRazorpayRefund()` |
| Webhook apply | `applyPartnerRefundWebhookEvent('razorpay', …)` — `includes/refund_webhooks.php` |
| Admin UI label | `refundDisplayStatus()` — honest `requested` / `processing` / `processed` |

**Live refund** requires Registry keys + successful capture with provider payment id.

---

## 5) Forward / queue

- KYC forward uses `includes/partner_forward_queue.php` — multi-partner, includes Razorpay registry key.
- **staged / local_record** = not sent to Razorpay API — honest labels in queue.

---

## 6) Route / split

| Capability | Status |
|------------|--------|
| Route order notes / linked account fields | Scaffold in gateways + merchant profile |
| Transfer webhooks | Handled → `updatePartnerTransferFromWebhook()` |
| Live money split execution | **PARKED** — `route_split_live_enabled` default **OFF** |
| Customer UI | No “split sent to bank” claim when PARKED |

---

## 7) Gaps vs Cashfree/PayU bar

| Area | Razorpay | Notes |
|------|----------|-------|
| Webhook verify | Yes | Same central module |
| Refund webhook | Yes | Parity with cashfree/payu |
| Timestamp skew | N/A | Cashfree has skew; Razorpay uses body HMAC only |
| Empty body reject | Yes | `empty_body` → 400 |
| Idempotent ledger | Yes | Shared `captureVerifiedPaymentOrder` |

No class-A gap found requiring new product scope in this audit.

---

## 8) Idempotency

| Event | Key |
|-------|-----|
| Pay capture | `gateway_events (provider, event_id)` + order `paid` + ledger `business_reference` |
| Pay duplicate webhook | 200 + no second txn |
| Refund | `provider_refund_id` in `applyPartnerRefundWebhookEvent` |
| API writes | `api_idempotency_keys` per merchant |

---

## Owner verify (no live money)

1. GET `https://uniweb.co.in/razorpay_webhook.php` → health JSON  
2. POST without signature → **401** JSON (not HTML trace)  
3. Admin → Integration Board → Razorpay row shows STUB/PARKED honestly  
4. Smoke: `php tests/probe_money_rails.php` → failures=0  
5. Txn detail shows **Status confirmed via** when a test txn exists  

Live payment test = Owner step after keys pasted.
