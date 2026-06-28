# AllTrue 補習班管理系統

AllTrue is a full-stack **tutoring center management system** for multi-branch cram schools. It runs in production on a Raspberry Pi and serves directors, teachers, front-desk staff, and parents across four campuses (興隆、新店、大安、木柵).

**Production:** Raspberry Pi 5 — Vue 3 SPA + Laravel 8 API + MySQL (`AllTrue`)  
**GitHub:** [jerry200176-png/AllTrue_System](https://github.com/jerry200176-png/AllTrue_System)

**Runtime spec:** [`docs/CONTROL_PLANE_CONTRACT.md`](docs/CONTROL_PLANE_CONTRACT.md) · **Catalog:** [`docs/INDEX.md`](docs/INDEX.md) · **Execution:** [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)

---

## Product — What the System Does

AllTrue replaces scattered spreadsheets and manual admin work with one platform for daily tutoring operations: enrolling students, scheduling classes, tracking attendance, billing, and communicating with parents.

### Domain entities

| Entity | What it represents | Primary tables / concepts |
|--------|-------------------|---------------------------|
| **Students** | Learners enrolled at a campus | `Student` — profile, RFID, contact, campus |
| **Classes** | A student's course contract with a teacher, rate, and session count | `StudentClass` — sessions remaining, schedule mode, payment state |
| **Sessions** | Individual class meetings on a calendar date | `ClassSession` — date, time, status; linked to a `StudentClass` |
| **Attendance / records** | Who showed up, when, and how class went | `StudentSingIn` (attendance/sign-in), `LearningRecord` (teacher evaluations, director approval) |

Supporting concepts in daily use: **schedules** (fixed weekly slots), **invoices/payments** (billing), **substitute teachers**, and **branch-scoped data** (each campus is isolated).

### What users do

| Role | Typical actions |
|------|-----------------|
| **Director / admin** | Manage students and courses, review today's schedule, track tuition alerts, approve learning evaluations, oversee multi-branch operations |
| **Teacher** | View personal schedule, take attendance, fill learning records, request makeup/substitute sessions, export monthly reports |
| **Front desk** | Manual check-in, RFID binding, absence catch-up, parent notifications |
| **Parent** | View child's schedule, evaluations, and payment status via Parent Portal or LINE |

### Product stack (summary)

| Layer | Technology |
|-------|------------|
| Frontend | Vue 3.4 + Vite 5 — `frontend/src/pages/` |
| Backend | Laravel 8 / PHP 8+ — REST API at `/api/v1/*` |
| Database | MySQL |
| Auth | Bearer token in `localStorage.alltrue_session` |
| Integrations | RFID swipe (`POST /api/v1/swipe-rfid`), LINE Login / Webhook |

### Product documentation

| Need | Start here |
|------|------------|
| Navigation map | [`docs/INDEX.md`](docs/INDEX.md) (catalog only) |
| **Production incident** | [`docs/INCIDENT_START_HERE.md`](docs/INCIDENT_START_HERE.md) |
| **Deploy execution** | [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) |
| Deployment & ops | [`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md), [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) |
| API / schema | [`docs/SYSTEM_TECH_GUIDE.md`](docs/SYSTEM_TECH_GUIDE.md) |
| AI / dev workflow | [`AGENTS.md`](AGENTS.md), [`.cursorrules`](.cursorrules) |

---

# Engineering Infrastructure — MemPalace Ingestion System

> **MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.**

**Local, single-machine, best-effort** recall index for AI engineering context (WSL2). Not production infrastructure — no Pi deploy, no paging.

Local-first recall index for AllTrue engineering context. Indexes Cursor agent transcripts and git-tracked docs into searchable wings on the developer machine (WSL2).

**Authority:** Git markdown in this repository is source of truth. MemPalace is a recall index only — if search results conflict with git, trust git.

**Detailed operations:** See [`docs/MEMPALACE_OPERATIONS_HANDBOOK.md`](docs/MEMPALACE_OPERATIONS_HANDBOOK.md) for runbook, failure playbook, and on-call guide.

---

## 1. Overview

MemPalace ingestion is an **event-sourced, DAG-based pipeline** that mines two source trees into a ChromaDB index:

| Source | Wing | Path |
|--------|------|------|
| Cursor agent transcripts | `alltrue-sessions` | `$TRANSCRIPT_DIR` (auto-detected) |
| Repository docs | `alltrue-docs` | `$MEMPALACE_REPO_ROOT/docs` |

There is **one ingestion entry point**: `scripts/mempalace-ingest.sh`. All other scripts delegate to it or wrap it.

The pipeline has **8 stages** defined in a declarative manifest. Execution order is derived from `depends_on` edges, not file order. Run state lives in an append-only **`events.jsonl`** per run — not filesystem `.done` markers.

**Environment:** WSL2 only (`~/alltrue`). Ingest does not run on the production Raspberry Pi or in GitHub Actions CI.

---

## 2. System Architecture

### DAG pipeline

The pipeline is defined in `scripts/mempalace/engine/pipeline.manifest.json`. The DAG engine (`dag.sh`) validates the graph and produces a topological execution order.

```
preflight → lock → discovery → mining ──┐
                          ↘ normalization ──→ storage → index → verify
```

`mining` and `normalization` both depend on `discovery`. They run serially (stable sort: `mining` before `normalization`).

### Event-sourced model

Each run writes an append-only event log:

```
~/.mempalace/palace/.ingest-run/runs/<run_id>/events.jsonl
```

Resume, replay, and stage-skip decisions read this file. The verify stage emits `run_completed` on success.

### Execution flow

```
Operator / post-merge hook
        │
        ▼
scripts/mempalace-ingest.sh          ← single entry point
        │
        ▼
scripts/mempalace/engine/run.sh      ← CLI parsing, replay mode, orchestration
        │
        ├── dag.sh                     ← validate manifest, derive plan
        ├── runner.sh                  ← execute nodes, retries, emit events
        ├── events.sh                  ← append to events.jsonl
        ├── state.sh                   ← run lifecycle, resume checks
        ├── log.sh                     ← human ingest.log
        └── stages/*.sh                ← stage handlers (8 nodes)
        │
        ▼
~/.mempalace/palace/                   ← ChromaDB index
~/.mempalace/palace/.ingest-run/       ← run artifacts + event logs
```

---

## 3. Components

### Ingestion entry point

| File | Role |
|------|------|
| `scripts/mempalace-ingest.sh` | Thin wrapper; `exec` into engine |
| `scripts/mempalace/engine/run.sh` | Engine: flags, replay, plan display, main loop |

### DAG engine

| File | Role |
|------|------|
| `scripts/mempalace/engine/pipeline.manifest.json` | Declarative nodes: handler, inputs, outputs, depends_on, retry |
| `scripts/mempalace/engine/dag.sh` | Topological sort, cycle detection, `--from-stage` downstream resolution |

### Runner

| File | Role |
|------|------|
| `scripts/mempalace/engine/runner.sh` | Input artifact checks, retry policy, handler dispatch, event emission |

For each node the runner: checks inputs → emits `stage_started` → calls handler → emits `stage_completed` or `stage_failed`. Lock abort returns exit code 2 (surfaced as skip, not failure).

### Event system

| File | Role |
|------|------|
| `scripts/mempalace/engine/events.sh` | Append-only JSONL writes, completed-stage queries, replay reconstruction |
| `scripts/mempalace/engine/state.sh` | Run ID allocation, resume reuse, success/failure markers |

### Stage handlers

| Stage | Handler | Script |
|-------|---------|--------|
| preflight | `mempalace_ingest_stage_preflight` | `stages/preflight.sh` |
| lock | `mempalace_ingest_stage_lock` | `stages/lock.sh` |
| discovery | `mempalace_ingest_stage_discovery` | `stages/discovery.sh` |
| mining | `mempalace_ingest_stage_mining` | `stages/mining.sh` |
| normalization | `mempalace_ingest_stage_normalization` | `stages/normalization.sh` |
| storage | `mempalace_ingest_stage_storage` | `stages/storage.sh` |
| index | `mempalace_ingest_stage_index` | `stages/index.sh` |
| verify | `mempalace_ingest_stage_verify` | `stages/verify.sh` |

Shared utilities: `stages/_helpers.sh`.

### Config SSOT

| File | Role |
|------|------|
| `scripts/mempalace-config.sh` | Paths, wing names, CLI location, transcript dir resolution |

Key defaults:

| Variable | Default |
|----------|---------|
| `MEMPALACE` | `~/.local/bin/mempalace` |
| `MEMPALACE_PALACE_DIR` | `~/.mempalace/palace` |
| `MEMPALACE_WING_SESSIONS` | `alltrue-sessions` |
| `MEMPALACE_WING_DOCS` | `alltrue-docs` |
| `MEMPALACE_DOCS_DIR` | `$REPO_ROOT/docs` |

### Operational tooling

| File | Role |
|------|------|
| `scripts/mempalace-maintain.sh` | `--status`, `--repair`, default: status → ingest → status |
| `scripts/mempalace/run-stage.sh` | Run one stage: `--stage <name>` wrapper |
| `scripts/install-git-hooks.sh` | Installs post-merge hook that backgrounds ingest |

---

## 4. Execution Model

### Full run

```bash
cd ~/alltrue
bash scripts/mempalace-ingest.sh
```

Success: exit **0**, console prints `✅ Ingest complete. run_id=<id>`, final event is `run_completed`.

### Partial run (`--from-stage`)

Runs the named node and all **downstream** dependents only. Does not re-run upstream ancestors.

```bash
bash scripts/mempalace-ingest.sh --from-stage storage
# Plan: storage → index → verify
```

Requires upstream artifacts in the current run directory, or use `--force` (skips input validation — use with care).

### Resume

Reuses `current/run_id`. Skips stages that already have `stage_completed` in the event log.

```bash
bash scripts/mempalace-ingest.sh --resume
```

### Replay

Reconstructs run state from events. **Does not execute** any stages.

```bash
bash scripts/mempalace-ingest.sh --replay              # current run
bash scripts/mempalace-ingest.sh --replay <run_id>     # specific run
```

### Dry-run

Plans and writes sentinel artifacts; no `mempalace mine` writes.

```bash
bash scripts/mempalace-ingest.sh --dry-run --no-lock
```

### Other flags

| Flag | Effect |
|------|--------|
| `--stage NAME` | Run one node only |
| `--no-lock` | Skip flock (testing) |
| `--force` | Ignore missing input artifacts |
| `--list-stages` | Print topo-sorted node names |
| `--show-plan` | Print derived execution plan and exit |

---

## 5. Events System

### Location

```
~/.mempalace/palace/.ingest-run/
├── current/
│   ├── run_id
│   ├── failed_stage          (present if last run failed)
│   └── last_success_run_id
└── runs/<run_id>/
    ├── events.jsonl          ← source of truth for run state
    ├── ingest.log            ← human-readable log
    ├── execution.plan        ← derived DAG order snapshot
    └── <stage artifacts>     ← preflight.env, manifest.*.json, etc.
```

Lock file (separate from events): `~/.mempalace/palace/.ingest.lock`

### Record structure

Each line in `events.jsonl` is one JSON object:

```json
{
  "seq": 1,
  "ts": "2026-06-27T22:52:09Z",
  "run_id": "20260627T225209Z-1124755",
  "event": "stage_completed",
  "stage": "preflight",
  "detail": "cli=3.3.3 sources=2"
}
```

Fields: `seq`, `ts`, `run_id`, `event`, optional `stage`, optional `detail`.

### Event types

| Event | When |
|-------|------|
| `run_started` | Pipeline begins; detail includes execution plan |
| `stage_started` | Node execution begins |
| `stage_completed` | Node succeeded |
| `stage_failed` | Node failed |
| `stage_skipped` | Skipped (resume or missing source) |
| `stage_retry` | Retry attempt (from runner retry policy) |
| `run_completed` | Full success (emitted by verify stage) |
| `run_failed` | Pipeline failed |
| `run_aborted` | Lock held; no work done |

### Replay behavior

Replay reads all events sequentially and prints:

- Event count
- Final status: `completed`, `failed`, `aborted`, or `in_progress`
- Full timeline (seq, timestamp, event, stage, detail)
- Per-stage state summary

A `stage_failed` event sets run status to `failed`. A later `run_completed` overrides to `completed`.

---

## 6. Operations

### How to run

| Scenario | Command |
|----------|---------|
| Normal ingest | `bash scripts/mempalace-ingest.sh` |
| Monthly health check | `bash scripts/mempalace-maintain.sh` |
| Status only | `bash scripts/mempalace-maintain.sh --status` |
| Chroma index repair | `bash scripts/mempalace-maintain.sh --repair` |
| Single stage test | `bash scripts/mempalace/run-stage.sh preflight --no-lock` |
| Install post-merge hook | `bash scripts/install-git-hooks.sh` |

**Automated triggers:**

- **Post-merge hook** (local): backgrounds ingest after `git merge` if hooks installed
- **Monthly reminder** (GitHub): `.github/workflows/mempalace-monthly.yml` comments on issue #519 — operator must run `mempalace-maintain.sh` on WSL2 manually

**Prerequisite:** `mempalace` CLI at `~/.local/bin/mempalace` (`uv tool install mempalace` or `pipx install mempalace`).

### How to debug failures

1. Note `run_id` from console or `cat ~/.mempalace/palace/.ingest-run/current/run_id`
2. Replay: `bash scripts/mempalace-ingest.sh --replay`
3. Find last `stage_failed` in timeline
4. Read human log: `runs/<run_id>/ingest.log`
5. Check stage artifacts in `runs/<run_id>/`
6. Fix root cause, then `--resume`

Exit codes:

| Code | Meaning |
|------|---------|
| 0 | Success, or lock held (skipped) |
| 1 | Failure (`❌ Ingest failed.`) |

### How to inspect logs

```bash
RUN=$(cat ~/.mempalace/palace/.ingest-run/current/run_id)
RUN_DIR=~/.mempalace/palace/.ingest-run/runs/$RUN

cat "$RUN_DIR/events.jsonl"
cat "$RUN_DIR/ingest.log"
cat "$RUN_DIR/execution.plan"
bash scripts/mempalace-ingest.sh --replay "$RUN"
~/.local/bin/mempalace status
```

### How to recover from failures

| Situation | Action |
|-----------|--------|
| Stage failed mid-pipeline | Fix cause → `bash scripts/mempalace-ingest.sh --resume` |
| Event log corrupt | Start fresh: `bash scripts/mempalace-ingest.sh` (new run_id) |
| Chroma index corrupt | `bash scripts/mempalace-maintain.sh --repair` → full ingest |
| Lock stuck with no process | Investigate stale lock → retry ingest |
| Partial success (some stages done) | `--resume` (do not start parallel full ingest) |

Search (read-only, does not write index):

```bash
~/.local/bin/mempalace search "<keyword>" --wing alltrue-sessions
~/.local/bin/mempalace search "<keyword>" --wing alltrue-docs
```

---

## 7. Failure Handling

### Lock behavior

The `lock` stage acquires `flock` on `~/.mempalace/palace/.ingest.lock`.

| Outcome | Signal | Exit |
|---------|--------|------|
| Lock acquired | Normal execution continues | — |
| Lock held | `⏳ Ingest skipped (lock held).` + `run_aborted` event | **0** |

If lock skip persists with no running ingest, check for stale lock holder before removing the lock file.

### Stage failure

Handler returns non-zero after retries exhausted → `stage_failed` → `run_failed` → exit **1**.

Recovery: fix root cause, then `--resume`. Resume skips nodes with `stage_completed` in the event log.

Common causes: missing `mempalace` CLI, palace not writable, mine command failure, missing source directories, missing upstream artifacts when using `--from-stage`.

### Replay mismatch

Symptom: replay shows `completed` but search is stale, or vice versa.

Usually means wrong `run_id` was inspected, or ingest ran outside the pipeline (direct `mempalace mine` bypasses events). Recovery: run full ingest via `mempalace-ingest.sh`, confirm with `--replay` and `mempalace status`.

### Config drift

Symptom: transcripts not indexed, wrong paths in `preflight.env`.

Root cause: environment overrides (`TRANSCRIPT_DIR`, `MEMPALACE_DOCS_DIR`) diverging from `mempalace-config.sh` defaults.

Recovery: `unset TRANSCRIPT_DIR MEMPALACE_DOCS_DIR` and re-run. Inspect `preflight.env` in the run directory to confirm resolved paths.

---

## 8. Constraints

### Ponytail rule layer

Ponytail (`.cursor/rules/ponytail-mempalace.mdc`) applies **implementation-only** constraints to stage handlers and helpers. It does not govern architecture.

**Allowed to change (with Ponytail rules):**

- `scripts/mempalace/engine/stages/*.sh`
- `scripts/mempalace/engine/stages/_helpers.sh`
- `scripts/mempalace/engine/log.sh` (formatting only)

**Forbidden to change via Ponytail or casual edits:**

- `pipeline.manifest.json` — DAG structure
- `dag.sh`, `events.sh`, `runner.sh`, `run.sh`, `state.sh` — engine contract
- Stage count, stage names, or `depends_on` edges
- `scripts/mempalace-ingest.sh` as sole entry point
- `scripts/mempalace-config.sh` behavior changes without review

Handler rules: preserve event emission, no duplicate `stage_started` logging, no parallel stage execution, no new ingestion paths.

### Architecture is frozen

The following are fixed by design and must not be changed in documentation or code without explicit architecture review:

- DAG-based pipeline with manifest-driven execution order
- Event-sourced state (`events.jsonl` as SSOT; no `.done` files)
- Single entry point (`scripts/mempalace-ingest.sh`)
- 8 stages with current names and dependency graph
- Local WSL2 execution only (no CI ingest, no Pi deployment)

---

## File map

```
scripts/
├── mempalace-ingest.sh              # Entry point
├── mempalace-maintain.sh            # Status / repair / wrapped ingest
├── mempalace-config.sh              # Config SSOT
├── install-git-hooks.sh             # Post-merge hook installer
└── mempalace/
    ├── run-stage.sh                 # Single-stage wrapper
    └── engine/
        ├── run.sh                   # Engine orchestrator
        ├── dag.sh                   # DAG resolution
        ├── runner.sh                # Node execution
        ├── events.sh                # Event log + replay
        ├── state.sh                 # Run lifecycle
        ├── log.sh                   # Human log
        ├── pipeline.manifest.json   # DAG definition
        └── stages/
            ├── _helpers.sh
            ├── preflight.sh
            ├── lock.sh
            ├── discovery.sh
            ├── mining.sh
            ├── normalization.sh
            ├── storage.sh
            ├── index.sh
            └── verify.sh
```

---

*Product docs: [`docs/INDEX.md`](docs/INDEX.md) · MemPalace derived from repository implementation · Last synced: 2026-06-28.*
