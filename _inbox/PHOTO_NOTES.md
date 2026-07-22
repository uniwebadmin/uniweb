# UniWeb — Photo inbox notes (agent memory)

Owner phone screenshots → `_inbox/`. Sync via OneDrive. Do not treat `*-aaminalaptop.php` as source of truth; prefer git HEAD. If a file shrinks oddly, restore from git blob then re-apply fixes. Keep this folder **Always keep on this device** in OneDrive.

## Themes from owner photos (28)

| Area | Owner ask | Status |
|------|-----------|--------|
| A Layout | Homepage preview skewed on laptop; KYC empty right space; Payment Links icon cut; Staff Add Team Member form cut on mobile | homepage aspect fixed; KYC 2-col; Payment Links SVG; staff form scroll+padding |
| B Instant UPI QR | No fixed amount — open amount only | done (`qr_upi_print.php`) |
| C Settlements | Batch ID clickable; **failed reason visible**; settlement settings **P2M only**, other rails auto bank/partner | done |
| D Invoices | Tax as **%** (12/18/28), not ₹ amount | done |
| E KYC | “Select document” empty options; IEC Failed; admin SQL **collation mix** error | done (normalize entity, uploadable list, IEC format, collation-safe queries) |
| F Chargebacks/Disputes | Demo chargeback data; dispute ID clickable | done |
| G Admin | Axis Bank on gateway submit; **enable all** methods on edit merchant | done (+ migration 018) |
| H Clickables | Recon txn IDs + wallet commission TXN IDs clickable | done |
| I Checkout | Mobile **mandatory**, **no OTP**, email optional | done |

## Suggested fix order

1. Clickables + settlement fail reason (H/C) — done
2. Checkout phone mandatory / no OTP (I) — done
3. Invoice tax % (D) — done
4. KYC select + collation (E) — done
5. Layout cut-offs (A) — done (basic)
6. UPI open amount, P2M-only settings, chargeback demo, Axis, enable-all (B/C/F/G) — done

## Guardrails

- English UI only; Hindi OK in chat
- No inventing credentials; no force-push; no destructive DB without confirm
- Commit only when user asks (unless shipping via agreed live-prep path)

## Work log

- 2026-07-22: Notebook created. Restored OneDrive-truncated files from git. Photo fixes shipped in working tree (not committed yet). Owner must Apply migration 018 + pending 011–017 via Gateway Settings; paste gateway keys.
- Manual still: live FTP if CI secrets missing; confirm diabetes DB cleanup if requested.
