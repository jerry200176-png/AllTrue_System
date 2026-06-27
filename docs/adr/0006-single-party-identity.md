---
status: Accepted
date: 2026-06-27
owner: Identity context
---
# ADR-0006: One identity model (Party)

## Constitution link
Derives from CONSTITUTION.md Article V (single owner: identity).

## Context
A teacher exists as both a `Teacher` row and a `User` row (type=T) with `Teacher.id === User.id` (G-001). Joins on `TeacherID` are ambiguous; retirement is already underway (`TeacherUserMergeService`, "retire Teacher table — Wave A").

## Decision
`Party` (the `User` table extended) is the single identity SoR; `Teacher` is retired. All `TeacherID` references resolve to `User.id`.

## Consequences
+ Unambiguous identity; removes a whole class of join/identity drift.
− Multi-wave migration; backward-compatible reads during transition.

## References
Fact F-01. Debt TD-IDN. Related: existing migration commits #719.
