# PB-02 — Completeness UI

| Field | Value |
|-------|-------|
| Phase / Risk | 1 / T1 |
| Issue | [#1438](https://github.com/jerry200176-png/AllTrue_System/issues/1438) |
| Depends / Blocks | PB-00 / PB-03 |
| Board | backlog |

**Scope:** StudentsList/detail completeness + filters; Wizard collect `parent_phone`; Import map 家長手機→`parent_phone`; campus completeness API.  
**Non-scope:** Pairing UI (PB-05); Inbox create (PB-03).

**AC:** Director lists missing contact ≤2 clicks; new enroll saves `parent_phone`; CSV writes it; summary matches fixture campus.  
**Tests:** import/update/completeness campus isolation.  
**Rollback:** `parent_binding_completeness_ui=off`.
