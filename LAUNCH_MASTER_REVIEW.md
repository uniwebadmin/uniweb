# UniWeb — Master Launch List Review

_Evaluation of the full owner checklist against the actual codebase + live status. For owner + advisor._

**Legend:** ✅ Live · ⚙️ Built, needs keys/config · 🔧 Partial / enhancement needed · ❌ Not built (new module) · 🐞 Reported bug (verify in code) · ⚠️ Risk · ⛔ Avoid / reconsider

---

## The 5 answers (summary)

### 1. What we already have (done)
Core of all existing portals is live: Public site, Merchant portal, Admin panel, Staff/Ops. Plus recently shipped: **one-click multi-gateway forward + status matrix + compliance audit trail + KYC document versioning**, MDR settings (`update_mdr.php`), 2FA/step-up, security headers, IST timezone, invoice PDF, branded errors, high-throughput QR, custom address auto-fill with "use my location", bank-verify scaffold, settlement batches/engine.

### 2. What's left (real work)
Owner-gated: gateway/partner API keys (paste when received), Digio for Aadhaar face-match, DB cleanup + settlement "diabetes" value (owner-confirm), Pine Labs / e-Rupee / Shopify expansions. Payout scaffold is shipped but live money stays gated until licensed partner keys + `payout_live_enabled`. **One-time:** apply pending migrations `011`–`017` via Gateway Settings → **Apply pending migrations** (same watchdog key as cron — see `migrations/README.md`). Cloud auto-deploy uses FTP curl on `main` (secrets in GitHub Actions); if CI fails, manual FTP remains the fallback.

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
| Staff activity log not working | ✅ fail-safe query live (`includes/staff.php`); migration `011_staff_activity_logs.sql` in repo — **owner: one-time Apply pending migrations** if table missing |
| Agreement download bug | ✅ FIXED + live — regenerates PDF on demand if missing (`merchant_agreement_pdf.php`) |
| Settlement batch number clickable | ✅ live (`admin_settlement_batches.php`) |
| "Use my location" button | ✅ FIXED + live — `.htaccess` had geolocation disabled; now `geolocation=(self)` |
| "diabetes" weird settlement status word | ✅ badge hardened live (shows "Unknown"); ⚠️ correcting the stale DB value needs owner-confirmed DB write — owner-confirm pending |
| Unmatched webhook log section (admin) | ✅ live in `admin_reconciliation.php` ("PG Reconciliation" in admin nav) |

### PART 2 — KYC, onboarding & bank verification
| Item | Status |
|---|---|
| KYC docs (Aadhaar/PAN/GST/MCA/Udyam) entity-based | ✅ |
| Video KYC | ✅ (page); ⚙️ automated face-match via Digio keys in Gateway Settings (no in-house biometrics) |
| Aadhaar face mapping (live selfie) | 🔜 ⚙️ Digio partner key fields ready (`digio_*` in `gateway_settings.php`) — do not build in-house |
| Bank verification (penny drop + name fetch) | ⚙️ scaffold (`includes/verification.php`, `add_bank.php`) — needs live bank/Decentro keys |
| IFSC → branch auto-fetch | ✅ live — type valid IFSC on Add Bank → Bank Name auto-fills + branch/city/state shown (free `ifsc.razorpay.com` directory, no key; `lookupIfsc()` + auth-gated `ifsc_lookup.php` proxy) |
| Merchant bank add/update/change | ✅ (`add_bank.php`, `admin_merchant_banks.php`) |
| Merchant docs → Razorpay/Cashfree page | ✅ (multi-gateway forward, shipped) |
| Website compliance check (Contact/Policy page) | ✅ live — "Run compliance check" on `merchant_website.php` scans homepage (SSRF-guarded, read-only) for HTTPS + Contact/Privacy/Terms/Refund/About pages, shows pass/fail scorecard (`checkWebsiteCompliance()`) |
| Premium KYC / Video-KYC design | ✅ LIVE — merchant KYC shows per-doc status + rejection reason banner; Video KYC shows reject reason + re-upload; admin stores `rejection_reason` + notifies merchant (`kyc.php`, `video_kyc.php`, `admin_kyc.php`, migration `014`) |

### PART 3 — Gateways, API & transactions
| Item | Status |
|---|---|
| Razorpay / Cashfree / Decentro / PayU | ⚙️ keys pending — adapters + test connections live; UI shows "Keys pending" via `isGatewayConfigured()`; create-order helpers no-op without full keys; `getActivePaymentGateway()` only selects checkout-capable configured gateways |
| Pine Labs Plural | ⚙️ scaffold — sandbox stub `pineLabsSandboxCreateOrder()` + Gateway Settings fields; checkout gated (roadmap) |
| Test/Live toggle | ✅ |
| API keys security/refresh/connect/notify | ✅ (notify email + in-app done) |
| MDR settings (partner-wise) | ✅ (`update_mdr.php`) |
| Hide platform fee from customer | ✅ payer sees only "Amount Payable" (`checkout.php`); split breakdown removed |
| Txn/settlement status + exact reason | ✅ live — transaction detail shows a tone-coded plain-language reason banner (`transactionStatusExplainer()`); settlements show reason via `settlementReasonText()` on list + `settlement_detail.php` |
| Delayed split settlement (1–2 hr batch) | ✅ engine live (`includes/settlement_engine.php`, batches); ⚙️ bank rail confirms when partner keys are live |
| Unmatched webhook section | ✅ `admin_reconciliation.php` ("Unmatched Webhooks" + "Gateway Txns Without Webhook Log"), linked in admin nav as "PG Reconciliation" |
| Shopify/WordPress/e-Rupee | 🔜 (WooCommerce plugin exists) |

### PART 4 — Portals, UI/UX, QR & customer features
| Item | Status |
|---|---|
| 4 portals responsive | ✅ (public/merchant/admin/staff); Customer portal ✅ LIVE + premium redesign (`customer_login.php` / `customer_portal.php` / `customer_ticket.php`) |
| Full Hindi website | ⛔ DROPPED (owner decision) — UI stays English-only |
| Google location autocomplete + autofill | ✅ free OpenStreetMap Nominatim (search + device location); paid Google Places intentionally NOT used |
| Pincode → address autofill | ✅ free India PIN lookup (`api.postalpincode.in`) — type 6-digit PIN → State/District/City autofill (`address-picker.js`) |
| Razorpay-style QR + UniWeb logo + per-QR history | ✅ LIVE — centre UniWeb logo baked (GD, ECC-H), per-QR Collected/Payments summary + Print poster + "View payments" (`qr_code.php`, `qr_image.php`) |
| Customer profile self-update (auto-approve) | ❌ ⛔ fraud risk — OTP-verify, no auto-approve |
| Invoice PDF (GST/name/addr/mobile/email/no.) | ✅ LIVE — PDF always prints Invoice No, Bill From (business name, GSTIN, full address, mobile, email) + Bill To (name, email, mobile, address); create form collects customer address (`invoice_pdf.php`, `SimpleInvoicePdf`, migration `013`) |

> **Customer Portal — ✅ PREMIUM + CROSS-ROLE (2026-07-21 overnight):** lightweight **payer-facing** portal.
> - **Login:** mobile + **WhatsApp/SMS OTP** (passwordless). Premium Manrope/Fraunces shell on `customer_login.php` (owner-approved redesign). Demo OTP when channels lack keys.
> - **History:** all txns for that mobile across merchants (matches `transactions.customer_phone` + `payment_links.customer_phone`), read-only, status + reason.
> - **Tickets:** raise grievance from any txn; thread replies.
> - **Cross-role:** Admin + Staff (`admin_customer_tickets.php` in staff nav for support/ops) see all; Merchant (`merchant_customer_tickets.php`) sees **own merchant_id only** and can reply; replies fan out WhatsApp/SMS when configured (`replyToCustomerTicket` / migration `016`).
> - **Guardrails:** no auto-approve contact self-update; no password on customer portal (OTP only).

| Payment method request (merchant→admin) | ✅ live — merchant self-toggles only entitled methods; locked methods show "Request to Enable" → admin approve/reject queue (`admin_method_requests.php`), approval unlocks instantly (`includes/method_requests.php`, `collection_settings.php`) |

### PART 5 — Payout system (new module)
| Item | Status |
|---|---|
| Payout enable request | ✅ scaffold — merchant request → admin approve (`merchant_payout.php`, `admin_payout.php`); live money still gated |
| IMPS/NEFT/RTGS/UPI payout | ⚙️ needs licensed payout partner keys (`payoutLiveMoneyAllowed()` hard gate) |
| Beneficiary mgmt + penny drop | ✅ add/edit/list/deactivate + IFSC autofill; penny-drop button gated (`requestPayoutBeneficiaryPennyDrop`) |
| Bulk payout (CSV) | ✅ scaffold — CSV template + upload on `merchant_payout.php` (`processPayoutBulkCsv`); live money still gated |
| Separate collection vs payout wallet | ✅ display-only split on payout page (`getMerchantWalletSplitView`) |
| Failed payout reason + auto-reversal | ✅ failed drafts show `failure_reason`; reversal queue (`requestPayoutReversal` → admin reconcile) ⛔ NEVER auto-credits |
| Maker-checker high-value payout | ✅ ≥ ₹50k → `pending_checker`; `approvePayoutChecker` (maker ≠ checker); no live dispatch without keys |
| Payout API keys | ✅ generate/rotate/revoke UI (`merchant_payout_keys.php`) — live use gated; paste partner keys in Gateway Settings when signed |

### PART 6 — Legal, security & marketing
| Item | Status |
|---|---|
| Privacy / Terms / Business Agreement | ✅ |
| Admin login security + IST | ✅ |
| Universal MFA/OTP (admin/staff mandatory, merchant optional) | ✅ LIVE — admin/staff login forces MFA enrollment or challenge (setup prompt, no lockout); merchant 2FA optional with dashboard/settings prompts + clear policy UI (`mfaPolicy()`, `merchant_2fa.php`, `admin_login.php`, `staff_login.php`) |
| Login pages (merchant / admin / staff / customer) | ✅ PREMIUM redesigned (2026-07-21 overnight) — shared `auth-portal.css` brand panel + focused form; POST/CSRF/fields unchanged. Owner freeze lifted. |
| Forget password all portals | ✅ merchant + admin; customer is OTP-only (no password). Staff uses admin recovery for privileged accounts |
| API-generated email notification | ✅ — key regenerate (merchant `api_settings.php` + admin `admin_edit_merchant.php`) sends email + in-app notification + staff-activity log via `regenerateMerchantApiKey()`; secret never emailed |
| Blog + Search Console + WhatsApp | ✅ LIVE — blog exists; Search Console token via Gateway Settings → `google_site_verification` (meta in `header.php`); WhatsApp alerts fan out from `createNotification` → `onMerchantNotificationCreated` when merchant prefs enable WhatsApp + Meta keys are set |
| Cloud auto-deploy (laptop-free) | ⚙️ `.github/workflows/deploy.yml` on `main` — **FTP curl** (parallel=8 + per-file retry) via `UNIWEB_FTP_*` secrets; verified green after parallel fix; PR #29 features also live via manual FTP. Merge to `main` to auto-deploy; fall back to manual FTP if Actions fails |
| Schema migrations 011–017 | ⚙️ in repo + `migrations/README.md` — **owner one-time** Gateway Settings → Apply pending migrations (same cron/watchdog key; do not invent `CRON_KEY`). Idempotent `IF NOT EXISTS` on ALTER columns |
| e-Rupee / Shopify / WordPress | 🔜 (WooCommerce ✅) |

---

## Recommended immediate sequence
1. **Owner one-time migrations** — Gateway Settings → **Apply pending migrations** (011–017). Expect `ok: true`. See `migrations/README.md`.
2. **Paste first partner gateway keys** when received; Test Connection; set Primary Payment Gateway.
3. **Confirm CI auto-deploy** on next `main` merge (Actions → Deploy to UniWeb Hostinger). If red, use laptop FTP once.
4. **DB cleanup / diabetes settlement status** — only after owner confirms; backup + reversible soft-archive.
5. **Payout live money** — only with licensed partner keys + `payout_live_enabled` (never auto-credit reversals).
6. **Pine Labs / PhonePe / e-Rupee / Shopify** — after primary PG is live.

_Customer portal: ✅ shipped (passwordless WhatsApp/SMS OTP → history → tickets; cross-role admin/staff/merchant). No auto-approve contact self-update; no in-house biometrics (Digio partner)._

---

## Session log — 2026-07-22 (afternoon)

### Shipped / merged
- **PR #29 MERGED + LIVE** — invoice PDF fields, premium KYC + rejection reason, MFA policy, payout scaffold (money-gated), Search Console + WhatsApp fan-out, customer portal premium + cross-role tickets, all 4 login redesigns, Pine Labs stub, `cloud_modules.php`, migrations `013`–`017`.
- **CI auto-deploy fixed** — Hostinger FTP parallel curl (`xargs -P8`); Actions run **green**. Secrets: `UNIWEB_FTP_*` (no secrets in git).
- **PR #30 MERGED** (was draft from cloud agent [Continue remaining parts overnight](bc-b39e79bc-fc69-40fd-a1a7-754269257a9e)) — `migrations/README.md`, migration apply link in Gateway Settings, gateway create-order gated by `isGatewayConfigured()`, CI FTP retry, KYC load safety (`function_exists` + `config.dev.php` stub cleanup).

### Cloud agents status (this session)
- Overnight agent completed work into PR #29 / later PR #30 draft; some follow-up cloud resumes failed with conversation-state version errors — work was recovered from draft PR #30 and merged.
- Photo inbox `_inbox/` empty (no new owner screenshots).

### Still owner-manual
1. Gateway Settings → **Apply pending migrations** (011–017)
2. Paste gateway / Digio / payout partner keys when received
3. Confirm DB cleanup + “diabetes” row only after explicit OK

