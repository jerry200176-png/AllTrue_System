---
status: Accepted
date: 2026-06-27
owner: Scheduling context
---
# ADR-0011: Canonical session/leave status vocabulary

## Constitution link
Derives from CONSTITUTION.md Article V (single owner per fact) + Handbook NM-7 (status enums defined once); extends ADR-0008.

## Context
The leave / non-quota / calendar-excluded status sets were hardcoded as array literals in 5+ files (`LearningRecord` scope, `StudentClassController`, `ClassSessionController`, `LearningRecordController`, `ScheduleGuardService`). Adding `'excused'` (FR-005) required editing every copy — a recurring drift vector and an NM-7 violation. Detected and ratcheted by fitness FIT-5.

## Decision
`App\Support\SessionStatus` owns the vocabulary: `LEAVE`, `NON_QUOTA`, `EXCLUDED_FROM_CALENDAR`. All consumers reference the constants; no module hardcodes the set.

## Consequences
+ One edit adds/changes a status everywhere; NM-7 enforceable.
+ FIT-5 ratcheted 5 → 1 (only the canonical source); baseline locked at 1.
− Eventual move to a typed enum + the shared FE/BE decision-contract package (ADR-0008) is the next step.

## Status of implementation
**Implemented** (this is not a draft): `SessionStatus.php` added, 7 literal sites replaced, leave-exclusion + session tests green (10 tests), FIT-5 locked at 1.

## References
ADR-0008 (decision-contract package). Handbook NM-7. Fitness FIT-5. Fact-adjacent F-05/F-06.
