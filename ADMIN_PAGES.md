# Admin Pages — Sidebar vs Internal/Cron

## In Admin Sidebar (header.php)

### Merchant
- admin_dashboard.php — Dashboard
- manage_merchant.php — All Merchants
- add_merchant.php — Add Merchant
- admin_edit_merchant.php — Edit Merchant
- admin_view_merchant.php — Merchant Context
- admin_kyc.php — KYC Review
- admin_kyc_doc.php — KYC Documents
- admin_merchant_banks.php — Merchant Banks
- admin_payment_links.php — Payment Links
- admin_qr_codes.php — QR Codes

### Staff
- admin_manage_staff.php — Staff / Employees
- admin_staff_activity.php — Staff Activity Log

### Payments
- admin_transactions.php — Transactions
- admin_refunds.php — Refunds
- admin_disputes.php — Disputes
- admin_chargebacks.php — Chargebacks
- admin_financial_reports.php — Financial Reports
- admin_pg_webhooks.php — PG Webhooks
- admin_reconciliation.php — PG Reconciliation

### Settlements
- admin_settlements.php — Settlements
- admin_settlement_settings.php — Settlement Engine
- admin_settlement_batches.php — Settlement Batches
- admin_bulk_payout.php — Bulk Payout
- admin_bank_reconciliation.php — Bank Reconciliation
- admin_bank_holidays.php — Bank Holidays
- admin_rolling_reserve.php — Rolling Reserve
- admin_payout.php — Payout Requests

### Partners & Rails
- admin_gateway_registry.php — Partner Registry
- gateway_settings.php — Platform Integrations (super-admin only)
- admin_method_requests.php — Method Requests
- admin_reason_map.php — Reason Maps (super-admin only)
- admin_forward_queue.php — KYC Forward Queue
- admin_gateway_matrix.php — Gateway Matrix
- admin_gateway_health.php — Gateway Health
- admin_virtual_accounts.php — Virtual Accounts
- admin_auto_kyc.php — Auto KYC Engine
- admin_onboarding_invite.php — Onboarding Invites
- admin_partner_requests.php — Partner Requests
- admin_partner_commercial.php — Partner Commercial
- admin_partner_decentro.php — Decentro Checklist
- admin_axis.php — Axis UAT
- admin_circuit_breaker.php — Circuit Breaker
- admin_webhook_reliability.php — Webhook Reliability

### Support & Risk
- admin_support.php — Support Tickets
- admin_customer_tickets.php — Customer Complaints
- admin_website_reviews.php — Website Reviews
- admin_aml.php — AML Compliance
- admin_risk.php — Risk & AML
- admin_risk_engine.php — Risk Engine
- admin_grievance.php — Grievance Officer

### Platform & Settings
- admin_platform_status.php — Platform Status
- admin_transaction_monitor.php — Transaction Monitor
- admin_website.php — Website & API Keys
- admin_security.php — Security & Password
- admin_security_hardening.php — Security Hardening
- admin_encrypt_pii.php — Encrypt PII Backfill
- admin_ledger_state.php — Ledger State Machine (super-admin only)
- admin_error_log.php — Error Log
- admin_watchdog.php — Link Watchdog
- admin_link_audit.php — Link Audit
- admin_nodal_accounts.php — Nodal Accounts
- admin_audit_log.php — Audit Log (super-admin only)
- admin_incidents.php — Incidents
- admin_reports.php — Reports
- admin_merchant_health.php — Merchant Health
- admin_sub_merchants.php — Sub Merchants
- admin_throughput.php — Throughput Monitor

## Not in Sidebar — Internal/Cron/Utility

| Page | Purpose | Access |
|------|---------|--------|
| admin_forgot_password.php | Admin password reset | Public (token-gated) |
| admin_reset_password.php | Admin password reset form | Public (token-gated) |
| admin_login.php | Admin/staff login | Public |
| admin_logout.php | Logout | Public |
| admin_partner.php | Partner detail view (redirect from registry) | super-admin |
| admin_partners.php | Partners list (redirect from registry) | super-admin |
| admin_pii_reveal.php | PII reveal with audit | super-admin |
| admin_wallet.php | Platform wallet ops | super-admin |
| admin_gateway_submit.php | Gateway submit helper | super-admin |
| admin_gateway_detail.php | Gateway detail view (redirect from registry) | super-admin |
| admin_settlement.php | Settlement detail view | super-admin |
| admin_customer_message.php | Customer message view | staff |
| admin_customer_view.php | Customer detail view | staff |
| admin_integration_matrix.php | Integration matrix report | super-admin |
| admin_stepup.php | Step-up auth verification | staff |
| admin_nbfc.php | NBFC module (out of scope, not in sidebar) | super-admin |
| staff_dashboard.php | Staff landing page | staff |
| staff_login.php | Staff login | Public |

## Cron Jobs (no admin UI)

| File | Schedule | Purpose |
|------|----------|---------|
| cron_auto_audit.php | 10 min | Platform health audit |
| cron_mandates.php | Daily 9 AM | Process due mandate debits |
| cron_db_backup.php | Daily | Database backup |
| cron_settlement_engine.php | Hourly | Process pending settlements |
| cron_payout_worker.php | 15 min | Process payout queue |

## Super-Admin Only Pages (requireSuperAdmin)

- gateway_settings.php (partner keys, global defaults)
- admin_reason_map.php (global failure reason maps)
- admin_ledger_state.php (ledger state machine)
- admin_audit_log.php (immutable audit log)
- admin_platform_wallet.php (platform fee wallet)
- admin_dashboard.php (admin dashboard)
- admin_partner_decentro.php (Decentro checklist)
- admin_partner_requests.php (partner requests)
- admin_axis.php (Axis UAT)
- admin_platform_status.php (platform status)
- admin_transaction_monitor.php (transaction monitor)
- admin_encrypt_pii.php (PII encryption)
- admin_error_log.php (error log)
- admin_watchdog.php (link watchdog)
- admin_link_audit.php (link audit)
- admin_website.php (website & API keys)
- admin_incidents.php (incidents)
- admin_pii_reveal.php (PII reveal)
- admin_partner.php (partner detail)
- admin_partners.php (partners list)
- admin_wallet.php (platform wallet)
- admin_aml.php (AML compliance)

## Hidden/Removed from UI

- merchant_nbfc.php — NBFC module (out of scope, filtered in header.php)
- merchant_nbfc_loan.php — NBFC loans (out of scope, filtered in header.php)
- Customer wallet — no wallet in customer portal nav
