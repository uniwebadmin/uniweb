# Nav audit methodology (UniWeb)

Repeatable checklist for Admin, Merchant, and Staff sidebars. Canonical nav source: `includes/sidebar_nav.php` (merchant + super-admin) and `staffNavForRole()` in `includes/staff.php`.

Last run: **2026-08-30** — file-existence probe + manual classify.

## Step A — Inventory

| Role | Source | Notes |
|------|--------|-------|
| Merchant | `uniwebMerchantNavGroups()` | Rendered in `header.php` merchant sidebar |
| Admin (super) | `uniwebAdminNavGroups()` | Super-admin panel only |
| Staff | `staffNavForRole($role)` | Subset by role; no Partner Registry |

Hidden URLs: `uniwebMerchantHiddenUrls()` / `uniwebAdminHiddenUrls()` (currently empty).

Run file probe:

```bash
php tests/probe_nav_audit.php
```

## Step B — Resolve

For each URL: `is_file(repo_root / url)` → route exists.

HTTP expectations (manual / smoke):

| Code | Meaning |
|------|---------|
| 200 + content | OK candidate |
| 302 → login | OK if auth-gated |
| 403 | OK if permission enforced |
| 404 / blank / 500 | **DEAD** — remove from primary nav or fix |

## Step C — Classify

| Class | Rule |
|-------|------|
| **OK** | Loads, real content, correct role |
| **DEAD** | 404 / blank / fatal — must fix or remove this job |
| **STUB** | Page exists, incomplete — STUB pill or collapsed group |
| **PARKED** | Intentionally later — PARKED pill, not primary |
| **NEVER** | NBFC / PPI / white-label — must not appear |

Capability pills: `uniwebNavCapabilityStates()` in `includes/sidebar_nav.php` + `uiCapabilityPill()`.

## Step D — Act

- **Primary nav** = OK live tools only (Collect, Payments, Settlements, Today).
- STUB/PARKED demoted to collapsed **Money (more)** or **Advanced · *** groups.
- DEAD removed or wired.

### Changes applied (2026-08-30)

- Merchant **Payment Pack** moved from Collect → **Money (more)** (collapsed).
- Admin **Bulk Payout** moved from Settlements → **Advanced · Money** (collapsed) + STUB pill.
- STUB/PARKED pills on sidebar for honest labels.

## Step E — Summary counts (2026-08-30)

| Class | Count |
|-------|-------|
| OK | 88 |
| DEAD | 0 |
| STUB (nav pill) | 10 |
| PARKED (nav pill) | 2 |
| NEVER | 0 |

## Full inventory table

### Merchant primary + collapsed

| Label | URL | Class | Action |
|-------|-----|-------|--------|
| Dashboard | dashboard.php | OK | — |
| Launch Center | merchant_launch.php | OK | — |
| Payment Links | payment_links.php | OK | — |
| QR Code | qr_code.php | OK | — |
| Website / Pay button | merchant_website.php | OK | — |
| Payment Methods | payment_methods.php | OK | — |
| Transactions | transactions.php | OK | — |
| Refunds | refunds.php | OK | — |
| Reports | reports.php | OK | — |
| Disputes | disputes.php | OK | — |
| Orders | orders.php | OK | — |
| Settlement Balance | wallet.php | OK | — |
| Settlements | settlements.php | OK | — |
| Settlement Bank | add_bank.php | OK | — |
| Settlement Settings | merchant_settlement_settings.php | OK | — |
| Payment Pack (test links) | merchant_payment_pack.php | OK | Demoted to Money (more) |
| Faster Settlement Batches | merchant_instant_settlement.php | STUB | Collapsed + STUB pill |
| Payouts | merchant_payout.php | PARKED | Collapsed + PARKED pill |
| Payout API Keys | merchant_payout_keys.php | PARKED | Collapsed + PARKED pill |
| Beneficiaries | beneficiaries.php | OK | — |
| Recurring & Mandates | merchant_recurring.php | STUB | Collapsed + STUB pill |
| Instant UPI QR | qr_upi_print.php | OK | — |
| QR Analytics | qr_analytics.php | OK | — |
| KYC | kyc.php | OK | — |
| Shop Photos | merchant_shop_photos.php | OK | — |
| Team / Invoices / Complaints | merchant_team.php … | OK | — |
| Tools / Settings block | checkout_customize.php … | OK | — |

### Admin

| Label | URL | Class | Action |
|-------|-----|-------|--------|
| Today block (KYC, Registry, Support…) | admin_kyc.php … | OK | — |
| Merchants block | manage_merchant.php … | OK | — |
| Partners block | gateway_settings.php … | OK | — |
| Transactions block | admin_transactions.php … | OK | — |
| Settlements | admin_settlements.php | OK | — |
| Payout Requests | admin_payout.php | OK | — |
| Bulk Payout (CSV) | admin_bulk_payout.php | STUB | Demoted + STUB pill |
| Integration Board | admin_integration_matrix.php | STUB | Advanced collapsed + pill |
| Gateway Routing | admin_gateway_matrix.php | STUB | Advanced collapsed + pill |
| Deep Audit Plan | admin_audit_plan.php | STUB | Reference checklist |
| Virtual Accounts | admin_virtual_accounts.php | STUB | Partner keys pending |
| Ledger State Machine | admin_ledger_state.php | STUB | Ops tool |
| Advanced · Risk / Security items | admin_aml.php … | OK | Collapsed |

### Staff (representative)

All URLs in `staffNavForRole()` resolve to existing files (0 DEAD). Partner Registry / Platform Settings intentionally excluded.

## Error pages

| Path | Purpose |
|------|---------|
| `error.php` | All ErrorDocument targets (403/404/500/429…) |
| `error_404.php` | Alias → error.php |
| `includes/error_page.php` | `renderUniwebErrorShell()` — minimal branded layout |
| `checkout.php` | Expired / invalid payment link (customer) |

Token expired: `error.php?code=401&reason=token`

## Security headers

Central: `includes/security_headers.php` via `config.php` bootstrap + `.htaccess` for static files.

Skipped for partner webhooks (`*_webhook.php`, `api.php`) so POST bodies are not blocked by browser CSP.
