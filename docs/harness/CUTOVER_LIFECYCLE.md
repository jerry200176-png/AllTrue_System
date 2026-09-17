# HARNESS_STORE_AUTHORITY_CUTOVER — Lifecycle report

**Goal:** Promote `harness.sqlite` to domain authority; demote `CURRENT_STATE`/JSON to projection.  
**Date (UTC):** 2026-09-17  
**Non-scope held:** no Restate/wake engine, no generic durable execution, no Founder Console, no CI inbox.

## Lifecycle stages (do not collapse)

| Stage | Status | Evidence |
|-------|--------|----------|
| CODE_WRITTEN | **YES** | `schema_migrate.py`, `project_state.py`, CLI `migrate-schema` / `project-state` (dogfood runner retained under `state/alltrue/cutover-evidence-20260917/`) |
| TESTS_PASSED | **YES** | `test_harness_cutover.py` + existing harness suites green |
| MIGRATION_COPY_VERIFIED | **YES** | `tmp-cutover/MIGRATE_COPY_REPORT.json`; additive; stable digest preserved |
| LIVE_SCHEMA_MIGRATED | **YES** | live `schema_version=4`; `/home/jerry/workspace/state/alltrue/cutover-evidence-20260917/LIVE_MIGRATE_REPORT.json`; STABLE_PRESERVED |
| DOGFOOD_RUNTIME_VERIFIED | **YES** | seed → Supervisor PID kill → fresh process recover; fencing deny; attach resume |
| AUTHORITY_CUTOVER | **YES** | meta `authority_role=domain_authority` after dogfood gates |
| JSON_WRITERS_DEMOTED | **YES** | `CURRENT_STATE.json` rewritten with `role=projection`; meta `json_writers_demoted=true` |
| OPERATIONALLY_ACCEPTED | **NO** | Awaits Founder/operator acceptance; machine restart **UNPROVEN** |

## Dogfood proof checklist

| # | Requirement | Result |
|---|-------------|--------|
| 1 | create/bind WorkerRun | `wr_cutover_9301d6a79b98` |
| 2 | agent session exists | `81d3be4f3f3c4818b481c3adeb389169` |
| 3 | Supervisor process exits | PID `456571` SIGTERM |
| 4 | fresh Supervisor process | PID `456590`, `env -i` |
| 5 | reads harness.sqlite | recover discovers run |
| 6 | discovers correct WorkerRun | run_id match |
| 7 | reattaches worktree/session | `agent-start` mode=resume, same worktree/branch |
| 8 | stale fencing denied | `stale_worker_fencing` |
| 9 | structured handoff | `handoff_accepted` + `worker_handoff_observed` |
| 10 | projection converges | `project-state` after cutover |

**Founder relay during recovery:** none (`founder_relay_used=false`, `chat_history_used=false`).

## Restart boundary

| Test | Status |
|------|--------|
| Kill/restart dogfood Supervisor **process** | **PROVEN** |
| Full service/machine restart | **UNPROVEN** (not performed; WSL host not rebooted) |

## Live DB identity (post-cutover)

- Path: `/home/jerry/workspace/state/alltrue/harness.sqlite`
- schema_version: **4**
- authority_role: **domain_authority**
- current_state_role: **projection**

## Next Goal (not started)

`RESTATE_ADOPTION_GATE_1` — durable wake/wait/retry journal decision only.
