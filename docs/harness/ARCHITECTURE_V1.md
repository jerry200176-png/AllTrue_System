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

## H4 dispatch

`scripts/harness/dispatch.py` consumes H3 `PlanResult` with Founder amendments:

- Execution-critical revalidation (goal_fp, leases, READY/WIP, governance, main SHA) —
  **not** time-dependent `input_snapshot_fingerprint` equality
- Structured multi-resource `LeaseBinding`
- Durable `DispatchAttempt` (schema v3) written **before** spawn
- Lease heartbeat/`renew` while attempt is active
- Stale-worker fencing check on handoff ingestion
- Fail-closed partial acquire rollback
- Release execution leases at PR_READY / structured handoff
- Concurrent apply: exactly one winner (`dispatch_attempts` open-task index + CAS)

CLI: `python3 -m scripts.harness dispatch [--dry-run|--apply]`.

## H4b WorkerRun

Closes the deferred launcher gap from H4.0:

- Default spawn calls `worker_run.start_or_resume_worker` after CAS
- Durable `worker_runs` table (schema **v4**) stores child `session_id`,
  worktree, branch, fencing snapshot, and handoff observation
- `agent-start --attach` (gateway ≥0.5.1) resumes an existing task worktree
  instead of failing `worktree exists`
- Create path soft-defers unless `HARNESS_SPAWN_CREATE=1` (WORKTREE_POLICY:
  prefer gateway create; harness attaches)
- Handoff: `ingest_handoff` fencing against live leases, then
  `observe_worker_handoff` marks the WorkerRun `handed_off`

H4 remains **PARTIAL / NOT ACCEPTED** until Supervisor proves end-to-end
PlanResult → attach → child identity → handoff wake.

