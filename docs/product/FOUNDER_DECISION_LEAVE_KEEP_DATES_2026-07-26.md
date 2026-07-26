# Founder Decision — Ordinary leave keeps future dates (2026-07-26)

**Decision ID:** FD-LEAVE-KEEP-DATES-2026-07-26  
**Status:** Binding  
**Supersedes:** Discovery lock that ordinary leave shifts future sessions (`DISCOVERY_LEAVE_APPEND_VS_SHIFT.md` pre-decision text); AI_REGRESSION §R75 ordinary-leave vacated-week semantics.

## Decision

For **count-based, fixed-cadence** courses, a **single-session leave**:

- Marks the session `leave` (non-billable).
- **Must not** move existing future session dates/times.
- Appends at most one tail session to preserve purchased count.
- Must not create a silent vacated week.

Whole-course **shift / pause** remains a separate capability (`SHIFT_FUTURE_DATES_APPEND_TAIL`) and must not be the default leave path.

## Rationale

Operators and teaching ops consistently treat vacated next weeks after leave as a defect, not a feature. Spec-conforming tests of the old SHIFT behaviour do not justify retaining the product rule.

## Implementation pointers

- Service: `CourseLeaveCascadeService`
- Repair: `php artisan repair:leave-vacated-weeks`
- Lessons: AI_REGRESSION §R82 (active), §R75 (superseded note)
