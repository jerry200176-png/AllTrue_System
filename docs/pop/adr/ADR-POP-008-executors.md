# ADR-POP-008: Executors (Self-hosted Primary)

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 85% |
| Risk | Medium |
| Revisit | 2026-11 |

## Context

GitHub-hosted → Pi SSH couples execution to secrets hop and blocks Cloud Agent dispatch. Pi is single production write node.

## Decision

- **Primary:** self-hosted runner on Pi runs `pop` CLI locally (no SSH).
- **Secondary:** GHA thin adapter triggers same CLI via token claim.
- **Break-glass:** `CliExecutor` on Pi for ESCALATED_CP_FAILURE.

## Alternatives

- SSH-only forever: rejected.
- Cloud Agent workflow_dispatch: rejected (platform).

## Trade-offs

| Pro | Con |
|-----|-----|
| Local DB access | Runner SPOF on Pi |
| No PAT in agent | Runner maintenance |

## Consequences

- Align with deploy.yml self-hosted direction (#867).
- Executor heartbeat in Meta Controller (ADR-POP-011).
