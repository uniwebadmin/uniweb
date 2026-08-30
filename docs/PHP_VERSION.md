# PHP version — UniWeb (8.2 / 8.3 readiness)

## Recommended version

| Environment | PHP | Notes |
|-------------|-----|-------|
| **Minimum** | **8.2** | Deprecations visible; production-safe with current codebase |
| **Recommended** | **8.3** | Local laptop CLI already **8.3.32**; smoke green |
| Hostinger | Match panel to 8.2 or 8.3 | After deploy, smoke + Admin login |

`sdk/php/composer.json` declares `"php": ">=8.1"` — bump to `>=8.2` when Owner confirms server ≥8.2.

## Deprecation audit (2026-08-30)

| Finding | Location | Class | Action |
|---------|----------|-------|--------|
| No `utf8_encode` / `utf8_decode` | App code | — | None |
| No `${var}` interpolation | App code | — | None |
| No `create_function` / `each()` | App code | — | None |
| Smoke under `E_ALL` | `run_smoke_checks.php` | — | **0 deprecations** emitted (1293 pass) |
| `WC_Gateway_UniWeb` dynamic props | `plugins/woocommerce/.../uniweb-payments.php` | A | **Fixed** — explicit property declarations |
| `ClientConfig` probe false positive | `sdk/php/src/ClientConfig.php` | — | Uses constructor promotion; OK |
| Legacy QR library | `includes/phpqrcode/` | B | Third-party; excluded from app scan; do not hand-edit vendor |
| Integrity tests DB warnings | `run_integrity_tests.php` when MariaDB down | C | Owner/local: start MariaDB or ignore shutdown warnings |

## Dynamic properties scan

Probe: `php tests/probe_dynamic_properties.php` (excludes `includes/phpqrcode`, `vendor`).

| Run | Found | Fixed | Remaining |
|-----|-------|-------|-----------|
| 2026-08-30 | 12 candidates | 8 (WooCommerce gateway) | 4 false positives (SDK promoted props) |

## Owner — Hostinger PHP upgrade (≤5 steps)

1. **hPanel → Advanced → PHP Configuration** → select **PHP 8.3** (or 8.2 if 8.3 unavailable on plan).
2. Wait 2–3 minutes; hard refresh `https://uniweb.co.in/`.
3. **Admin login** + open Dashboard (expect 200, no white screen).
4. If SSH/cron available: `php tests/run_smoke_checks.php` on server (optional).
5. **Rollback:** same panel → switch back to previous PHP version if site breaks.

## Do not

- Hide deprecations with `@` or `error_reporting(0)` in app code.
- Edit `includes/phpqrcode/` by hand — replace library if upgrade needed.
- Force PHP 8.4-only syntax until Hostinger confirms runtime.
