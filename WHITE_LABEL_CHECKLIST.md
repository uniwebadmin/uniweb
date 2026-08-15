# UniWeb — branding / deal ops checklist (Phase 10 reference)

**Hard rule (Owner 2026-08-15):** UniWeb has **no white-label program**. We **do** run a **partner program** (work with bank / PG / KYC partners). Merchants only: **own domain** + **checkout customize**. Do not sell or build a full white-label portal.

**Status:** awareness / deal-ops reference only. Implement a row **only** if the Owner’s own deal needs that item — and only after Phase 0–2.

**Audit tickets:** WL-01 … WL-12 in `DEEP_AUDIT_ORDERED.md`  
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
| Audit log | `admin_audit_log.php` + `immutable_audit_log` — date CSV later if a deal asks |

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

**Problem:** API merchant create.  
**Expectation:** Documented REST.  
**Today:** **Later.** `api_docs.php` states there is no public create-merchant action. Signup / admin invite / KYC stay on the website.

**When a deal asks:** document REST then — do not invent a second onboarding app.

## WL-08 Status / SLA

**Problem:** Public status.  
**Expectation:** Honest `status.php`.  
**Today:** Named components, 90-day uptime, incident list, partner credentials labelled as credentials (not Live-rail health). `health.php` is the uptime probe (plain OK). Support acknowledgement: 1 business day.

## WL-09 Security pack

**Problem:** Questionnaires.  
**Expectation:** Evidence on trust pages.  
**Today:** `trust.php` — HTTPS, KYC Live gate, HMAC, audit, DPDP, no fake PCI/ISO badges, grievance contacts. Questionnaire answers map to those cards.

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

## Never from this checklist

- Homepage or pricing “White-label your gateway”
- Multi-tenant domains / separate white-label PHP app
- Giving Support/KYC staff Partner Registry keys
- Hiding GST / CIN on checkout
- Hiding Test vs Live badges
- Consumer PPI wallet or NBFC product
- Extra Hostinger crons
- Phase 11 Route/Split from this document

## Owner clicks (when a real deal needs one item)

1. Checkout Customize — logo, colours, enable.  
2. Hide UniWeb mark — only if the contract says so.  
3. Domain — Hostinger + `APP_URL`, not a new product.  
4. API Settings — webhook URL + copy the PHP verify block.  
5. Team / Staff Control — confirm keys stay with Super Admin.
