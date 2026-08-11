# Cron Inventory — Hostinger Setup

## How to get the watchdog key

The platform watchdog key is auto-generated and stored in `gateway_settings` table under `platform_watchdog_key`. Find it in Admin → Platform Status or Gateway Settings. All cron URLs below use `?key=<WATCHDOG_KEY>`.

Replace `https://uniweb.co.in` with your production domain.

## Cron Jobs for Hostinger Panel

| # | Schedule | URL | Purpose | Idempotent? |
|---|----------|-----|---------|-------------|
| 1 | Every 10 min | `https://uniweb.co.in/cron_auto_audit.php?key=WATCHDOG_KEY` | Platform health audit, link scan, error check, watchdog, webhook queue, partner forward queue | Yes — safe to re-run |
| 2 | Every 10 min | `https://uniweb.co.in/cron_auto_kyc.php?key=CRON_AUTO_KYC_KEY` | Zero-touch auto KYC engine — auto-approve clean docs | Yes — only processes pending docs |
| 3 | Every 15 min | `https://uniweb.co.in/cron_settlements.php?key=SETTLEMENT_CRON_KEY` | Process pending settlement batches | Yes — only unsettled txns |
| 4 | Daily 09:00 IST | `https://uniweb.co.in/cron_mandates.php?key=CRON_MANDATES_KEY` | Process due mandate debits (recurring payments) | Yes — only due mandates, idempotent by idempotency_key |
| 5 | Daily 02:00 IST | `https://uniweb.co.in/cron_db_backup.php?key=WATCHDOG_KEY` | Database backup (gzip SQL to backups/, email to admin) | Yes — creates new file each run |
| 6 | Daily 03:00 IST | `https://uniweb.co.in/cron_reconciliation.php?key=WATCHDOG_KEY` | Daily reconciliation summary + auto-mark reconciled | Yes — regenerates summaries |
| 7 | Daily 04:00 IST | `https://uniweb.co.in/cron_bank_reconciliation.php?key=BANK_RECON_CRON_KEY` | Auto-fetch and reconcile bank statements | Yes — processes new statements only |

### Notes
- Cron #1 (auto_audit) also processes the partner forward queue and webhook retry queue as part of its run.
- Cron #2 (auto_kyc) has its own key: set `cron_auto_kyc_key` in Gateway Settings.
- Cron #3 (settlements) has its own key: set `settlement_cron_key` in Gateway Settings.
- Cron #4 (mandates) has its own key: set `cron_mandates_key` in Gateway Settings.
- Cron #7 (bank_reconciliation) has its own key: set `bank_reconciliation_cron_key` in Gateway Settings.
- All crons also work from CLI (php /path/to/cron_*.php) — CLI bypasses key check.

### Hostinger Cron Format

In Hostinger hPanel → Cron Jobs, use:
```
*/10 * * * * curl -s "https://uniweb.co.in/cron_auto_audit.php?key=YOUR_KEY" > /dev/null 2>&1
```

Or use the PHP CLI path:
```
*/10 * * * * php /home/u123/public_html/cron_auto_audit.php > /dev/null 2>&1
```

## Monitoring

- **Admin UI (operator view):** Admin → Data & Platform → Platform Status + Cron Jobs (`admin_platform_status.php`) — shows all 7 cron jobs in one table with last run, age, and OK/STALE/NEVER status.
- Cron health is visible in Admin → Platform Status (`admin_platform_status.php`)
- Failed cron runs log to `platform_errors` table → visible in Admin → Error Log
- The auto-audit cron checks DB connectivity, file integrity, and broken links every 10 min
- If cron stops running, admin banner shows "Audit stale" in header

## Webhook URLs for Partner Dashboards

Configure these URLs in each partner's dashboard (test + live):

| Partner | Webhook URL | Events to subscribe |
|---------|-------------|---------------------|
| Razorpay | `https://uniweb.co.in/razorpay_webhook.php` | payment.captured, payment.failed, refund.processed, refund.failed, payout.processed, payout.failed, mandate.authorised, mandate.cancelled, mandate.failed |
| Cashfree | `https://uniweb.co.in/cashfree_webhook.php` | PAYMENT_RESPONSE, REFUND_RESPONSE |
| PayU | `https://uniweb.co.in/payu_webhook.php` | All payment events |
| Axis Bank | `https://uniweb.co.in/axis_webhook.php` | Transaction status, mandate status |
| Decentro | `https://uniweb.co.in/decentro_webhook.php` | Transaction status, mandate status, KYC status |

### Webhook Security
- All webhooks verify signatures using partner-specific secrets stored in `gateway_settings`
- Idempotent processing via `registerGatewayEvent()` — duplicate events return success without reprocessing
- Failed webhook events retry with exponential backoff (max 5 retries) then dead-letter
- Webhook reliability queue visible in Admin → Webhook Reliability (`admin_webhook_reliability.php`)
