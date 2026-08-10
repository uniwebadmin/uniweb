# Admin Portal Training Outline

Per page: purpose, who uses, main actions, enable/disable notes.
Headings in English + Hindi.

## Admin Login / एडमिन लॉगिन
- **Purpose**: Super-admin authentication with MFA
- **Who uses**: Super-admin only
- **Main actions**: Username + password → TOTP/2FA → dashboard
- **Notes**: 5 failed attempts → 15 min lockout

## Admin Dashboard / एडमिन डैशबोर्ड
- **Purpose**: Overview of platform stats, pending tasks, decision center
- **Who uses**: Super-admin, CEO
- **Main actions**: View merchant count, transaction volume, pending KYC, open disputes, unresolved errors
- **Notes**: Quick links to all admin pages

## Partner Registry / पार्टनर रजिस्ट्री
- **Purpose**: View all banking/gateway partners, their status, configuration
- **Who uses**: Super-admin
- **Main actions**: Click "Configure" to open partner detail page
- **Notes**: Shows keys saved status, test connection result per partner

## Gateway Detail (Tabs) / गेटवे विवरण
- **Purpose**: Configure a single partner — keys, methods, webhooks, test, logs, reason maps
- **Who uses**: Super-admin
- **Main actions**: Save API keys (test/live), toggle methods on/off, set priority, test connection, view API logs, add reason maps
- **Notes**: Encrypted credentials — only last4 shown. Methods disabled by default.

## Platform Integrations / प्लेटफॉर्म एकीकरण
- **Purpose**: Global gateway settings, merchant support channels, public site config
- **Who uses**: Super-admin
- **Main actions**: Configure support channels, view/rotate cron keys, toggle public pricing
- **Notes**: Super-admin only (requireSuperAdmin)

## Method Requests / विधि अनुरोध
- **Purpose**: Merchant requests for new payment methods
- **Who uses**: Super-admin, Ops
- **Main actions**: Approve/reject method requests, assign to partner
- **Notes**: Routes to partner_methods when approved

## KYC Forward Queue / KYC फॉरवर्ड कतार
- **Purpose**: Track KYC packages submitted to partners
- **Who uses**: Super-admin, Ops, KYC staff
- **Main actions**: View submission status, retry failed forwards
- **Notes**: Auto-retry with exponential backoff

## Reason Maps / रीज़न मैप
- **Purpose**: Map partner failure codes to merchant/customer-friendly messages
- **Who uses**: Super-admin
- **Main actions**: Add/edit failure code mappings (EN + HI)
- **Notes**: Super-admin only. Used in transaction list and payment status.

## All Merchants / सभी मर्चेंट
- **Purpose**: List, search, filter all merchants
- **Who uses**: Super-admin, Ops, KYC, Sales
- **Main actions**: Search by name/phone/email, filter by status, view merchant context
- **Notes**: Click merchant → admin_view_merchant.php for full context

## KYC Review / KYC समीक्षा
- **Purpose**: Review merchant KYC submissions, approve/reject
- **Who uses**: Super-admin, KYC staff, Ops
- **Main actions**: Verify video KYC, approve/reject documents, force hold/reject/resubmit
- **Notes**: All actions logged via logStaffActivity. Step-up auth for live enable.

## Transactions / लेनदेन
- **Purpose**: View all platform transactions with filters
- **Who uses**: Super-admin, Ops, Finance
- **Main actions**: Filter by status/method/date/merchant, export CSV, view transaction detail
- **Notes**: Failed transactions show mapped reason

## Refunds / रिफंड
- **Purpose**: Process and track refunds
- **Who uses**: Super-admin, Ops, Finance
- **Main actions**: Initiate refund, view refund status, export
- **Notes**: Idempotent — duplicate refund requests blocked

## Settlements / सेटलमेंट
- **Purpose**: View settlement batches, fees, merchant payouts
- **Who uses**: Super-admin, Finance
- **Main actions**: View settled/pending amounts, trigger settlement, view split breakdown
- **Notes**: Settlement engine runs via cron every 15 min

## Staff / Employees / स्टाफ प्रबंधन
- **Purpose**: Create and manage staff accounts with roles
- **Who uses**: Super-admin, CEO
- **Main actions**: Create staff, assign role, enable/disable 2FA, view activity log
- **Notes**: Roles: super, ceo, ops, kyc, finance, regional_manager, etc.

## Audit Log / ऑडिट लॉग
- **Purpose**: Immutable record of all admin/staff actions
- **Who uses**: Super-admin only
- **Main actions**: Filter by action/merchant/date, view details
- **Notes**: Super-admin only (requireSuperAdmin). Cannot be edited.

## Error Log / एरर लॉग
- **Purpose**: Platform errors, warnings, watchdog alerts
- **Who uses**: Super-admin, Ops
- **Main actions**: View errors, resolve, filter by level
- **Notes**: Auto-pruned to 800 entries. Watchdog alerts throttled.

## Platform Status / प्लेटफॉर्म स्थिति
- **Purpose**: Cron health, watchdog results, self-checks
- **Who uses**: Super-admin, Ops
- **Main actions**: View cron last-run, run watchdog manually, view self-check results
- **Notes**: Shows "24/7 ON" when cron is running. "Stale" if missed schedule.
