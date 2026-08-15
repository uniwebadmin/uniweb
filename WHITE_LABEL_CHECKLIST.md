# UniWeb — branding / deal ops checklist (Phase 10 reference)

**Hard rule (Owner 2026-08-15):** UniWeb has **no white-label program**. We **do** run a **partner program** (work with bank / PG / KYC partners). Merchants only: **own domain** + **checkout customize**. Do not sell or build a full white-label portal.

**Status:** awareness / deal-ops reference only. Implement a row **only** if the Owner’s own deal needs that item — and only after Phase 0–2.

**Audit tickets:** WL-01 … WL-12 in `DEEP_AUDIT_ORDERED.md`; WL-13 … WL-15, WL-EXIST, LIVE-01 in `DEEP_AUDIT_FULL_A_TO_Z.md`.  
**No public sales page.** There is no “buy white-label” CTA on the homepage.

## What already exists (do not rebuild)

| Need | Where it lives today |
|------|----------------------|
| Merchant logo / colours / checkout title | `checkout_customize.php` → table `merchant_checkout_customize` → applied on `checkout.php` |
| Platform name / logo | `APP_NAME`, `includes/brand_logo.php`, `assets/img/uniweb-logo.svg` |
| Platform domain | `APP_URL` in gitignored `config.php` (Hostinger). One site, one domain. |
| Hide “Secured by UniWeb” on checkout | `hide_powered_by` on checkout customize (**default OFF**) |
| Test vs Live badges | Checkout banners; Instant Test Pay only in Test; Live UTR cannot confirm |
| Test must not live-settle | `account_mode` + `isMerchantTest()` / `isSettlementSandbox()` in settlement engine |
| Signed merchant webhooks + retry | Merchant **API Settings**; HMAC `X-UniWeb-Signature`; Send Test; Retry row |
| Webhook reliability (platform) | `admin_webhook_reliability.php` + 10-min auto-audit retries |
| Merchant roles without partner keys | `merchant_team.php` matrix (Owner / Admin / Finance / Developer / Support / Viewer) |
| Staff roles without partner keys | Staff sidebar (`staffNavForRole`) does **not** list Partner Registry or Platform Settings |
| Settlement CSV | `reports.php` date range + Tally CSV |
| Status / SLA | `status.php` |
| Trust / security copy | `trust.php` and legal pages |
| Disputes | `admin_disputes.php` / merchant disputes — after real payments |
| Reconciliation | `admin_reconciliation.php` — after volume |
| Audit log | `admin_audit_log.php` + `immutable_audit_log` — date CSV export |
| Partner × method / MID rail view | Partner Registry + `admin_gateway_matrix.php` (ops explain which rail) |
| Onboarding API | **Parked** — written exception on `api_docs.php` (UI signup / invite / KYC) |
| Full portal chrome rebrand | **Not sold** — checkout customize only (see WL-15) |

## WL-01 Branding / domain

**Problem:** Logo, colours, domain requests.  
**Expectation:** Configurable brand.  
**Today:** Checkout Customize (logo URL, primary/accent/button colours, title, CSS). Platform brand is `APP_NAME` + SVG.

**When a deal needs a custom domain (Owner + Hostinger):**

1. Add the domain in Hostinger and turn on SSL.
2. Point it at this same UniWeb site (not a second app).
3. Set `APP_URL` in live `config.php` to `https://that-domain` (never commit this file).
4. Merchant still sets **their** logo/colours on Checkout Customize.
5. Do **not** build a `*_v2` white-label app or reseller portal. Merchant branding stays: domain (same site) + Checkout Customize only.

## WL-02 Powered-by

**Problem:** UniWeb brand on checkout.  
**Expectation:** Optional hide flag.  
**Today:** Checkout QR card shows “Secured by UniWeb”. Footer still shows GST / CIN (legal, keep). PayU/Razorpay “Powered by …” lines stay — those are partner rails, not UniWeb marketing.

**When a contract requires it:** merchant ticks **Hide UniWeb mark on checkout** on Checkout Customize. Default is OFF. GST/CIN row stays.

## WL-03 Test / live isolation

**Problem:** Mixed modes.  
**Expectation:** Badges + block live settle in Test.  
**Today:** `merchantAccountMode()` / `isMerchantTest()`; Test stripe on merchant portal; Instant Test Pay only in Test; Live checkout refuses typed UTR; settlement sandbox uses simulated UTR (`SIM…` / `PG-TEST-…`), not a live partner payout.

**When a deal asks:** click-check Test vs Live on one merchant. Do not invent a second isolation product.

## WL-04 Webhooks

**Problem:** Signed webhooks + retry.  
**Expectation:** Copy blocks + reliability UI.  
**Today:** Merchant **API Settings** — URL, signing secret, HMAC-SHA256 header, PHP verify snippet, Send Test, delivery log Retry. Ops: **Webhook Reliability** (replay / dead letter). Cron retries stay inside the existing 10-min auto-audit. Do **not** add a new Hostinger cron.

**When a deal asks:** point their developer at API Settings + `api_docs.php`. Extend that page — do not build a new webhook product.

## WL-05 RBAC

**Problem:** Limited ops roles.  
**Expectation:** Role matrix **without keys**.  
**Today:**

- Merchant team: Developer/Admin can use **merchant API keys**. Viewer/Support cannot. Nobody on the merchant team gets Partner Registry / bank keys.
- Staff: Support / KYC / Finance / Field see tickets, KYC, money lists as allowed. Partner Registry + Platform Settings stay Super Admin (CEO) — and Registry also Ops. Do **not** add `gateway_settings.php` to staff nav.

**When a deal asks:** add a role only if it cannot see live partner keys.

## WL-06 Settlement / reports export

**Problem:** CSV/API reports.  
**Expectation:** Date-range export.  
**Today:** Merchant **Reports** — From/To dates, **Download CSV (date range)**, Tally Accounting CSV. API `list_transactions` accepts optional `from` / `to` (`YYYY-MM-DD`).

**When a deal asks:** use Reports CSV. Do not build a second report product.

## WL-07 Onboarding API

**Problem:** Banks want REST create-merchant / KYC status.  
**Expectation:** Documented API **or** written exception (UI-only).  
**Today:** **Parked until a named deal.** `api_docs.php` → “Merchant onboarding API” states there is **no public REST** create-merchant. Path today: merchant signup + admin invite + KYC UI.

**When a deal asks:** extend `api_docs.php` (+ OpenAPI) for create-merchant / KYC status only — do not invent a second onboarding app.

## WL-08 Status / SLA

**Problem:** Public status.  
**Expectation:** Honest `status.php`.  
**Today:** Named components, 90-day uptime, incident list, partner credentials labelled as credentials (not Live-rail health). `health.php` is the uptime probe (plain OK). Support acknowledgement: 1 business day.

## WL-09 Security pack

**Problem:** Fake PCI Level 1 badges destroy diligence.  
**Expectation:** Honest answers mapped to trust controls.  
**Today:** `trust.php` questionnaire cards + `pci_dss.php` readiness path. **Never** invent PCI Level 1 / ISO / SOC 2 badges. Card data sits with licensed partners. Map buyer questionnaires to Trust centre rows (HTTPS, KYC Live gate, HMAC, audit, DPDP, grievance).

**When a deal asks:** answer from `trust.php` / `pci_dss.php` only — do not invent a second “cert pack” product.

## WL-10 Disputes

**Problem:** Thin SLA.  
**Expectation:** Timers end-to-end.  
**Today:** Merchant raises a dispute; admin queue shows a **5-day due** (overdue in red). Chargebacks already have evidence due dates. Full bank SLA polish waits on real payments.

## WL-11 Reconciliation

**Problem:** Tables exist.  
**Expectation:** Runbook + ingest.  
**Today:** Admin **PG Reconciliation** — upload partner CSV, match unmatched, daily summary. Auto-audit already marks obvious matches. Use after live volume.

## WL-12 Audit export

**Problem:** Audit log present.  
**Expectation:** Date export.  
**Today:** Admin **Audit Log** — From/To + **Download CSV** (max 5,000 rows). Immutable log stays; this is the small export tool.

## WL-13 Multi-MID / multi-acquirer matrix

**Problem:** Enterprise wants clear MID ↔ partner rail.  
**Expectation:** Ops can explain which rail each merchant uses.  
**Today:** **Partner Registry** (keys / methods / activate) + **Gateway Routing Matrix** (`admin_gateway_matrix.php`) + merchant enabled methods. UniWeb merchant code = platform MID label; partner sub-MID lives with the partner after forward.

**When a deal asks:** deepen matrix UI only if Owner names that deal — do not build a second multi-acquirer product before Phase 0–2 live green.

## WL-14 Maker-checker dual control

**Problem:** Roles exist; full dual-approve product is thin.  
**Expectation:** Sensitive money / go-live needs second approver when contract requires.  
**Today (interim):** Staff roles + KYC/Live checker flows where already wired + immutable **Audit Log** + payout maker-checker threshold scaffold. Not a full bank-grade dual-control product.

**When a deal asks:** extend dual-approve only for that named contract — do not invent a second approval app for marketing.

## WL-15 Full portal white-label shell

**Problem:** Checkout brandable; dashboard still UniWeb chrome.  
**Expectation:** Portal chrome matches buyer when deal requires.  
**Today:** **Not a UniWeb product.** Merchant branding limit stays: **own domain** (same Hostinger site) + **Checkout Customize**. No `*_v2` app, no reseller portal, no “buy white-label”.

**If Owner ever names a deal that needs more:** prefer config flags for logo/name on the **same** codebase — still not a second app. Until then: do not implement portal chrome rebrand.

## WL-EXIST — Already present for buyers

Buyers often miss what already ships. Point them here (not a sales white-label page):

| HAVE | Where |
|------|--------|
| Hide powered-by on checkout | Checkout Customize (`hide_powered_by`) |
| Test / Live isolation | Account mode badges; Instant Test Pay; settlement sandbox |
| HMAC webhooks + retry | API Settings + Webhook Reliability |
| RBAC without partner keys | Merchant team + staff nav matrix |
| CSV reports | Reports date-range + Tally CSV |
| Status page | `status.php` + `health.php` |
| Dispute timers | Admin disputes overdue / 5-day due |
| Recon upload | Admin PG Reconciliation |
| Audit CSV | Admin Audit Log export |
| API docs scaffold | `api_docs.php` + OpenAPI |

## LIVE-01 — After every Git pull (Owner)

**Problem:** GitHub code is not live until Hostinger pulls.  
**Expectation:** Pull → hard refresh → smoke.  
**Owner smoke:** home → merchant login → Payment Links (methods + Fixed/Open) → Payment Pack Regenerate → checkout Instant Test Pay → admin dashboard → KYC decrypt view.

Also see `_inbox/chat/LIVE_01_after_git_pull.txt`.

## LIVE-02 — Migrations & PII (Owner)

**Problem:** Schema / cipher width may lag code after pull.  
**Expectation:** Pending migrations applied; plaintext PII encrypted if any remains.  
**Never:** `DROP DATABASE`.

**Owner clicks (after LIVE-01 pull):**

1. Admin → **Platform / Gateway Settings** → **Apply pending migrations** (expect JSON `ok: true`). Includes **062** (widen PII cipher columns) and **063** (payment link Fixed/Open `amount_type`).  
2. Admin → **Encrypt PII Backfill** — run only if pending plaintext count &gt; 0. Do not change `ENCRYPTION_KEY` casually.  
3. Spot-check one merchant KYC view (PAN/GST decrypt readable).

Also: `_inbox/chat/LIVE_02_migrations_pii.txt`.

## LIVE-03 — Backup before Hostinger cleanup (Owner)

**Problem:** Block A file deletes without backup can break the site.  
**Expectation:** Files backup + full SQL export **first**.  
**Then:** delete only proven junk (`zip`, `tests/`, `dev_local/`, etc.) per `BLOCK_A_CLEANUP.md`. Never hard-delete money transactions.

Also: `_inbox/chat/LIVE_03_backup_before_cleanup.txt`.

## Never from this checklist

- Homepage or pricing “White-label your gateway”
- Multi-tenant domains / separate white-label PHP app / full portal shell (WL-15)
- Giving Support/KYC staff Partner Registry keys
- Hiding GST / CIN on checkout
- Hiding Test vs Live badges
- Consumer PPI wallet or NBFC product
- Extra Hostinger crons
- Phase 11 Route/Split from this document
- Fake PCI Level 1 / invent certifications (WL-09)
- Public create-merchant REST without a named deal (WL-07)

## Owner clicks (when a real deal needs one item)

1. Checkout Customize — logo, colours, enable.  
2. Hide UniWeb mark — only if the contract says so.  
3. Domain — Hostinger + `APP_URL`, not a new product.  
4. API Settings — webhook URL + copy the PHP verify block.  
5. Team / Staff Control — confirm keys stay with Super Admin.  
6. After Git pull — LIVE-01 smoke list above.  
7. LIVE-02 — Apply pending migrations; Encrypt PII if needed.  
8. LIVE-03 — Backup before any Hostinger junk deletes.
