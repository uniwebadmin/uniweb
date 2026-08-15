# UniWeb Deep Audit — Ordered (full detail)

**Source PDF:** `C:/Users/start/Downloads/UniWeb_Deep_Audit_Ordered_First_Things_First.pdf`  
**Generated:** 2026-08-15 03:54 UTC  
**Evidence:** Hostinger tar 14 Aug 2026 · SQL dumps · ~434 PHP · tickets 2.1–2.16 + stability  
**Format:** Problem → Expectation → Solution  
**Product exclusions:** NBFC and customer PPI wallet only. Do not drop production DB.

Work **top-down by PHASE**. Owner verifies each phase on live before the next.  
Phases 9–10 (market / white-label) are **full reference** — they do **not** override Phase 0–2.

---

## How to use

- PHASE 0 — DB, migrations, schema, fatal capture (**FIRST**)
- PHASE 1 — Single money / keys plane
- PHASE 2 — Checkout, QR, methods, links
- PHASE 3 — KYC & onboarding
- PHASE 4 — Menus & panels A–Z
- PHASE 5 — Ops (Watchdog, cron, queue, notifications)
- PHASE 6 — Global search
- PHASE 7 — Public website
- PHASE 8 — Design polish
- PHASE 9 — Market comparison (reference only)
- PHASE 10 — White-label checklist (reference only)
- PHASE 11 — Later optional + never NBFC/PPI

---

## PHASE 0 — Database, migrations, schema, errors (DO FIRST)

Failure here corrupts later work. Never drop production DB.

### P0-01 · Migration runner / partial apply

1) **Problem:** `migrate_release` failed (e.g. Unknown column `partner_key` on `gateway_reason_maps` INSERT when table pre-existed). Checksum mismatch can block later migrations. Opaque failures hid file names.  
2) **Expectation:** Idempotent migrations; ALTER before INSERT; JSON shows file + SQLSTATE; `ok:true` or no pending.  
3) **Action:** 044-style fixes on all risky seeds; harden `migrations.php` + `migrate_release.php`; Owner runs until `ok:true`.

### P0-02 · Schema drift

1) **Problem:** Missing columns, collation mix, empty `partner_commercial` vs Pricing UI.  
2) **Expectation:** Migrations + `schema_ensure` aligned; commercial never fatal when empty.  
3) **Action:** Confirm `schema_ensure`; smoke Commercial, KYC joins, `gateway_events`.

### P0-03 · Snag / white screen / silent fatals

1) **Problem:** Fatals need page re-open; Owner cannot scan every URL.  
2) **Expectation:** Early catcher; `platform_errors`; Watchdog/Error Log from DB; no SQL in browser.  
3) **Action:** `env_loader` + `error_catcher` everywhere; Test error capture proof.

### P0-04 · config.php drift

1) **Problem:** Live gitignored `config.php` may miss `createNotification` dedup / includes.  
2) **Expectation:** Critical helpers match release.  
3) **Action:** Sync from `config.dev` after deploy. Adding a file to `$__includes` in `config.dev.php` does **not** auto-load on production.

### P0-05 · Undefined functions (mail)

1) **Problem:** `sendTemplatedEmail` without loaded templates → KYC fatal.  
2) **Expectation:** mailer loads templates; SMTP soft-fail.  
3) **Action:** Keep require chain; grep bare calls.

---

## PHASE 1 — Single money control plane

### P1-01 · Dual API key sources

1) **Problem:** `gateway_settings` plaintext vs Partner Detail “No Keys”; wrong Owner guidance to paste on Platform Integrations.  
2) **Expectation:** Keys only `partner_credentials` via Partner Detail; Platform = SMTP/cron/templates + banner.  
3) **Action:** Disable platform live secret fields; Owner uses Registry → Keys; rotate dumped secrets.

### P1-02 · partner_commercial / Pricing

1) **Problem:** Empty commercial caused snag.  
2) **Expectation:** UPSERT; non-fatal form; Commercial & Split tab; Route scaffold not live API.  
3) **Action:** Verify UPSERT; seed on first open.

### P1-03 · Primary PG misread

1) **Problem:** Platform primary looks global.  
2) **Expectation:** Template for new merchants only.  
3) **Action:** Keep relabel + banner.

---

## PHASE 2 — Checkout, QR, methods, links

### P2-01 · Checkout blank / no methods

1) **Problem:** `checkout.php?link=…` method load fail hid UPI/card tabs.  
2) **Expectation:** Render amount + enabled methods; soft message if no keys; clear empty state.  
3) **Action:** Harden checkout + collection loader; default enable UPI/QR after pack.

### P2-02 · QR image missing

1) **Problem:** Warnings corrupted QR image; missing UPI → blank.  
2) **Expectation:** Valid PNG or explicit UPI missing message.  
3) **Action:** Clean `qr_image.php`; require `upi_id`.

### P2-03 · Payment link wiring

1) **Problem:** List vs public URL confusion.  
2) **Expectation:** Copied link always opens checkout.  
3) **Action:** Create link → open public URL regression.

---

## PHASE 3 — KYC & onboarding

### P3-01 · Reject reasons letters

1) **Problem:** Stored h/j/k codes.  
2) **Expectation:** Human phrases; merchant same text.  
3) **Action:** Min length + presets + display helper.

### P3-02 · Video KYC verify

1) **Problem:** Blind UPDATE soft-fail.  
2) **Expectation:** Row-id verify/reject; clear flash.  
3) **Action:** Regression admin video actions. Video KYC must be **live camera**, not file-upload-only; record IP + datetime.

### P3-03 · Upload then error

1) **Problem:** Save then mid-flow error.  
2) **Expectation:** Clear success or error.  
3) **Action:** Guard post-upload path.

### P3-04 · Live activation gate

1) **Problem:** KYC ≠ live money.  
2) **Expectation:** Gate lists missing docs/bank/website/agreement.  
3) **Action:** Keep gate; ops complete fields.

---

## PHASE 4 — Menus & panels A–Z

Product exclusion: NBFC pages and customer PPI wallet — hide/do not build.  
Settlement Balance = merchant settlement only.

### 4.1 Admin

**P4-A01 Dashboard snag** — Queries without fallback → empty widgets + log → keep try/catch.  
**P4-A02 Partner Registry** — Tabs incomplete commercial/go-live → Keys, methods, MDR, webhook copy, go-live checklist → UI gates on existing pages.  
**P4-A03 Nav density** — Too many advanced items → daily path clear; Advanced collapsed → IA only.

### 4.2 Merchant

**P4-M01 Full P2M menu** — Over-slim live; Owner rejected 4–5 items → full groups; only NBFC/PPI hidden → deploy `header.php`; FTP path; hard refresh.  
**P4-M02 Settlement naming** — `wallet.php` confuses with PPI → merchant settlement labels → copy review.

### 4.3 Sub-merchant

**P4-SM01 Model unclear** — Surfaces without clear rules → document or hide until CRUD done → finish or hide.

### 4.4 Staff

**P4-ST01 Activity empty** — UI empty vs audit full → `staff_activity_logs` for admin → verify high-value `logStaffActivity`.

### 4.5 Team

**P4-TM01 Team roles** — May lag invite/role matrix → invite, roles, audit → extend `merchant_team` if stub.

### 4.6 Customer

**P4-C01 Portal scope** — `customer_*` for pay/support → not PPI wallet → do not expand to consumer wallet.

### 4.7 Wiring

**P4-W01 Dead links** — Nav to missing files → every URL resolves or removed → crawl nav vs filesystem.

---

## PHASE 5 — Ops reliability

### P5-01 · Watchdog/cron

1) **Problem:** Some checks fail unclear.  
2) **Expectation:** Failed checks listed with labels; key masked.  
3) **Action:** Clarify auto_audit + fix checks.

**Hostinger (live, Owner 14 Aug 2026):**  
- Required job EXISTS: every 10 min `cron_auto_audit.php` (Watchdog + KYC + settlements + mandates + forward queue inside).  
- Backup job EXISTS: `cron_db_backup.php` (twice daily).  
- Do **not** add separate settlement / KYC / migration crons.  
- Migrations = Gateway Settings → Apply pending migrations (one click, not a cron).

### P5-02 · Forward queue

1) **Problem:** Empty despite UI.  
2) **Expectation:** Idempotent enqueue on KYC/live events.  
3) **Action:** Verify one real row.

### P5-03 · Notification spam

1) **Problem:** Thousands of duplicates.  
2) **Expectation:** `event_key` dedup; optional archive.  
3) **Action:** Keep dedup helper.

### P5-04 · AML spam

1) **Problem:** Repeat `kyc_pending` flags.  
2) **Expectation:** Skip if open; clear on verify.  
3) **Action:** Keep `recordAmlFlag` dedup.

---

## PHASE 6 — Global search

### P6-01 · Not universal

1) **Problem:** Spotlight exists; coverage incomplete; min 3 chars; data types limited.  
2) **Expectation:** All nav pages 1:1 header; merchants, txns, links, staff, tickets, GSTIN/PAN; role-scoped.  
3) **Action:** Sync featurePages; more SQL types; ID prefixes; JSON tests.

### P6-02 · Discoverability

1) **Problem:** Ctrl+K unknown.  
2) **Expectation:** Visible search + examples.  
3) **Action:** UI tip optional.

---

## PHASE 7 — Public website

**P7-01 Homepage** — Weak value prop → hero, pillars, trust, CTAs → content rewrite.  
**P7-02 About/Solutions/Pricing** — Thin fee story → clear segments; honest pricing → align to commercial model.  
**P7-03 Contact** — Form must deliver → ticket or email + SLA → E2E + SMTP.  
**P7-04 Compliance pack** — Skeleton pages → real legal + status → fill trust/compliance/privacy/terms/faq/status.

---

## PHASE 8 — Design polish

**P8-01 Mobile tables** — Vertical text / clip → LTR + overflow-x → CSS cache-bust.  
**P8-02 Empty states** — Empty looks broken → next-action empty copy → standardise lists.

---

## PHASE 9 — Market comparison (REFERENCE ONLY)

Do **not** implement parity before Phase 0–2.

| Peer | What they do well | UniWeb bar |
|------|-------------------|------------|
| Razorpay | Docs, Links, QR, Route, trust UX | Match links/QR reliability; Route only when Owner asks |
| Cashfree | Payouts, verification, marketplace split | Payout polish after core collect |
| PayU | India coverage, cards | Credentials + methods hard |
| Juspay | Orchestration reliability | Clarify story; measure uptime |
| Stripe | Docs, webhooks, test mode | Webhook + API doc quality |
| Worldline | Acquiring POS+online | Online-first unless Owner adds POS |
| Decentro | Banking/UPI APIs | Sandbox vs live labels clear |

**P9-01 Packaging gap** — Features high; polish lags → educated empty states; actionable errors → polish after 0–2 green.

---

## PHASE 10 — White-label checklist (REFERENCE ONLY)

Not a command to sell white-label as UniWeb’s main product. Implement only if Owner’s own deals need a specific item — **after Phase 0–2**.

| ID | Topic | Expectation |
|----|--------|-------------|
| WL-01 | Branding/domain | Configurable brand; theme + domain guide when needed |
| WL-02 | Powered-by | Optional hide flag on checkout if deal requires |
| WL-03 | Test/live isolation | Badges + block live settle in test; `account_mode` rules |
| WL-04 | Webhooks | Signed webhooks + retry; copy blocks + reliability UI |
| WL-05 | RBAC | Role matrix without keys; staff roles carefully |
| WL-06 | Settlement export | Date-range CSV/API on reports.php |
| WL-07 | Onboarding API | Documented REST — later |
| WL-08 | Status/SLA | Honest `status.php`; content + health |
| WL-09 | Security pack | Evidence on trust pages |
| WL-10 | Disputes | Timers end-to-end after payments |
| WL-11 | Reconciliation | Runbook + ingest after volume |
| WL-12 | Audit export | Date export — small tool later |

---

## PHASE 11 — Later optional · never NBFC/PPI

### P11-01 · Live Route/Split API

Scaffold only today. Live only after keys + commercial + **explicit Owner ask**. No auto live status; no SDK early.

### P11-02 · NBFC & customer PPI (EXCLUDED)

License risk and product confusion. Not in product; hidden from menus. Keep `nbfc*` hidden; never ship consumer PPI wallet.

---

## APPENDIX — Evidence

Site tar + SQL 14 Aug 2026; ~434 PHP under `public_html`; migrations through 060; `checkout.php`; global_search; `header.php` full merchantNav in repo. Live HTTP/SMTP not executed offline in the PDF.

---

## Agent progress snapshot (2026-08-15, uniweb1)

Code landed on `main` around this audit (Owner still verifies live):

| Ticket | Code status | Owner still must |
|--------|-------------|------------------|
| P0-01 | Done in repo: 044 CREATE+ALTER before INSERT; named failures; checksum rebase | Live: Apply pending migrations until `ok:true` |
| P0-02 | Partial: `schema_ensure` + collation helper | Smoke Commercial / KYC joins on live |
| P0-03 | Done in repo: catcher, Error Log probe, header badge from DB | Confirmed: Test error capture row appeared |
| P0-04 | Open | Live `config.php` includes vs `config.dev.php` |
| P0-05 | Prior commit: mailer loads templates | Keep grep |
| P1-* | Done in repo: keys only Partner Registry; commercial UPSERT; new-merchant template | Paste Live keys on Partner Detail → Keys |
| P2-01 | Done in repo: amount always shown; methods soft-fail to UPI; keys missing = soft banner | Open one live checkout link |
| P2-02 | Done in repo: QR PNG flush + “UPI ID missing” image | Open QR / checkout QR on live |
| P2-03 | Done in repo: create → public checkout URL + Copy uses real URL | Create link → Copy → Open |
| P3-01 | Done in repo: h/j/k → human phrases; admin + merchant same text | Reject one doc on live, merchant sees same sentence |
| P3-02 | Done in repo: Video verify/reject by recording row; clear flash | Admin KYC → Video queue → Verify / Reject |
| P3-03 | Done in repo: upload success stays success if later notify fails | Merchant KYC upload / live camera video |
| P3-04 | Done in repo: Live gate lists docs/bank/website/agreement; ops complete links | Admin KYC → Live activation gate |
| P5-01 | Hostinger: 10-min auto-audit + backup cron exist | Do not add extra settlement/KYC/migration crons |
| Backup email | Default `startelecom620@gmail.com`; PHP dump if no mysqldump | SMTP in Gateway Settings; Hostinger Files → Backups for **full site** (Gmail cannot hold whole website) |

Next agent: Owner verifies Phase 3 on **live** (reject reason text, Video KYC verify, upload message, Live gate). Then Phase 4 menus. Do not start Phase 4 until that live click-check.
