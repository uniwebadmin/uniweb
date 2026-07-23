# Overnight Cloud Run — Points 480 → 3340

**Owner order (2026-07-23 night):** Laptop off. Cloud agents only. Work until morning. **No deploy. No merge to `main`.** Secrets never invent/commit.

## Source of truth
- `_inbox/UniWeb_Master_Status_Points.json` (from UniWeb_Master_Status_Report.pdf)
- Slices: `_inbox/overnight_480_3340/points_*.json`
- Progress log (each agent appends): `_inbox/overnight_480_3340/PROGRESS.md`

## Scope
| Range | Notes |
|-------|--------|
| **480–3340** | Only these point numbers |
| Outside | Ignore |
| **480–540** | Already polished/shipped in PR #53–#56 — re-verify lightly, do not redo unless broken |
| **541–3340** | Primary overnight work |

## Hard skips (count as DONE/SKIP, do not code)
1. Any `*-aaminalaptop.php` — local OneDrive backup; live usually 404
2. Bucket `6_SHOULD_NOT` / “talk first” / big wishlist without owner start
3. Pasting partner gateway keys / inventing `config.php` / SFTP / API secrets
4. Anything that **requires owner decision or owner credentials** — log as `BLOCKED_OWNER` and continue
5. Force-push, hard reset, deleting production pages

## Allowed ship path (so work survives laptop off)
- Commit on **cloud feature branch** only
- Open **DRAFT PR** against `main` (do **not** merge — Hostinger auto-deploy must not fire)
- English UI only; Hindi OK only in agent notes

## Definition of 100% per point
- Feature completeness / polish / verify for that page or atom
- Live smoke when possible: public → 200; staff pages → 302 login redirect OK
- Mobile responsive: no cut-off controls; `w-full` / `p-4 sm:p-6` / horizontal tables
- Security: CSRF on mutating actions; never print secrets/watchdog keys in HTML
- Skip types above = 100% SKIP (documented)

## Agent lanes (failover = parallel, not serial)
| Agent | Points file | Range |
|-------|-------------|-------|
| A | `points_A_541_1000.json` | 541–1000 |
| B | `points_B_1001_1600.json` | 1001–1600 |
| C | `points_C_1601_2200.json` | 1601–2200 |
| D | `points_D_2201_2800.json` | 2201–2800 |
| E | `points_E_2801_3340.json` | 2801–3340 |

If you finish your lane early: take the **next unfinished** lane from PROGRESS.md (do not collide — claim a 50-point block first).

## PROGRESS.md format (append only)
```
## Agent <letter> <ISO time>
- Claimed: N–M
- Done: …
- SKIP: …
- BLOCKED_OWNER: …
- Branch: …
- Draft PR: …
```

## Morning owner report
Summarize from PROGRESS.md + draft PRs: how many points done / skipped / blocked.
