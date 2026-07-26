# PB-07 — Migration & dual-write

| Field | Value |
|-------|-------|
| Phase / Risk | 2 / T3 |
| Issue | [#1443](https://github.com/jerry200176-png/AllTrue_System/issues/1443) |
| Depends / Blocks | PB-04, PB-05 / PB-08,09 |
| Board | backlog / blocked |

**Scope:** Backfill verified SLB→ParentIdentity+active GSR(`contact_phone_legacy`); dual-write GSR+SLB; orphan/purge on delete; audit command orphans=0; **revoke invalidates ParentSession immediately**; phone-change/transfer hooks.  
**Non-scope:** Drop SLB; OTP; force sunset.

**AC:** Post-backfill orphans=0; dual-write both sides; delete leaves nothing usable; revoke sessions tested; rollback plan CI-tested.  
**Tests:** idempotent backfill; dual-write; purge; transfer; session after revoke.  
**Rollback:** stop dual-write; auth→SLB; keep rows.
