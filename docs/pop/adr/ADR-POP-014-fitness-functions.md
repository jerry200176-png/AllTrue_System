# ADR-POP-014: Architecture Fitness Functions

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 88% |
| Risk | Low |
| Revisit | 2027-01 |

## Context

Architecture Freeze is meaningless without automated enforcement that new code respects layer boundaries.

## Decision

- CI script `scripts/pop-fitness-check.mjs` + PHPUnit architecture tests.
- FIT-001–010 (see script header). Phase 1 enables 001–003 skeleton; full gate by Phase 2.
- **Failed fitness → merge blocked.**

## Alternatives

- Manual review only: rejected (regression inevitable).
- Full ArchUnit day one: deferred.

## Trade-offs

| Pro | Con |
|-----|-----|
| OCP enforced | Initial false positives |

## Consequences

- Each Phase completion runs fitness before merge.
- Architecture change only via Breaking ADR + updated fitness rules.
