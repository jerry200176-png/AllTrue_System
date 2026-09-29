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
- A Founder-explicit single-repair rule may reduce quorum only when the
  catalog operation itself proves a single-student blast radius, reversibility,
  snapshot, rollback, and verification. This is an operation-specific policy,
  not a general Founder or Agent production privilege.

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

## Exact-case monthly exception (2026-09-29)

The one-case, actual-owner confirmation path has a separately gated activation.
A separate `founder-exact-monthly-manifest` policy may allow one authenticated
human to request and approve an irreversible monthly receipt/contract correction.
It must bind the full signed parameters, unique idempotency key, same actual
requester/approver, exact reference and finite expiry; empty eligibility denies
all requests. This is not the reversible single-repair rule above and does not
alter critical dual approval for other operations. Git records eligibility,
never approval state (ADR-POP-002); the actual DB event, short-lived token,
version pin, Pi-local execution and verification remain required. See
[authentication boundary](../CONTROL_PLANE_AUTH.md#reviewed-monthly-case-owner-exception).
