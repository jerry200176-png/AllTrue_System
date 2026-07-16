# ADR-POP-007: Operations State Machine

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 88% |
| Risk | Low |
| Revisit | 2026-12 |

## Context

Informal statuses (pending/running/done) cannot enforce legal transitions, concurrency, or audit.

## Decision

14 states with enforced transitions in `pop_state_transitions`:

`draft → planned → awaiting_approval → approved → scheduled → claimed → running → verifying → succeeded → closed`

Failure paths: `verification_failed`, `failed`, `rolled_back`, `cancelled`, `expired`.

## Alternatives

- Free-form status strings: rejected.
- Temporal state machines: deferred.

## Trade-offs

| Pro | Con |
|-----|-----|
| Illegal transitions blocked | Migration from legacy statuses |

## Consequences

- See `docs/pop/STATE_MACHINE.md` for transition table.
- FIT-005 enforces defined transitions.
