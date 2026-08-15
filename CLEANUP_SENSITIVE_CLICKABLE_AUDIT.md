# UniWeb — Cleanup · Sensitive data · Clickable links (audit addendum)

**Source PDF:** `C:/Users/start/Downloads/UniWeb_Cleanup_SensitiveData_ClickableLinks_Audit.pdf`  
**Generated:** 2026-08-15 12:50 UTC  
**Format:** Problem → Expectation → Solution (English)  
**Scope:** Owner-reported live behaviour + platform standards. Safe cleanup only.  
**Exclusions:** No NBFC / no customer PPI wallet.

Same three-point style as prior UniWeb audit PDFs.

---

## Document map

| Block | Topic |
|-------|--------|
| **A** | Hostinger file manager & database cleanup |
| **B** | Sensitive data: encrypted at rest, plaintext for authorized display & webhooks |
| **C** | Clickable links, IDs, dashboard cards (no dead ends) |
| **D** | Implementation order |
| **E** | Owner verification checklist |

---

## BLOCK A — Hostinger & database cleanup

**Goal:** remove junk that does not power the live site. If unsure whether a file/table is used, leave it and mark for review.

### A-01 · Junk / leftover files on Hostinger `public_html`

1) **Problem:** File Manager often accumulates: old zip/tar backups in web root, duplicate test PHP scripts, overnight agent scripts, local db samples, unused demo pages, temporary upload debris, nested `public_html` copies.  
2) **Expectation:** Web root contains only files the live app needs. Backups live outside public web root. No unauthenticated demo scripts callable by URL.  
3) **Action:** Inventory `public_html`; list PHP not referenced from nav, cron, webhooks, or checkout. Move backups out of `public_html`. Delete only confirmed orphans after searching require/include references. Keep config, header, checkout, webhooks, `migrate_release`. Document every deletion.

### A-02 · Dummy / seed contacts and noise rows in database

1) **Problem:** Dummy merchants, test contacts, spam notifications, duplicate AML flags, and seed rows clutter live lists and reports.  
2) **Expectation:** Production holds real ops data plus intentional test merchants clearly marked (`account_mode=test`). No mass notification spam in live queues.  
3) **Action:** Archive or soft-delete old duplicate notifications; tag test merchants; **never hard-delete money transactions**; **never DROP DATABASE**. Owner reviews cleanup SQL before run.

### A-03 · Extra text / useless headings / dead pages in UI

1) **Problem:** Leftover marketing headings, empty sections, or links to removed features (including any NBFC residue) confuse users.  
2) **Expectation:** Every visible heading maps to a working section; every nav item opens a real page; NBFC and customer PPI wallet are not shown.  
3) **Action:** Compare `merchantNav` and `adminNav` to files on disk; remove or fix dead menu items; strip empty placeholder blocks from dashboards.

### A-04 · Cleanup safety (must not break live)

1) **Problem:** Deleting unknown PHP or tables can take down checkout, KYC, or cron.  
2) **Expectation:** Cleanup is backup-first and proven-unused-only.  
3) **Action:** Before delete: (1) Hostinger backup + SQL export, (2) search codebase for filename, (3) remove one item at a time, (4) smoke home, login, checkout, admin dashboard.

---

## BLOCK B — Sensitive data encryption & display rules

**Owner report:** merchant portal shows PAN, GST, CIN, mobile, address, email in encrypted or unreadable form.  

**Required policy:** encrypted at rest everywhere; decrypted for merchant (own data), admin (all merchants), customer (own data), and partner webhooks outbound.

### B-01 · Merchant sees ciphertext (wrong)

1) **Problem:** Merchant portal shows PAN/GST/email/phone/address/CIN as encrypted or garbled text. Merchant cannot verify their own KYC data.  
2) **Expectation:** When merchant views own profile, KYC, or settings, system decrypts and shows original values. Default for the data owner is clear text, not cipher text.  
3) **Action:** Locate missing decrypt on merchant KYC/settings/profile read paths. Use central encrypt on write and decrypt on read for authorized roles. Merchant session may only decrypt rows for their `merchant_id`.

### B-02 · Storage encrypted at rest (required)

1) **Problem:** Plaintext PAN/GST in MySQL means SQL dumps and Hostinger backups expose PII.  
2) **Expectation:** At rest in the database, sensitive columns are encrypted with an application key from server environment (not committed to git). Backups then contain ciphertext only for those fields.  
3) **Action:** Use AES (or existing project crypto helper). Encrypt on INSERT/UPDATE for pan, gstin, cin, email, phone, address used in KYC. One-time migration to encrypt existing plaintext rows idempotently.

### B-03 · Admin sees real values

1) **Problem:** If admin also sees ciphertext, ops cannot review KYC or support tickets.  
2) **Expectation:** Admin KYC Review, merchant detail, and ops views show decrypted original values.  
3) **Action:** Admin templates call the same decrypt helper with admin authorization. Never render raw ciphertext in admin KYC or merchant detail.

### B-04 · Partner webhooks get decrypted payload

1) **Problem:** Encrypted strings in webhook JSON are useless to partner systems.  
2) **Expectation:** Outbound partner webhooks and forward-queue payloads contain plaintext fields the partner is allowed to receive. TLS protects data in transit.  
3) **Action:** In webhook/payload builders: decrypt immediately before JSON encode and HTTP send. Do not send encryption keys to partners. Do not print full PII in browser logs.

### B-05 · Customer portal own data readable

1) **Problem:** Customer portal must not show encrypted email/phone for the logged-in customer.  
2) **Expectation:** Customer sees own profile fields in original form.  
3) **Action:** Customer profile and ticket contact fields: decrypt only for the session customer id.

### B-06 · One encrypt/decrypt API for the whole app

1) **Problem:** Ad-hoc base64, partial masking, or double-encryption causes ciphertext on screen.  
2) **Expectation:** Single helper pair app-wide; detect already-encrypted values to avoid double-encrypt; round-trip tests.  
3) **Action:** Shared helper module (e.g. field crypto include): `encryptSensitive` / `decryptSensitive`. All PII read paths for UI/webhooks decrypt; all write paths encrypt. Document sensitive columns.

### B-07 · What must not happen

1) **Problem:** Storing plaintext for convenience, or decrypting into public pages / unauthenticated APIs.  
2) **Expectation:** Public marketing pages and unauthenticated APIs never emit PAN, GST, or full address.  
3) **Action:** No decrypt on public site. API responses only for authenticated merchant or admin sessions.

---

## BLOCK C — Clickable links & dead ends

Wherever a user reasonably expects click to open detail, the control must be an active link or button to the correct URL. Text that looks clickable but does nothing is a defect.

### C-01 · Merchant name / ID not clickable in lists

1) **Problem:** On admin merchant list (and similar tables), merchant name and ID are plain text.  
2) **Expectation:** Merchant name and/or merchant code is a link to that merchant detail or KYC review URL.  
3) **Action:** Wrap name and code with links in admin merchants table, search results, and related widgets. Use existing detail routes only.

### C-02 · Transaction ID not clickable

1) **Problem:** Transaction lists show IDs as text with no jump to detail.  
2) **Expectation:** Transaction ID links to the existing transaction detail page for that id.  
3) **Action:** Add link on admin and merchant transaction tables.

### C-03 · Staff name not clickable

1) **Problem:** Staff lists show names without link to activity or profile.  
2) **Expectation:** Click staff name opens staff activity or staff detail for that admin id.  
3) **Action:** Link from staff management lists to existing staff activity page.

### C-04 · Partner name not clickable

1) **Problem:** Partner Registry may show partner key as text only.  
2) **Expectation:** Click partner name/key opens Partner Detail (gateway detail for that partner).  
3) **Action:** Registry table rows must link to Partner Detail.

### C-05 · Dashboard cards not clickable

1) **Problem:** Cards such as Pending KYC, Failed Transactions, or Daily Log show numbers only with no navigation.  
2) **Expectation:** Each metric card links to the filtered list that explains the number.  
3) **Action:** Wrap cards in links or add View actions with the correct query filters.

### C-06 · Button-like labels that do nothing

1) **Problem:** Labels styled like Check-in, View Detail, or Go Live look interactive but have no action.  
2) **Expectation:** Anything that looks like an action is a real working control, or is restyled as plain non-clickable text.  
3) **Action:** UI pass: wire missing handlers to existing actions, or remove button styling from inert labels.

### C-07 · Global search results must open valid targets

1) **Problem:** Search hits may not navigate or may 404.  
2) **Expectation:** Every search result URL works for that user role.  
3) **Action:** Align global search URLs with real routes; test results after typing a merchant name and a transaction id.

### C-08 · All portals (Admin, Merchant, Customer, Staff)

1) **Problem:** Dead ends can exist on any portal, not only admin.  
2) **Expectation:** Same rule everywhere: names, IDs, and cards that imply detail are clickable.  
3) **Action:** Checklist pass: admin lists, merchant transactions and links, customer tickets, staff dashboard widgets.

---

## BLOCK D — Implementation order (keep system stable)

1. Backup files + full SQL export.  
2. Sensitive data: decrypt on authorized read; encrypt on write; webhook decrypt-before-send; test one merchant end-to-end before mass migration.  
3. Clickable links: merchant list, transaction list, partner registry, dashboard cards first.  
4. Safe cleanup: only proven unused files/rows after search + smoke tests.  
5. Final smoke: admin/merchant login, KYC fields readable, one payment link, Watchdog open.

---

## BLOCK E — Owner verification checklist

- [ ] Merchant KYC shows real PAN/GST/email/phone (not cipher text).  
- [ ] Admin KYC Review shows the same real values.  
- [ ] Raw SQL for those columns is not trivial plaintext (encrypted at rest).  
- [ ] Click merchant name in admin list opens detail/KYC.  
- [ ] Click transaction ID opens detail.  
- [ ] Click Pending KYC (or equivalent) dashboard card opens filtered list.  
- [ ] After cleanup: home, login, checkout, admin dashboard still work.

---

## Agent notes

- Owner delivered this PDF 2026-08-15 evening; noted in `AGENTS.md`.  
- **P0-04 live `config.php`** stays parked until this cleanup addendum work is done — then remind Owner.  
- Do not DROP production DB. Do not unhide NBFC / customer PPI.
- **2026-08-15 progress:** Block B complete locally — widen cipher columns (migration 062 + `ensureSensitivePiiColumnWidths`); `encryptSensitive`/`decryptSensitive`/`sensitiveUiPlain`/`decryptMerchantPiiFields`; merchant+admin+invoice decrypt on read; address encrypted on write; partner outbound decrypts before JSON; Encrypt PII backfill includes address; login email/phone stay plaintext. **Block C** done (clickable cards/names). **Block A** — see `BLOCK_A_CLEANUP.md`: dead checklist/demo links fixed; `.htaccess` + FTP exclude junk dirs; **Hostinger deletes wait on Owner backup**; DB cleanup = Owner-reviewed SELECT/UPDATE only.
- **Owner still must (Block D-1):** Hostinger Files → Backups + phpMyAdmin SQL export before any Hostinger file delete (Block A).
- **Owner verify (Block E)** after deploy + hard refresh.
