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
`reconcile()` bind agents to durable SHA+scope+contract authority via
`autonomy_gate` adapter only. Deny-and-continue keeps unrelated READY work unblocked.
Schema v2 adds `leases`, `goals`, `decision_receipts`, `checkpoints`.

Lease CAS guarantees atomic ownership and fencing-token lifecycle across processes.
End-to-end enforcement against an already-running stale worker at every mutation
boundary is deferred to H4 (session/worktree bind). DecisionReceipt authorizes
exactly one requested action; Founder-only decisions require Founder actor.

## H3 planner

`scripts/harness/planner.py` selects the next READY task into a world-bound
`PlanResult` for H4. Founder amendments bind implementation:

- **A1** `effective_priority = business_value + aging_boost`, then tie-breaks
- **A2** live lease ownership only with `lease_id` + `fencing_token` + `task_id`
- **A3** no `would_execute` without valid GoalContract (`missing_goal_contract`)
- **A4** read-only planner (no lease probe/acquire/renew/reclaim)
- **A5** PlanResult binds goal_id, contract fingerprint, observed_main_sha,
  input snapshot fingerprint, required_leases, governance
- **A6** aging from ready_since / transition-to-READY (not `updated_at`)

CLI: `python3 -m scripts.harness plan [--program ID] [--sync] [--main-sha SHA]`.
H4 launcher/worktree/dispatch is out of scope for H3.

