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

- **Primary:** the production Pi's existing host cron invokes Laravel's scheduler, which runs `pop:execute-approved` locally (no SSH or GitHub runner).
- **Secondary:** a GHA thin adapter may claim the same approved token in a future reviewed deployment; it is not enabled by this change.
- **Break-glass:** `CliExecutor` on Pi for ESCALATED_CP_FAILURE.

## Alternatives

- SSH-only forever: rejected.
- Cloud Agent workflow_dispatch: rejected (platform).

## Trade-offs

| Pro | Con |
|-----|-----|
| Local DB access | Existing scheduler is a single-node dependency |
| No PAT in agent | Host scheduler maintenance |

## Consequences

- The authenticated control plane exposes only draft, dry-run, and approval
  entrypoints. Dry-run is read-only and records the exact plan required before
  approval; no HTTP execute endpoint exists.
- The scheduler command is the only local production mutation adapter; it reconstructs the short-lived token from DB evidence and the host-only `APP_KEY`.
- `withoutOverlapping` plus a MySQL named lock prevents duplicate claims. Missing approval, expired token, malformed manifest, or deployed SHA mismatch fails closed.
- Executor heartbeat remains a Meta Controller concern (ADR-POP-011).
