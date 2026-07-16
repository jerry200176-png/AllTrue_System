# ADR-POP-001: Control Plane (Desired State + Reconciliation)

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 85% |
| Risk | Medium |
| Revisit | 2026-10 |

## Context

AllTrue production changes were fragmented (deploy.yml, one-off repair workflows, Pi SSH). Repair logic existed in artisan commands but lacked unified governance, desired-state semantics, and continuous reconciliation.

## Decision

Establish **POP** as Production Control Plane with flow:

`Desired State → Planner → Approval → Scheduler → Executor → Verification → Reconciliation → Observed State → Feedback`

Operations are **declarative** (desired properties per resource scope), not imperative one-off commands.

## Alternatives

- **Execution-only platform** (execute + verify, done): rejected — no drift detection.
- **GitOps approval in Git history**: rejected — see ADR-POP-002.
- **Full Kubernetes operator stack**: rejected — overkill for single-Pi Laravel.

## Trade-offs

| Pro | Con |
|-----|-----|
| Five-year extensibility | Higher initial engineering cost |
| Drift detection | Reconcile loop DB load |

## Consequences

- All new production operations register as Strategies in catalog.
- Legacy workflows sunset per migration plan.
- INCIDENT stack remains for **incident classification**; POP executes **approved operations**.
