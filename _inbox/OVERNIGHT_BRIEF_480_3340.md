# Overnight Cloud Run — Points 480 → 3340 (confirmed from PDF)

Source: UniWeb Full Points Status PDF / chat paste (3352 atomic points).  
**Owner:** laptop off · cloud only · **no deploy · no merge to main · no secrets**.

## Bucket rules inside 480–3340

| Bucket | # range (approx) | Agent action |
|--------|------------------|--------------|
| 2 ~50% | 480–1243 | Bring to **100%**: feature/smoke/mobile/helpers/matrices where code can |
| 3 ~25% | 1244–3270 | Bring to **100%** UX atoms on **real product pages** (not backups) |
| 4 BAAKI | 3271–3314 | Do **code scaffolds** that need no partner keys; else `BLOCKED_OWNER` |
| 5 KARNA CHAHIYE | 3315–3329 | **SKIP code** — ops/process for owner (keys, smoke, backups) |
| 6 NAHI KARNA | 3330–3339 | **SKIP** — wrong sequence / Axis live / invent keys / FTP spam |
| 7 BILKUL NAHI | (after 3339) | **SKIP** |

## Hard skips (always)
- `*-aaminalaptop.php` (backup; live 404)
- Invent/paste partner keys, `config.php`, SFTP, Axis production, payout live money
- Shopify/e-Rupee/wishlist without owner start
- Merge to `main` / FTP deploy / force-push / delete production pages
- N/A UX atoms on non-UI files (webhooks, cron, config, lang, lib-only): mark **N/A PASS** if CSRF/pagination truly not applicable — still verify security headers / auth gates

## Already done (do not redo unless broken)
**480–540** polished in PR #53–#56 (smoke/mobile/feature for those admin pages; aaminalaptop SKIP).

## Lanes
| Agent | File | Range |
|-------|------|-------|
| A | `points_A_541_1000.json` | 541–1000 |
| B | `points_B_1001_1600.json` | 1001–1600 |
| C | `points_C_1601_2200.json` | 1601–2200 |
| D | `points_D_2201_2800.json` | 2201–2800 |
| E | `points_E_2801_3340.json` | 2801–3340 (heavy SKIP at end) |
| W | watchdog | fill gaps; write `MORNING_REPORT.md` |

## Ship
Commit on cloud branch → **DRAFT PR only**. Append `_inbox/overnight_480_3340/PROGRESS.md`.
