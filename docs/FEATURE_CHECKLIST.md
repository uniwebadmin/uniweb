# UniWeb ΓÇö Platform Feature Checklist

_Prepared for advisor / partner review. Snapshot of what is built and live on the UniWeb payment platform._

**Live URL:** https://uniweb.co.in
**Last verified:** 2026-07-21 (all key pages returned HTTP 200; auto-audit link watchdog covers every page below)

## Status legend
- Γ£à **Built & live** ΓÇö coded, deployed, and reachable in production
- ΓÜÖ∩╕Å **Built ΓÇö needs owner input** ΓÇö feature is coded; requires partner API keys / config to transact on real money
- ≡ƒö£ **On roadmap** ΓÇö planned, not yet enabled

---

## 1. Public Website (customer-facing)
| Feature / Page | Status |
|---|---|
| Homepage | Γ£à |
| About / Contact / FAQ | Γ£à |
| Pricing | Γ£à |
| Solutions | Γ£à |
| Trust Centre | Γ£à |
| Roadmap | Γ£à |
| Blog + articles | Γ£à |
| API Documentation (`api_docs.php` + OpenAPI 3.0 spec) | Γ£à |
| Guided Platform Tour / Demo video | Γ£à |
| Live Demo Payment (Γé╣1 test link, self-seeding) | Γ£à |
| Terms / Privacy / Refund Policy / Compliance Framework | Γ£à |
| Merchant Agreement (public copy + PDF) | Γ£à |
| System Status page | Γ£à |
| Merchant Signup / Login | Γ£à |
| Forgot / Reset Password (merchant + admin) | Γ£à |
| Hosted Checkout page | Γ£à |
| Payment Status page | Γ£à |
| Branded error pages (403 / 404 / 500 via ErrorDocument) | Γ£à |
| Security headers (X-Frame-Options, HSTS, Referrer-Policy, etc.) | Γ£à |
| Mobile-responsive layout (footer/overflow hardened) | Γ£à |

## 2. Merchant Portal
| Feature / Page | Status |
|---|---|
| Merchant Dashboard | Γ£à |
| Onboarding / Business Setup wizard | Γ£à |
| Transactions list + Transaction detail | Γ£à |
| Reports | Γ£à |
| Export transactions | Γ£à |
| Payment Links | Γ£à |
| Payment Pack | Γ£à |
| QR Code (dynamic) | Γ£à |
| Instant UPI Print QR (P2M, high-throughput) | Γ£à |
| Wallet (Ledger / Available / In-transit / holds) | Γ£à |
| Settlements + Settlement detail + Settlement settings | Γ£à |
| Collection settings | Γ£à |
| Invoices (view + PDF) | Γ£à |
| Recurring payments | Γ£à |
| Refunds | Γ£à |
| Disputes | Γ£à |
| Chargebacks | Γ£à |
| KYC (entity-based document rules) | Γ£à |
| Video KYC upload | Γ£à |
| Team Members + role-based access (Admin/Finance/Developer/Viewer) | Γ£à |
| Team invite + accept | Γ£à |
| My Website (merchant micro-site) | Γ£à |
| API Settings (test/live keys, webhooks) | Γ£à |
| Two-Factor Authentication (TOTP) | Γ£à |
| Notification preferences | Γ£à |
| Agents / sub-merchant | Γ£à |
| Bank account add / verify | Γ£à |
| Test Γåö Live mode toggle | Γ£à |
| Support / tickets | Γ£à |
| My Account / Security | Γ£à |

## 3. Admin Panel
| Feature / Page | Status |
|---|---|
| Admin Dashboard | Γ£à |
| All Merchants / View / Edit / Add | Γ£à |
| Staff management + activity log | Γ£à |
| Transactions (platform-wide) | Γ£à |
| Refunds | Γ£à |
| Disputes | Γ£à |
| Chargebacks | Γ£à |
| KYC Review queue + document viewer + Video-KYC verify | Γ£à |
| AML monitoring (high-value threshold) | Γ£à |
| Financial Reports | Γ£à |
| PG Webhooks console | Γ£à |
| PG Reconciliation | Γ£à |
| Bank Auto-Reconciliation | Γ£à |
| Settlements / Settlement Engine / Batches | Γ£à |
| Platform Wallet | Γ£à |
| Merchant Banks | Γ£à |
| Partners / Partner Detail / Partner Requests | Γ£à |
| Platform Status / self-checks | Γ£à |
| Link Audit + Link Watchdog | Γ£à |
| Error Log | Γ£à |
| Step-up Auth (sensitive-action re-auth) | Γ£à |
| Security settings | Γ£à |
| Customer messaging | Γ£à |
| **Website & API Keys** (paste partner gateway keys) | ΓÜÖ∩╕Å paste keys |
| **Gateway Settings** (Razorpay / Cashfree / PayU / Axis) | ΓÜÖ∩╕Å paste keys |
| Axis UAT console | ΓÜÖ∩╕Å UAT creds |

## 4. Staff / Operations Portal
| Feature / Page | Status |
|---|---|
| Staff Dashboard | Γ£à |
| Merchants (staff view) | Γ£à |
| KYC Review (staff) | Γ£à |
| Refunds (staff) | Γ£à |
| Disputes (staff) | Γ£à |
| Support (staff) | Γ£à |
| Transactions (staff) | Γ£à |
| Settlements (staff) | Γ£à |
| PG Webhooks / Reconciliation (staff) | Γ£à |
| Staff control + activity audit | Γ£à |

## 5. Payments, API & Integrations
| Feature | Status |
|---|---|
| Merchant REST API + API verify | Γ£à |
| Webhooks: merchant, Razorpay, Cashfree, PayU, Axis, WhatsApp | Γ£à (code) / ΓÜÖ∩╕Å keys |
| Smart routing across gateways | Γ£à |
| UPI collect / QR pay flow | Γ£à |
| High-throughput QR (up to Γé╣20 crore cap, ~10 lakh txns/day, no false "high-frequency" block on small repeat payments) | Γ£à |
| WooCommerce plugin | Γ£à |
| Razorpay / Cashfree / PayU live transacting | ΓÜÖ∩╕Å paste live keys |
| PhonePe checkout | ≡ƒö£ roadmap (keys UI ready) |
| Digio (video-KYC / e-sign) | ΓÜÖ∩╕Å keys pending from Digio |

## 6. Platform Health & Compliance
| Item | Status |
|---|---|
| Auto-audit cron (link watchdog + integrity) | Γ£à code ΓÇö ΓÜÖ∩╕Å schedule on Hostinger cron |
| Morning ops cron | Γ£à code ΓÇö ΓÜÖ∩╕Å schedule |
| Settlement cron | Γ£à code ΓÇö ΓÜÖ∩╕Å schedule |
| Smoke-test suite (`tests/run_smoke_checks.php`) | Γ£à |
| Integrity test suite (`tests/run_integrity_tests.php`) | Γ£à |
| Financial integrity checks / wallet guards | Γ£à |
| Branded error handling + security headers | Γ£à |
| Real DB migrations as schema of record | Γ£à |

---

## What is left (owner / manual actions ΓÇö not code)
1. **Paste partner API keys** in Admin ΓåÆ Website & API Keys / Gateway Settings (Razorpay, Cashfree, PayU) once received ΓÇö to move from test to real money.
2. **Schedule cron jobs** on Hostinger (auto-audit, morning ops, settlements) if not already set.
3. **Digio keys** ΓÇö paste when the partner provides them (enables automated video-KYC / e-sign).
4. **PhonePe checkout** ΓÇö enable when it moves off roadmap.
5. **Business/bank agreements** with the acquiring bank / PA-PG partner (commercial, not technical).

## Suggested questions for the advisor
- Which acquirer / PA-PG licence path do we target first (own PA licence vs. partner white-label)?
- Any additional KYC/AML controls the bank will expect before go-live?
- Settlement cycle (T+1 / T+2) and reserve/rolling-hold policy the bank prefers?
- Do we need PCI-DSS SAQ scope confirmation for the hosted checkout?
