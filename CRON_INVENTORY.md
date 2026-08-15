# Cron Inventory — Hostinger Setup

The cron/watchdog key is **made by UniWeb** (Gateway Settings). It does not come from a bank or payment partner.

Partner API keys (PayU / Razorpay / Decentro) are a **different** thing: paste those in Partner Registry when the partner emails them.

## Required on Hostinger — 1 job only

| Schedule | What to paste | Already includes |
|----------|----------------|------------------|
| Every 10 min | Gateway Settings → **Show full Hostinger command** (wget or URL) | Watchdog, auto KYC, settlements, recurring mandates, partner forward, payout queue, recon mark, morning ops |

Command shape:

```
*/10 * * * * wget -q -O /dev/null "https://uniweb.co.in/cron_auto_audit.php?key=YOUR_UNIWEB_KEY"
```

Copy the real command from Admin → Gateway Settings (logged-in reveal). Do not invent a key.

## Not a cron

| Task | Where |
|------|--------|
| Database updates (migrations) | Gateway Settings → **Apply pending migrations** (one click after deploy) |

## Optional extra Hostinger jobs

| Schedule | URL | When you need it |
|----------|-----|------------------|
| Daily 02:00 IST | `cron_db_backup.php?key=WATCHDOG_KEY` | Daily SQL backup (also reveal in Gateway Settings) |
| Daily 04:00 IST | `cron_bank_reconciliation.php?key=WATCHDOG_KEY` | Only if you fetch bank statement files |

Separate `cron_auto_kyc.php` / `cron_settlements.php` / `cron_mandates.php` jobs are **not required** — the 10-minute job already runs them. They still accept the same UniWeb watchdog key if you add extras.

## Browser “Forbidden” text (expected)

If a human opens a `cron_*.php` URL in the browser without the secret key, the script prints a short **Forbidden** message and stops. That is intentional for machines (Hostinger cron / wget).

- **Do not** add cron scripts to the admin sidebar.
- Humans check health in **Admin → Link Watchdog** and **Platform Status + Cron Jobs**.
- Cron keys stay in Gateway Settings; never put them in menus or public pages.

## After saving the Hostinger job

1. Gateway Settings → **Test cron now**
2. Wait 10–15 min
3. Admin → Platform Status — Auto Audit should show last run, not NEVER

