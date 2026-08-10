# UI Freeze Pages — Stable URLs for Training

These are the frozen pages for training screenshots. Menu items match this list. Pages not listed here are either internal (cron, redirects) or hidden (wallet/NBFC).

## Admin Portal (super-admin)

| Page | URL | Purpose |
|------|-----|---------|
| Admin Login | `/admin_login.php` | Super-admin login with MFA |
| Admin Dashboard | `/admin_dashboard.php` | Stats, quick links, decision center |
| Partner Registry | `/admin_gateway_registry.php` | All partners card list |
| Gateway Detail | `/admin_gateway_detail.php?partner=<key>` | Partner config: keys, methods, webhooks, test, logs, reason maps |
| Platform Integrations | `/gateway_settings.php` | Global gateway settings, merchant support channels |
| Method Requests | `/admin_method_requests.php` | Merchant requests for new payment methods |
| KYC Forward Queue | `/admin_forward_queue.php` | KYC packages sent to partners |
| Reason Maps | `/admin_reason_map.php` | Global failure reason mapping |
| All Merchants | `/manage_merchant.php` | Merchant list with filters |
| Add Merchant | `/add_merchant.php` | Create merchant manually |
| Edit Merchant | `/admin_edit_merchant.php` | Edit merchant details |
| Merchant Context | `/admin_view_merchant.php` | View merchant as admin |
| KYC Review | `/admin_kyc.php` | Review/approve/reject KYC |
| KYC Documents | `/admin_kyc_doc.php` | Document management |
| Transactions | `/admin_transactions.php` | All transactions with filters |
| Refunds | `/admin_refunds.php` | Refund management |
| Disputes | `/admin_disputes.php` | Dispute/chargeback management |
| Settlements | `/admin_settlements.php` | Settlement batches |
| Settlement Engine | `/admin_settlement_settings.php` | Settlement configuration |
| Payout Requests | `/admin_payout.php` | Payout approval queue |
| Bulk Payout | `/admin_bulk_payout.php` | Bulk payout upload |
| Financial Reports | `/admin_financial_reports.php` | Revenue/volume reports |
| PG Webhooks | `/admin_pg_webhooks.php` | Webhook event log |
| Reconciliation | `/admin_reconciliation.php` | PG reconciliation |
| Bank Reconciliation | `/admin_bank_reconciliation.php` | Bank statement reconciliation |
| Staff / Employees | `/admin_manage_staff.php` | Staff account management |
| Staff Activity Log | `/admin_staff_activity.php` | Audit trail of staff actions |
| Audit Log | `/admin_audit_log.php` | Immutable audit log (super-admin only) |
| Error Log | `/admin_error_log.php` | Platform errors with resolve |
| Platform Status | `/admin_platform_status.php` | Cron health, watchdog, self-checks |
| Transaction Monitor | `/admin_transaction_monitor.php` | TPS, success/fail rate, VA health |
| Security & Password | `/admin_security.php` | Admin password change |
| Security Hardening | `/admin_security_hardening.php` | Security checklist dashboard |
| Website & API Keys | `/admin_website.php` | Public site config, API key management |
| Support Tickets | `/admin_support.php` | Merchant support ticket queue |
| Customer Complaints | `/admin_customer_tickets.php` | Customer grievance tickets |
| Website Reviews | `/admin_website_reviews.php` | Pending/verified/rejected merchant websites |
| AML Compliance | `/admin_aml.php` | AML monitoring |
| Risk & AML | `/admin_risk.php` | Risk engine settings |
| Risk Engine | `/admin_risk_engine.php` | Velocity rules, risk scoring |
| Grievance Officer | `/admin_grievance.php` | Grievance redressal dashboard |
| Gateway Matrix | `/admin_gateway_matrix.php` | Partner × method matrix |
| Gateway Health | `/admin_gateway_health.php` | Partner health metrics |
| Virtual Accounts | `/admin_virtual_accounts.php` | VA management |
| Auto KYC Engine | `/admin_auto_kyc.php` | Zero-touch KYC rules |
| Onboarding Invites | `/admin_onboarding_invite.php` | Pre-filled onboarding email/link |
| Partner Requests | `/admin_partner_requests.php` | Partner email templates |
| Partner Commercial | `/admin_partner_commercial.php` | Commercial terms per partner |
| Circuit Breaker | `/admin_circuit_breaker.php` | Gateway circuit breaker status |
| Webhook Reliability | `/admin_webhook_reliability.php` | Webhook retry queue + dead-letter |
| Incidents | `/admin_incidents.php` | Incident log |
| Nodal Accounts | `/admin_nodal_accounts.php` | Nodal account configuration |
| Merchant Health | `/admin_merchant_health.php` | Health score per merchant |
| Reports | `/admin_reports.php` | Custom report builder |
| Bank Holidays | `/admin_bank_holidays.php` | Bank holiday calendar |
| Rolling Reserve | `/admin_rolling_reserve.php` | Rolling reserve configuration |
| Settlement Batches | `/admin_settlement_batches.php` | Batch settlement view |
| Merchant Banks | `/admin_merchant_banks.php` | Merchant bank account management |
| Payment Links (admin) | `/admin_payment_links.php` | All merchant payment links |
| QR Codes (admin) | `/admin_qr_codes.php` | All merchant QR codes |
| Sub Merchants | `/admin_sub_merchants.php` | Sub-merchant management |
| Throughput Monitor | `/admin_throughput.php` | API throughput metrics |
| Encrypt PII Backfill | `/admin_encrypt_pii.php` | PII encryption backfill tool |
| Link Watchdog | `/admin_watchdog.php` | Broken link scanner |
| Link Audit | `/admin_link_audit.php` | Payment link audit trail |

## Merchant Portal

| Page | URL | Purpose |
|------|-----|---------|
| Merchant Login | `/login.php` | Email/phone + password + OTP/2FA |
| Merchant Register | `/merchant_register.php` | New merchant signup |
| Merchant Setup | `/merchant_setup.php` | Onboarding wizard |
| Dashboard | `/dashboard.php` | Stats, balance, failed payments, health score |
| KYC | `/kyc.php` | Document upload + video KYC |
| Agreement | `/agreement.php` | Merchant agreement sign |
| Payment Links | `/payment_links.php` | Create/manage payment links |
| QR Codes | `/qr_code.php` | Create/manage QR codes |
| Transactions | `/transactions.php` | Transaction list with filters |
| Refunds | `/refunds.php` | Initiate/track refunds |
| Recurring & Mandates | `/merchant_recurring.php` | E-mandate setup, recurring schedules |
| Settlements | `/settlements.php` | Settlement history |
| Wallet | `/wallet.php` | Balance breakdown (available, in-transit, on-hold, settled) |
| Payouts | `/merchant_payout.php` | Initiate payouts |
| Team | `/merchant_team.php` | Team members with roles |
| API Keys | `/api_keys.php` | API key management |
| Notifications | `/notifications.php` | In-app notification center |
| Support | `/support.php` | Support ticket creation |
| Security | `/my_account.php` | Password, 2FA, contact change with OTP |
| Merchant Website | `/merchant_website.php` | Storefront/website settings |
| Orders | `/orders.php` | Order/invoice management |
| Merchant Launch | `/merchant_launch.php` | Go-live checklist for merchant |

## Staff Portal (role-limited subset of admin)

| Page | URL | Purpose |
|------|-----|---------|
| Staff Login | `/staff_login.php` | Staff login with MFA |
| Staff Dashboard | `/staff_dashboard.php` | Role-based landing page |

Staff see a limited nav generated by `staffNavForRole()` in `includes/staff.php`. Super-admin pages (gateway_settings, reason_map, ledger_state, platform_wallet, audit_log) are blocked.

## Customer Portal

| Page | URL | Purpose |
|------|-----|---------|
| Customer Login | `/customer_login.php` | Phone + OTP login |
| Customer Portal | `/customer_portal.php` | Dashboard: transactions, tickets |
| Customer Profile | `/customer_profile.php` | Profile edit with OTP, payment history |

**No wallet, no NBFC, no topup, no PPI** in customer portal.

## Public Website

| Page | URL | Purpose |
|------|-----|---------|
| Home | `/index.php` | Landing page |
| About | `/about.php` | Company info |
| Pricing | `/pricing.php` | Pricing (only shows when approved + methods enabled) |
| Solutions | `/solutions.php` | Feature overview |
| Demo | `/demo.php` | Demo merchant + ₹1 test link |
| Blog | `/blog.php` | Blog index |
| Trust | `/trust.php` | Trust/security page |
| Roadmap | `/roadmap.php` | Product roadmap |
| Responsibility Matrix | `/responsibility_matrix.php` | RBI compliance matrix |
| Signup | `/merchant_register.php` | Merchant registration |
| Terms | `/terms.php` | Terms & Conditions |
| Privacy | `/privacy.php` | Privacy Policy |
| Refund Policy | `/refund_policy.php` | Refund Policy |
| Grievance | `/grievance.php` | Grievance Redressal |
| Merchant Agreement | `/merchant_agreement.php` | Merchant Agreement |
| Compliance | `/compliance.php` | Compliance overview |
| PCI-DSS | `/pci_dss.php` | PCI-DSS Readiness |
| Support | `/support.php` | Support contact |

## Hidden from menus (not in freeze list)

- `admin_nbfc.php` — NBFC module (out of scope, not in sidebar)
- `merchant_nbfc.php`, `merchant_nbfc_loan.php` — NBFC merchant pages (filtered in header.php)
- `admin_partner_decentro.php` — redirect only to `admin_gateway_detail.php?partner=decentro`
- `admin_partner.php` — legacy partner page (still works, `admin_gateway_detail.php` is primary)
- `admin_partners.php` — legacy partner list (still works, `admin_gateway_registry.php` is primary)
- `admin_wallet.php` — platform wallet ops (super-admin only, not in sidebar)
- `admin_pii_reveal.php` — PII reveal (super-admin only, not in sidebar)
- `admin_stepup.php` — step-up auth verification (internal)
- `admin_customer_view.php`, `admin_customer_message.php` — customer detail views (internal)
- `admin_gateway_detail.php`, `admin_gateway_submit.php` — gateway helpers (internal)
- `admin_integration_matrix.php` — integration matrix report (internal)
- `admin_settlement.php` — settlement detail (internal redirect)
- All `cron_*.php` — cron jobs (not UI pages)
- All `*_webhook.php` — webhook endpoints (not UI pages)
- `dev_local/` — local dev tools (blocked from web)
