# UniWeb — Local laptop first

**Owner order (2026-07-24):** do **all** agent work on this Windows laptop.  
Do **not** use Cursor Cloud Agents (`cursor.com/agents`) unless the owner reverses this.

## Owner chat (permanent — 2026-07-24)

- Talk to the owner in **simple everyday Hindi / Urdu** (Delhi style). Short lines. No heavy tech jargon.
- Website / product UI text stays **English**.
- Rule file: `.cursor/rules/owner-simple-hindi.mdc`

## Autonomous execution (permanent — 2026-07-24)

- Owner authorizes autonomous continuation of the 20-day plan: implement, commit, push, and deploy without asking for per-step approval. Work one task at a time, retain safety checks, local-laptop-only workflow.
- Independently pick up and continue pending work: run tests, migrations, deploys, live checks, and fix errors without asking for routine command confirmation.
- Only prompt the owner for unavoidable actions requiring a human: private login/session steps, OTP, external approvals, or browser-only controls (e.g. clicking "Rotate key" in an authenticated live Gateway Settings page, then updating the Hostinger cron job URL with the new key).

Repo: https://github.com/6396601005/uniweb

## Local stack (Windows)

1. PHP 8.3+ and MariaDB must be on PATH.
2. From repo root:

```powershell
# one-time / idempotent
.\dev_local\bootstrap_db.ps1

# run app
php -S localhost:8000
```

Open http://localhost:8000/

Defaults: db `uniweb`, user `uniweb`, password `uniweb_dev`  
(override with `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS`).

`config.php` is gitignored — bootstrap copies `config.dev.php` → `config.php` if missing.

## Secrets

- Never commit `config.php`, `.vscode/sftp.json`, or live API keys.
- Partner gateway keys: paste in admin UI when received (live or local).

## Mobile inbox (still OK for notes/photos)

| Drop here | What |
|-----------|------|
| `_inbox/` | Screenshots / photos |
| `_inbox/chat/` | Text points (`.txt` / `.md`) |

## What this app is

UniWeb is a single PHP (8.3) + MariaDB payment-gateway web app. No build step, no Composer, no JS bundler — root `*.php` pages + `includes/*.php`. Every page starts with `require_once __DIR__ . '/config.php';`.

### Schema

- Real schema: `migrations/*.sql` (apply via bootstrap / `applyPendingMigrations()`).
- Do not hand-write production schema.

### Lint / test / run

```powershell
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch 'phpqrcode' } | ForEach-Object { php -l $_.FullName }
php tests/run_integrity_tests.php
php -S localhost:8000
```

### Notable gotchas

- `flash()` / `getFlash()` — single message; `header.php` uses `$flash['type']` / `$flash['message']`.
- Email-signup placeholder phone `+919900000000` must differ from `COMPANY_PHONE`.
- `demo.php` seeds demo merchant + ₹1 test link via `ensureDemoMerchant()`.
- Hello-world path: `merchant_register.php` → `merchant_setup.php` → `dashboard.php`.
- `config.dev.php` is the template; `config.php` is the gitignored runtime file. Adding a file to `config.dev.php`'s `$__includes` array does **not** auto-load it on production. For new includes needed at runtime, add an explicit `require_once` guard (see `includes/auto_audit.php`'s `qr_events.php` require pattern).

## Workspace Paths

- Primary active workspace: `c:\Users\start\OneDrive\Desktop\uniweb1`
- Secondary/related copy: `c:\Users\start\OneDrive\Desktop\uniweb`
- Treat `uniweb1` as the main repo.

## Pending Hard Requirements

- Video KYC must be a live camera capture, not a file upload.
- The captured session must record the user's IP address and the exact date/time.
- Do not build file-upload-only Video KYC.

## Remaining Work / Launch Plan

Summary from `LAUNCH_MASTER_REVIEW.md`, `FEATURE_CHECKLIST.md`, and `MULTI_GATEWAY_FORWARD_ANALYSIS.md` (Jul 26 2026):

1. Apply pending DB migrations 011–018.
2. Paste first partner gateway keys (Decentro staging credentials are in `_inbox` screenshots).
3. Build one-click multi-gateway forward + per-merchant status matrix + merchant auto-notify.
4. Build first real partner adapter (Decentro UPI P2M / dynamic QR) using staging keys. Gateway-agnostic architecture in `includes/gateways.php` is already ready, so there is no structural blocker.
5. Payout live money only after licensed partner keys are configured.
6. DB cleanup and diabetes settlement word fix only after owner confirm.

Avoid:

- Auto-deletion
- Auto-approve contact changes
- In-house biometrics
- Failed-payout auto-credit
- Google Places
- Full orchestrator before one live partner

Recommended immediate build order:

1. One-click multi-forward + status matrix
2. Pre-filled onboarding email/link
3. Document versioning
4. First real API adapter

## Partner Keys Status

- Decentro staging API credentials are in `_inbox` screenshots dated 2026-07-25 (subject: "STAGING Environment || Here are your Decentro Payments API Credentials").
- Active Decentro production access thread: Bhavana HA `<bhavana.ha@decentro.tech>`, cc Vaibhav Jain and Pratik Daudkhane.
- Modules requested from Decentro: KYC & Onboarding, Collections (UPI P2M / Payment Aggregator v3 with dynamic UPI Payment Links and UPI QR codes), Payouts, Virtual Accounts, Penny Drop, Aadhaar/DigiLocker/CKYC.
- Axis Bank UAT keys already in DB; production/live IP whitelist still pending.
- Soundbox / other partner keys not found in codebase or `_inbox`; likely still in owner's email inbox.
- Next practical step: paste Decentro sandbox keys into Gateway Settings and test KYC/collections API connectivity.

## Latest Status (2026-07-27)

- QR code admin features (points 1–10) completed and integrated.
- Payment link enhancements completed: `payment_links.php` (share + website embed modal), `admin_payment_links.php` (admin dashboard with merchant/partner filters, analytics, CSV export).
- Merchant support channels admin-configurable via Gateway Settings.
- Admin Website Reviews dashboard created (pending/verified/rejected merchants).
- Merchant website page enhanced with Pay Button embed code.
- All changes committed and deployed to Hostinger via GitHub Actions (run 30259994533, success).
- Local MariaDB running on `localhost:8000`.
- Next pending items: paste Decentro staging keys into Gateway Settings, build one-click multi-gateway forward + status matrix, build first real partner adapter (Decentro UPI P2M / dynamic QR).

## Latest Status (2026-07-28)

- Fixed broken phone-less WhatsApp share links (`wa.me/?text=` → `api.whatsapp.com/send?text=`) across qr_code.php, payment_links.php, merchant_website.php, includes/collection.php, admin_qr_codes.php.
- QR bulk-create (qr_code.php, action=bulk_create — one name per line, max 50).
- New `qr_analytics.php`: scan/payment KPIs, trend charts, top-performing QR table.
- QR expiry + low-scan merchant alerts: `includes/qr_events.php::runQrHealthAlerts()`, wired into the 10-min cron (`includes/auto_audit.php`). `qr_code_events.event_type` widened ENUM→VARCHAR(32) for new alert event types.
- `.gitignore` hardened: `_inbox/*.zip`, `_inbox/_tmp_*`, `_inbox/UniWeb_Master_Status_*`, `_inbox/_gen_points.*`, `_inbox/config_READY_TO_PASTE.php`, `dev_local/inbox_view/`, `Jump to Content*` — these are owner's personal notes/screenshots, never website content, never commit.
- Payment Links: added "No Expiry" option (`payment_links.php`, `expiry_hours=never` → `expires_at=NULL`). QR codes already default to no-expiry; admin edit form now clarifies "leave blank = no expiry".
- All committed + pushed + deployed via GitHub Actions (commits 6f61789, fe69bfc, 896603b, ee382db — all success).
- Conversational market research done: NPCI UPI caps are per-payer (₹1 lakh/day, 10-20 txn/day), not per-merchant-QR — merchant aggregate collection via one QR is NOT capped. Owner may ask for a full RazorpayX/Cashfree/PayU/SBI/ICICI vs UniWeb comparison table next — not built yet.
- **Session continuity**: if a new agent picks up this repo (e.g. after a "Permission denied / model unavailable" error forces a session switch), check Cascade memory tagged `session_continuity` + `qr_code` for full context, in addition to this file.
