# Soft-launch blocker list (UniWeb)

**Canonical checklist for Owner soft-launch.** Work top to bottom. Do not skip migrations or smoke.

Related: [OWNER_RUNBOOK.md](OWNER_RUNBOOK.md) · [CRON_INVENTORY.md](CRON_INVENTORY.md) · [RAZORPAY_INTEGRATION_AUDIT.md](RAZORPAY_INTEGRATION_AUDIT.md)

Admin shortcut: **Admin → Soft Launch** (`admin_soft_launch.php`)

---

## Ordered blockers

| # | Item | DONE looks like (green) |
|---|------|-------------------------|
| **a** | **Apply pending migrations** | Admin → Platform Settings → **Apply pending migrations** → `ok: true`, no fatal SQL errors. Migrations **066–081** applied on live if not already. |
| **b** | **Partner Registry keys** | Admin → Partner Registry → Razorpay / Cashfree / PayU (as needed) → Keys tab → **Test Connection** green for each rail you will use. No secrets in chat or git. |
| **c** | **Webhook URLs + secrets** | Partner dashboards point to `https://uniweb.co.in/razorpay_webhook.php` (and cashfree/payu/axis as used). Webhook signing secret pasted in Registry. GET on webhook URL returns health JSON (not 404). |
| **d** | **Live Money Switches** | Platform Settings → **Live Money Switches** → Payout / Recurring / Route routing stay **OFF (default)** until Owner deliberately turns ON. Collect checkout can work with keys while switches stay OFF. |
| **e** | **SMTP / pay emails** | Platform Settings → SMTP filled OR pay emails deferred (Owner accepts no receipt email until SMTP). Test email from Support if SMTP required. |
| **f** | **Cron / queue (Hostinger)** | **Not pre-installed** — Owner adds in hPanel. Copy exact command from Platform Settings → **Show full Hostinger command**. Schedule: `*/10 * * * *` → `cron_auto_audit.php?key=…`. Platform Status shows Auto Audit last run within 15 min. |
| **g** | **Smoke command** | On laptop: `php tests/run_smoke_checks.php` → **SMOKE OK passed=1320+ failed=0**. Optional: `php tests/probe_money_rails.php` → **failures=0**. |

---

## Honest PARKED (not blockers for first collect)

| Item | Status | Owner action |
|------|--------|--------------|
| Phase 11 Route / Easy Split transfers | **PARKED** | Keep switch OFF; commercial + SDK later |
| Marketplace payout / Easy Split to bank | **PARKED** | Payout live switch OFF until keys + approval |
| Recurring / mandates live debits | **PARKED** | Recurring switch OFF until approved |
| KYC forward `staged` / `local_record` | **By design** | Not “paid at partner” — queue until live API |

---

## Code sanity (verified in repo)

- New merchants: `sanitizeDefaultCollectionMode()` — never Route/Split default
- Customer checkout: UniWeb branding; no partner CTA buttons on error chrome
- `staged` / `local_record` ≠ paid in forward queue + refund labels
- Public errors: `error.php` + `includes/error_page.php` — no stack traces

---

## Emergency stop

1. Platform Settings → set **Live Money Switches** all **OFF**
2. Partner Registry → disable or rotate compromised keys
3. Admin → Error Log → review unresolved
4. Admin → Watchdog → fix broken links before re-opening traffic

Do **not** delete production DB. Roll back by switches + keys, not data drops.
