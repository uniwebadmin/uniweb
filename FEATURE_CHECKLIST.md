# UniWeb — Platform Feature Checklist

_Prepared for advisor / partner review. Snapshot of what is built and live on the UniWeb payment platform._

**Live URL:** https://uniweb.co.in
**Last verified:** 2026-07-21 (all key pages returned HTTP 200; auto-audit link watchdog covers every page below)

## Status legend
- ✅ **Built & live** — coded, deployed, and reachable in production
- ⚙️ **Built — needs owner input** — feature is coded; requires partner API keys / config to transact on real money
- 🔜 **On roadmap** — planned, not yet enabled

---

## 1. Public Website (customer-facing)
| Feature / Page | Status |
|---|---|
| Homepage | ✅ |
| About / Contact / FAQ | ✅ |
| Pricing | ✅ |
| Solutions | ✅ |
| Trust Centre | ✅ |
| Roadmap | ✅ |
| Blog + articles | ✅ |
| API Documentation (`api_docs.php` + OpenAPI 3.0 spec) | ✅ |
| Guided Platform Tour / Demo video | ✅ |
| Live Demo Payment (₹1 test link, self-seeding) | ✅ |
| Terms / Privacy / Refund Policy / Compliance Framework | ✅ |
| Merchant Agreement (public copy + PDF) | ✅ |
| System Status page | ✅ |
| Merchant Signup / Login | ✅ |
| Forgot / Reset Password (merchant + admin) | ✅ |
| Hosted Checkout page | ✅ |
| Payment Status page | ✅ |
| Branded error pages (403 / 404 / 500 via ErrorDocument) | ✅ |
| Security headers (X-Frame-Options, HSTS, Referrer-Policy, etc.) | ✅ |
| Mobile-responsive layout (footer/overflow hardened) | ✅ |

## 2. Merchant Portal
| Feature / Page | Status |
|---|---|
| Merchant Dashboard | ✅ |
| Onboarding / Business Setup wizard | ✅ |
| Transactions list + Transaction detail | ✅ |
| Reports | ✅ |
| Export transactions | ✅ |
| Payment Links | ✅ |
| Payment Pack | ✅ |
| QR Code (dynamic) | ✅ |
| Instant UPI Print QR (P2M, high-throughput) | ✅ |
| Wallet (Ledger / Available / In-transit / holds) | ✅ |
| Settlements + Settlement detail + Settlement settings | ✅ |
| Collection settings | ✅ |
| Invoices (view + PDF) | ✅ |
| Recurring payments | ✅ |
| Refunds | ✅ |
| Disputes | ✅ |
| Chargebacks | ✅ |
| KYC (entity-based document rules) | ✅ |
| Video KYC upload | ✅ |
| Team Members + role-based access (Admin/Finance/Developer/Viewer) | ✅ |
| Team invite + accept | ✅ |
| My Website (merchant micro-site) | ✅ |
| API Settings (test/live keys, webhooks) | ✅ |
| Two-Factor Authentication (TOTP) | ✅ |
| Notification preferences | ✅ |
| Agents / sub-merchant | ✅ |
| Bank account add / verify | ✅ |
| Test ↔ Live mode toggle | ✅ |
| Support / tickets | ✅ |
| My Account / Security | ✅ |

## 3. Admin Panel
| Feature / Page | Status |
|---|---|
| Admin Dashboard | ✅ |
| All Merchants / View / Edit / Add | ✅ |
| Staff management + activity log | ✅ |
| Transactions (platform-wide) | ✅ |
| Refunds | ✅ |
| Disputes | ✅ |
| Chargebacks | ✅ |
| KYC Review queue + document viewer + Video-KYC verify | ✅ |
| AML monitoring (high-value threshold) | ✅ |
| Financial Reports | ✅ |
| PG Webhooks console | ✅ |
| PG Reconciliation | ✅ |
| Bank Auto-Reconciliation | ✅ |
| Settlements / Settlement Engine / Batches | ✅ |
| Platform Wallet | ✅ |
| Merchant Banks | ✅ |
| Partners / Partner Detail / Partner Requests | ✅ |
| Platform Status / self-checks | ✅ |
| Link Audit + Link Watchdog | ✅ |
| Error Log | ✅ |
| Step-up Auth (sensitive-action re-auth) | ✅ |
| Security settings | ✅ |
| Customer messaging | ✅ |
| **Website & API Keys** (paste partner gateway keys) | ⚙️ paste keys |
| **Gateway Settings** (Razorpay / Cashfree / PayU / Axis) | ⚙️ paste keys |
| Axis UAT console | ⚙️ UAT creds |

## 4. Staff / Operations Portal
| Feature / Page | Status |
|---|---|
| Staff Dashboard | ✅ |
| Merchants (staff view) | ✅ |
| KYC Review (staff) | ✅ |
| Refunds (staff) | ✅ |
| Disputes (staff) | ✅ |
| Support (staff) | ✅ |
| Transactions (staff) | ✅ |
| Settlements (staff) | ✅ |
| PG Webhooks / Reconciliation (staff) | ✅ |
| Staff control + activity audit | ✅ |

## 5. Payments, API & Integrations
| Feature | Status |
|---|---|
| Merchant REST API + API verify | ✅ |
| Webhooks: merchant, Razorpay, Cashfree, PayU, Axis, WhatsApp | ✅ (code) / ⚙️ keys |
| Smart routing across gateways | ✅ |
| UPI collect / QR pay flow | ✅ |
| High-throughput QR (up to ₹20 crore cap, ~10 lakh txns/day, no false "high-frequency" block on small repeat payments) | ✅ |
| WooCommerce plugin | ✅ |
| Razorpay / Cashfree / PayU live transacting | ⚙️ paste live keys |
| PhonePe checkout | 🔜 roadmap (keys UI ready) |
| Digio (video-KYC / e-sign) | ⚙️ keys pending from Digio |

## 6. Platform Health & Compliance
| Item | Status |
|---|---|
| Auto-audit cron (link watchdog + integrity) | ✅ code — ⚙️ schedule on Hostinger cron |
| Morning ops cron | ✅ code — ⚙️ schedule |
| Settlement cron | ✅ code — ⚙️ schedule |
| Smoke-test suite (`tests/run_smoke_checks.php`) | ✅ |
| Integrity test suite (`tests/run_integrity_tests.php`) | ✅ |
| Financial integrity checks / wallet guards | ✅ |
| Branded error handling + security headers | ✅ |
| Real DB migrations as schema of record | ✅ |

---

## What is left (owner / manual actions — not code)
1. **Paste partner API keys** in Admin → Website & API Keys / Gateway Settings (Razorpay, Cashfree, PayU) once received — to move from test to real money.
2. **Schedule cron jobs** on Hostinger (auto-audit, morning ops, settlements) if not already set.
3. **Digio keys** — paste when the partner provides them (enables automated video-KYC / e-sign).
4. **PhonePe checkout** — enable when it moves off roadmap.
5. **Business/bank agreements** with the acquiring bank / PA-PG partner (commercial, not technical).

## Suggested questions for the advisor
- Which acquirer / PA-PG licence path do we target first (own PA licence vs. partner white-label)?
- Any additional KYC/AML controls the bank will expect before go-live?
- Settlement cycle (T+1 / T+2) and reserve/rolling-hold policy the bank prefers?
- Do we need PCI-DSS SAQ scope confirmation for the hosted checkout?
