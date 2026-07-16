# ADR-POP-012: Dead Letter Queue

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 90% |
| Risk | Low |
| Revisit | 2027-01 |

## Context

Infinite retry on permanent failures (policy deny, invariant permanent fail, replay version missing) wastes resources and hides incidents.

## Decision

- `pop_dlq` table for exhausted retries, permanent policy deny, invariant permanent fail, replay failure, orphaned claims.
- **No automatic replay** from DLQ; manual `requeue` + new approval if required.
- DLQ depth/age alerts via Meta Controller.

## Alternatives

- Retry forever: rejected.
- Drop failed ops silently: rejected.

## Trade-offs

| Pro | Con |
|-----|-----|
| Clear triage queue | Manual ops for DLQ |

## Consequences

- FIT-009 tests retry exhaustion → DLQ.
