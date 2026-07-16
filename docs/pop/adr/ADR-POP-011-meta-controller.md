# ADR-POP-011: Meta Controller (CP Self-Health)

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 83% |
| Risk | Medium |
| Revisit | 2026-10 |

## Context

POP must detect when **the control plane itself** is unhealthy (scheduler stuck, outbox lag, approval queue stale), not only production drift.

## Decision

- **Meta Controller** probes: scheduler, approval queue, executor heartbeat, outbox lag, DLQ depth, snapshot failures, policy eval errors, metrics pipeline.
- On `ESCALATED_CP_FAILURE`: freeze new executes; observe continues; notify founder.
- Bounded auto-remediation (restart worker, failover executor).

## Alternatives

- External monitoring only: rejected (no reconcile of CP desired state).
- No freeze on CP failure: rejected (unsafe writes).

## Trade-offs

| Pro | Con |
|-----|-----|
| CP self-awareness | Meta complexity |

## Consequences

- SLO/error budget for Approval API and Execution Plane (see `docs/pop/SLO.md`).
- `GET /pop/health/deep` in Phase 2+.
