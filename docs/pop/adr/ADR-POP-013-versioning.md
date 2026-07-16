# ADR-POP-013: Full Versioning for Replay

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 85% |
| Risk | Medium |
| Revisit | 2026-11 |

## Context

Replay and time-travel require knowing exact strategy, policy, catalog, engine, and invariant pack versions at execution time.

## Decision

- `pop_version_registry` stores immutable blobs with content hash.
- Pin versions at plan/approve/execute boundaries.
- **Retired** strategies remain replayable via registry entries.
- Missing pin → `REPLAY_VERSION_MISSING`.

## Alternatives

- Replay against current strategy only: rejected (incorrect audit).
- Git tags only: rejected (incomplete for policy/invariants).

## Trade-offs

| Pro | Con |
|-----|-----|
| Forensic accuracy | Registry retention policy |

## Consequences

- Lifecycle `retired` + `archived` per Architecture Freeze deprecation policy.
- Replay modes: timeline, simulate, audit_export.
