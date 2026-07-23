# UniWeb schema migrations

Versioned SQL in this folder is the **schema of record**. Runtime `ensure*Schema()` helpers in `includes/` are a safety net; release deploys should still apply pending files via `migrate_release.php` so `schema_migrations` stays accurate.

## Owner — one-time apply on live (011–018)

After FTP/CI ships these files to Hostinger, apply pending migrations once:

1. Open **Admin → Gateway Settings**.
2. Copy the existing watchdog/cron key from the **“Test cron now”** link  
   (`cron_auto_audit.php?key=…` — same key already used for Hostinger cron).  
   **Do not invent a new `CRON_KEY`.** If the key field is empty, open Gateway Settings once so the platform can persist `platform_watchdog_key`, then copy it from that link.
3. In a browser (or curl), hit:

   ```text
   https://uniweb.co.in/migrate_release.php?key=YOUR_EXISTING_WATCHDOG_KEY
   ```

4. Expect JSON like:

   ```json
   {
     "ok": true,
     "applied": ["011_….sql", "012_….sql", …],
     "pending_after": [],
     "ran_at": "…"
   }
   ```

5. Safe to re-run: already-applied versions are skipped (checksum-checked). Idempotent `IF NOT EXISTS` / ENUM expand guards on 011–018 avoid duplicate-column failures when runtime schema ensure already added columns.

If `ok: false`, fix the reported SQL error (often a missing base table from an older install) and re-run. Do **not** hand-edit `schema_migrations` unless advised.

## What 011–018 add

| File | Purpose |
|---|---|
| `011_staff_activity_logs.sql` | Staff↔merchant assignments + activity log tables |
| `012_method_requests.sql` | Merchant “Request to Enable” payment-method queue |
| `013_invoice_customer_address.sql` | Invoices + `customer_address` for PDF Bill To |
| `014_kyc_rejection_reason.sql` | KYC `rejection_reason` + `reviewed_at` |
| `015_payout_scaffold.sql` | Payout enable requests, beneficiaries, draft orders (live money gated) |
| `016_customer_ticket_roles.sql` | Customer ticket merchant/staff sender roles + merchant index |
| `017_payout_expansion.sql` | Payout batches, reversal queue, API credentials, penny-drop note |
| `017a_gateway_submissions_base_table.sql` | Base `gateway_submissions` table (previously only runtime-created; a genuinely fresh install had no schema for it, breaking 018 below) — sorts before 018 intentionally |
| `018_gateway_submissions_axis.sql` | Expand `gateway_submissions.gateway` ENUM to include Axis Bank |
| `019_kyc_verifications_table.sql` | Base `kyc_verifications` table (Aadhaar/PAN/bank verification results) — had no CREATE TABLE anywhere in the repo at all; found via overnight smoke-testing (KYC page threw "table doesn't exist") |
## Local / cloud VM

```bash
bash dev_local/bootstrap_db.sh   # applies all pending migrations/*.sql
```

## Security note

`migrate_release.php` uses the same `validateCronRequest()` gate as `cron_auto_audit.php`. Never commit the live key; never paste partner gateway keys into migration SQL.