# PB-01 — Safe external copy

| Field | Value |
|-------|-------|
| Phase / Risk | 1 / T1 |
| Issue | [#1437](https://github.com/jerry200176-png/AllTrue_System/issues/1437) |
| Depends / Blocks | PB-00 / PB-09 (partial) |
| Board | backlog |

**Scope:** UX Spec safe fail copy; stop echoing name on LINE fail; remove Portal empty-phone existence leak; LINE ambiguous→fail-closed; webhook bind RL; fix help teaching phone-less bind.  
**Non-scope:** Pairing; GSR; Inbox; success verification method.

**AC:** Wrong/empty/unknown phone → same parent copy; no LINE first-win (`AMBIGUOUS_MATCH`); parents get no discriminating codes; RL→`RATE_LIMITED`; docs don’t teach bare「綁定 姓名」.  
**Tests:** enum suite; ambiguous; RL; valid name+phone still binds.  
**Rollback:** `parent_binding_safe_copy=off` (keep reason logging).
