# UniWeb — Phase 11 (later optional)

**Status:** parked. Do **not** go live with Route/Split. Do **not** sell NBFC or a customer PPI wallet.

**Audit tickets:** P11-01, P11-02 in `DEEP_AUDIT_ORDERED.md`

## P11-01 Live Route / Split API

**Problem:** Scaffold only today.  
**Expectation:** Live only after keys + commercial + **explicit Owner ask**.  
**Action:** No auto live status. No SDK early.

**Today (scaffold, not a product):**

| Piece | Where | Live? |
|-------|--------|--------|
| Route config save | Partner Detail → Commercial & Split | Status `live` is locked in the form. Save remaps `live` → `ready_for_api` unless Owner setting `route_split_live_enabled=1` |
| `canUsePartnerRoute()` | `includes/split_settlement.php` | **false** until that setting is 1 **and** mode=`partner_api` **and** status=`live` |
| Partner API call | `executePartnerRouteSplit()` | Records a pending transfer. **Does not** call Razorpay Route / Cashfree Easy Split / PayU split SDK |
| Merchant collection picker | Setup + Collection Settings | Only Direct UPI and Platform PG. Route/Easy Split are not offered as a live choice |
| Linked account / vendor ID fields | Collection Settings | May be saved as notes. Saving an ID does **not** turn Route live |

**When Owner says start (all three required):**

1. Partner Route keys + commercial terms exist.  
2. Owner explicitly says start (not this document).  
3. Then set `route_split_live_enabled=1` and implement the partner SDK — not before.

## P11-02 NBFC & customer PPI (EXCLUDED)

**Problem:** Licence risk and product confusion if built.  
**Expectation:** Not in product; hidden from menus.  
**Action:** Keep `nbfc*` hidden; never ship a consumer PPI wallet.

**Today:**

- Merchant / admin sidebars hide `merchant_nbfc.php`, `merchant_nbfc_loan.php`, `admin_nbfc.php`.
- Direct URL: `abortFeatureDisabled('nbfc')` + `nbfcLiveDisburseAllowed()` is **always false**.
- No `customer_wallet.php`. Merchant `wallet.php` is settlement balance, not a prepaid PPI.
- Public copy on Trust, Terms, FAQ, Compare: we do not sell NBFC or PPI.

Do **not** unhide those menus. Do **not** add a customer wallet page.

## Appendix — audit evidence (PDF)

Site tar + SQL 14 Aug 2026; ~434 PHP under `public_html`; migrations through 060; `checkout.php`; `global_search`; `header.php` full merchant nav in repo. Live HTTP/SMTP were not executed offline in the PDF.

## Never from this phase

- Razorpay Route / Cashfree Easy Split / PayU split SDK  
- Auto-flipping Route status to `live` because keys exist  
- NBFC loan product in any menu  
- Consumer PPI / prepaid wallet  
- Claiming an RBI PA / NBFC / PPI licence UniWeb does not hold
