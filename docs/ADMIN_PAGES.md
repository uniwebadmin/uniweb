# Admin Pages ΓÇö Sidebar vs Internal/Cron

## In Admin Sidebar (header.php)

### Merchant
- admin_dashboard.php ΓÇö Dashboard
- manage_merchant.php ΓÇö All Merchants
- add_merchant.php ΓÇö Add Merchant
- admin_edit_merchant.php ΓÇö Edit Merchant
- admin_view_merchant.php ΓÇö Merchant Context
- admin_kyc.php ΓÇö KYC Review
- admin_kyc_doc.php ΓÇö KYC Documents
- admin_merchant_banks.php ΓÇö Merchant Banks
- admin_payment_links.php ΓÇö Payment Links
- admin_qr_codes.php ΓÇö QR Codes

### Staff
- admin_manage_staff.php ΓÇö Staff / Employees
- admin_staff_activity.php ΓÇö Staff Activity Log

### Payments
- admin_transactions.php ΓÇö Transactions
- admin_refunds.php ΓÇö Refunds
- admin_disputes.php ΓÇö Disputes
- admin_chargebacks.php ΓÇö Chargebacks
- admin_financial_reports.php ΓÇö Financial Reports
- admin_pg_webhooks.php ΓÇö PG Webhooks
- admin_reconciliation.php ΓÇö PG Reconciliation

### Settlements
- admin_settlements.php ΓÇö Settlements
- admin_settlement_settings.php ΓÇö Settlement Engine
- admin_settlement_batches.php ΓÇö Settlement Batches
- admin_bulk_payout.php ΓÇö Bulk Payout
- admin_bank_reconciliation.php ΓÇö Bank Reconciliation
- admin_bank_holidays.php ΓÇö Bank Holidays
- admin_rolling_reserve.php ΓÇö Rolling Reserve
- admin_payout.php ΓÇö Payout Requests

### Partners & Rails
- admin_gateway_registry.php ΓÇö Partner Registry
- gateway_settings.php ΓÇö Platform Integrations (super-admin only)
- admin_method_requests.php ΓÇö Method Requests
- admin_reason_map.php ΓÇö Reason Maps (super-admin only)
- admin_forward_queue.php ΓÇö KYC Forward Queue
- admin_gateway_matrix.php ΓÇö Gateway Matrix
- admin_gateway_health.php ΓÇö Gateway Health
- admin_virtual_accounts.php ΓÇö Virtual Accounts
- admin_auto_kyc.php ΓÇö Auto KYC Engine
- admin_onboarding_invite.php ΓÇö Onboarding Invites
- admin_partner_requests.php ΓÇö Partner Requests
- admin_partner_commercial.php ΓÇö Partner Commercial
- admin_partner_decentro.php ΓÇö Decentro Checklist
- admin_axis.php ΓÇö Axis UAT
- admin_circuit_breaker.php ΓÇö Circuit Breaker
- admin_webhook_reliability.php ΓÇö Webhook Reliability

### Support & Risk
- admin_support.php ΓÇö Support Tickets
- admin_customer_tickets.php ΓÇö Customer Complaints
- admin_website_reviews.php ΓÇö Website Reviews
- admin_aml.php ΓÇö AML Compliance
- admin_risk.php ΓÇö Risk & AML
- admin_risk_engine.php ΓÇö Risk Engine
- admin_grievance.php ΓÇö Grievance Officer

### Platform & Settings
- admin_platform_status.php ΓÇö Platform Status
- admin_transaction_monitor.php ΓÇö Transaction Monitor
- admin_website.php ΓÇö Website & API Keys
- admin_security.php ΓÇö Security & Password
- admin_security_hardening.php ΓÇö Security Hardening
- admin_encrypt_pii.php ΓÇö Encrypt PII Backfill
- admin_ledger_state.php ΓÇö Ledger State Machine (super-admin only)
- admin_error_log.php ΓÇö Error Log
- admin_watchdog.php ΓÇö Link Watchdog
- admin_link_audit.php ΓÇö Link Audit
- admin_nodal_accounts.php ΓÇö Nodal Accounts
- admin_audit_log.php ΓÇö Audit Log (super-admin only)
- admin_incidents.php ΓÇö Incidents
- admin_reports.php ΓÇö Reports
- admin_merchant_health.php ΓÇö Merchant Health
- admin_sub_merchants.php ΓÇö Sub Merchants
- admin_throughput.php ΓÇö Throughput Monitor

## Not in Sidebar ΓÇö Internal/Cron/Utility

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
| admin_aml.php | AML alerts | super-admin |
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

## Removed from product (do not restore)

- merchant_nbfc.php / merchant_nbfc_loan.php / admin_nbfc.php ΓÇö NBFC deleted 2026-08-15
- Customer PPI wallet ΓÇö never built; no `customer_wallet.php`
