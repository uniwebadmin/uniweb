# Mobile start — overnight points 480–3340

Local launch from laptop **failed** (`resource_exhausted` on Cursor Cloud). Start from phone: https://cursor.com/agents  
Repo: `6396601005/uniweb` · Branch base: `overnight/brief-480-3340` (has brief + point slices).

## Rules (every agent)
- Scope only **480–3340** (work hard from **541**; 480–540 already done in PR #53–#56)
- **No deploy / no merge to main** (DRAFT PR only)
- Skip `*-aaminalaptop.php`, owner secrets/keys, bucket `6_SHOULD_NOT`
- 100% polish: mobile + CSRF + English UI
- Log `_inbox/overnight_480_3340/PROGRESS.md`

## Paste — Agent A (541–1000)
```
UniWeb overnight Agent A. Base branch overnight/brief-480-3340.
Read _inbox/OVERNIGHT_BRIEF_480_3340.md and _inbox/overnight_480_3340/points_A_541_1000.json.
Work points 541-1000 to 100% or SKIP/BLOCKED_OWNER. DRAFT PR only — never merge/deploy. No secrets. Skip aaminalaptop. Commit often. Update PROGRESS.md. Keep going until done; if early continue 1001+.
```

## Paste — Agent B (1001–1600)
```
UniWeb overnight Agent B. Base overnight/brief-480-3340.
Read brief + points_B_1001_1600.json. Points 1001-1600. DRAFT PR only, no merge/deploy/secrets. Skip aaminalaptop + owner-only. PROGRESS.md. Continuous.
```

## Paste — Agent C (1601–2200)
```
UniWeb overnight Agent C. Base overnight/brief-480-3340.
Read brief + points_C_1601_2200.json. Points 1601-2200. DRAFT PR only. Skip aaminalaptop/owner. PROGRESS.md. Continuous.
```

## Paste — Agent D (2201–2800)
```
UniWeb overnight Agent D. Base overnight/brief-480-3340.
Read brief + points_D_2201_2800.json. Points 2201-2800. DRAFT PR only. Skip aaminalaptop/owner. PROGRESS.md. Continuous.
```

## Paste — Agent E (2801–3340) + failover
```
UniWeb overnight Agent E + failover. Base overnight/brief-480-3340.
Read brief + points_E_2801_3340.json. Do 2801-3340 (SKIP 6_SHOULD_NOT). Then fill any gaps in PROGRESS for 541-3340. DRAFT PR only — never merge/deploy. Write MORNING_REPORT.md. Continuous overnight.
```

## Morning check
1. Open draft PRs titled `DRAFT overnight`
2. Read `_inbox/overnight_480_3340/PROGRESS.md` + `MORNING_REPORT.md` on those branches
3. Owner merges only after review (deploy happens on merge)
