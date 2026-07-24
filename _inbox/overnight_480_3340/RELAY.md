# Overnight RELAY — auto handoff (480–3340)

## Idea
Har cloud agent **same command** use karta hai. Start par `QUEUE.md` padhta hai → pehla **OPEN** block **CLAIM** karta hai → khatam hone par DONE mark → **turant next OPEN** claim.  
Agar agent toot jaye: doosra agent same prompt se start → unfinished CLAIM (stale > 45 min) ya next OPEN le leta hai.

Laptop se Task-cloud abhi version-bug se fail ho raha hai → **phone** pe 3–5 identical RELAY workers start karo.

## Paste THIS on every new agent (same text)
https://cursor.com/agents · repo `6396601005/uniweb` · base branch **`overnight/brief-480-3340`**

```
UniWeb OVERNIGHT RELAY WORKER. Base branch: overnight/brief-480-3340.

1) Read _inbox/OVERNIGHT_BRIEF_480_3340.md and _inbox/overnight_480_3340/QUEUE.md and PROGRESS.md.
2) CLAIM the first block whose status is OPEN. If a CLAIM is older than 45 minutes with no DONE, steal it (mark CLAIM again with your agent name + time).
3) Work that point range to 100% or SKIP/N/A/BLOCKED_OWNER per brief. Group by PHP file. English UI. Skip *-aaminalaptop.php. Skip points 3315-3339. Never invent secrets. Never merge to main. Never deploy. DRAFT PR only. Commit often on your cloud branch.
4) When the block is finished: set that QUEUE row to DONE, append PROGRESS.md, push.
5) IMMEDIATELY go to step 2 — claim the next OPEN block. Do not stop. Do not wait for humans. Repeat until QUEUE has no OPEN/CLAIM rows left, then write MORNING_REPORT.md and stop.

If you finish early or find another agent's draft PR covering your files, still mark DONE and take the next OPEN block (no duplicate polish unless broken).
```

## Phone: how many agents?
Start **3 to 5** agents, **same paste** each time. They auto-split via QUEUE claims.  
Ek toot jaye → naya agent same paste → baaki OPEN + stale CLAIM khatam karega.

## Do NOT
- Merge to `main` / FTP deploy
- Paste partner keys / invent config secrets
- Work outside 480–3340 (focus 541+)
