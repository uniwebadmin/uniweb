# UniWeb — Master Launch List Review

_Evaluation of the full owner checklist against the actual codebase + live status. For owner + advisor._

**Legend:** ✅ Live · ⚙️ Built, needs keys/config · 🔧 Partial / enhancement needed · ❌ Not built (new module) · 🐞 Reported bug (verify in code) · ⚠️ Risk · ⛔ Avoid / reconsider

---

## The 5 answers (summary)

### 1. What we already have (done)
Core of all existing portals is live: Public site, Merchant portal, Admin panel, Staff/Ops. Plus recently shipped: **one-click multi-gateway forward + status matrix + compliance audit trail + KYC document versioning**, MDR settings (`update_mdr.php`), 2FA/step-up, security headers, IST timezone, invoice PDF, branded errors, high-throughput QR, custom address auto-fill with "use my location", bank-verify scaffold, settlement batches/engine.

### 2. What's left (real work)
Payout module (new), QR-with-logo + per-QR history, real gateway API keys/adapters, Aadhaar face-match (via partner), payment-method request, API/email notifications, staff-log migration, owner-confirmed DB cleanup. _(All PART-1 bugs are now fixed + live. Full Hindi UI dropped by owner.)_

### 3. What we should NOT take / must reconsider ⛔
- **Auto-deleting production merchants/payments** ("keep only AK Digital Media") — destructive; must be a backup + reversible archive, owner-confirmed. Not run automatically.
- **Customer login portal with auto-approve mobile/email self-update** — payers rarely need accounts on a PA, and auto-approving contact changes is an account-takeover/fraud vector. Reconsider scope.
- **In-house Aadhaar face-mapping / biometrics** — ✅ OWNER-CONFIRMED (2026-07-21): do NOT store face/Aadhaar biometric data on our server. Do it via a certified partner API (e.g. Digio). No raw biometrics in our DB.
- **Failed-payout auto-reversal without a reconciliation gate** — ✅ OWNER-CONFIRMED (2026-07-21): a failed payout must NOT auto-credit back to the wallet. Reversal only after reconciliation confirms the bank did not debit (manual/maker-checker gate). Money-movement risk.
- **Paid Google Maps / Places for address** — ✅ OWNER-CONFIRMED (2026-07-21): do NOT use paid Google Places. Use free PIN lookup (`api.postalpincode.in`) + OpenStreetMap Nominatim (already implemented in `assets/js/address-picker.js`).
- **Full 4-gateway payout/forward orchestrator before a live partner is signed** — premature.
- **Google Places API** as the default — it is paid + needs billing; a free India-Post pincode lookup may suffice.

### 4. What is better for us ⭐
Recommended order: **(a)** fix PART-1 demo-critical bugs first (cheap, high trust), **(b)** finish gateway real-API adapter when the first partner signs, **(c)** Payout via a partner API (RazorpayX/Cashfree Payouts) — not in-house, **(d)** QR logo + per-QR history (quick win), **(e)** Hindi incrementally via the existing `__()` + a `lang/hi.php`, **(f)** pincode-based address autofill instead of paid Google Places.

### 5. What could harm our journey ⚠️
- **Regulatory:** Payouts + multi-PG aggregation fall under RBI PA-PG rules; payouts need a licensed partner.
- **Destructive DB reset** could corrupt the wallet ledger / wipe real data.
- **Auto-approve customer contact changes** → fraud / ATO.
- **Scope explosion:** 60+ items at once will delay the demo — prioritise bugs + one gateway.
- **Data protection:** Aadhaar / face / bank data mishandling = legal risk.

---

## Part-by-part status

### PART 1 — Urgent bugs & DB reset
| Item | Status |
|---|---|
| DB clean-up (keep only "AK Digital Media") | ⚠️⛔ destructive — owner-confirm + backup first, reversible archive (not auto). STILL PENDING owner confirm |
| Test/Live toggle per merchant | ✅ (`merchant_toggle_mode.php`) |
| Wallet balance transfer bug | ✅ FIXED + live — reservation model (no double-count); debit only on UTR-confirmed completion (`admin_wallet.php`) |
| Payment link "available method" / "please select an item" error | ✅ root cause was address-form external API; fixed via local fallback + 6s timeout (`address-picker.js`) |
| Report & Analysis oversized icons / trends | ✅ fixed (CSS caps in repo) + CSS cache-bust deployed |
| Sidebar icons cut off | ✅ fixed (CSS) + cache-bust deployed |
| Laptop footer | ✅ fixed + live |
| Staff activity log not working | ✅ fail-safe query live (`includes/staff.php`); ⚠️ committed migration for `staff_activity_logs` still pending |
| Agreement download bug | ✅ FIXED + live — regenerates PDF on demand if missing (`merchant_agreement_pdf.php`) |
| Settlement batch number clickable | ✅ live (`admin_settlement_batches.php`) |
| "Use my location" button | ✅ FIXED + live — `.htaccess` had geolocation disabled; now `geolocation=(self)` |
| "diabetes" weird settlement status word | ✅ badge hardened live (shows "Unknown"); ⚠️ correcting the stale DB value needs owner-confirmed DB write |
| Unmatched webhook log section (admin) | ✅ live in `admin_reconciliation.php` ("PG Reconciliation" in admin nav) |

### PART 2 — KYC, onboarding & bank verification
| Item | Status |
|---|---|
| KYC docs (Aadhaar/PAN/GST/MCA/Udyam) entity-based | ✅ |
| Video KYC | ✅ (page); ⚙️ automated match needs Digio |
| Aadhaar face mapping (live selfie) | 🔜 ⚙️ via partner (do not build in-house) |
| Bank verification (penny drop + name fetch) | ⚙️ scaffold (`includes/verification.php`, `add_bank.php`) — needs live bank/Decentro keys |
| IFSC → branch auto-fetch | ✅ live — type valid IFSC on Add Bank → Bank Name auto-fills + branch/city/state shown (free `ifsc.razorpay.com` directory, no key; `lookupIfsc()` + auth-gated `ifsc_lookup.php` proxy) |
| Merchant bank add/update/change | ✅ (`add_bank.php`, `admin_merchant_banks.php`) |
| Merchant docs → Razorpay/Cashfree page | ✅ (multi-gateway forward, shipped) |
| Website compliance check (Contact/Policy page) | ✅ live — "Run compliance check" on `merchant_website.php` scans homepage (SSRF-guarded, read-only) for HTTPS + Contact/Privacy/Terms/Refund/About pages, shows pass/fail scorecard (`checkWebsiteCompliance()`) |
| Premium KYC / Video-KYC design | 🔧 polish |

### PART 3 — Gateways, API & transactions
| Item | Status |
|---|---|
| Razorpay / Cashfree / Decentro / PayU | ⚙️ keys pending |
| Pine Labs Plural | ❌ new integration |
| Test/Live toggle | ✅ |
| API keys security/refresh/connect/notify | ✅ (notify email + in-app done) |
| MDR settings (partner-wise) | ✅ (`update_mdr.php`) |
| Hide platform fee from customer | ✅ payer sees only "Amount Payable" (`checkout.php`); split breakdown removed |
| Txn/settlement status + exact reason | ✅ live — transaction detail shows a tone-coded plain-language reason banner (`transactionStatusExplainer()`); settlements show reason via `settlementReasonText()` on list + `settlement_detail.php` |
| Delayed split settlement (1–2 hr batch) | ✅/⚙️ (`includes/settlement_engine.php`, batches) |
| Unmatched webhook section | ✅ `admin_reconciliation.php` ("Unmatched Webhooks" + "Gateway Txns Without Webhook Log"), linked in admin nav as "PG Reconciliation" |
| Shopify/WordPress/e-Rupee | 🔜 (WooCommerce plugin exists) |

### PART 4 — Portals, UI/UX, QR & customer features
| Item | Status |
|---|---|
| 4 portals responsive | ✅ (public/merchant/admin/staff); Customer portal ✅ LIVE (`customer_login.php`/`customer_portal.php`) |
| Full Hindi website | ⛔ DROPPED (owner decision) — UI stays English-only |
| Google location autocomplete + autofill | ✅ free OpenStreetMap Nominatim (search + device location); paid Google Places intentionally NOT used |
| Pincode → address autofill | ✅ free India PIN lookup (`api.postalpincode.in`) — type 6-digit PIN → State/District/City autofill (`address-picker.js`) |
| Razorpay-style QR + UniWeb logo + per-QR history | ✅ LIVE — centre UniWeb logo baked (GD, ECC-H), per-QR Collected/Payments summary + Print poster + "View payments" (`qr_code.php`, `qr_image.php`) |
| Customer profile self-update (auto-approve) | ❌ ⛔ fraud risk — OTP-verify, no auto-approve |
| Invoice PDF (GST/name/addr/mobile/email/no.) | ✅ verify fields (`invoice_pdf.php`) |

> **Customer Portal — ✅ BUILT + LIVE (2026-07-21):** lightweight **payer-facing** portal, NOT a full account.
> - **Login:** mobile + **WhatsApp/SMS OTP** (passwordless, hashed OTP, 10-min expiry, rate-limited). Demo-mode shows OTP on screen when no channel configured. (`customer_login.php`)
> - **View:** payer's own **transaction history** matched by mobile, read-only, with plain-language status reason. (`customer_portal.php`)
> - **Support:** raise + track a **grievance/ticket** from any transaction; admin replies via **admin_customer_tickets.php** ("Customer Complaints" in admin nav). (`customer_ticket.php`)
> - **Guardrails honoured:** no auto-approve contact self-update; transactions read-only; isolated session + tables (migration `010_customer_portal.sql`).
| Payment method request (merchant→admin) | ✅ live — merchant self-toggles only entitled methods; locked methods show "Request to Enable" → admin approve/reject queue (`admin_method_requests.php`), approval unlocks instantly (`includes/method_requests.php`, `collection_settings.php`) |

### PART 5 — Payout system (new module)
| Item | Status |
|---|---|
| Payout enable request | ❌ new |
| IMPS/NEFT/RTGS/UPI payout | ❌ ⚙️ needs licensed payout partner |
| Beneficiary mgmt + penny drop | ❌ new |
| Bulk payout (CSV) | ❌ new |
| Separate collection vs payout wallet | ❌ new (wallet exists; split needed) |
| Failed payout reason + auto-reversal | ❌ show reason ✅ plan; auto-reversal ⛔ OWNER-CONFIRMED: no auto-credit — reversal only after reconciliation gate |
| Maker-checker high-value payout | ❌ new (RBAC exists to build on) |
| Payout API keys | ❌ new |

### PART 6 — Legal, security & marketing
| Item | Status |
|---|---|
| Privacy / Terms / Business Agreement | ✅ |
| Admin login security + IST | ✅ |
| Universal MFA/OTP (admin/staff mandatory, merchant optional) | ✅/🔧 (2FA + step-up exist; enforce policy) |
| Forget password all portals | ✅ (existing portals) |
| API-generated email notification | ✅ — key regenerate (merchant `api_settings.php` + admin `admin_edit_merchant.php`) sends email + in-app notification + staff-activity log via `regenerateMerchantApiKey()`; secret never emailed |
| Blog + Search Console + WhatsApp | 🔧 (blog ✅; WhatsApp webhook exists; Search Console = config) |
| e-Rupee / Shopify / WordPress | 🔜 (WooCommerce ✅) |

---

## Recommended immediate sequence
1. **PART-1 bug fixes** (wallet transfer, payment-link method, staff activity log, agreement download, settlement batch clickable, use-my-location, report/sidebar UI, unmatched-webhook view). Cheap, demo-critical, low risk.
2. **DB cleanup** — only after owner confirms; via backup + reversible soft-archive, never a blind delete.
3. **QR logo + per-QR history** — quick, visible win.
4. **First real gateway API adapter** — when a partner signs.
5. **Payout module** — via licensed partner API, phased, with maker-checker; not in-house money movement.
6. **Pincode address autofill** — incremental. _(Full Hindi UI: dropped by owner — English-only.)_

_Customer portal (clarified): passwordless WhatsApp-OTP login → own transaction history → raise grievance/ticket. Build later, step by step. No auto-approve contact self-update; no in-house biometrics (use a certified partner)._

---

## ⏳ TOMORROW — owner input pending (do NOT start without it)
- **Login pages redesign (ALL 4: Merchant `login.php`, Admin `admin_login.php`, Staff `staff_login.php`, Customer `customer_login.php`)** — owner will give the exact design direction / reference photo on **the morning of 2026-07-22**. Requested scope: all pages, consistent look. Wait for the owner's reference before implementing; do not guess the visual style. (Noted 2026-07-21 evening.)
