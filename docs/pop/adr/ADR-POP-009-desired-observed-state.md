# ADR-POP-009: Desired / Observed State Stores

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 80% |
| Risk | Medium |
| Revisit | 2026-10 |

## Context

Command-style operation requests cannot express ongoing convergence (e.g. "renewal superseded" until drift).

## Decision

- `pop_desired_states`: declarative targets with `valid_from` / `valid_to`.
- `pop_observed_snapshots`: periodic read-only captures per scope.
- Planner computes drift; reconcile loop re-enters until closed or DLQ.

## Alternatives

- One-shot execute only: rejected (Round 2 requirement).
- External config store: rejected (MySQL sufficient).

## Trade-offs

| Pro | Con |
|-----|-----|
| Continuous governance | Storage + reconcile cost |

## Consequences

- Time-travel queries join desired + observed at timestamp T.
- Schema in `docs/pop/SCHEMA_DRAFT.md`.
