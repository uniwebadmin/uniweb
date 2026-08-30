# PHPUnit migration path (investigation — UniWeb)

**Status:** Investigation complete · **No full migration this job** · **Recommended:** S1 dual track

## 1) Current harness

| Piece | How it works |
|-------|----------------|
| `tests/run_smoke_checks.php` | Single PHP CLI file; `$assert(bool, name)` counter; no PHPUnit |
| Bootstrap | Sets `APP_URL` default; **no DB** for most checks (file reads, grep, `link_watchdog`) |
| DB tests | `run_integrity_tests.php` loads `config.php` → needs local MariaDB |
| SDK | `sdk/php/tests/RequestShapeTest.php` — standalone script, not PHPUnit |
| CI | GitHub Actions deploy workflow; optional curl smoke only (non-blocking) |

## 2) PHPUnit availability

| Location | PHPUnit? | PHP constraint |
|----------|----------|----------------|
| Repo root | **No** `composer.json` | CLI tested: **PHP 8.3.32** (local laptop) |
| `sdk/php/composer.json` | **No** dev dependency | `"php": ">=8.1"` |

**Blocker for pilot:** Root app is not Composer-managed. Adding PHPUnit requires new root `composer.json` (dev-only) — deferred to Phase 1 when Owner approves.

## 3) Framework comparison (short)

| Framework | Fit for UniWeb | Pros | Cons |
|-----------|----------------|------|------|
| **Custom smoke** (current) | **Primary E2E/wiring** | Fast, no deps, grep/file asserts, works on Hostinger-less laptop | No isolation, long single file |
| **PHPUnit** | **Unit/helpers** | Standard, CI-friendly, data providers | Needs Composer bootstrap at root |
| **Pest** | Optional later | Nicer syntax over PHPUnit | Same bootstrap cost as PHPUnit |
| **Codeception** | Low | Browser acceptance | Heavy; overkill for solo ops |

Payment webhooks + DB: keep **integration** in smoke/integrity; unit-test **pure helpers** only (signature, catalog, hash).

## 4) Strategies

### S1 — Dual track (**RECOMMENDED**)

- **Keep** `run_smoke_checks.php` for wiring, nav, STUB guards, checkout brand.
- **Add** PHPUnit only for pure functions (no network, no live keys).

**Phase 0:** Root `composer.json` with `phpunit/phpunit:^10.5` (PHP 8.2–8.3), `phpunit.xml.dist`, folder `tests/Unit/`.

**Phase 1 candidates (3–5 tests, no DB):**

| Target | File |
|--------|------|
| `merchantApiErrorCatalog()` keys + HTTP codes | `includes/merchant_api_errors.php` |
| `merchantApiPublicActions()` vs openapi enum | same + `openapi.json` |
| `cryptoTimingSafeEqual()` / signature helper | `includes/crypto_compare.php` |
| Idempotency request hash stability | `includes/financial_integrity.php` (extract pure hash fn if needed) |
| `mapPartnerErrorToPublicCode()` mapping | `includes/partner_error_mapping.php` |

**Phase 2:** Wrap smoke sections into PHPUnit `@group smoke` (optional, medium effort).

**Never in unit tests:** Live Razorpay/Cashfree HTTP, real API keys, Hostinger DB mutations.

### S2 — Wrap smoke in PHPUnit

Convert each `$assert` to a test method. **Effort: L** — 1293 asserts, one file. Risk: slow CI, same flakiness if live URLs added.

### S3 — Big-bang migrate

**Not recommended** — suite is large and grep-heavy.

### S4 — Smoke-only forever

Valid for solo Owner + green smoke, but **risk:** no isolated repro for helper regressions; PHPUnit Phase 1 still worth it.

## 5) Recommendation

**S1 dual track** — smoke stays source of truth for rails; PHPUnit adds fast helper coverage.

**Effort:** Phase 1 = **S** (small, ~2–4 hours once root Composer added) · Full S2 = **L**

**Risks:** CI needs `composer install`; DB fixtures should stay in integrity/smoke, not unit tests.

## 6) Commands (today)

```powershell
cd c:\Users\start\OneDrive\Desktop\uniweb1
php tests/run_smoke_checks.php
php tests/run_integrity_tests.php
php sdk/php/tests/RequestShapeTest.php
```

PHPUnit (after Phase 0):

```powershell
composer install
vendor/bin/phpunit
```
