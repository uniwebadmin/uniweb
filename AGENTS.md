# UniWeb — Local laptop first

**Owner order (2026-07-24):** do **all** agent work on this Windows laptop.  
Do **not** use Cursor Cloud Agents (`cursor.com/agents`) unless the owner reverses this.

## Owner chat (permanent — 2026-07-24)

- Talk to the owner in **simple everyday Hindi / Urdu** (Delhi style). Short lines. No heavy tech jargon.
- Website / product UI text stays **English**.
- For new proposals/research, use **explanation-first mode**: do not start implementation until the owner explicitly says "start karo"; answer owner questions one at a time in simple Hindi/Urdu covering **kya hai**, **kya fayda hai**, and **na kare to kya ho sakta hai**.
- Rule file: `.devin/rules/owner-communication.md`

## Autonomous execution (permanent — 2026-07-24)

- Owner authorizes autonomous continuation of the 20-day plan and approved phase-based work: implement, commit, push, and deploy without asking for per-step approval. Work one task at a time, retain safety checks, local-laptop-only workflow.
- This includes standing instructions such as "continue every phase, every time, auto mode, no permission needed" for approved multi-phase items like the high-volume UPI infra work.
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

## Deep Audit ordered plan (standing — 2026-08-15)

**Owner file:** `UniWeb_Deep_Audit_Ordered_First_Things_First.pdf` (Downloads, generated 2026-08-15 03:54 UTC).  
**Full text in repo:** `DEEP_AUDIT_ORDERED.md` (every ticket: Problem → Expectation → Solution).  
**Workspace:** `uniweb1` only. Local laptop. No cloud agents. No live Route SDK. No `*_v2` apps.

Work **top-down**. Owner verifies each phase on **live** before the next. Phases 9–10 are reference only — they do **not** jump ahead of 0–2.

| Phase | What | Do now? |
|-------|------|---------|
| **0** | DB, migrations, schema, snag/error capture | **FIRST** — remaining: P0-02 live smoke, P0-04 live `config.php` includes |
| **1** | Single money/keys plane (Partner Registry only) | After 0 green |
| **2** | Checkout, QR, methods, payment links | After 1; code started (P2-01/02 in repo) |
| **3** | KYC & onboarding (video = live camera + IP + time) | After 2 |
| **4** | Menus A–Z; hide NBFC/PPI only | After 3 |
| **5** | Ops: Watchdog, cron, queue, notification/AML dedup | 10-min + backup crons already on Hostinger |
| **6** | Global search coverage | Later |
| **7** | Public website copy | Later |
| **8** | Design polish (mobile tables, empty states) | Later |
| **9** | Market comparison | Reference — no parity build before 0–2 |
| **10** | White-label checklist | Only if a real deal needs an item, after 0–2 |
| **11** | Live Route/Split | Only after keys + commercial + Owner says start |
| **Never** | NBFC product, customer PPI wallet | Hidden; do not build |

Never drop production DB. Migrations = **Apply pending migrations** button, not a Hostinger cron.

**Hostinger crons already created (do not duplicate):**  
1) `*/10 * * * *` → `cron_auto_audit.php` (Watchdog + KYC + settlement + mandates + forward inside).  
2) Backup → `cron_db_backup.php`.  
Backup notify email: `startelecom620@gmail.com`. Full website restore = Hostinger **Files → Backups** (Gmail cannot hold the whole site). SMTP must be set for backup mail to arrive.

**Ticket IDs (see DEEP_AUDIT_ORDERED.md for full 1/2/3):**  
P0-01…P0-05 · P1-01…P1-03 · P2-01…P2-03 · P3-01…P3-04 · P4-A01/A02/A03 · P4-M01/M02 · P4-SM01 · P4-ST01 · P4-TM01 · P4-C01 · P4-W01 · P5-01…P5-04 · P6-01/02 · P7-01…P7-04 · P8-01/02 · P9-01 · WL-01…WL-12 · P11-01/P11-02.

---

## Remaining Work / Launch Plan (older Jul 2026 notes — superseded by Deep Audit order above)

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

## Latest Status (2026-07-31)

High-volume UPI infra — all 7 phases completed per owner note `_inbox/Screenshot/Read now.txt`:

1. **Multiple VA + Multiple QR** — DONE. New `merchant_virtual_accounts` table (migration 031), `includes/va_manager.php` (create additional VAs, pick least-busy, usage/fail counters, auto-disable after 10 fails/day). `admin_virtual_accounts.php` UI. Backward compatible with old single-VA columns on `merchants`.
2. **Smart QR/VA assignment** — DONE. Least-busy logic in `pickLeastBusyVirtualAccount()`, wired into `checkout.php` for `axis_va` handler.
3. **Fast webhook + queue** — DONE within hosting constraints (no Redis/RabbitMQ on Hostinger shared PHP). Used `fastcgi_finish_request()` fast-ack pattern via `includes/webhook_queue.php::webhookFastAck()`, wired into razorpay/cashfree/payu/axis webhook files. Idempotency already solid pre-existing (`registerGatewayEvent()` atomic INSERT + unique constraint in `includes/financial_integrity.php`).
4. **Real-time ledger** — ALREADY EXISTED. `ensureMerchantWalletReady()` in `includes/wallet.php` returns balance/available/pending_out/on_hold, shown in `wallet.php`, `settlements.php`, `dashboard.php`, `merchant_payout.php`.
5. **Rate limiting + retry/backoff** — DONE. `consumeApiRateLimit()` (120 req/min per API credential) already existed in `includes/platform_api.php`. Added `axisHttpRequest()` retry with exponential backoff (3 attempts, transient 5xx/429/network errors only) in `includes/axis.php`.
6. **Monitoring + alerts** — DONE. New `admin_transaction_monitor.php` (TPS, success/fail rate, per-minute buckets, VA health, per-collection-mode breakdown). VA auto-disable already alerts merchant + logs platform error.
7. **Merchant-facing multi-QR download/print/live-status** — ALREADY EXISTED (`qr_code.php` per-QR download+print+share+stats, `qr_download_zip.php`, `export_qr_codes.php`, `qr_analytics.php`).

All commits pushed to main: `0a689b8` (Phase 1), `514ded4` (Phase 2+4), `a1a9757` (Phase 5). All `php -l` clean, `tests/run_smoke_checks.php` 242/242 pass after each commit.

**Migration 031** `multi_virtual_accounts.sql`: owner confirmed they ran "Apply pending migrations" on production via Gateway Settings — migration applied. `merchant_virtual_accounts` table should now exist live. Local MariaDB on this dev laptop was still down/unreachable as of this note (`dev_local/apply_migrations.php` connection refused) — apply locally too next time local DB is up, to keep dev/prod schema in sync for future local testing.

Owner's explicit standing instruction: continue autonomously through phases without asking permission each time.

- **Session continuity**: if a new agent picks up this repo, read `AGENTS.md` Deep Audit section + `DEEP_AUDIT_ORDERED.md` first (order: Phase 0 → 1 → 2…). Also check Cascade memory tagged `session_continuity` + `qr_code`.

## Latest Status (2026-08-15)

Owner delivered ordered Deep Audit PDF. Live Hostinger has 10-min auto-audit + backup cron. Phases 0–9 are in repo. Phase 10 is **reference only** (`WHITE_LABEL_CHECKLIST.md`): checkout theme already exists; hide-UniWeb mark defaults OFF; Test/Live isolation already in settlement; webhook HMAC copy on API Settings; staff nav still has no Partner Registry keys. Do **not** sell white-label as the main product. Do **not** start Phase 11 Route/Split unless the Owner says start + keys + commercial.
