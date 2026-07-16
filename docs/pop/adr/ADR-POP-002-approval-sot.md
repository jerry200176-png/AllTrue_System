# ADR-POP-002: Approval SoT = Database

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 90% |
| Risk | Low |
| Revisit | 2026-09 |

## Context

Git is SoT for **source code**, not operational approval state. Manifest-merge patterns cannot express TTL, revoke, quorum, or time-travel queries.

## Decision

- **SoT:** `pop_operation_requests` + `pop_approval_events` (append-only).
- **Execution auth:** short-lived signed tokens bound to `operation_id`, strategy, commit SHA, phase, TTL.
- **GitHub Environment:** optional UX mirror (webhook → DB); not SoT.

## Alternatives

- Git manifest merge: rejected (audit, revoke, AI discover).
- GitHub Environment only: rejected (GHA coupling).
- Signed token without DB: rejected (no durable audit).

## Trade-offs

| Pro | Con |
|-----|-----|
| Durable audit, time travel | Requires schema + API |
| GHA-independent | Must secure Approval API |

## Consequences

- No approval state in Git commits.
- AI may create `draft` requests only; execute requires approved token.
