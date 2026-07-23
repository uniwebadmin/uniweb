# UniWeb chat inbox — agent thread

Mobile / OneDrive `_inbox/chat/*.txt` messages are summarized here.
Owner rule: strategy pack coding waits until explicit **start**.

## How to send (phone)

See `README.txt` in this folder. Drop a `.txt` with subject + instruction.

## Confirmed strategy pack (owner 2026-07-22)

Agent explanation accepted. Status:

| # | Point | Action when owner says start |
|---|--------|------------------------------|
| 1 | Exact reason copy | Polish if gaps; leave if already sufficient |
| 2 | Shopify / WP / e-Rupee | Woo exists — leave; others after PG live |
| 3 | Razorpay-style QR | Verify live; polish gaps only |
| 4 | No auto-approve contact update | OTP for email+mobile change only |
| 5 | Payout partners | Scaffold OK; wire when keys arrive |
| 6 | No failed-payout auto-reversal | Keep gate; recon-only |

**START received 2026-07-23 ~18:06 IST** ("let's start"). Coding #1, #3, #4 in parallel; #2/#5/#6 stay leave/scaffold per table.

## Message log

| When | File / source | Summary | Agent |
|------|---------------|---------|--------|
| 2026-07-23 ~18:10 | Cursor agent | **#4 OTP contact change started + shipped** — merchant email/mobile change on `my_account.php` now requires OTP to new (+ old when real); never silent profile overwrite. Branch `feature/otp-contact-change`. Customer portal stays OTP-login only (no profile self-update). | shipped PR |
| 2026-07-23 ~18:06 | Cursor chat | Owner: let's start — strategy pack coding begins | #1/#3/#4 agents launched |
| 2026-07-22 | Cursor chat | Chat inbox system requested (like photos) | created this folder |
| 2026-07-22 | Cursor chat | Strategy pack confirmed; wait for start | noted |
| 2026-07-22 | `.txt.docx` (empty) | Accidental Word file — deleted; use plain .txt | fixed |
| 2026-07-22 | Auto mode | Live `/cust` was 404 — add `cust/index.php` redirect | shipping |
| 2026-07-22 ~18:54 | `_TEMPLATE.docx` | Long pasted AI-chat: exact-reason spec (GatewayReasonMapper, webhook retry+idempotency, reason column+audit log), KYC wishlist (progress %, autosave, AI blur/fake check, submit-all-gateways, preview, timeline, geo, penny-drop, e-sign), owner note on auto-populate reason + new "admin 1-hour approve → 1-click to partner" flow for any merchant/customer request, + "2 page issues" audit | logged to strategy pack; **no START_CODE → not coded**; verified both "page issues" are false positives (config.private.php 403 = intentional .htaccess block; ifsc_lookup.php 401 = intentional login-gate) — no fix needed |
