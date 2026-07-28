# PB-08 — E2E & security verification

| Field | Value |
|-------|-------|
| Phase / Risk | 2–3 / T2 |
| Issue | [#1444](https://github.com/jerry200176-png/AllTrue_System/issues/1444) |
| Depends / Blocks | PB-05,06,07 / PB-09 |
| Board | backlog / blocked |

**Scope:** Automate bind test matrix in CI + manual checklist; mobile/desktop smoke if patterns exist; security: brute/replay/IDOR/cross-campus/log PII/ambiguous/malformed; post-merge smoke (health, issue, consume, revoke).  
**Non-scope:** New product features; full load test.

**AC:** CI parent-binding green; checklist+Actions evidence; no raw phone/token in sampled logs; revoke removes access in one cycle.  
**Rollback:** N/A — fail blocks PB-09; disable pairing flag.
