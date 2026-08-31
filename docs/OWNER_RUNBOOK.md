# Owner runbook (soft-launch)

Short operational guide. Product UI stays English.

---

## First live payment verify (≤10 steps)

1. Complete [SOFT_LAUNCH_BLOCKERS.md](SOFT_LAUNCH_BLOCKERS.md) items **a–g**.
2. Admin → **Partner Registry** → confirm Razorpay (or chosen PG) keys + webhook secret.
3. Admin → **Merchants** → pick test merchant → KYC **verified** if live collect required.
4. Merchant login → **Payment Links** → create small live link (or use Payment Pack in test mode first).
5. Open link on phone → pay with real UPI/card (Owner accepts small amount).
6. **Pending ≠ paid** — wait for success page or track link; do not ship goods on pending.
7. Admin → **Transactions** → txn status **Success**; open **Transaction detail**.
8. Check **Status confirmed via** (webhook / checkout) and **Ledger posted**.
9. Admin → **Error Log** — zero new errors for that txn id.
10. Admin → **Watchdog** — no new broken link for checkout path.

---

## Where things live

| Need | Admin path |
|------|------------|
| Errors / crashes | **Error Log** (`admin_error_log.php`) |
| Broken links / cron health | **Watchdog** (`admin_watchdog.php`) |
| Cron last run | **Platform Status** (`admin_platform_status.php`) — verify Auto Audit fresh (cron already on Hostinger; if stale check hPanel) |
| Partner truth vs UniWeb | **Transaction detail** → status + reconcile source |
| PG settlement files | **PG Reconciliation** (`admin_reconciliation.php`) |
| KYC not at bank yet | **KYC Forward Queue** → filter **Staged** |

---

## Payment status vs partner

- **UniWeb txn status** = what dashboard/API show (`pending`, `success`, `failed`).
- **Partner status** = from webhook or server fetch after signature verify.
- **Reconcile source** on txn detail = last path that updated status (`webhook`, `checkout`, `poll`, `reconcile`).
- **staged / local_record** (forward queue) = saved on UniWeb only — **not** partner payment success.

---

## Pay success but ledger / Watchdog warns

1. Open **Transaction detail** — ledger line: posted / pending / failed.
2. **Error Log** — search txn id; resolve after fix.
3. Ledger pending: `reconcilePendingPaymentLedgers` runs via cron backfill — wait one Auto Audit cycle (10 min) or ask agent to run locally.
4. Do **not** manually mark paid again — idempotent path prevents double credit.
5. If partner paid but UniWeb still pending >30 min: check webhook URL + secret; replay from partner dashboard if supported.

---

## Rollback mindset

- **Switches OFF** first (Payout, Recurring, Route routing).
- **Stop live keys** only if compromise suspected — rotate in Registry, update partner webhooks.
- Keep checkout OFF by disabling methods or merchant live mode — not by deleting merchants.
- Smoke green locally before re-enabling switches.

---

## Smoke (laptop)

```powershell
cd c:\Users\start\OneDrive\Desktop\uniweb1
php tests/run_smoke_checks.php
php tests/probe_money_rails.php
```

Green = safe to deploy; live keys still required for real money.
