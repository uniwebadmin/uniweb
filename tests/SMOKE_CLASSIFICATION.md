# Smoke test classification (UniWeb)

Used when fixing `tests/run_smoke_checks.php` failures. **Do not weaken money/security rails to look green.**

| Class | Meaning | Action |
|-------|---------|--------|
| **A** | Real product bug — assert is correct, code is wrong | Fix product code |
| **B** | Intentional product change — assert is outdated | Update assert to match new behavior; keep protection |
| **C** | Environment / Owner config (DB down, live keys, Hostinger cron, network) | Do not fake pass; skip with clear `C:` label or document as Owner-only |

## Rules

1. Prefer **A** fixes over deleting tests.
2. **B** must not weaken: idempotency, webhook signature, ownership guards, STUB/PARKED/NEVER rules, checkout brand rails.
3. **C** must name the dependency (e.g. `C: MariaDB not running locally`).
4. Re-run after every fix batch; report before/after fail counts.

## Harness

| Script | DB required? | Purpose |
|--------|----------------|---------|
| `php tests/run_smoke_checks.php` | No (static + optional `--live=URL`) | Main regression (~1293 checks) |
| `php tests/run_integrity_tests.php` | Yes (loads `config.php`) | Password, webhook SSRF, migration files |
| `php sdk/php/tests/RequestShapeTest.php` | No | SDK method shapes |

Optional live probe: `php tests/run_smoke_checks.php --live=https://uniweb.co.in`

## Latest classified run (2026-08-30)

**Before:** 0 fail · **After:** 0 fail · **1293 passed**

| Test / area | A/B/C | Action |
|-------------|-------|--------|
| Full smoke suite (1293 asserts) | — | Already green; no code change required |
| `run_integrity_tests.php` with DB down | C | Local MariaDB off → SQL connection warnings; suite still exits 0 for static asserts |
| `--live=` curl probes | C | Optional; skipped unless `--live=` or `SMOKE_BASE_URL` set |
| Critical rails (error_code catalog, idempotency, webhook dedup, routing OFF) | — | Covered by existing asserts; kept unchanged |

## When a new failure appears

1. Note exact assert name from `SMOKE FAIL` line or `$results` array.
2. Classify A / B / C in this table (add a row).
3. Fix A in product code; fix B in assert + comment why; document C for Owner.
4. Re-run: `php tests/run_smoke_checks.php`
