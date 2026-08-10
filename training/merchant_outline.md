# Merchant Portal Training Outline

Per page: purpose, who uses, main actions, enable/disable notes.
Headings in English + Hindi.

## Merchant Login / मर्चेंट लॉगिन
- **Purpose**: Merchant authentication with OTP/2FA
- **Who uses**: Merchant owners, team members
- **Main actions**: Email/phone + password → OTP via WhatsApp/email → dashboard
- **Notes**: 8 failed attempts in 5 min → 15 min block

## Merchant Register / मर्चेंट पंजीकरण
- **Purpose**: New merchant signup
- **Who uses**: New merchants
- **Main actions**: Fill business details, verify phone/email, create account
- **Notes**: Throttled by velocity check (8/hour per IP)

## Merchant Setup / मर्चेंट सेटअप
- **Purpose**: Onboarding wizard — complete profile, KYC, bank details
- **Who uses**: New merchants
- **Main actions**: Upload documents, complete video KYC, add bank account, sign agreement
- **Notes**: Step-by-step progress tracker. Cannot go live until all steps complete.

## Dashboard / डैशबोर्ड
- **Purpose**: Overview of merchant stats, balance, recent transactions
- **Who uses**: Merchant owners, team members
- **Main actions**: View today/month/all-time stats, balance breakdown, failed payments, health score
- **Notes**: Test/Live mode toggle. Failed payments widget links to filtered transactions.

## KYC / KYC प्रक्रिया
- **Purpose**: Submit and track KYC documents + video KYC
- **Who uses**: Merchant owners
- **Main actions**: Upload PAN/Aadhaar/GST/bank proof, complete video KYC capture
- **Notes**: Video KYC is live camera capture (not file upload). Records IP + timestamp.

## Agreement / अनुबंध
- **Purpose**: Sign merchant agreement
- **Who uses**: Merchant owners
- **Main actions**: Read agreement, sign digitally
- **Notes**: Agreement version tracked. Must sign before going live.

## Payment Links / पेमेंट लिंक
- **Purpose**: Create and share payment links
- **Who uses**: Merchants, team members with create_links capability
- **Main actions**: Create link (amount, description, method), share via WhatsApp/SMS/email, embed on website, track views/payments
- **Notes**: Rate limited: 20 links per 10 min. No-expiry option available.

## QR Codes / QR कोड
- **Purpose**: Create and manage UPI QR codes
- **Who uses**: Merchants, team members
- **Main actions**: Create single/bulk QR, download PNG/PDF, print, share, view analytics
- **Notes**: Bulk create: 50 max per batch. QR analytics show scan/payment KPIs.

## Transactions / लेनदेन
- **Purpose**: View and search merchant transactions
- **Who uses**: Merchants, team members
- **Main actions**: Filter by status/method/date/QR, search by customer, export CSV, view reason for failed
- **Notes**: Failed transactions show mapped reason in EN + HI

## Refunds / रिफंड
- **Purpose**: Initiate and track refunds
- **Who uses**: Merchants with refund capability
- **Main actions**: Select transaction, initiate refund, track status
- **Notes**: Idempotent. Refund amount cannot exceed original.

## Recurring & Mandates / आवर्ती भुगतान और मैंडेट
- **Purpose**: Set up e-mandate recurring payments
- **Who uses**: Merchants with recurring capability
- **Main actions**: Create mandate (amount, frequency, UPI/card), pause/resume/cancel, view debit history
- **Notes**: Mandate registration via partner (Razorpay/Decentro). Auto-debit via daily cron.

## Settlements / सेटलमेंट
- **Purpose**: View settlement history and balance breakdown
- **Who uses**: Merchants, finance team
- **Main actions**: View settled/pending/on-hold amounts, download settlement advice
- **Notes**: Available = can be settled. In-transit = processing. On-hold = under review.

## Wallet / वॉलेट
- **Purpose**: Balance overview (available, in-transit, on-hold, settled)
- **Who uses**: Merchants
- **Main actions**: View balance breakdown, transaction history
- **Notes**: This is merchant settlement wallet — NOT customer wallet. No topup/PPI.

## Payouts / पेआउट
- **Purpose**: Initiate payouts to bank accounts
- **Who uses**: Merchants with payout capability
- **Main actions**: Add beneficiary, initiate payout, track status
- **Notes**: Payout access requires admin approval. UTR shown on success.

## Team / टीम
- **Purpose**: Manage team members with role-based access
- **Who uses**: Merchant owners
- **Main actions**: Add team member, assign role (admin/manager/staff/viewer), enable/disable
- **Notes**: Roles control capabilities: create_links, create_qr, refund, payout, etc.

## API Keys / API कुंजी
- **Purpose**: Generate and manage API keys for integration
- **Who uses**: Developers, merchants
- **Main actions**: Generate API key, set permissions, revoke
- **Notes**: Keys are hashed at rest. Show full key only once on creation.

## Notifications / सूचनाएं
- **Purpose**: In-app notification center
- **Who uses**: Merchants
- **Main actions**: View notifications, mark as read, configure preferences
- **Notes**: Notifications for KYC, payment, payout, mandate events. WhatsApp/email fan-out based on prefs.

## Support / सहायता
- **Purpose**: Create and track support tickets
- **Who uses**: Merchants
- **Main actions**: Create ticket, view responses, close ticket
- **Notes**: Support channels (WhatsApp/email/Telegram) configured by admin.

## Security / सुरक्षा
- **Purpose**: Password change, 2FA setup, contact change with OTP
- **Who uses**: Merchant owners
- **Main actions**: Change password, enable TOTP 2FA, change email/phone with OTP verification
- **Notes**: Contact changes require OTP on both old and new channels.
