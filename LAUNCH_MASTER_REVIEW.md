# UniWeb — Master Launch List Review

_Evaluation of the full owner checklist against the actual codebase + live status. For owner + advisor._

**Legend:** ✅ Live · ⚙️ Built, needs keys/config · 🔧 Partial / enhancement needed · ❌ Not built (new module) · 🐞 Reported bug (verify in code) · ⚠️ Risk · ⛔ Avoid / reconsider

---

## The 5 answers (summary)

### 1. What we already have (done)
Core of all existing portals is live: Public site, Merchant portal, Admin panel, Staff/Ops. Plus recently shipped: **one-click multi-gateway forward + status matrix + compliance audit trail + KYC document versioning**, MDR settings (`update_mdr.php`), 2FA/step-up, security headers, IST timezone, invoice PDF, branded errors, high-throughput QR, custom address auto-fill with "use my location", bank-verify scaffold, settlement batches/engine.

### 2. What's left (real work)
Payout module (new), Customer portal (new), full Hindi UI, Google-style address autocomplete, QR-with-logo + per-QR history, Aadhaar face-match, "unmatched webhook" admin section, several PART-1 bug fixes, and UI polish.

### 3. What we should NOT take / must reconsider ⛔
- **Auto-deleting production merchants/payments** ("keep only AK Digital Media") — destructive; must be a backup + reversible archive, owner-confirmed. Not run automatically.
- **Customer login portal with auto-approve mobile/email self-update** — payers rarely need accounts on a PA, and auto-approving contact changes is an account-takeover/fraud vector. Reconsider scope.
- **In-house Aadhaar face-mapping / biometrics** — use a certified partner (Digio); do not store raw biometrics (legal exposure).
- **Failed-payout auto-reversal without a reconciliation gate** — money-movement risk.
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
| DB clean-up (keep only "AK Digital Media") | ⚠️⛔ destructive — owner-confirm + backup first, reversible archive (not auto) |
| Test/Live toggle per merchant | ✅ (`merchant_toggle_mode.php`) |
| Wallet balance transfer bug | 🐞 verify (`includes/wallet.php`, `admin_wallet.php`) |
| Payment link "available method" / "please select an item" error | 🐞 verify (`payment_links.php`) |
| Report & Analysis oversized icons / trends | 🐞 UI (`reports.php`) |
| Sidebar icons cut off | 🐞 UI (`header.php`) |
| Laptop footer | ✅ fixed + live (PR #28 today) |
| Staff activity log not working | 🐞 verify (`admin_staff_activity.php`, `includes/staff.php`) |
| Agreement download bug | 🐞 verify (`merchant_agreement_pdf.php`) |
| Settlement batch number clickable | 🔧 small UI (`admin_settlement_batches.php`) |
| "Use my location" button | 🐞 verify (`assets/js/address-picker.js`) — button exists |
| "diabetes" weird settlement status word | ✅ not found in code (likely stale DB value) — verify data |
| Unmatched webhook log section (admin) | 🔧 partial (`admin_pg_webhooks.php` exists; add dedicated view) |

### PART 2 — KYC, onboarding & bank verification
| Item | Status |
|---|---|
| KYC docs (Aadhaar/PAN/GST/MCA/Udyam) entity-based | ✅ |
| Video KYC | ✅ (page); ⚙️ automated match needs Digio |
| Aadhaar face mapping (live selfie) | 🔜 ⚙️ via partner (do not build in-house) |
| Bank verification (penny drop + name fetch) | ⚙️ scaffold (`includes/verification.php`, `add_bank.php`) — needs live bank/Decentro keys |
| IFSC → branch auto-fetch | 🔧 verify/complete |
| Merchant bank add/update/change | ✅ (`add_bank.php`, `admin_merchant_banks.php`) |
| Merchant docs → Razorpay/Cashfree page | ✅ (multi-gateway forward, shipped) |
| Website compliance check (Contact/Policy page) | 🔧 partial (`includes/merchant_website.php`) |
| Premium KYC / Video-KYC design | 🔧 polish |

### PART 3 — Gateways, API & transactions
| Item | Status |
|---|---|
| Razorpay / Cashfree / Decentro / PayU | ⚙️ keys pending |
| Pine Labs Plural | ❌ new integration |
| Test/Live toggle | ✅ |
| API keys security/refresh/connect/notify | ✅ / 🔧 (notify email 🔧) |
| MDR settings (partner-wise) | ✅ (`update_mdr.php`) |
| Hide platform fee from customer | 🔧 verify (`checkout.php`) |
| Txn/settlement status + exact reason | 🔧 improve copy |
| Delayed split settlement (1–2 hr batch) | ✅/⚙️ (`includes/settlement_engine.php`, batches) |
| Unmatched webhook section | 🔧 |
| Shopify/WordPress/e-Rupee | 🔜 (WooCommerce plugin exists) |

### PART 4 — Portals, UI/UX, QR & customer features
| Item | Status |
|---|---|
| 4 portals responsive | ✅ (public/merchant/admin/staff); Customer portal ❌ |
| Full Hindi website | ⛔ DROPPED (owner decision) — UI stays English-only |
| Google location autocomplete + autofill | 🔧 custom picker exists (not Google Places) |
| Pincode → address autofill | 🔧 verify |
| Razorpay-style QR + UniWeb logo + per-QR history | 🔧 enhancement (`qr_code.php`) |
| Customer profile self-update (auto-approve) | ❌ ⛔ fraud risk — OTP-verify, no auto-approve |
| Invoice PDF (GST/name/addr/mobile/email/no.) | ✅ verify fields (`invoice_pdf.php`) |

> **Customer Portal — clarified owner spec (build later, step by step):** a lightweight **payer-facing** portal, NOT a full account.
> - **Login:** mobile number + **WhatsApp OTP** (passwordless). No email/password signup.
> - **View:** the payer's own **transaction history** (payments made across UniWeb merchants for that mobile number).
> - **Support:** from any transaction, **raise a grievance / support ticket** (dispute/complaint) and track its status.
> - **Guardrails:** do NOT auto-approve mobile/email changes (account-takeover risk) — OTP-verify any contact change; transactions are read-only, only ticket creation writes.
> - Priority: after PART-1 bugs + gateway + payout groundwork. Keep in roadmap.
| Payment method request (merchant→admin) | 🔜 |

### PART 5 — Payout system (new module)
| Item | Status |
|---|---|
| Payout enable request | ❌ new |
| IMPS/NEFT/RTGS/UPI payout | ❌ ⚙️ needs licensed payout partner |
| Beneficiary mgmt + penny drop | ❌ new |
| Bulk payout (CSV) | ❌ new |
| Separate collection vs payout wallet | ❌ new (wallet exists; split needed) |
| Failed payout reason + auto-reversal | ❌ ⚠️ auto-reversal risky |
| Maker-checker high-value payout | ❌ new (RBAC exists to build on) |
| Payout API keys | ❌ new |

### PART 6 — Legal, security & marketing
| Item | Status |
|---|---|
| Privacy / Terms / Business Agreement | ✅ |
| Admin login security + IST | ✅ |
| Universal MFA/OTP (admin/staff mandatory, merchant optional) | ✅/🔧 (2FA + step-up exist; enforce policy) |
| Forget password all portals | ✅ (existing portals) |
| API-generated email notification | 🔧 |
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
