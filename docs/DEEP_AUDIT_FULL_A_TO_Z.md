# UniWeb Deep A-to-Z Audit

**Generated:** 2026-08-15 14:42 UTC  
**PDF:** `UniWeb_Deep_A_to_Z_Audit_20260815_144246.pdf`  
**Format:** Problem ΓåÆ Expectation ΓåÆ Solution (English)

## 0 ┬╖ Executive summary & how to use

This report is evidence-based from the uniweb1 codebase (nav, search, panels, public pages, Phase 11 parks). It is not a live browser crawl of every Hostinger URL. Owner should verify Priority fixes on live after Git pull. Work top-down: wiring and search first; market/white-label are reference.

### SUM-01 ┬╖ What is healthy already

1) **THE PROBLEM:** Without a baseline, every gap looks like a blocker.

2) **THE EXPECTATION:** Know what already works so effort goes to real gaps.

3) **THE SOLUTION / ACTION:** Keep: merchant/admin/staff nav file targets exist; NBFC and customer PPI wallet stay out of menus; Video KYC is live camera (not upload-only); Partner Registry as keys plane; payment links Fixed+Open; Instant Test Pay in Test Mode; Watchdog/cron pattern; customer portal = pay + complaints only.

### SUM-02 ┬╖ Top priority action list

1) **THE PROBLEM:** Too many findings can freeze progress.

2) **THE EXPECTATION:** A short ordered list for the next engineering week.

3) **THE SOLUTION / ACTION:** P1: Global search coverage + admin Payment Links/QR in nav+search. P2: Fix abortFeatureDisabled redirect; remove duplicate Watchdog nav; soft PDF/KYC die pages. P3: Merge reports/monitor UX; pci.php alias; public copy wallet wording. P4: Owner live smoke + migrations Apply. Park: live Route, NBFC, PPI.

### SUM-03 ┬╖ Doable vs not doable (product)

1) **THE PROBLEM:** Requests for NBFC/PPI/Route look like missing features but are Owner parks.

2) **THE EXPECTATION:** Clear DOABLE now vs NEVER / PARKED.

3) **THE SOLUTION / ACTION:** DOABLE: search expansion, nav cleanup, empty states, PCI alias, soft errors, pack regenerate, KYC queue, partner keys paste. PARKED/NEVER: live Route SDK, NBFC lending, customer PPI wallet, fake RBI PA claims, dropping production DB.

## 1 ┬╖ Technical errors, bugs & wiring

Broken wiring, white screens, dead links, wrong redirects.

### TECH-01 ┬╖ NBFC disabled redirect can land on public home

1) **THE PROBLEM:** abortFeatureDisabled() checks isMerchantLoggedIn() which does not exist in repo (isLoggedIn() does). Logged-in users hitting hidden NBFC URLs may be sent to index.php or a bare 403 exit.

2) **THE EXPECTATION:** Merchant ΓåÆ dashboard.php; staff/admin ΓåÆ staff/admin dashboard; clear flash. Never bare white exit for logged-in users.

3) **THE SOLUTION / ACTION:** Fix includes/ops_security.php abortFeatureDisabled() to use isLoggedIn()/isAdminLoggedIn(); replace bare exit with flash+redirect.

### TECH-02 ┬╖ PDF / KYC document bare die() white ends

1) **THE PROBLEM:** invoice_pdf.php, merchant_agreement_pdf.php, admin_kyc_doc.php still die/exit with plain text on missing docs.

2) **THE EXPECTATION:** Minimal branded HTML error with Back link (same pattern as checkout unavailable).

3) **THE SOLUTION / ACTION:** Replace die/exit with a small error template or flash+redirect when a session exists.

### TECH-03 ┬╖ Some merchant pages redirect to login without flash

1) **THE PROBLEM:** merchant_launch.php, merchant_setup.php and similar call redirect(login) without always flashing why.

2) **THE EXPECTATION:** User always sees Please log in or session expired.

3) **THE SOLUTION / ACTION:** Use requireLogin() everywhere or flash before redirect.

### TECH-04 ┬╖ pci.php missing (pci_dss.php is real page)

1) **THE PROBLEM:** Footer correctly links pci_dss.php; /pci.php does not exist ΓåÆ 404 for old bookmarks.

2) **THE EXPECTATION:** /pci or pci.php reaches the PCI statement.

3) **THE SOLUTION / ACTION:** Add thin pci.php redirect to pci_dss.php (same pattern as cust.php).

### TECH-05 ┬╖ Admin Watchdog listed twice in Advanced nav

1) **THE PROBLEM:** admin_watchdog.php and admin_link_audit.php both appear; the second only redirects to Watchdog rules tab.

2) **THE EXPECTATION:** One nav entry for Link Watchdog.

3) **THE SOLUTION / ACTION:** Remove admin_link_audit.php from sidebar; keep redirect file for old bookmarks.

### TECH-06 ┬╖ Video KYC appears as its own nav page but only redirects

1) **THE PROBLEM:** video_kyc.php only redirects to kyc.php?section=video while still listed in merchant nav.

2) **THE EXPECTATION:** One KYC entry; video section inside KYC.

3) **THE SOLUTION / ACTION:** Remove video_kyc.php from sidebar; link KYC ΓåÆ video section. Keep alias files.

### TECH-07 ┬╖ Live config include drift (partially mitigated)

1) **THE PROBLEM:** Live Hostinger config.php uses a short require_once list; many helpers were missing until cloud_modules bridge.

2) **THE EXPECTATION:** All runtime includes load without overwriting DB/ENCRYPTION secrets.

3) **THE SOLUTION / ACTION:** Git pull so cloud_modules.php loads missing modules. Optional later: sync only $__includes; never paste full config.dev over live config.

### TECH-08 ┬╖ Cron Forbidden die is OK for machines

1) **THE PROBLEM:** Cron scripts exit Forbidden when hit without key ΓÇö looks like white text if opened in browser.

2) **THE EXPECTATION:** Humans use admin Watchdog UI; crons stay machine-only.

3) **THE SOLUTION / ACTION:** Keep cron die; do not add crons to sidebar. Document in ops runbook.

### TECH-09 ┬╖ Payment Pack old links could 404/error

1) **THE PROBLEM:** Expired or inactive pack URLs opened Error tabs for Demo Store.

2) **THE EXPECTATION:** Pack shows only active Fixed+Open links; regenerate retires old ones.

3) **THE SOLUTION / ACTION:** Already fixed in code (027c3f9): regenerate Fixed+Open, inactive old pack. Owner: Git pull + Regenerate Pack on live.

### TECH-10 ┬╖ Payment Links method dropdown empty (partner filter)

1) **THE PROBLEM:** get_available_pay_methods filtered Direct UPI via gateway_registry/partner_methods so only All enabled methods showed.

2) **THE EXPECTATION:** Entitled methods (at least UPI P2M) always selectable in Test Mode.

3) **THE SOLUTION / ACTION:** Already fixed (7beb1b2). Owner hard-refresh Payment Links after pull.

## 2 ┬╖ Design, menus & page structure

Where menus and pages should sit for a professional PA/PG console.

### IA-01 ┬╖ Homepage should sell Collect first

1) **THE PROBLEM:** If the hero is generic fintech noise or over-claims (instant settle, white-label buy, Route live), serious merchants bounce.

2) **THE EXPECTATION:** Hero: collect via UPI/QR/links/checkout; Test free; Live after KYC. Two CTAs. Trust strip. No NBFC/PPI/Route as available products.

3) **THE SOLUTION / ACTION:** Tighten index.php to Collect / Operate / Settle; link Trust, Status, API docs; keep compare.php honest.

### IA-02 ┬╖ Admin Advanced is too dense

1) **THE PROBLEM:** ~40 Advanced items bury daily tools.

2) **THE EXPECTATION:** Daily path short; advanced tools findable via search + subgroups.

3) **THE SOLUTION / ACTION:** Keep collapse; subgroup Risk / Money / Ops / Security; fix search so Advanced pages are jumpable by nickname.

### IA-03 ┬╖ Admin Payment Links & QR Codes missing from sidebar

1) **THE PROBLEM:** admin_payment_links.php and admin_qr_codes.php exist and staff can open them, but they are not in admin/staff nav.

2) **THE EXPECTATION:** Ops pages used daily appear in nav and search.

3) **THE SOLUTION / ACTION:** Add both to uniwebAdminNavGroups and staffNavForRole for finance/ops/ceo/super.

### IA-04 ┬╖ Merchant Collect group is correct

1) **THE PROBLEM:** None critical ΓÇö Payment Links, QR, Instant UPI QR, Payment Methods map to collect.

2) **THE EXPECTATION:** Collect tools stay together; Instant UPI QR must warn money may bypass UniWeb ledger.

3) **THE SOLUTION / ACTION:** Keep group; strengthen Instant UPI QR banner copy.

### IA-05 ┬╖ wallet.php naming vs PPI fear

1) **THE PROBLEM:** Word wallet confuses buyers who fear consumer PPI.

2) **THE EXPECTATION:** Merchant settlement balance language only.

3) **THE SOLUTION / ACTION:** Keep page; prefer nav label Settlement Balance; solutions.php copy Settlements & wallet ΓåÆ Settlements & balance.

### IA-06 ┬╖ Staff vs merchant Team labels

1) **THE PROBLEM:** Team/staff words collide across merchant_team and admin_manage_staff.

2) **THE EXPECTATION:** Merchant: Team members. Admin: Employees / Staff.

3) **THE SOLUTION / ACTION:** Copy-only rename in UI strings; do not merge files.

## 3 ┬╖ Panels AΓÇôZ (Admin, Merchant, Sub-merchant, Staff, Team, Customer)

### PNL-A01 ┬╖ Admin panel ΓÇö keys only in Partner Registry

1) **THE PROBLEM:** Historical dual key UIs confuse ops (platform paste vs Registry).

2) **THE EXPECTATION:** Live secrets only on Partner Detail ΓåÆ Keys; Platform Settings = SMTP/cron/templates.

3) **THE SOLUTION / ACTION:** Keep Phase 1 rule; Owner never pastes partner keys into wrong screen.

### PNL-A02 ┬╖ Admin KYC queue must show decrypted PII

1) **THE PROBLEM:** Ciphertext on KYC review blocks ops (Cleanup Block B).

2) **THE EXPECTATION:** Admin sees real PAN/GST after decrypt; DB stays encrypted.

3) **THE SOLUTION / ACTION:** Already shipped sensitiveUiPlain / decryptMerchantPiiFields. Owner: pull + migration 062 + Encrypt PII backfill if needed.

### PNL-M01 ┬╖ Merchant panel ΓÇö Fixed and Open payment links

1) **THE PROBLEM:** Merchants need both preset amount and customer-entered amount.

2) **THE EXPECTATION:** Create Fixed or Open on Payment Links; Pack offers both per method.

3) **THE SOLUTION / ACTION:** Shipped. Owner regenerate pack on live after pull.

### PNL-M02 ┬╖ Merchant Instant Settlement honesty

1) **THE PROBLEM:** UI exists but live bank transfer needs partner keys.

2) **THE EXPECTATION:** Never look like live payout without rails.

3) **THE SOLUTION / ACTION:** Keep waiting/partner messaging; do not market instant forever.

### PNL-SM01 ┬╖ Sub-merchant / Agents vs Team

1) **THE PROBLEM:** agents.php (child merchants), merchant_team.php (portal users), admin_sub_merchants.php (hierarchy) look duplicate.

2) **THE EXPECTATION:** Three clear models or hide unused Agents until CRUD is complete.

3) **THE SOLUTION / ACTION:** Keep files; document: Agents = franchise children; Team = login users; Sub-merchants = admin tree. Hide Agents from nav if unused.

### PNL-ST01 ┬╖ Staff cannot see partner keys (correct)

1) **THE PROBLEM:** None ΓÇö intentional exclusion from staff nav.

2) **THE EXPECTATION:** Support/ops work without Registry secrets.

3) **THE SOLUTION / ACTION:** Keep. Add Payment Links/QR to staff nav without adding Registry.

### PNL-ST02 ┬╖ admin_risk_engine allows phantom role risk

1) **THE PROBLEM:** requireStaffAccess includes role risk which is not in staffRoleDefinitions.

2) **THE EXPECTATION:** Allowed roles Γèå defined roles.

3) **THE SOLUTION / ACTION:** Remove dead risk role or add real Risk role + nav; prefer map to ops/finance.

### PNL-ST03 ┬╖ Risk surfaces split across three pages

1) **THE PROBLEM:** admin_risk.php, admin_aml.php, admin_risk_engine.php overlap in mental model.

2) **THE EXPECTATION:** One Risk hub with Rules / Flags / Engine.

3) **THE SOLUTION / ACTION:** Keep code; add hub links or tabs; rename staff nav Risk & AML accordingly.

### PNL-C01 ┬╖ Customer panel scope is correct

1) **THE PROBLEM:** No global search; no wallet ΓÇö some owners may ask for both.

2) **THE EXPECTATION:** Customer = OTP login, history, complaints, profile. No PPI.

3) **THE SOLUTION / ACTION:** Keep. Optional later: customer-scoped ticket/txn search only.

### PNL-PUB01 ┬╖ Public legal pack present

1) **THE PROBLEM:** Blog may be empty until seeded; contact needs SMTP.

2) **THE EXPECTATION:** Filled legal + working contact ticket path.

3) **THE SOLUTION / ACTION:** Verify blog_posts on live; SMTP for contact mail; ensure DB ticket even if mail fails.

## 4 ┬╖ Duplicate pages & functions (keep / merge / hide)

Same job twice confuses merchants and staff. Decision per pair.

### DUP-01 ┬╖ wallet.php vs settlements.php

1) **THE PROBLEM:** Both settlement-related; shared icon.

2) **THE EXPECTATION:** Balance vs settlement history clearly split.

3) **THE SOLUTION / ACTION:** KEEP BOTH. Distinct icons; nav Settlement Balance vs Settlements.

### DUP-02 ┬╖ payment_methods.php vs collection_settings.php

1) **THE PROBLEM:** Method toggles may dual-write from collection settings.

2) **THE EXPECTATION:** Methods page = ON/OFF; collection = mode/rail only.

3) **THE SOLUTION / ACTION:** KEEP BOTH; remove leftover method save from collection_settings if still dual-writing.

### DUP-03 ┬╖ admin_reports.php vs admin_financial_reports.php

1) **THE PROBLEM:** Two admin report homes.

2) **THE EXPECTATION:** One Reports entry.

3) **THE SOLUTION / ACTION:** MERGE UX: keep financial date-range as primary; redirect the other or make a tab.

### DUP-04 ┬╖ admin_transaction_monitor.php vs admin_throughput.php

1) **THE PROBLEM:** Both show TPS-style monitoring.

2) **THE EXPECTATION:** One live ops monitor.

3) **THE SOLUTION / ACTION:** MERGE: keep richer transaction_monitor; redirect throughput into it.

### DUP-05 ┬╖ disputes.php vs chargebacks.php

1) **THE PROBLEM:** Chargebacks UI title says Disputes & chargebacks.

2) **THE EXPECTATION:** Clear labels for two flows.

3) **THE SOLUTION / ACTION:** KEEP BOTH; fix H2 to Chargebacks only.

### DUP-06 ┬╖ gateway_settings.php vs admin_gateway_registry.php

1) **THE PROBLEM:** Look like two key places.

2) **THE EXPECTATION:** Registry = partners/keys; Platform = SMTP/ops.

3) **THE SOLUTION / ACTION:** KEEP BOTH with banners that point keys to Registry only.

### DUP-07 ┬╖ qr_code.php vs qr_upi_print.php

1) **THE PROBLEM:** Two QR products; Instant UPI may bypass UniWeb settlement.

2) **THE EXPECTATION:** Distinct purpose + honest warning.

3) **THE SOLUTION / ACTION:** KEEP BOTH; Instant UPI banner must say money may not hit UniWeb ledger.

### DUP-08 ┬╖ cust.php and payer.php both ΓåÆ customer login

1) **THE PROBLEM:** Two short URLs same job.

2) **THE EXPECTATION:** Documented short links OK.

3) **THE SOLUTION / ACTION:** KEEP BOTH; pick one for public docs.

### DUP-09 ┬╖ admin_wallet.php vs admin_platform_wallet.php

1) **THE PROBLEM:** Related money screens.

2) **THE EXPECTATION:** Bank withdraw vs fee ledger titles stay clear.

3) **THE SOLUTION / ACTION:** KEEP BOTH; cross-link headers.

### DUP-10 ┬╖ security.php vs merchant_2fa.php

1) **THE PROBLEM:** Password vs TOTP split.

2) **THE EXPECTATION:** OK split under Settings hub.

3) **THE SOLUTION / ACTION:** KEEP BOTH.

## 5 ┬╖ Global search (must become universal)

Owner requirement: every feature and every important data row reachable from the search bar (admin/merchant/staff).

### SRCH-01 ┬╖ Payment Links / QR page-jump broken for admin aliases

1) **THE PROBLEM:** Aliases map to admin_payment_links.php / admin_qr_codes.php but those URLs are not in uniwebAdminSearchPages, so Page results never appear.

2) **THE EXPECTATION:** Typing payment links or QR jumps to the admin list pages.

3) **THE SOLUTION / ACTION:** Add both URLs to admin nav + search page list, or allow aliases without membership check for super.

### SRCH-02 ┬╖ Missing record types in search

1) **THE PROBLEM:** No free-text search for orders, chargebacks, beneficiaries, packs, bank accounts, payouts, method requests, VAs, AML flags, admin invoices.

2) **THE EXPECTATION:** At least ORD / chargeback / pack / beneficiary / payout findable; exact IDs via id_go.

3) **THE SOLUTION / ACTION:** Extend global_search.php loops + result links; keep role gates.

### SRCH-03 ┬╖ Feature aliases incomplete for Advanced tools

1) **THE PROBLEM:** Nickname search (MDR, cron, 2FA, encrypt PII, watchdog) is thin.

2) **THE EXPECTATION:** Common ops nicknames hit the right page.

3) **THE SOLUTION / ACTION:** Expand $featureAliases in global_search.php / sidebar helpers.

### SRCH-04 ┬╖ Staff search returns rows for pages they cannot open

1) **THE PROBLEM:** Some admin record queries run without canPage gates.

2) **THE EXPECTATION:** Either gate queries or soft-fail open with permission flash.

3) **THE SOLUTION / ACTION:** Wrap loops in staffCanAccess / canPage checks.

### SRCH-05 ┬╖ Customer portal has no search bar

1) **THE PROBLEM:** global_search_ui only in merchant/admin/staff header.

2) **THE EXPECTATION:** Optional customer-scoped search for own TXN/ticket.

3) **THE SOLUTION / ACTION:** Later: customer-only search; not required for PPI-free scope.

### SRCH-06 ┬╖ 100% coverage target

1) **THE PROBLEM:** Owner asked AΓÇôZ everything searchable.

2) **THE EXPECTATION:** Every nav URL + every primary money/KYC/staff/settings entity.

3) **THE SOLUTION / ACTION:** Treat as Phase 6 program: (1) page jump complete (2) money entities (3) KYC/staff (4) settings nicknames (5) ID hop parity with id_click.php.

## 6 ┬╖ Market research & peer comparison

Peers: Juspay, Cashfree, Razorpay, Stripe, Worldline, PayU, Decentro. Reference only ΓÇö do not clone catalogues before Phase 0ΓÇô2 live green.

### P9-01 ┬╖ Professional polish vs Razorpay / Cashfree marketing

1) **THE PROBLEM:** Empty tables, raw errors, and unclear Test/Live cues feel unfinished.

2) **THE EXPECTATION:** Educated empty states; actionable errors; Test never looks like live money.

3) **THE SOLUTION / ACTION:** Phase 8 empty states + userFacingError; Owner live smoke on collect path.

### P9-02 ┬╖ Collections reliability bar

1) **THE PROBLEM:** Merchants judge create link ΓåÆ pay ΓåÆ settle and print QR ΓåÆ scan.

2) **THE EXPECTATION:** Same reliability as Razorpay Links/QR for the happy path.

3) **THE SOLUTION / ACTION:** Owner click-check checkout, QR PNG, share URL; Partner Registry keys only.

### P9-03 ┬╖ Docs / webhooks vs Stripe

1) **THE PROBLEM:** Thin docs look amateur even if UI works.

2) **THE EXPECTATION:** API docs, HMAC verify, delivery log, strict test keys.

3) **THE SOLUTION / ACTION:** Keep api_docs.php; deepen when a deal asks; never settle live in Test.

### P9-04 ┬╖ Do not claim Juspay-style orchestration

1) **THE PROBLEM:** Orchestrator marketing without multi-rail uptime is empty.

2) **THE EXPECTATION:** Honest status page + partner routing story.

3) **THE SOLUTION / ACTION:** status.php + Watchdog green; no second orchestrator app.

### P9-05 ┬╖ Payouts / Easy Split vs Cashfree

1) **THE PROBLEM:** Payouts scaffold; Route/Easy Split not live.

2) **THE EXPECTATION:** Collect first; payouts after keys; no fake marketplace split.

3) **THE SOLUTION / ACTION:** Honest payout UI; park Route (P11) until Owner + commercial.

### P9-06 ┬╖ Coverage honesty vs PayU / Worldline

1) **THE PROBLEM:** Showing card/POS without credentials looks fake.

2) **THE EXPECTATION:** Only enabled methods show as available.

3) **THE SOLUTION / ACTION:** Hard-gate methods on Partner Registry + merchant activation; POS only if a real deal.

### P9-07 ┬╖ Decentro is a partner, not UniWeb-as-bank

1) **THE PROBLEM:** Blurring brands / staging-as-live destroys trust.

2) **THE EXPECTATION:** Sandbox vs production always labelled.

3) **THE SOLUTION / ACTION:** Keys on Partner Detail; environment selectors; UniWeb = merchant console.

### P9-08 ┬╖ Licence language

1) **THE PROBLEM:** Over-claiming RBI PA or 0% UPI forever is legal risk.

2) **THE EXPECTATION:** Honest trust centre; live money via contracted partners.

3) **THE SOLUTION / ACTION:** Keep trust.php factual; never claim independent PA/banking licence unless true.

## 7 ┬╖ White-label buyer requirements

What a bank/fintech may demand. Implement a row only if Owner names a deal ΓÇö after Phase 0ΓÇô2. Do not put Buy white-label on homepage.

### WL-01 ┬╖ Branding / domain

1) **THE PROBLEM:** Buyers want logo + often custom domain; UniWeb is one Hostinger APP_URL.

2) **THE EXPECTATION:** Checkout brandable; domain via Hostinger when deal requires.

3) **THE SOLUTION / ACTION:** Use Checkout Customize today; deal domain = Hostinger SSL + APP_URL. No *_v2 app.

### WL-07 ┬╖ Programmatic merchant onboarding API

1) **THE PROBLEM:** Banks want REST create-merchant / KYC status.

2) **THE EXPECTATION:** Documented API or written exception (UI-only onboarding).

3) **THE SOLUTION / ACTION:** Park until deal; then extend api_docs.php. Until then: signup + invite + KYC UI.

### WL-09 ┬╖ PCI / security questionnaire pack

1) **THE PROBLEM:** Fake PCI Level 1 badges destroy diligence.

2) **THE EXPECTATION:** Honest answers mapped to trust controls.

3) **THE SOLUTION / ACTION:** Map questionnaires to trust.php; never invent certifications.

### WL-13 ┬╖ Multi-MID / multi-acquirer matrix

1) **THE PROBLEM:** Enterprise wants clear MID Γåö partner rail matrix.

2) **THE EXPECTATION:** Ops can explain which rail each merchant uses.

3) **THE SOLUTION / ACTION:** Partner Registry + methods matrix; deep multi-MID UI only if deal needs.

### WL-14 ┬╖ Maker-checker dual control

1) **THE PROBLEM:** Roles exist; full dual-approve product is thin.

2) **THE EXPECTATION:** Sensitive money/go-live needs second approver when contract requires.

3) **THE SOLUTION / ACTION:** Interim: staff roles + audit log; build dual-approve only for named deal.

### WL-15 ┬╖ Full portal white-label shell

1) **THE PROBLEM:** Checkout brandable; full dashboard still UniWeb chrome.

2) **THE EXPECTATION:** Portal chrome matches buyer when deal requires.

3) **THE SOLUTION / ACTION:** Config flags for logo/name; avoid second codebase.

### WL-EXIST ┬╖ Already largely present for buyers

1) **THE PROBLEM:** Buyers may not know what UniWeb already has.

2) **THE EXPECTATION:** Checklist of existing WL strengths.

3) **THE SOLUTION / ACTION:** HAVE: hide powered-by option, Test/Live isolation, HMAC webhooks+retry, RBAC without keys, CSV reports, status page, disputes timers, recon upload, audit CSV, API docs scaffold.

## 8 ┬╖ Owner live verification checklist (Block E style)

### LIVE-01 ┬╖ After every Git pull

1) **THE PROBLEM:** Code on GitHub is not live until Hostinger pulls.

2) **THE EXPECTATION:** Pull ΓåÆ hard refresh ΓåÆ smoke.

3) **THE SOLUTION / ACTION:** Smoke: home, merchant login, Payment Links (methods + Fixed/Open), Payment Pack Regenerate, checkout Instant Test Pay, admin dashboard, KYC decrypt view.

### LIVE-02 ┬╖ Migrations & PII

1) **THE PROBLEM:** Schema/cipher width may lag code.

2) **THE EXPECTATION:** Apply pending migrations (062/063); Encrypt PII backfill if plaintext remains.

3) **THE SOLUTION / ACTION:** Admin Apply pending migrations; Encrypt PII Backfill; never DROP DATABASE.

### LIVE-03 ┬╖ Backup before Hostinger cleanup deletes

1) **THE PROBLEM:** Block A file deletes without backup risk the site.

2) **THE EXPECTATION:** Files Backups + SQL export first.

3) **THE SOLUTION / ACTION:** Follow BLOCK_A_CLEANUP.md; delete only zip/tests/dev_local junk.

