# ADR-POP-004: Policy Engine (Configuration)

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 82% |
| Risk | Medium |
| Revisit | 2026-09 |

## Context

Hard-coded approval rules (e.g. all critical → founder) do not scale across strategies, campuses, and maintenance windows.

## Decision

- Policies in `operations/policies/*.yaml`, loaded to immutable versioned blobs in DB.
- Evaluator inputs: risk, blast_radius, campus, time, strategy capabilities, DAG outcomes.
- Outputs: approvers, windows, max_parallel, retries, auto_rollback, deny.

## Alternatives

- Code-only rules: rejected (violates Open/Closed).
- GitHub branch protection only: rejected (not operation-aware).

## Trade-offs

| Pro | Con |
|-----|-----|
| Hot config without Engine change | Rule conflict resolution needed |
| Night/weekend gates | Policy testing burden |

## Consequences

- `operations/policies/default.yaml` ships in Phase 1.
- Policy version pinned at approve time for replay.
