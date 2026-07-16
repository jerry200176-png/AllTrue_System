# ADR-POP-003: Execution Plane Interface

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 88% |
| Risk | Low |
| Revisit | 2027-01 |

## Context

Business logic must not depend on GitHub Actions, SSH, Cursor, or Pi. Executors must be replaceable.

## Decision

Define `ExecutionPlane` interface with adapters:

1. **SelfHostedRunnerExecutor** (primary)
2. **GithubActionsExecutor** (thin: claim token → `pop execute`)
3. **CliExecutor** (break-glass)

Engine invokes planes; Strategies never reference executors.

## Alternatives

- SSH-only GHA workflows: rejected (tight coupling).
- Cursor agent dispatch: rejected (platform limits).

## Trade-offs

| Pro | Con |
|-----|-----|
| Executor swap without Strategy change | Adapter maintenance |
| No SSH hop with self-hosted runner | Runner ops burden |

## Consequences

- Legacy SSH repair workflows deprecated.
- GHA workflows ≤20 lines orchestration.
