---
status: Accepted
date: 2026-06-27
owner: Scheduling context
---
# ADR-0005: All occurrence creation flows through one SessionWriter

## Constitution link
Derives from CONSTITUTION.md Article I.3 (make illegal states unrepresentable); enforces DI-1.

## Context
`ClassSession::create` is called from **26 sites across 11 files** (controllers, services, observers, console commands). Any site can create a duplicate/orphan occurrence — the mechanism behind in-app #173/#175. There is no chokepoint at which DI-1 (≤1 occurrence per student+date+slot) can be enforced.

## Decision
Introduce a single `SessionWriter` application service; **all** occurrence creation routes through it. It enforces DI-1 (cross-course same-slot dedup — already implemented at one site in `EnrollmentService`, branch `fix/cross-course-session-dedup`) and emits `OccurrenceScheduled`.

## Consequences
+ DI-1 enforceable in one place; duplicate-occurrence family closed by construction.
+ Enables a `(StudentID,SessionDate,StartTime)` partial-unique constraint after historical de-dup.
− 26 call-sites must be migrated incrementally (ratcheted by `arch-fitness-check.mjs` FIT-1).

## References
ADR-0001. Fact F-05. Invariant DI-1. Fitness FIT-1 (baseline 26 → 1). Debt TD-SCHED.
