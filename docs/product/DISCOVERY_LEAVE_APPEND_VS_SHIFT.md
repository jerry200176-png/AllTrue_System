# Product Discovery — Leave semantics: 順延 vs append-only

**Status:** Discovery only — **do not implement**  
**Opened:** 2026-07-18 (Founder Decision 2)  
**Related:** in-app #204 (preview fixed; 順延 retained); GitHub [#1100](https://github.com/jerry200176-png/AllTrue_System/issues/1100) (A/B boundary after leave extend)  
**Owner:** Product / Founder  

## Current policy (locked)

Leave continues to **shift subsequent sessions forward** and append a tail session (`CourseLeaveCascadeService`).  
Operators must see vacated / moved / append dates **before confirm** (R75 / leave-cascade-preview).

## Not a bug

“只補尾、不推移既有日期” is an alternate product policy, not a defect in current cascade.

## Research questions (must answer with real user evidence)

1. Do directors expect **whole-course shift** or **keep existing calendar dates**?  
2. Do different leave types (normal / retro / bulk holiday / teacher leave) need different semantics?  
3. Can auto-moving sessions that parents were already notified about be acceptable?  
4. What conflict / notification / admin cost does shifting create vs append-only?  
5. Which mode minimizes risk to billing, attendance, prepaid sessions, and teacher calendars?  
6. Should directors explicitly choose a mode per campus or per course?

## Exit criteria for implementation

- Written product decision with campus evidence  
- Conflict with #1100 resolved or jointly designed  
- Migration plan for in-flight courses  
- Explicit Founder Decision to change semantics  

Until then: **keep 順延 + preview**.  
