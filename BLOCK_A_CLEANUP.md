# Block A — Hostinger & database cleanup

**Status (2026-08-15):** Code/UI safety fixes shipped. **Hostinger file deletes wait on Owner backup (LIVE-03).**  
**Rule:** if unsure → leave it, mark REVIEW. Never hard-delete money rows. Never `DROP DATABASE`.

**LIVE-03 order:** Files Backups + full SQL export → then delete junk only (lists below). See also `_inbox/chat/LIVE_03_backup_before_cleanup.txt` and **`HOSTINGER_CLEAN_NOW.md`** (why Git pull brings laptop folders).

Related: `CLEANUP_SENSITIVE_CLICKABLE_AUDIT.md` · Block D order = backup → B → C → **A** → E.  
**LIVE-02 (after Git pull):** Apply pending migrations (062/063) → Encrypt PII Backfill if needed — never DROP DATABASE.

---

## A-04 · Safety checklist (do this first)

1. Hostinger **Files → Backups** (full website).  
2. phpMyAdmin → **Export** full SQL dump (save outside public_html).  
3. Only then delete File Manager junk (one folder/file at a time).  
4. After each batch: open home, login, checkout/demo, admin dashboard.

**Agent cannot see live File Manager.** Owner deletes Hostinger junk using the lists below.

---

## A-01 · Junk on `public_html` (Owner File Manager)

### Delete if present (proven junk / not needed on web)

| Item | Why |
|------|-----|
| `*.zip`, `*.tar`, `*.gz`, `backup*`, `*_backup*` in web root | Backups must sit **outside** public_html |
| Nested `public_html/public_html/` | Duplicate mirror (dangerous if config mixed) |
| `db_probe.php`, `db_wizard.php` | Diagnostic / setup; credentials risk (already blocked in `.htaccess`) |
| `tests/` | CLI smoke only — not the live site |
| `scripts/` | Overnight / one-time helpers |
| `dev_local/` | Laptop MariaDB tools |
| `training/`, `docs/`, `_inbox/`, `archive/` | Notes / PDFs / screenshots — not product |
| `tools/tailwindcss.exe` | Local binary |

### Keep (do not delete)

`config.php`, `header.php`, `footer.php`, `checkout.php`, `*_webhook.php`, `webhook.php`, `migrate_release.php`, all `cron_*.php`, `health.php`, `demo.php`, `includes/`, `migrations/`, `assets/`, `lib/`.

### REVIEW only (leave unless Owner confirms)

`ping.php` (health probe), `mobile.php` (phone hub), `config.dev.php` (template — live uses `config.php`). Do not restore deleted NBFC / customer wallet pages.

### Code hardening (already in repo)

- `.htaccess` forbids web access to `dev_local/`, `tests/`, `scripts/`, `training/`, `docs/`, `archive/`, `_inbox/`, `storage/`.  
- FTP deploy workflow excludes those folders (Hostinger **Git** pull may still copy them — delete on server after backup if they appear).

**Deletions log (repo):** none from live yet — Owner action pending.

---

## A-02 · Database noise (Owner reviews SQL first)

Run **SELECT** in phpMyAdmin. Share counts with agent if unsure. Then run **UPDATE** soft actions only after Owner OK.

### SELECT — review only

```sql
-- Demo merchant (keep; intentional test)
SELECT id, merchant_code, email, account_mode, status, created_at
FROM merchants WHERE email = 'demo@uniweb.co.in';

-- Other test-mode merchants
SELECT id, merchant_code, email, account_mode, kyc_status, status, created_at
FROM merchants
WHERE account_mode = 'test' AND status = 'active' AND email <> 'demo@uniweb.co.in'
ORDER BY created_at DESC LIMIT 200;

-- Soft-deleted already
SELECT id, merchant_code, email, status, deleted_at
FROM merchants WHERE status = 'deleted' OR deleted_at IS NOT NULL LIMIT 200;

-- Noisy duplicate notification titles
SELECT merchant_id, title, COUNT(*) AS cnt, MIN(id) AS first_id, MAX(id) AS last_id
FROM notifications WHERE archived_at IS NULL
GROUP BY merchant_id, title HAVING cnt > 5
ORDER BY cnt DESC LIMIT 100;

-- Old open inbox items
SELECT id, merchant_id, title, is_read, created_at
FROM notifications
WHERE archived_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
ORDER BY created_at ASC LIMIT 500;

-- Duplicate open AML flags
SELECT merchant_id, transaction_id, flag_type, COUNT(*) AS cnt
FROM aml_flags WHERE status = 'open'
GROUP BY merchant_id, transaction_id, flag_type HAVING cnt > 1 LIMIT 100;

-- Seed-ish names
SELECT id, merchant_code, email, business_name, created_at
FROM merchants
WHERE email LIKE '%test%' OR email LIKE '%example%' OR business_name LIKE '%test%'
ORDER BY created_at DESC LIMIT 100;
```

### Soft UPDATE examples (only after Owner OK — edit IDs)

```sql
-- Archive old *read* notifications (safe; hides from inbox)
UPDATE notifications SET archived_at = NOW()
WHERE archived_at IS NULL AND is_read = 1
  AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Soft-delete a junk merchant that never took real money (Owner picks id)
-- UPDATE merchants SET status='deleted', deleted_at=NOW() WHERE id = ? AND account_mode='test';
```

**Forbidden:** `DELETE FROM transactions` / settlements / refunds / ledger · `DROP DATABASE` · mass-delete merchants without review.

App already has `archiveOldNotifications()` for 30-day read archive from merchant Notifications page.

---

## A-03 · Dead UI / nav (fixed in code)

| Issue | Fix |
|-------|-----|
| Onboarding “₹1 test payment” → missing `merchant_launch_test.php` | Now → `merchant_payment_pack.php` |
| Admin demo table → missing `platform_demo.php` | Now → `demo.php` |
| Sidebar merchant/admin links | All targets exist |
| NBFC in menu | Hidden (`uniwebMerchantHiddenUrls` / `uniwebAdminHiddenUrls`) |
| Customer PPI wallet | Not in nav; page not built |

No empty “Coming soon” blocks found on merchant/admin dashboards in this pass.

---

## Owner live steps (short)

1. Backup + SQL export.  
2. File Manager: remove zip/backups + `tests`/`scripts`/`dev_local` if present.  
3. Git pull (gets `.htaccess` + dead-link fixes).  
4. Run SELECT queries; archive noisy notifications if needed.  
5. Smoke: home, login, demo/checkout, admin dashboard.  
6. Block E checklist in cleanup audit PDF/md.
