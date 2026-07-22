# UniWeb — Photo inbox notes (agent memory)

Owner phone screenshots → `_inbox/`. Sync via OneDrive. Do not treat `*-aaminalaptop.php` as source of truth; prefer git HEAD. If a file shrinks oddly, restore from git blob then re-apply fixes. Keep this folder **Always keep on this device** in OneDrive.

## Themes from owner photos (28)

| Area | Owner ask | Status |
|------|-----------|--------|
| A Layout | Homepage preview skewed on laptop; KYC empty right space; Payment Links icon cut; Staff Add Team Member form cut on mobile | homepage aspect fixed; KYC 2-col; Payment Links SVG; staff form scroll+padding |
| B Instant UPI QR | No fixed amount — open amount only | done (`qr_upi_print.php`) |
| C Settlements | Batch ID clickable; **failed reason visible**; settlement settings **P2M only**, other rails auto bank/partner | done |
| D Invoices | Tax as **%** (12/18/28), not ₹ amount | done |
| E KYC | “Select document” empty options; IEC Failed; admin SQL **collation mix** error | done (normalize entity, uploadable list, IEC format, collation-safe queries) |
| F Chargebacks/Disputes | Demo chargeback data; dispute ID clickable | done |
| G Admin | Axis Bank on gateway submit; **enable all** methods on edit merchant | done (+ migration 018) |
| H Clickables | Recon txn IDs + wallet commission TXN IDs clickable | done |
| I Checkout | Mobile **mandatory**, **no OTP**, email optional | done |

## Suggested fix order

1. Clickables + settlement fail reason (H/C) — done
2. Checkout phone mandatory / no OTP (I) — done
3. Invoice tax % (D) — done
4. KYC select + collation (E) — done
5. Layout cut-offs (A) — done (basic)
6. UPI open amount, P2M-only settings, chargeback demo, Axis, enable-all (B/C/F/G) — done

## Owner strategy pack (2026-07-22) — TALK FIRST, code only when owner says start

Do **not** implement until owner explicitly says “kaam start”. Budget unconstrained; prefer correct/partner-gated design over shortcuts.

| # | Topic | Repo reality (today) | Agreed direction |
|---|--------|----------------------|------------------|
| 1 | Txn/settlement **exact reason** copy | Mostly ✅ `transactionStatusExplainer()` + settlement reason text live | Next polish: clearer Hindi-owner-facing English copy, more statuses, list pages consistency |
| 2 | Shopify / WordPress / e-Rupee | WooCommerce plugin ✅ `plugins/woocommerce/`; Shopify/WP generic/e-Rupee 🔜 | After primary PG live; Shopify app + e-Rupee via bank/partner API |
| 3 | Razorpay-style QR + UniWeb logo + per-QR history | Marked ✅ LIVE in master review (`qr_code.php`, `qr_image.php`) | Owner called “quick win pending” — verify live vs gaps (logo bake, history UX) then polish |
| 4 | Auto-approve profile self-update | ⛔ fraud — never auto-approve | Contact change = OTP verify on **mobile and email** only; no silent profile overwrite |
| 5 | Payout stack (enable, rails, beneficiary, penny-drop, CSV, wallets, maker-checker, API keys) | Scaffold ✅; live money gated | Keys from partners: Razorpay/X, Cashfree, PayU, Worldline, Axis — paste when signed |
| 6 | Failed-payout auto-reversal | ⛔ OWNER-CONFIRMED: no auto-credit without recon | Reversal only after recon confirms bank did not debit + licensed partner |

### How we can execute (no code until “start”)

1. **Exact reason** — inventory every txn/settlement status → map to one-line English reason; show on detail + key list rows; no fake bank reasons.
2. **Shopify/WP/e-Rupee** — Woo already in repo; Shopify = OAuth app + webhook; e-Rupee = CBDC partner/bank API when available (not invent).
3. **QR polish** — smoke live QR print/logo/history; fix only missing bits.
4. **OTP contact change** — request → OTP to old+new channel → apply; never auto-approve.
5. **Payout** — keep UI; wire partner APIs only with keys + `payout_live_enabled`; penny-drop via Decentro/bank.
6. **Reversal** — queue + admin reconcile only; never wallet auto-credit on partner “failed”.

Owner sending more detail next — discuss before coding.

### Added detail (2026-07-22, via chat inbox docx)

- **#1 exact reason** — concrete spec liked by owner: gateway→reason mapping dict, webhook retry+idempotency, merchant UI reason column+icon, auto email/notify on failed settlement, audit log. Owner's key ask: reason should **auto-populate from partner straight to merchant** — no manual admin relay step needed.
- **New: KYC/onboarding polish wishlist** — progress % bar, auto-save drafts, AI doc blur/fake check, one-click "submit to all gateways", doc preview, status timeline, Google location for address, penny-drop bank-verify button, digital signature/e-sign on agreement.
- **New: generic "admin approve → 1-click to partner" flow** — whenever merchant/customer requests something (enable payment method, etc.), request lands with admin; admin has ~1 hour to review; one button press forwards straight to the partner (no manual re-typing/relay by admin). Apply this pattern broadly wherever a partner approval is needed.
- **Page audit false positives (not bugs):** `config.private.php` 403 (intentional `.htaccess` block) and `ifsc_lookup.php` 401 (intentional login-gate) — verified, no fix needed.

Still **waiting for explicit "start"** before any of this is coded.

### Owner confirm (2026-07-22 evening)

- Agent ki baat sahi; usi hisaab se note.
- Jo already ho chuka → chhod dena; jo pending → **sirf jab owner "start" bole**.
- Abhi **START nahi** — wait for explicit start.
- Mobile chat inbox: `_inbox/chat/` (photo-style OneDrive drop).

## ⚠️ Deploy investigation (2026-07-22 evening) — BLOCKED on owner's Hostinger hPanel screenshot

- Fixed: rate-limit issue (8-parallel FTP was tripping Hostinger's anti-abuse, causing "Failed to connect port 21" on ~290/312 files). Deploy now incremental (only changed files) + 3-parallel — a full-sync run completed 100% success (`Upload OK: 312 files`).
- **But:** live-site smoke probes (`ap-phone` CSS class, custom PHP marker files dropped at 5 different candidate FTP base paths: `.`, `public_html`, `domains/uniweb.co.in/public_html`, `httpdocs`, `www`) — **none** show up live. All return the app's own 404 page (proven by matching `UNIWEBSESSID` cookie + identical headers to a deliberately-fake path), meaning FTP uploads are landing somewhere that is **not** the real docroot Apache serves for uniweb.co.in.
- Homepage/login/demo/customer_login all return 200 live — so the real site works, just not from wherever this FTP account's files land.
- **Root cause unknown without owner's eyes on hPanel** — need a screenshot of Hostinger hPanel → Files → FTP Accounts (shows each account's bound "Directory"/docroot). Asked owner for this; diagnostic workflow `.github/workflows/ftp_probe.yml` left in repo for a follow-up round once we know the real path (delete after confirmed).

## Work log

- 2026-07-22: Photo fixes PR #32/#35/#36 live. Migrations 011–018 apply still owner-manual.
- 2026-07-22: Strategy pack confirmed; chat inbox created; coding waits for start.
