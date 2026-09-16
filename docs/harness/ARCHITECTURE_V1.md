# AllTrue Autonomous Execution Harness — Architecture V1

## Separation

| Layer | Owns | Location |
|-------|------|----------|
| Governance | risk, Founder-required, exact-SHA, activation | `scripts/governance/` |
| Harness | Program/Task state, leases, selection, dispatch | `scripts/harness/` |
| Session gateway | worktree + manifest | `agent-start` |

## Stack

Python + SQLite WAL + subprocess/CLI. No Temporal/Celery/Redis/K8s.

## State machine

See `scripts/harness/states.py` (`DISCOVERED` … `DONE` / `FAILED` / `PAUSED`).
Transitions require evidence where listed in `transitions.py` (fail closed).

## Persistence

- Repo: `scripts/harness/programs/*.yaml`
- Host: `/home/jerry/workspace/state/alltrue/harness.sqlite` (`HARNESS_DB` override)

## Autonomy

Default CONTINUE via existing gate. Founder inbox only for gate/Goal §22 stops.

## H2 drift contracts

`GoalContract` / `EvidenceEnvelope` / `DecisionReceipt` / leases / checkpoint /
`reconcile()` bind agents to durable SHA+scope authority via `autonomy_gate`
adapter only. Deny-and-continue keeps unrelated READY work unblocked.
Schema v2 adds `leases`, `goals`, `decision_receipts`, `checkpoints`.
