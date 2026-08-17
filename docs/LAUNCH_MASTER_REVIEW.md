# UniWeb ΓÇö Master Launch List Review

_Evaluation of the full owner checklist against the actual codebase + live status. For owner + advisor._

**Legend:** Γ£à Live ┬╖ ΓÜÖ∩╕Å Built, needs keys/config ┬╖ ≡ƒöº Partial / enhancement needed ┬╖ Γ¥î Not built (new module) ┬╖ ≡ƒÉ₧ Reported bug (verify in code) ┬╖ ΓÜá∩╕Å Risk ┬╖ Γ¢ö Avoid / reconsider

---

## The 5 answers (summary)

### 1. What we already have (done)
Core of all existing portals is live: Public site, Merchant portal, Admin panel, Staff/Ops. Plus recently shipped: **one-click multi-gateway forward + status matrix + compliance audit trail + KYC document versioning**, MDR settings (`update_mdr.php`), 2FA/step-up, security headers, IST timezone, invoice PDF, branded errors, high-throughput QR, custom address auto-fill with "use my location", bank-verify scaffold, settlement batches/engine.

### 2. What's left (real work)
Owner-gated: gateway/partner API keys (paste when received), Digio for Aadhaar face-match, DB cleanup + settlement "diabetes" value (owner-confirm), Pine Labs / e-Rupee / Shopify expansions. Payout scaffold is shipped but live money stays gated until licensed partner keys + `payout_live_enabled`. **One-time:** apply pending migrations `011`ΓÇô`017` via Gateway Settings ΓåÆ **Apply pending migrations** (same watchdog key as cron ΓÇö see `migrations/README.md`). Cloud auto-deploy uses FTP curl on `main` (secrets in GitHub Actions); if CI fails, manual FTP remains the fallback.

### 3. What we should NOT take / must reconsider Γ¢ö
- **Auto-deleting production merchants/payments** ("keep only AK Digital Media") ΓÇö destructive; must be a backup + reversible archive, owner-confirmed. Not run automatically.
- **Customer login portal with auto-approve mobile/email self-update** ΓÇö payers rarely need accounts on a PA, and auto-approving contact changes is an account-takeover/fraud vector. Reconsider scope.
- **In-house Aadhaar face-mapping / biometrics** ΓÇö Γ£à OWNER-CONFIRMED (2026-07-21): do NOT store face/Aadhaar biometric data on our server. Do it via a certified partner API (e.g. Digio). No raw biometrics in our DB.
- **Failed-payout auto-reversal without a reconciliation gate** ΓÇö Γ£à OWNER-CONFIRMED (2026-07-21): a failed payout must NOT auto-credit back to the wallet. Reversal only after reconciliation confirms the bank did not debit (manual/maker-checker gate). Money-movement risk.
- **Paid Google Maps / Places for address** ΓÇö Γ£à OWNER-CONFIRMED (2026-07-21): do NOT use paid Google Places. Use free PIN lookup (`api.postalpincode.in`) + OpenStreetMap Nominatim (already implemented in `assets/js/address-picker.js`).
- **Full 4-gateway payout/forward orchestrator before a live partner is signed** ΓÇö premature.
- **Google Places API** as the default ΓÇö it is paid + needs billing; a free India-Post pincode lookup may suffice.

### 4. What is better for us Γ¡É
Recommended order: **(a)** fix PART-1 demo-critical bugs first (cheap, high trust), **(b)** finish gateway real-API adapter when the first partner signs, **(c)** Payout via a partner API (RazorpayX/Cashfree Payouts) ΓÇö not in-house, **(d)** QR logo + per-QR history (quick win), **(e)** Hindi incrementally via the existing `__()` + a `lang/hi.php`, **(f)** pincode-based address autofill instead of paid Google Places.

### 5. What could harm our journey ΓÜá∩╕Å
- **Regulatory:** Payouts + multi-PG aggregation fall under RBI PA-PG rules; payouts need a licensed partner.
- **Destructive DB reset** could corrupt the wallet ledger / wipe real data.
- **Auto-approve customer contact changes** ΓåÆ fraud / ATO.
- **Scope explosion:** 60+ items at once will delay the demo ΓÇö prioritise bugs + one gateway.
- **Data protection:** Aadhaar / face / bank data mishandling = legal risk.

---

## Part-by-part status

### PART 1 ΓÇö Urgent bugs & DB reset
| Item | Status |
|---|---|
| DB clean-up (keep only "AK Digital Media") | ΓÜá∩╕ÅΓ¢ö destructive ΓÇö owner-confirm + backup first, reversible archive (not auto). STILL PENDING owner confirm |
| Test/Live toggle per merchant | Γ£à (`merchant_toggle_mode.php`) |
| Wallet balance transfer bug | Γ£à FIXED + live ΓÇö reservation model (no double-count); debit only on UTR-confirmed completion (`admin_wallet.php`) |
| Payment link "available method" / "please select an item" error | Γ£à root cause was address-form external API; fixed via local fallback + 6s timeout (`address-picker.js`) |
| Report & Analysis oversized icons / trends | Γ£à fixed (CSS caps in repo) + CSS cache-bust deployed |
| Sidebar icons cut off | Γ£à fixed (CSS) + cache-bust deployed |
| Laptop footer | Γ£à fixed + live |
| Staff activity log not working | Γ£à fail-safe query live (`includes/staff.php`); migration `011_staff_activity_logs.sql` in repo ΓÇö **owner: one-time Apply pending migrations** if table missing |
| Agreement download bug | Γ£à FIXED + live ΓÇö regenerates PDF on demand if missing (`merchant_agreement_pdf.php`) |
| Settlement batch number clickable | Γ£à live (`admin_settlement_batches.php`) |
| "Use my location" button | Γ£à FIXED + live ΓÇö `.htaccess` had geolocation disabled; now `geolocation=(self)` |
| "diabetes" weird settlement status word | Γ£à badge hardened live (shows "Unknown"); ΓÜá∩╕Å correcting the stale DB value needs owner-confirmed DB write ΓÇö owner-confirm pending |
| Unmatched webhook log section (admin) | Γ£à live in `admin_reconciliation.php` ("PG Reconciliation" in admin nav) |

### PART 2 ΓÇö KYC, onboarding & bank verification
| Item | Status |
|---|---|
| KYC docs (Aadhaar/PAN/GST/MCA/Udyam) entity-based | Γ£à |
| Video KYC | Γ£à (page); ΓÜÖ∩╕Å automated face-match via Digio keys in Gateway Settings (no in-house biometrics) |
| Aadhaar face mapping (live selfie) | ≡ƒö£ ΓÜÖ∩╕Å Digio partner key fields ready (`digio_*` in `gateway_settings.php`) ΓÇö do not build in-house |
| Bank verification (penny drop + name fetch) | ΓÜÖ∩╕Å scaffold (`includes/verification.php`, `add_bank.php`) ΓÇö needs live bank/Decentro keys |
| IFSC ΓåÆ branch auto-fetch | Γ£à live ΓÇö type valid IFSC on Add Bank ΓåÆ Bank Name auto-fills + branch/city/state shown (free `ifsc.razorpay.com` directory, no key; `lookupIfsc()` + auth-gated `ifsc_lookup.php` proxy) |
| Merchant bank add/update/change | Γ£à (`add_bank.php`, `admin_merchant_banks.php`) |
| Merchant docs ΓåÆ Razorpay/Cashfree page | Γ£à (multi-gateway forward, shipped) |
| Website compliance check (Contact/Policy page) | Γ£à live ΓÇö "Run compliance check" on `merchant_website.php` scans homepage (SSRF-guarded, read-only) for HTTPS + Contact/Privacy/Terms/Refund/About pages, shows pass/fail scorecard (`checkWebsiteCompliance()`) |
| Premium KYC / Video-KYC design | Γ£à LIVE ΓÇö merchant KYC shows per-doc status + rejection reason banner; Video KYC shows reject reason + re-upload; admin stores `rejection_reason` + notifies merchant (`kyc.php`, `video_kyc.php`, `admin_kyc.php`, migration `014`) |

### PART 3 ΓÇö Gateways, API & transactions
| Item | Status |
|---|---|
| Razorpay / Cashfree / Decentro / PayU | ΓÜÖ∩╕Å keys pending ΓÇö adapters + test connections live; UI shows "Keys pending" via `isGatewayConfigured()`; create-order helpers no-op without full keys; `getActivePaymentGateway()` only selects checkout-capable configured gateways |
| Pine Labs Plural | ΓÜÖ∩╕Å scaffold ΓÇö sandbox stub `pineLabsSandboxCreateOrder()` + Gateway Settings fields; checkout gated (roadmap) |
| Test/Live toggle | Γ£à |
| API keys security/refresh/connect/notify | Γ£à (notify email + in-app done) |
| MDR settings (partner-wise) | Γ£à (`update_mdr.php`) |
| Hide platform fee from customer | Γ£à payer sees only "Amount Payable" (`checkout.php`); split breakdown removed |
| Txn/settlement status + exact reason | Γ£à live ΓÇö transaction detail shows a tone-coded plain-language reason banner (`transactionStatusExplainer()`); settlements show reason via `settlementReasonText()` on list + `settlement_detail.php` |
| Delayed split settlement (1ΓÇô2 hr batch) | Γ£à engine live (`includes/settlement_engine.php`, batches); ΓÜÖ∩╕Å bank rail confirms when partner keys are live |
| Unmatched webhook section | Γ£à `admin_reconciliation.php` ("Unmatched Webhooks" + "Gateway Txns Without Webhook Log"), linked in admin nav as "PG Reconciliation" |
| Shopify/WordPress/e-Rupee | ≡ƒö£ (WooCommerce plugin exists) |

### PART 4 ΓÇö Portals, UI/UX, QR & customer features
| Item | Status |
|---|---|
| 4 portals responsive | Γ£à (public/merchant/admin/staff); Customer portal Γ£à LIVE + premium redesign (`customer_login.php` / `customer_portal.php` / `customer_ticket.php`) |
| Full Hindi website | Γ¢ö DROPPED (owner decision) ΓÇö UI stays English-only |
| Google location autocomplete + autofill | Γ£à free OpenStreetMap Nominatim (search + device location); paid Google Places intentionally NOT used |
| Pincode ΓåÆ address autofill | Γ£à free India PIN lookup (`api.postalpincode.in`) ΓÇö type 6-digit PIN ΓåÆ State/District/City autofill (`address-picker.js`) |
| Razorpay-style QR + UniWeb logo + per-QR history | Γ£à LIVE ΓÇö centre UniWeb logo baked (GD, ECC-H), per-QR Collected/Payments summary + Print poster + "View payments" (`qr_code.php`, `qr_image.php`) |
| Customer profile self-update (auto-approve) | Γ¥î Γ¢ö fraud risk ΓÇö OTP-verify, no auto-approve |
| Invoice PDF (GST/name/addr/mobile/email/no.) | Γ£à LIVE ΓÇö PDF always prints Invoice No, Bill From (business name, GSTIN, full address, mobile, email) + Bill To (name, email, mobile, address); create form collects customer address (`invoice_pdf.php`, `SimpleInvoicePdf`, migration `013`) |

> **Customer Portal ΓÇö Γ£à PREMIUM + CROSS-ROLE (2026-07-21 overnight):** lightweight **payer-facing** portal.
> - **Login:** mobile + **WhatsApp/SMS OTP** (passwordless). Premium Manrope/Fraunces shell on `customer_login.php` (owner-approved redesign). Demo OTP when channels lack keys.
> - **History:** all txns for that mobile across merchants (matches `transactions.customer_phone` + `payment_links.customer_phone`), read-only, status + reason.
> - **Tickets:** raise grievance from any txn; thread replies.
> - **Cross-role:** Admin + Staff (`admin_customer_tickets.php` in staff nav for support/ops) see all; Merchant (`merchant_customer_tickets.php`) sees **own merchant_id only** and can reply; replies fan out WhatsApp/SMS when configured (`replyToCustomerTicket` / migration `016`).
> - **Guardrails:** no auto-approve contact self-update; no password on customer portal (OTP only).

| Payment method request (merchantΓåÆadmin) | Γ£à live ΓÇö merchant self-toggles only entitled methods; locked methods show "Request to Enable" ΓåÆ admin approve/reject queue (`admin_method_requests.php`), approval unlocks instantly (`includes/method_requests.php`, `collection_settings.php`) |

### PART 5 ΓÇö Payout system (new module)
| Item | Status |
|---|---|
| Payout enable request | Γ£à scaffold ΓÇö merchant request ΓåÆ admin approve (`merchant_payout.php`, `admin_payout.php`); live money still gated |
| IMPS/NEFT/RTGS/UPI payout | ΓÜÖ∩╕Å needs licensed payout partner keys (`payoutLiveMoneyAllowed()` hard gate) |
| Beneficiary mgmt + penny drop | Γ£à add/edit/list/deactivate + IFSC autofill; penny-drop button gated (`requestPayoutBeneficiaryPennyDrop`) |
| Bulk payout (CSV) | Γ£à scaffold ΓÇö CSV template + upload on `merchant_payout.php` (`processPayoutBulkCsv`); live money still gated |
| Separate collection vs payout wallet | Γ£à display-only split on payout page (`getMerchantWalletSplitView`) |
| Failed payout reason + auto-reversal | Γ£à failed drafts show `failure_reason`; reversal queue (`requestPayoutReversal` ΓåÆ admin reconcile) Γ¢ö NEVER auto-credits |
| Maker-checker high-value payout | Γ£à ΓëÑ Γé╣50k ΓåÆ `pending_checker`; `approvePayoutChecker` (maker Γëá checker); no live dispatch without keys |
| Payout API keys | Γ£à generate/rotate/revoke UI (`merchant_payout_keys.php`) ΓÇö live use gated; paste partner keys in Gateway Settings when signed |

### PART 6 ΓÇö Legal, security & marketing
| Item | Status |
|---|---|
| Privacy / Terms / Business Agreement | Γ£à |
| Admin login security + IST | Γ£à |
| Universal MFA/OTP (admin/staff mandatory, merchant optional) | Γ£à LIVE ΓÇö admin/staff login forces MFA enrollment or challenge (setup prompt, no lockout); merchant 2FA optional with dashboard/settings prompts + clear policy UI (`mfaPolicy()`, `merchant_2fa.php`, `admin_login.php`, `staff_login.php`) |
| Login pages (merchant / admin / staff / customer) | Γ£à PREMIUM redesigned (2026-07-21 overnight) ΓÇö shared `auth-portal.css` brand panel + focused form; POST/CSRF/fields unchanged. Owner freeze lifted. |
| Forget password all portals | Γ£à merchant + admin; customer is OTP-only (no password). Staff uses admin recovery for privileged accounts |
| API-generated email notification | Γ£à ΓÇö key regenerate (merchant `api_settings.php` + admin `admin_edit_merchant.php`) sends email + in-app notification + staff-activity log via `regenerateMerchantApiKey()`; secret never emailed |
| Blog + Search Console + WhatsApp | Γ£à LIVE ΓÇö blog exists; Search Console token via Gateway Settings ΓåÆ `google_site_verification` (meta in `header.php`); WhatsApp alerts fan out from `createNotification` ΓåÆ `onMerchantNotificationCreated` when merchant prefs enable WhatsApp + Meta keys are set |
| Cloud auto-deploy (laptop-free) | ΓÜÖ∩╕Å `.github/workflows/deploy.yml` on `main` ΓÇö **FTP curl** (parallel=8 + per-file retry) via `UNIWEB_FTP_*` secrets; verified green after parallel fix; PR #29 features also live via manual FTP. Merge to `main` to auto-deploy; fall back to manual FTP if Actions fails |
| Schema migrations 011ΓÇô017 | ΓÜÖ∩╕Å in repo + `migrations/README.md` ΓÇö **owner one-time** Gateway Settings ΓåÆ Apply pending migrations (same cron/watchdog key; do not invent `CRON_KEY`). Idempotent `IF NOT EXISTS` on ALTER columns |
| e-Rupee / Shopify / WordPress | ≡ƒö£ (WooCommerce Γ£à) |

---

## Recommended immediate sequence
1. **Owner one-time migrations** ΓÇö Gateway Settings ΓåÆ **Apply pending migrations** (011ΓÇô017). Expect `ok: true`. See `migrations/README.md`.
2. **Paste first partner gateway keys** when received; Test Connection; set Primary Payment Gateway.
3. **Confirm CI auto-deploy** on next `main` merge (Actions ΓåÆ Deploy to UniWeb Hostinger). If red, use laptop FTP once.
4. **DB cleanup / diabetes settlement status** ΓÇö only after owner confirms; backup + reversible soft-archive.
5. **Payout live money** ΓÇö only with licensed partner keys + `payout_live_enabled` (never auto-credit reversals).
6. **Pine Labs / PhonePe / e-Rupee / Shopify** ΓÇö after primary PG is live.

_Customer portal: Γ£à shipped (passwordless WhatsApp/SMS OTP ΓåÆ history ΓåÆ tickets; cross-role admin/staff/merchant). No auto-approve contact self-update; no in-house biometrics (Digio partner)._

---

## Session log ΓÇö 2026-07-22 (afternoon)

### Shipped / merged
- **PR #29 MERGED + LIVE** ΓÇö invoice PDF fields, premium KYC + rejection reason, MFA policy, payout scaffold (money-gated), Search Console + WhatsApp fan-out, customer portal premium + cross-role tickets, all 4 login redesigns, Pine Labs stub, `cloud_modules.php`, migrations `013`ΓÇô`017`.
- **CI auto-deploy fixed** ΓÇö Hostinger FTP parallel curl (`xargs -P8`); Actions run **green**. Secrets: `UNIWEB_FTP_*` (no secrets in git).
- **PR #30 MERGED** (was draft from cloud agent [Continue remaining parts overnight](bc-b39e79bc-fc69-40fd-a1a7-754269257a9e)) ΓÇö `migrations/README.md`, migration apply link in Gateway Settings, gateway create-order gated by `isGatewayConfigured()`, CI FTP retry, KYC load safety (`function_exists` + `config.dev.php` stub cleanup).

### Cloud agents status (this session)
- Overnight agent completed work into PR #29 / later PR #30 draft; some follow-up cloud resumes failed with conversation-state version errors ΓÇö work was recovered from draft PR #30 and merged.
- Photo inbox `_inbox/` empty (no new owner screenshots).

### Still owner-manual
1. Gateway Settings ΓåÆ **Apply pending migrations** (011ΓÇô**018**)
2. Paste gateway / Digio / payout partner keys when received
3. Confirm DB cleanup + ΓÇ£diabetesΓÇ¥ row only after explicit OK

### Owner photo inbox (2026-07-22)
- **28 photos** synced to `_inbox/`. Full task list: `_inbox/PHOTO_NOTES.md`.
- **PR #32 MERGED + LIVE** (FTP deploy green) ΓÇö clickables, settlement fail reason, checkout mobile mandatory, invoice tax %, KYC/Axis/P2M/open-amount QR, layout polish.
- Follow-up inbox photo: customer portal mobile field too small ΓåÆ enlarged `+91` control (**LIVE** PR #35).
- **PR #37** chat inbox `_inbox/chat/` LIVE. Strategy pack waits for owner **start**.
- Auto: live `/cust` 404 ΓåÆ physical `cust/index.php` redirect (next PR).

