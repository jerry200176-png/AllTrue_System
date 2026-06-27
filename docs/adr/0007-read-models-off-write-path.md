---
status: Accepted
date: 2026-06-27
owner: Delivery context
---
# ADR-0007: Read models live off the write path

## Constitution link
Derives from CONSTITUTION.md Article I.3; Handbook P4/LY-4.

## Context
Read endpoints mutate state: `LearningRecordController::ensurePastRecords` (a POST fired on page load) materializes `LearningRecord` rows for every past session in a branch — O(students×classes×sessions), ~914ms in prod (in-app #176), and it *amplifies* duplicate occurrences into duplicate evaluations. Monthly projection similarly materializes occurrences lazily on calendar read.

## Decision
Materialization/backfill happens in **background jobs triggered by events** (`OccurrenceScheduled`, `SessionDelivered`), never on a GET/page-load. Read paths only read precomputed projections.

## Consequences
+ Deterministic, idempotent, fast reads; duplicate amplification removed.
+ Page latency decoupled from branch size.
− Requires a job runner + projection store; interim fix already reduced the N+1 (branch `fix/ensure-past-n1-perf`).

## References
ADR-0001, ADR-0004. Fact F-10. Debt TD-READ. In-app #176.
