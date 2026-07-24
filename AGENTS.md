# UniWeb — Local laptop first

**Owner order (2026-07-24):** do **all** agent work on this Windows laptop.  
Do **not** use Cursor Cloud Agents (`cursor.com/agents`) unless the owner reverses this.

## Owner chat (permanent — 2026-07-24)

- Talk to the owner in **simple everyday Hindi / Urdu** (Delhi style). Short lines. No heavy tech jargon.
- Website / product UI text stays **English**.
- Rule file: `.cursor/rules/owner-simple-hindi.mdc`

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
