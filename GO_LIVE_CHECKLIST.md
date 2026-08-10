# Go-Live Configuration Checklist

## Dev-Done (already implemented/verified)

- [x] Security headers: X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP, HSTS (config.dev.php + .htaccess)
- [x] Branded error pages: 403/404/500 via error.php (no stack traces to users)
- [x] Session cookies: HttpOnly, Secure (HTTPS), SameSite=Lax
- [x] display_errors off in production (env UNIWEB_DISPLAY_ERRORS=0)
- [x] Directory listing disabled (Options -Indexes in .htaccess)
- [x] Login rate limiting: merchant (velocity 8/5min), admin (5/15min), staff (5/15min), customer (velocity 8/5min + OTP 6/10min)
- [x] CSRF on all state-changing forms (admin + merchant + customer)
- [x] Step-up/super-admin check on partner key edit (gateway_settings.php requireSuperAdmin)
- [x] All cron scripts have key-based auth
- [x] Webhook signature verification: Razorpay, Cashfree, PayU, Axis, Decentro
- [x] Webhook idempotency: registerGatewayEvent atomic INSERT + unique constraint
- [x] Webhook retry queue with dead-letter (webhook_reliability.php)
- [x] PII encryption at rest (AES-256, includes/encryption.php)
- [x] KYC docs in private storage (blocked from web via .htaccess RewriteRule ^storage/ - [F])
- [x] DB backup cron writes to backups/ with .htaccess deny
- [x] No plaintext secrets in repo (config.php gitignored, .env gitignored)
- [x] Compliance pages reachable from footer (Terms, Privacy, Refund Policy, Grievance)
- [x] No wallet/NBFC in customer portal or admin sidebar
- [x] No "we are the bank/PA" claims on public pages
- [x] Pricing only shows enabled methods when publicPricingApproved
- [x] Staff role-based access control (staffNavForRole, requireStaffAccess, requireSuperAdmin)
- [x] Staff activity logging (logStaffActivity on all KYC actions)
- [x] Failed payment reason mapping (transactionStatusExplainer + reason maps)
- [x] Merchant notifications: KYC fail, payment fail, mandate debit fail, payout fail
- [x] No _v2 duplicate pages
- [x] Diagnostic/debug scripts blocked in .htaccess

## Owner-Done (requires Owner action)

### Partner Keys & Methods
- [ ] Paste all production partner keys in Admin → Gateway Settings (test vs live separated)
- [ ] Decentro staging keys → paste and test connectivity first
- [ ] Methods enabled only where contract + keys OK
- [ ] Reason maps filled for top failure codes (Admin → Reason Maps)
- [ ] MDR defaults set + at least one test merchant M (commission rate) set
- [ ] Route/split mode confirmed per partner commercial (or standard settle documented)

### Webhooks
- [ ] Webhook URLs set on partner side (Razorpay/Cashfree/PayU/Axis/Decentro dashboards) — see CRON_INVENTORY.md
- [ ] Webhook secrets configured in Gateway Settings for each partner
- [ ] Test webhook: trigger a test payment and confirm webhook reaches server

### Domain & HTTPS
- [ ] HTTPS + domain final (SSL certificate active)
- [ ] APP_URL set to production domain in config.php

### Cron Jobs
- [ ] All 7 cron jobs scheduled in Hostinger hPanel — see CRON_INVENTORY.md
- [ ] Cron keys set in Gateway Settings: platform_watchdog_key, cron_auto_kyc_key, settlement_cron_key, cron_mandates_key, bank_reconciliation_cron_key
- [ ] Verify cron health shows "24/7 ON" in Admin → Platform Status after first run

### Communications
- [ ] Support email tested (send test from Admin → Support)
- [ ] WhatsApp OTP tested (if WhatsApp enabled)
- [ ] SMS gateway tested (if SMS enabled)

### Data Cleanup
- [ ] Demo/junk PII removed from public site (demo merchant, test data)
- [ ] Demo ₹1 pay path works (demo.php)

### Legal
- [ ] Terms page reviewed and accurate
- [ ] Privacy page reviewed and accurate
- [ ] Refund Policy page reviewed and accurate
- [ ] Grievance officer name/email/phone updated (config.php GRIEVANCE_OFFICER_*)

### Staff & Security
- [ ] Staff accounts created with least-privilege roles
- [ ] Super-admin 2FA/step-up enabled (TOTP in admin login)
- [ ] PII encryption key backed up offline (key loss = data unreadable)
- [ ] Rotate any credential that appeared in chat/backups

### Backups
- [ ] DB backup schedule confirmed (daily cron_db_backup.php or hosting backup)
- [ ] Backup location NOT web-public (backups/.htaccess denies access)
- [ ] Backup retention: 7 days minimum
