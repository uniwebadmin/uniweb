# UniWeb — Cloud / Mobile Agents

Primary control surface: **https://cursor.com/agents** (phone or any browser).

Repo: https://github.com/6396601005/uniweb

## Start a cloud agent from mobile

1. Open https://cursor.com/agents and sign in.
2. Select repo `6396601005/uniweb` (branch `main` unless continuing an open PR branch).
3. Paste a task, for example:

```text
UniWeb live launch continue. Priority: broken pages/links; cron auto-audit green; KYC verify on admin_kyc.php; gateway keys UI; smoke homepage/signup/demo/checkout/admin_website.php. English UI only. Do not delete production pages. Do not invent credentials. Commit on a cloud branch and open a PR.
```

4. Close the laptop anytime — the agent runs in Cursor cloud.

## Secrets

- `config.php` and SFTP credentials are **not** in git. Do not recreate secrets in PRs.
- Partner gateway keys are pasted by the owner in the live admin UI when received.

## Deploy note

Live Hostinger deploy may need the laptop/FTP once for a release, or an allowlisted `scripts/release_deploy.ps1` when credentials are available in the environment. Cloud agents should still ship code via PR even if live FTP is unavailable.

## Cursor Cloud specific instructions

### What this app is
UniWeb is a single PHP (8.3) + MariaDB payment-gateway web app. There is no
build step, no `composer.json`, and no JS bundler — pages are plain `*.php`
files at the repo root, with shared logic in `includes/*.php`. Every page starts
with `require_once __DIR__ . '/config.php';`.

### DB schema = real migrations (in repo); config.php = private
- The real schema lives in **`migrations/*.sql`** (committed to the repo). Apply
  them to build the database — do NOT hand-write schema. `includes/migrations.php`
  (`applyPendingMigrations()`) applies pending files and records them in
  `schema_migrations`; `migrate_release.php` is the cron-gated web entry point.
- `config.php` is **gitignored** (real one holds live DB creds + gateway keys and
  exists only on the owner's server). For local dev, `config.dev.php` is a
  committed template that connects to the local MariaDB via env vars; copy it to
  `config.php`.
- No production **data** dump is in the repo — the local DB is schema-only plus
  whatever you create while testing (e.g. `demo.php` seeds a demo merchant).

### One-time-per-session startup (DB is fresh each VM)
PHP + MariaDB are installed by the startup update script. Services/DB state are
NOT persisted, so at the start of a session run:

```
bash dev_local/bootstrap_db.sh     # starts MariaDB, creates db+user, applies migrations/*.sql, creates config.php
php -S 0.0.0.0:8000                 # dev server (run from repo root)
```

`bootstrap_db.sh` is idempotent. DB defaults: db `uniweb`, user `uniweb`,
password `uniweb_dev` (override via `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`
env vars, which `config.php` reads). It applies the real `migrations/*.sql`; if
they are ever missing it falls back to `dev_local/schema.sql`.

### Lint / test / run
- Lint (syntax): `php -l <file>` — lint everything except the vendored lib:
  `for f in $(find . -name '*.php' -not -path './includes/phpqrcode/*'); do php -l "$f"; done`
- Tests: `php tests/run_integrity_tests.php` — should be all green now that
  `migrations/*.sql` are present.
- Run: `php -S 0.0.0.0:8000` then open `http://localhost:8000/index.php`.

### Notable gotchas
- `flash()`/`getFlash()` in `config.php` use a single-message model — `header.php`
  reads `$flash['type']` / `$flash['message']` (not a list).
- Email-signup uses a placeholder phone `+919900000000`; keep `COMPANY_PHONE`
  different from it, or email signups collide with the demo merchant on the
  `email OR phone` uniqueness check.
- The public `demo.php` calls `ensureDemoMerchant()` which seeds a demo merchant
  + ₹1 test payment link on first hit.
- Verified end-to-end hello-world: merchant signup (`merchant_register.php`) →
  business setup (`merchant_setup.php`) → merchant dashboard (`dashboard.php`).
