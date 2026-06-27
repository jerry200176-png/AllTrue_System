---
status: Accepted
date: 2026-06-27
owner: Principal Architect
---
# ADR-0008: Shared FE/BE decision-contract package

## Constitution link
Derives from CONSTITUTION.md Article VI.3 (no hidden decisions); Handbook P8/NM-7.

## Context
Decision/error codes (scheduling 409s like `overlapping_active_course`, auth outcomes, leave-status sets) are duplicated as string literals across backend and frontend. When the backend added `overlapping_active_course`, the frontend silently didn't handle it → a dead-end UX (in-app #174). Status/leave literals are repeated in ~6 places.

## Decision
Define decision/error codes and domain enums **once** in a shared contract package, code-generated into both PHP and TypeScript. A consumer referencing an unknown code **fails the build**.

## Consequences
+ FE/BE can't drift; the #174 class becomes a compile error; NM-7 (no duplicated status literals) becomes enforceable.
− Requires a codegen step in CI.

## References
Handbook P8/NM-7. In-app #174. Fitness: future FIT-5 (status-literal duplication).
