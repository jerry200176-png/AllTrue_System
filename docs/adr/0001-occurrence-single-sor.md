---
status: Accepted
date: 2026-06-27
owner: Scheduling context
---
# ADR-0001: Occurrence is the single source of truth for class timing

## Constitution link
Derives from CONSTITUTION.md Article V (single owner per fact) + Handbook P1/ADR-001, invariant DI-1.

## Context
"When a class happens" is stored in two tables — `schedules` (recurrence + reschedule/leave exceptions) and `ClassSession` (materialized occurrence). Neither is authoritative; `schedule_discrepancies`, `ScheduleGuardService`, and the client-side `calendarOccurrenceMerge.js` exist only to reconcile them. This dual-SoR is the root of the largest recurring-defect family (in-app #170/#173/#175/#176; families F1/F3/F5).

## Decision
`Occurrence` is the single SoR for facts F-05 (timing) and F-06 (instructor). `schedules` is demoted to a pure *recurrence rule + exception event source* that only **generates** occurrences and is never read as truth.

## Consequences
+ Duplicate/divergent timing becomes structurally impossible; `schedule_discrepancies` retired by construction.
+ The calendar consumes one server projection (Handbook LY-5); FE stops re-deciding occurrence precedence.
− Migration is multi-phase (Expand/Contract); see roadmap in `RULE_ARCHITECTURE_GOVERNANCE.md §9`.

## References
ADR-0005 (single writer), ADR-0004 (events), ADR-0007. Facts F-05/F-06. Invariant DI-1/DI-4/DI-7. Debt TD-SCHED.
