# POP Schema Draft (Phase 1 — not migrated)

> **Status:** Draft. Phase 1 is read-only — no production tables until Phase 2 migration PR.  
> **Authority:** [ADR-POP-002](adr/ADR-POP-002-approval-sot.md), [ADR-POP-009](adr/ADR-POP-009-desired-observed-state.md)

## Core tables

| Table | Purpose |
|-------|---------|
| `pop_operation_requests` | Operation lifecycle + status + strategy_id + target JSON |
| `pop_approval_events` | Append-only approve/reject/revoke |
| `pop_executions` | Executor runs, phases, result |
| `pop_execution_records` | Unified execution-record JSON + artifact paths |
| `pop_events` | Domain events (append-only) |
| `pop_outbox` | Reliable event delivery |
| `pop_dlq` | Dead letter queue |
| `pop_desired_states` | Declarative targets (`valid_from`, `valid_to`) |
| `pop_observed_snapshots` | Read-only captures per scope |
| `pop_version_registry` | Strategy/policy/catalog/engine/invariant versions |
| `pop_state_transitions` | Legal from→to transitions |
| `pop_queues` | Blast-radius cells (`cell_key`, depth) |

## Indexes (minimum)

- `pop_operation_requests(status, strategy_id, created_at)`
- `pop_approval_events(operation_id, created_at)`
- `pop_events(operation_id, created_at)`
- `pop_desired_states(scope_key, valid_from, valid_to)`

## execution-record

Stored in `pop_execution_records.payload` and artifact store. Schema: see [EXECUTION_RECORD_SCHEMA.json](EXECUTION_RECORD_SCHEMA.json).
