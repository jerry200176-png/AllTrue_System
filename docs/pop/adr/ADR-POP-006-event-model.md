# ADR-POP-006: Event Model + Outbox

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 85% |
| Risk | Medium |
| Revisit | 2027-04 |

## Context

`audit_event` alone cannot drive Dashboard, notifications, reconciliation, and replay decoupling.

## Decision

- Append-only `pop_events` with domain event types (OperationApproved, DriftDetected, VerificationFailed, etc.).
- **Outbox pattern** for reliable delivery to subscribers (Dashboard, LINE, metrics, reconcile trigger).
- Subscribers must not call each other directly.

## Alternatives

- Direct service calls: rejected (coupling).
- Kafka day one: rejected (complexity); revisit if outbox lag exceeds SLO.

## Trade-offs

| Pro | Con |
|-----|-----|
| Replay + decoupling | Event volume growth |
| At-least-once delivery | Idempotent subscribers required |

## Consequences

- Schema draft includes `pop_events`, `pop_outbox`.
- DLQ for poison events (see ADR-POP-012).
