---
status: Accepted
date: 2026-06-27
owner: Principal Architect
---
# ADR-0004: Cross-context effects are domain events

## Constitution link
Derives from CONSTITUTION.md Article V (no writing non-owned facts); Handbook P2/P6.

## Context
Today cross-domain effects are in-line writes: `LearningRecord` approval → `SessionDeductionService` mutates `StudentClass` counters (Delivery writing Billing's fact); attendance swipe creates `ClassSession` + deducts; these violate single-ownership and entangle transactions.

## Decision
Cross-context effects happen via the **Event Catalog** (Handbook §5) through a transactional outbox: e.g. `SessionDelivered` → Billing appends `SessionUnitConsumed`; `EvaluationApproved` → Engagement/Parent. A context never writes another context's tables.

## Consequences
+ Single ownership preserved; satellites become idempotent consumers; effects are auditable as events.
− Requires an outbox + event versioning discipline (EV-1..3).

## References
ADR-0001, ADR-0002, ADR-0003. Handbook §5/§6. Events: `SessionDelivered`,`SessionUnitConsumed`,`ContractModeChanged`.
