# Work After Live Website + Partner Connection

> Yeh file un sab kaamo ki list hai jo **website live hone ke baad** aur **partner gateway connect hone ke baad** karni hai.
> Status: 2026-08-08

---

## A. Partner Keys Dependent Work (Decentro / Razorpay / Cashfree)

### 1. First Real Partner Adapter — Decentro UPI P2M / Dynamic QR
- Decentro staging keys pehle test huye (done)
- Live keys aane par: production API se dynamic UPI QR generate karna
- `includes/gateways.php` mein Decentro adapter already ready (gateway-agnostic architecture)
- Live UPI collection start hogi

### 2. Payout Live Money
- `merchant_payout.php` scaffold ready hai
- RazorpayX / Cashfree / PayU / Axis — jis bhi partner se keys aaye, wire karna
- `payout_live_enabled` setting ON karna
- Penny-drop verification via Decentro/bank
- Maker-checker flow already built
- **Gate:** Licensed partner keys required before any real money movement

### 3. Recurring Payments / Subscriptions (Plan Point F)
- `merchant_recurring.php` page hai, `recurring_mandates` table hai
- Mandate create hota hai `pending_partner` status mein
- Partner API se mandate activation karna baaki
- `processDueMandateDebits()` cron mein called hai but actual debit logic implement nahi
- UPI Autopay (NPCI e-mandate) via Decentro/Razorpay
- **Gate:** Partner API keys + Autopay product approval needed

### 4. Apple Pay / Google Pay (Plan Point G)
- Checkout pe sirf UPI hai abhi
- Razorpay/Cashfree ke through Apple Pay / Google Pay support
- `checkout.php` mein wallet payment handler
- `includes/gateways.php` mein wallet order creation
- **Gate:** Razorpay/Cashfree live keys needed

### 5. One-Click Multi-Gateway Forward + Status Matrix
- Merchant request -> Admin -> 1-click forward to all gateways
- Per-merchant status matrix (kis gateway pe kya status hai)
- Merchant auto-notify when gateway enables/disables
- `includes/gateways.php` architecture ready

### 6. Virtual Accounts (VA) API Products
- `_inbox/` mein Decentro VA API product docs hain (zip files):
  - Blob VA API Product.zip
  - Bulk Payment API Product.zip
  - Corporate Account API Product.zip
  - Corporate Single Payment API Product.zip
  - VA Creation API Product.zip
  - Virtual Account API Product.zip
- `merchant_virtual_accounts` table + `va_manager.php` already built
- Live VA creation via Decentro API when keys configured

---

## B. Post-Live Feature Work (No Partner Gate)

### 7. Sandbox Dashboard (Plan Point E)
- Test mode (`is_test=1`) exists, data separate hai
- Dedicated sandbox analytics page nahi hai
- `sandbox_dashboard.php` — test vs live comparison, success/fail metrics
- Merchant integration testing ke liye useful

### 8. Shopify / WordPress / e-Rupee (Strategy #2)
- WooCommerce plugin already exists (`plugins/woocommerce/`)
- Shopify app: OAuth + webhook integration
- WordPress generic plugin
- e-Rupee: CBDC partner/bank API when available
- **After primary PG live**

### 9. DB Cleanup + Diabetes Settlement Word Fix
- `AGENTS.md` mein listed: DB cleanup and diabetes settlement word fix only after owner confirm
- **Gate:** Owner confirmation needed

### 10. Live config.php Includes (Owner Manual)
- `qr_svg` add karna `$__includes` mein (checkout QR fix ke liye)
- `gateway_reason_map` add karna (failure reason mapping ke liye)
- File gitignored hai — hPanel File Manager se edit karna hoga

---

## C. Items Already Done (Don't Revisit)

| # | Item | Status |
|---|------|--------|
| 1 | Exact reason copy (Strategy #1) | Shipped |
| 2 | Razorpay-style QR (Strategy #3) | Shipped |
| 3 | OTP contact change (Strategy #4) | Shipped |
| 4 | Failed-payout auto-reversal gate (Strategy #6) | Gated (recon-only) |
| 5 | Webhook retry queue (all 4 gateways) | Completed |
| 6 | Webhook event history UI | Done |
| 7 | Decentro staging keys test | Done |
| 8 | High-volume UPI infra (7 phases) | Done |
| 9 | Hostinger Git deploy | Working |

---

## D. Forbidden (Per AGENTS.md + Owner)

- No auto-deletion
- No auto-approve contact changes
- No in-house biometrics
- No failed-payout auto-credit
- No Google Places
- No full orchestrator before one live partner
- No auto-debit without partner mandate activation
