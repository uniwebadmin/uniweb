# UniWeb chat inbox — agent thread

Mobile / OneDrive `_inbox/chat/*.txt` messages are summarized here.
Owner rule: strategy pack coding waits until explicit **start**.

## How to send (phone)

See `README.txt` in this folder. Drop a `.txt` with subject + instruction.

## Confirmed strategy pack (owner 2026-07-22)

Agent explanation accepted. Status:

| # | Point | Action when owner says start |
|---|--------|------------------------------|
| 1 | Exact reason copy | ✅ STARTED+shipped `feature/exact-reason-polish` |
| 2 | Shopify / WP / e-Rupee | Woo exists — leave; others after PG live |
| 3 | Razorpay-style QR | ✅ Verified + defensive load shipped |
| 4 | No auto-approve contact update | ✅ OTP shipped (`feature/otp-contact-change`) |
| 5 | Payout partners | Scaffold OK; wire when keys arrive |
| 6 | No failed-payout auto-reversal | Keep gate; recon-only |

**START received 2026-07-23 ~18:06 IST** ("let's start"). Coding #1/#3/#4; #2/#5/#6 stay leave/scaffold.

## Message log

| When | File / source | Summary | Agent |
|------|---------------|---------|--------|
| 2026-07-24 ~19:00 | Owner: auto flow + Hindi rule | Permanent rule: simple Hindi/Urdu chat. Coding: signup/KYC pe saari methods auto-queue; P2M turant ON; NBFC+Instant pages; partner webhook se auto ON/OFF. PR shipping. | coding |
| 2026-07-24 ~18:00 | Live-prep auto | KYC **Verify KYC now** (super + step-up) shipped PR #74. Smoke green; static broken_links 0. Keys UI tip clarified. Signup default-request model still discuss/await start. | shipped #74 |
| 2026-07-24 ~16:57 | Owner: **start** | Method Request partner flow **SHIPPED** — PR #73 merged + Hostinger deploy green. Flow: Merchant request → Admin Send to Partner → Partner decision → Final Enable. Real card money still needs gateway keys. | shipped #73 |
| 2026-07-23 ~18:20 | Cursor agent | **#1 Exact reason polish STARTED + shipped** — `gateway_reason_map.php`, webhook→`failure_reason`, txn list Reason column, migration 020. Branch `feature/exact-reason-polish`. Owner: add `'gateway_reason_map'` to live `config.php` `$__includes`. | coding+PR |
| 2026-07-23 ~18:10 | Cursor agent | **#4 OTP contact change started + shipped** — merchant email/mobile change on `my_account.php` now requires OTP to new (+ old when real); never silent profile overwrite. Branch `feature/otp-contact-change`. Customer portal stays OTP-login only (no profile self-update). | shipped PR |
| 2026-07-23 ~18:06 | Cursor chat | Owner: let's start — strategy pack coding begins | #1/#3/#4 agents launched |
| 2026-07-22 | Cursor chat | Chat inbox system requested (like photos) | created this folder |
| 2026-07-22 | Cursor chat | Strategy pack confirmed; wait for start | noted |
| 2026-07-22 | `.txt.docx` (empty) | Accidental Word file — deleted; use plain .txt | fixed |
| 2026-07-22 | Auto mode | Live `/cust` was 404 — add `cust/index.php` redirect | shipping |
| 2026-07-22 ~18:54 | `_TEMPLATE.docx` | Long pasted AI-chat: exact-reason spec (GatewayReasonMapper, webhook retry+idempotency, reason column+audit log), KYC wishlist (progress %, autosave, AI blur/fake check, submit-all-gateways, preview, timeline, geo, penny-drop, e-sign), owner note on auto-populate reason + new "admin 1-hour approve → 1-click to partner" flow for any merchant/customer request, + "2 page issues" audit | logged to strategy pack; **no START_CODE → not coded**; verified both "page issues" are false positives (config.private.php 403 = intentional .htaccess block; ifsc_lookup.php 401 = intentional login-gate) — no fix needed |
