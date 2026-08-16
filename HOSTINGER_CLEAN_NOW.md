# Hostinger File Manager — clean after every Git pull

**Why junk appears:** Live site uses **Hostinger Git pull**. That copies the **whole Git repo**, including folders that are only for the laptop. GitHub Actions FTP skips them, but **Git pull does not**.

**Web risk:** `.htaccess` returns **403** for `_inbox/`, `dev_local/`, `tests/`, etc. Still delete them so File Manager stays clean and secrets never sit on disk.

## Do this once after next pull (BACKUP FIRST)

1. Hostinger → **Files → Backups**  
2. phpMyAdmin → **Export** SQL  
3. File Manager → `public_html` → **delete these folders if present:**

| Delete | Why |
|--------|-----|
| `_inbox/` | Agent notes, photos, owner chat — **not the website**. Had a leaked env sample; remove forever. |
| `dev_local/` | Laptop DB tools / PDF scratch |
| `tests/` | Smoke scripts — CLI only |
| `scripts/` | One-off helpers |
| `training/` | Outline notes |
| `docs/` | Internal markdown |
| `.cursor/` `.devin/` `.github/` | Agent / CI — not needed on web |
| `*.zip` / `backup*` in web root | Move outside public_html |

4. **Keep:** `config.php`, `includes/`, `migrations/`, `assets/`, all `*.php` product pages, `cron_*.php`, `health.php`, `.htaccess`

5. Open https://uniweb.co.in/ + login + Instant Test Pay

## Database (LIVE-02)

- **Apply pending migrations** (Gateway Settings) — never DROP DATABASE  
- Encrypt PII Backfill only if pending &gt; 0  

## Security note (2026-08-15)

A file under `_inbox` with live DB settings was **removed from Git**. After you delete `_inbox/` on Hostinger, **change the Hostinger database password** in hPanel and update **only** `config.php` on the server (do not commit the new password).

## From Latest Status Audit PDF (backup 15 Aug) — also do

1. **CR-01:** In live `config.php`, **delete** old `function createNotification...` (keep `require_once .../includes/notifications.php`). Note: `_inbox/chat/REMIND_CR01_config_notification.txt`
2. **CR-02:** Delete root `*.md` from public_html OR rely on `.htaccess` deny for `.md` after pull (still cleaner to delete).
3. **CR-03:** Remove nested `public_html/public_html` copies after backup.
4. **CR-04/05:** Partner Detail → Commercial save once + paste Test keys; SMTP.
5. **CR-06:** Apply pending migrations → `ok: true`.

## Next (Owner)

Partner keys → Partner Registry → Test collect → then advertise for new merchants.
