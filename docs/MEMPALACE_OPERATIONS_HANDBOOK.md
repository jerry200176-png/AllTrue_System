# MemPalace Ingestion — Operations Handbook

> **MemPalace is explicitly excluded from production SLO, alerting, and incident detection.**  
> It is a local best-effort system and must not be used for production inference.
>
> | Property | MemPalace | AllTrue production (Pi) |
> |----------|-----------|-------------------------|
> | Scope | Local WSL2 dev tooling | Tutoring app for users |
> | SLO / alerting | **Excluded** | UptimeRobot, SRE_POLICY |
> | Incident detection | **No** | INCIDENT_START_HERE |
> | Health inference | **Never** use MemPalace to judge prod health |
>
> **Production incident?** → [`docs/INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) — not this handbook.

**Related files**

| File | Purpose |
|------|---------|
| `scripts/mempalace-ingest.sh` | **Single ingestion entry point** |
| `scripts/mempalace-maintain.sh` | Health check, repair, wraps ingest |
| `scripts/mempalace-config.sh` | Config SSOT (paths, wings) |
| `scripts/mempalace/engine/pipeline.manifest.json` | DAG definition |
| `scripts/mempalace/run-stage.sh` | Run one stage (testing/recovery) |
| `scripts/install-git-hooks.sh` | Installs post-merge ingest hook |

---

# Part 1 — Operations Runbook

## 1.1 System overview

MemPalace ingestion is a **local-first, event-sourced DAG pipeline** that indexes:

- **Sessions** — Cursor agent transcripts → wing `alltrue-sessions`
- **Docs** — `~/alltrue/docs` → wing `alltrue-docs`

```
scripts/mempalace-ingest.sh
        │
        ▼
scripts/mempalace/engine/run.sh          (orchestrator)
        │
        ├── pipeline.manifest.json       (DAG: nodes, depends_on, I/O)
        ├── dag.sh                       (derive execution order)
        ├── runner.sh                    (execute nodes, emit events)
        ├── events.sh                    (append-only events.jsonl)
        └── stages/*.sh                  (stage handlers)
        │
        ▼
~/.mempalace/palace/                     (ChromaDB index + drawers)
~/.mempalace/palace/.ingest-run/         (run artifacts + event logs)
```

**State model:** Append-only `events.jsonl` per run. No separate `.done` files. Resume and replay read events only.

**Environment:** WSL2 dev machine (`~/alltrue`). Ingest **cannot run in GitHub Actions** (by design).

---

## 1.2 Entry points

| Command | When to use |
|---------|-------------|
| `bash scripts/mempalace-ingest.sh` | Normal full ingestion |
| `bash scripts/mempalace-maintain.sh` | Monthly health: status → ingest → status |
| `bash scripts/mempalace-maintain.sh --status` | Health check only |
| `bash scripts/mempalace-maintain.sh --repair` | Chroma index rebuild (interactive confirm) |
| `bash scripts/mempalace/run-stage.sh <stage>` | Single stage (testing/recovery) |

All ingestion **must** go through `mempalace-ingest.sh`. `mempalace-maintain.sh --ingest` delegates to it.

---

## 1.3 Normal execution flow

### Automated triggers

| Trigger | Mechanism | What runs |
|---------|-----------|-----------|
| **Post-merge** | Local git hook (if installed via `bash scripts/install-git-hooks.sh`) | Background `bash scripts/mempalace-ingest.sh` after `git merge` |
| **Monthly reminder** | `.github/workflows/mempalace-monthly.yml` | Comments on GitHub issue #519 — **manual action required on WSL2** |

### Manual triggers (operator)

| Cadence | Command |
|---------|---------|
| After important doc/session work | `bash scripts/mempalace-ingest.sh` |
| Monthly (1st) | `bash scripts/mempalace-maintain.sh` |
| After merge if hook not installed | `bash scripts/mempalace-ingest.sh` |

### Derived execution order (from DAG)

```
preflight → lock → discovery → mining → normalization → storage → index → verify
                                    ↘___________↗
                              (both depend on discovery; run serially)
```

Full run emits events ending with `run_completed`. Human output ends with:

```
✅ Ingest complete. run_id=<id>
```

---

## 1.4 Stage-by-stage reference

| Stage | Handler | Purpose | Key outputs |
|-------|---------|---------|-------------|
| **preflight** | `mempalace_ingest_stage_preflight` | Verify CLI, palace writable, ≥1 source dir | `preflight.env` |
| **lock** | `mempalace_ingest_stage_lock` | `flock` on `.ingest.lock`; skip if held | `lock.acquired` |
| **discovery** | `mempalace_ingest_stage_discovery` | Count sources, write raw manifest | `manifest.raw.json` |
| **mining** | `mempalace_ingest_stage_mining` | Build mine command plan | `mining.plan.env` |
| **normalization** | `mempalace_ingest_stage_normalization` | Apply SSOT wing names | `manifest.normalized.json` |
| **storage** | `mempalace_ingest_stage_storage` | `mempalace mine` sessions wing | `storage.sessions.done` |
| **index** | `mempalace_ingest_stage_index` | `mempalace mine` docs wing | `index.env` |
| **verify** | `mempalace_ingest_stage_verify` | `mempalace status`, orphan wing warn | `verify.env`, `post-verify.status.txt` |

**Side effects:** `storage` prints `→ mine sessions → wing alltrue-sessions`. `index` prints `→ mine docs → wing alltrue-docs`.

**Retries:** Defined per node in manifest (`max_attempts`, `delay_sec`). Runner emits `stage_retry` on retry.

---

## 1.5 Configuration model (SSOT)

**File:** `scripts/mempalace-config.sh`

| Variable | Default | Meaning |
|----------|---------|---------|
| `MEMPALACE` | `~/.local/bin/mempalace` | CLI binary |
| `MEMPALACE_PALACE_DIR` | `~/.mempalace/palace` | Index storage |
| `MEMPALACE_WING_SESSIONS` | `alltrue-sessions` | Transcript wing |
| `MEMPALACE_WING_DOCS` | `alltrue-docs` | Docs wing |
| `TRANSCRIPT_DIR` | Auto-detect from repo slug; fallback `~/.cursor/projects/home-jerry-alltrue/agent-transcripts` | Cursor transcripts |
| `MEMPALACE_DOCS_DIR` | `$REPO_ROOT/docs` | Docs source |

Override via environment for testing only. Do not duplicate wing names elsewhere.

**Prerequisite:** `mempalace` CLI installed (`uv tool install mempalace` or `pipx install mempalace`).

---

## 1.6 Event system (`events.jsonl`)

**Location per run:**

```
~/.mempalace/palace/.ingest-run/runs/<run_id>/events.jsonl
```

**Current run pointer:**

```
~/.mempalace/palace/.ingest-run/current/run_id
```

**Event types:**

| Event | Meaning |
|-------|---------|
| `run_started` | Pipeline began; includes execution plan |
| `stage_started` | Node execution began |
| `stage_completed` | Node succeeded |
| `stage_failed` | Node failed |
| `stage_skipped` | Skipped (resume or lock) |
| `stage_retry` | Retry attempt |
| `run_completed` | Full success (from verify) |
| `run_failed` | Pipeline failed |
| `run_aborted` | Lock held; no work done |

**Human-readable mirror:** `runs/<run_id>/ingest.log` (same run directory).

---

## 1.7 Replay usage

Replay **does not execute** anything. It reconstructs state from events.

```bash
# Last run (uses current/run_id)
bash scripts/mempalace-ingest.sh --replay

# Specific run
bash scripts/mempalace-ingest.sh --replay 20260627T225209Z-1124755
```

Output includes: event count, final status (`completed` / `failed` / `aborted` / `in_progress`), timeline, per-stage state.

Use replay to answer: *Where did this run fail? Which stages completed?*

---

## 1.8 Running full ingestion

```bash
cd ~/alltrue
bash scripts/mempalace-ingest.sh
```

Expected success:

- Exit code **0**
- Final event: `run_completed`
- Console: `✅ Ingest complete. run_id=...`

With health wrapper:

```bash
bash scripts/mempalace-maintain.sh
```

---

## 1.9 Partial ingestion

### Resume failed run (same run_id)

Skips nodes that already have `stage_completed` in the event log:

```bash
bash scripts/mempalace-ingest.sh --resume
```

Uses `current/run_id` — does **not** start a new run.

### Run from stage through downstream

Runs the named node and all **downstream** dependents (not upstream ancestors):

```bash
bash scripts/mempalace-ingest.sh --from-stage storage
# Plan: storage → index → verify
```

**Requires:** Upstream artifacts already exist in the run directory, or use `--force` (dangerous).

### Single stage only

```bash
bash scripts/mempalace/run-stage.sh discovery --no-lock
# equivalent: bash scripts/mempalace-ingest.sh --stage discovery --no-lock
```

### Dry-run (no mine writes)

```bash
bash scripts/mempalace-ingest.sh --dry-run --no-lock
```

### Show plan without executing

```bash
bash scripts/mempalace-ingest.sh --show-plan
bash scripts/mempalace-ingest.sh --list-stages
```

---

## 1.10 Inspecting logs and runs

```bash
# Current run id
cat ~/.mempalace/palace/.ingest-run/current/run_id

RUN=$(cat ~/.mempalace/palace/.ingest-run/current/run_id)
RUN_DIR=~/.mempalace/palace/.ingest-run/runs/$RUN

# Event log
cat "$RUN_DIR/events.jsonl"

# Human log
cat "$RUN_DIR/ingest.log"

# Execution plan snapshot
cat "$RUN_DIR/execution.plan"

# Stage artifacts
ls -la "$RUN_DIR/"

# Replay summary
bash scripts/mempalace-ingest.sh --replay "$RUN"

# Palace health (outside ingest)
bash scripts/mempalace-maintain.sh --status
~/.local/bin/mempalace status
```

**List recent runs:**

```bash
ls -lt ~/.mempalace/palace/.ingest-run/runs/ | head
```

---

## 1.11 Debugging a failed run

1. **Get run_id** from console output or `current/run_id`.
2. **Replay:** `bash scripts/mempalace-ingest.sh --replay <run_id>`
3. **Find last `stage_failed`** in timeline — note stage name and detail.
4. **Read human log:** `runs/<run_id>/ingest.log`
5. **Check artifacts** — missing inputs cause runner to fail before handler runs.
6. **Fix root cause** (CLI missing, permissions, mine error, etc.).
7. **Resume:** `bash scripts/mempalace-ingest.sh --resume`

Common failure signatures:

| Console | Meaning |
|---------|---------|
| `❌ Ingest failed.` | Exit 1; see last `stage_failed` event |
| `⏳ Ingest skipped (lock held).` | Exit 0; another ingest holds lock |
| `palace not writable` | Preflight failed — permissions |
| `missing input artifact` | Upstream stage incomplete or wrong run dir |

---

## 1.12 Day-to-day operator checklist

| Task | Owner | Frequency |
|------|-------|-----------|
| Merge PRs on WSL2 with hooks installed | Dev | Per merge (automatic ingest) |
| Run `mempalace-maintain.sh` | Dev | Monthly (GitHub reminder #519) |
| Verify search works for recent topics | Dev | After major doc changes |
| Install/update git hooks after clone | Dev | Once per machine |
| Install/update `mempalace` CLI | Dev | When upgrading (#996) |

**Automated:** post-merge hook (local only), monthly GitHub comment (reminder only).  
**Manual:** All actual ingestion execution on WSL2.

---

# Part 2 — Failure Playbook

## 2.1 Lock contention (`.ingest.lock` held)

| Field | Detail |
|-------|--------|
| **Symptom** | `⏳ Ingest skipped (lock held).` Exit **0**. Event: `run_aborted`. |
| **Root cause** | Another `mempalace-ingest.sh` holds `flock` on `$MEMPALACE_PALACE_DIR/.ingest.lock`. |
| **Detection** | Console message; `events.jsonl` has `run_aborted`; no `run_completed`. |
| **Severity** | **P2** (noise unless ingest never completes) |
| **Immediate mitigation** | Wait for other ingest to finish; retry in 1–2 min. |
| **Recovery** | If no ingest process running but lock persists: identify stale holder (`lsof` / `fuser` on lock file), kill stale PID, remove lock only if no active process. |
| **Prevention** | Avoid launching multiple full ingests concurrently; post-merge runs in background — don't also start manual full ingest immediately after merge. |

---

## 2.2 Stage failure mid-pipeline

| Field | Detail |
|-------|--------|
| **Symptom** | `❌ Ingest failed.` Exit **1**. Event: `stage_failed` then `run_failed`. |
| **Root cause** | Handler returned non-zero after retries exhausted (mine error, missing sources, status check failed). |
| **Detection** | `--replay`; last `stage_failed` in timeline; `ingest.log` ERROR lines. |
| **Severity** | **P1** if search stale for active work; **P2** otherwise |
| **Immediate mitigation** | Read failure detail from replay; fix underlying issue. |
| **Recovery** | `bash scripts/mempalace-ingest.sh --resume` (same run_id, skips completed stages). |
| **Prevention** | Run `--status` monthly; keep CLI installed; ensure transcript/docs dirs exist. |

---

## 2.3 DAG / invalid manifest

| Field | Detail |
|-------|--------|
| **Symptom** | Engine exits before `run_started`. Messages like `cycle detected`, `depends on unknown`, `Pipeline manifest missing`. |
| **Root cause** | Corrupt or edited `pipeline.manifest.json`; invalid JSON; broken `depends_on` graph. |
| **Detection** | Exit 1 immediately; no new `events.jsonl` entries. |
| **Severity** | **P0** (ingest completely blocked) |
| **Immediate mitigation** | `git checkout HEAD -- scripts/mempalace/engine/pipeline.manifest.json` |
| **Recovery** | Restore manifest from git; re-run ingest. |
| **Prevention** | Do not edit manifest during ops; architecture changes require code review. |

---

## 2.4 Event log corruption or partial write

| Field | Detail |
|-------|--------|
| **Symptom** | `--replay` shows parse gaps; `--resume` skips wrong stages; incomplete timeline. |
| **Root cause** | Disk full mid-write; manual edit of `events.jsonl`; process killed during append. |
| **Detection** | `python3 -m json.tool` on each line fails; replay shows inconsistent stage state. |
| **Severity** | **P1** |
| **Immediate mitigation** | Do not delete events manually. Copy run dir for forensics. |
| **Recovery** | Start fresh run (omit `--resume`): `bash scripts/mempalace-ingest.sh`. New run_id, full re-ingest. Old run dir remains for audit. |
| **Prevention** | Monitor disk space on WSL2; avoid killing ingest mid-run. |

---

## 2.5 Replay mismatch vs execution

| Field | Detail |
|-------|--------|
| **Symptom** | Replay shows `completed` but search misses content; or replay `failed` but index looks updated. |
| **Root cause** | Inspected wrong `run_id`; post-merge background ingest used different run; manual `mempalace mine` outside pipeline (bypass — not supported). |
| **Detection** | Compare `current/run_id` with replay target; check `verify.env` drawer_count vs `mempalace status`. |
| **Severity** | **P2** |
| **Immediate mitigation** | Confirm correct run_id; run full ingest via entry point. |
| **Recovery** | `bash scripts/mempalace-maintain.sh` (status → ingest → status). |
| **Prevention** | Only ingest via `mempalace-ingest.sh`; never call `mempalace mine` directly for routine ops. |

---

## 2.6 Missing or broken handler script

| Field | Detail |
|-------|--------|
| **Symptom** | `stage_failed` detail: `handler not loaded: mempalace_ingest_stage_*` |
| **Root cause** | Stage script missing, syntax error, or repo checkout incomplete. |
| **Detection** | Fails at first node using that handler; replay shows immediate `stage_failed`. |
| **Severity** | **P0** |
| **Immediate mitigation** | `git status scripts/mempalace/engine/stages/`; restore from git. |
| **Recovery** | Fix/checkout scripts; `--resume` or full re-run. |
| **Prevention** | Don't edit stage handlers without testing `--dry-run --no-lock`. |

---

## 2.7 Config drift (SSOT mismatch)

| Field | Detail |
|-------|--------|
| **Symptom** | Transcripts not indexed; wrong wing in search; `Transcript dir missing` warnings. |
| **Root cause** | `TRANSCRIPT_DIR` env override wrong; hardcoded paths in shell profile; repo renamed but fallback path stale. |
| **Detection** | `preflight.env` in run dir shows paths; compare to `scripts/mempalace-config.sh` resolution. |
| **Severity** | **P2** |
| **Immediate mitigation** | Unset overrides: `unset TRANSCRIPT_DIR MEMPALACE_DOCS_DIR`; re-run. |
| **Recovery** | Set correct env or fix repo path; full ingest. |
| **Prevention** | Only override config via `mempalace-config.sh` env vars; document local overrides. |

---

## 2.8 Disk or filesystem permission issues

| Field | Detail |
|-------|--------|
| **Symptom** | Preflight: `palace not writable`; mine failures; `No space left on device`. |
| **Root cause** | Full disk; WSL2 sparse file blow-up (historical: Chroma `link_lists.bin` corruption). |
| **Detection** | `df -h`; `mempalace-maintain.sh --status` warns on `link_lists.bin` >100M. |
| **Severity** | **P0** if disk full; **P1** if index corrupt |
| **Immediate mitigation** | Free disk space; do not delete palace blindly. |
| **Recovery** | Backup `chroma.sqlite3`; `bash scripts/mempalace-maintain.sh --repair` (confirms before rebuild); if repair fails, backup then delete corrupt index files and full re-ingest. |
| **Prevention** | Monthly `--status`; monitor `du -sh ~/.mempalace/palace`. |

---

## 2.9 Partial ingestion success state

| Field | Detail |
|-------|--------|
| **Symptom** | Replay shows some `stage_completed` but `run_failed` or no `run_completed`; search partially updated. |
| **Root cause** | Failure after `storage` but before `verify`; interrupted run. |
| **Detection** | Replay stage state: storage=completed, index/verify=failed or missing. |
| **Severity** | **P1** |
| **Immediate mitigation** | Do not start parallel full ingest. |
| **Recovery** | `bash scripts/mempalace-ingest.sh --resume` OR `--from-stage <first_failed_stage>` if artifacts exist. |
| **Prevention** | Use resume after any non-complete run. |

---

## 2.10 Duplicate ingestion attempts

| Field | Detail |
|-------|--------|
| **Symptom** | Second ingest skips (lock) or two runs overlap; high CPU; duplicate events across run_ids. |
| **Root cause** | Manual ingest during post-merge background ingest; two terminals. |
| **Detection** | Multiple recent run dirs; `run_aborted` events; lock skip messages. |
| **Severity** | **P2** (mine is idempotent but wastes time) |
| **Immediate mitigation** | Let one finish; ignore lock-skip exits (exit 0). |
| **Recovery** | No special action if latest run has `run_completed`. |
| **Prevention** | Wait 2 min after merge before manual ingest; check for running ingest first. |

---

## 2.11 Additional failure modes

### Missing mempalace CLI

| Field | Detail |
|-------|--------|
| **Symptom** | `❌ mempalace not found at ~/.local/bin/mempalace` |
| **Severity** | **P0** |
| **Recovery** | `uv tool install mempalace` or `pipx install mempalace`; re-run. |

### Orphan UUID wings (search scoping)

| Field | Detail |
|-------|--------|
| **Symptom** | `--status` warns `UUID-named wing(s)`; scoped search misses content. |
| **Severity** | **P2** |
| **Recovery** | Full ingest with correct `--wing` flags (automatic in pipeline); see GitHub #997 for consolidation. |

### `--from-stage` without upstream artifacts

| Field | Detail |
|-------|--------|
| **Symptom** | `stage_failed: missing input artifact: manifest.normalized.json` |
| **Severity** | **P2** |
| **Recovery** | Full ingest, or run upstream stages first, or `--force` only if you understand missing deps. |

---

# Part 3 — On-Call Guide

## 3.1 What alerts matter

MemPalace has **no automated paging**. Operators react to:

| Signal | Priority | Action |
|--------|----------|--------|
| Ingest exit **1** after manual/monthly run | **P1** | Debug + resume |
| `mempalace` CLI missing | **P0** | Install CLI |
| Disk >90% on WSL2 | **P0** | Free space before ingest |
| `link_lists.bin` >100M warning | **P1** | Plan repair |
| Lock skip (exit 0) once | **Noise** | Retry later |
| Monthly GitHub comment #519 | **Reminder** | Run maintain within week |
| AI can't find recent decisions in search | **P2** | Run ingest |

**Not P0:** Single lock skip; orphan wing warning; dry-run test failures on laptop without CLI.

---

## 3.2 Quick health check (60 seconds)

```bash
cd ~/alltrue

# 1. CLI present
~/.local/bin/mempalace --version

# 2. Palace status
bash scripts/mempalace-maintain.sh --status

# 3. Last run outcome
bash scripts/mempalace-ingest.sh --replay

# 4. Disk
df -h ~
du -sh ~/.mempalace/palace
```

**Healthy if:** CLI responds; `mempalace status` shows drawers; last replay `Status: completed`; disk not full.

---

## 3.3 First commands in an incident

```bash
cd ~/alltrue

# What happened last?
bash scripts/mempalace-ingest.sh --replay

# Is anything running?
pgrep -af mempalace-ingest || true
lsof ~/.mempalace/palace/.ingest.lock 2>/dev/null || true

# Palace + disk
bash scripts/mempalace-maintain.sh --status
df -h ~

# Last failed stage (if any)
grep stage_failed ~/.mempalace/palace/.ingest-run/runs/$(cat ~/.mempalace/palace/.ingest-run/current/run_id)/events.jsonl | tail -1
```

---

## 3.4 Decision tree

```
Ingest failed or search stale?
│
├─ mempalace CLI missing?
│   └─ YES → install CLI → full ingest → DONE
│
├─ Disk full or palace not writable?
│   └─ YES → free space / fix perms → full ingest → DONE
│
├─ Replay Status: aborted (lock held)?
│   ├─ Another ingest running? → WAIT → retry
│   └─ No process? → investigate stale lock → retry
│
├─ Replay Status: failed at stage X?
│   ├─ Transient (mine/network)? → --resume
│   ├─ Missing artifacts + used --from-stage? → full ingest
│   └─ Corrupt index (large link_lists.bin)? → --repair → full ingest
│
├─ Replay Status: completed but search stale?
│   └─ Wrong run_id / need full re-index → mempalace-maintain.sh
│
└─ Unknown → full ingest (new run) → verify with search sample
```

### When to restart (full ingest, no --resume)

- New `run_id` wanted
- Event log corrupt
- `--resume` loops on same failure
- After `--repair`

```bash
bash scripts/mempalace-ingest.sh
```

### When to resume

- Same run_id; replay shows partial `stage_completed`; root cause fixed

```bash
bash scripts/mempalace-ingest.sh --resume
```

### When to rollback state

**There is no index rollback command.** Options:

1. **Repair index:** `bash scripts/mempalace-maintain.sh --repair` (rebuilds vector index from drawers)
2. **Restore backup:** Copy backed-up `chroma.sqlite3` if you made one before repair
3. **Re-ingest:** Full ingest recreates index from sources (idempotent mine)

Do **not** delete `~/.mempalace/palace` without backup.

### When lock skip is safe to ignore

- Exit **0** + `run_aborted` + message `lock held`
- Another ingest is running OR just finished
- Retry in 2–5 minutes; confirm with `--replay` that a `run_completed` exists

---

## 3.5 Escalation conditions

Escalate to repo maintainer when:

- Manifest/DAG/engine files corrupted and git restore doesn't fix
- `repair` fails or makes status worse
- Disk corruption affects WSL2 broadly (not just MemPalace)
- Repeated P0 failures after full ingest + repair cycle
- Need to change wings, stages, or architecture (not ops scope)

**Do not escalate for:** monthly reminder, single lock skip, orphan UUID wings (known #997).

---

## 3.6 Do NOT do (dangerous actions)

| Action | Why |
|--------|-----|
| Run `mempalace mine` directly for routine ops | Bypasses events, breaks traceability |
| Delete `events.jsonl` to "fix" a run | Destroys audit trail; breaks resume |
| Delete `~/.mempalace/palace` without backup | Loses entire index |
| Edit `pipeline.manifest.json` on ops call | Breaks DAG; architecture change |
| Run ingest on Pi/production server | System is WSL2-local only |
| Use `--force` on production ingest without cause | Skips artifact validation |
| Kill ingest during `storage`/`index` without plan | Partial state; use replay then resume |
| Install Cursor/Claude hooks that mine independently | Removed; violates single-path design |
| Concurrent full ingests intentionally | Lock prevents; wastes resources if forced |

---

## 3.7 Recovery time objectives (suggested)

| Scenario | Target RTO | Notes |
|----------|------------|-------|
| Lock contention | **< 5 min** | Wait + retry |
| Stage failure (transient) | **< 15 min** | Fix + `--resume` |
| Full re-ingest (69 transcripts + 85 docs) | **< 30 min** | Machine-dependent |
| Chroma `--repair` | **< 60 min** | Large palace; interactive confirm |
| CLI reinstall | **< 10 min** | `uv tool install mempalace` |
| Event log corrupt → full re-run | **< 30 min** | No resume |

MemPalace index failure does **not** block AllTrue production app. RTO is for **AI recall quality**, not site uptime.

---

## 3.8 On-call shift checklist

**Start of week (if responsible for doc-heavy work):**

- [ ] `bash scripts/mempalace-maintain.sh --status`
- [ ] `bash scripts/mempalace-ingest.sh --replay` shows recent `completed`

**After major merges to docs/:**

- [ ] Confirm post-merge hook fired (check latest run dir timestamp)
- [ ] Or run `bash scripts/mempalace-ingest.sh` manually

**Monthly (when #519 comment appears):**

- [ ] `bash scripts/mempalace-maintain.sh`
- [ ] Sample search: `~/.local/bin/mempalace search "recent topic" --wing alltrue-docs`

---

## Appendix A — CLI flags reference

```
bash scripts/mempalace-ingest.sh [options]

  --stage NAME       Run one node only
  --from-stage NAME  Run node + downstream dependents
  --resume           Skip stage_completed in event log
  --replay [RUN_ID]  Reconstruct from events (no execution)
  --dry-run          No mempalace mine writes
  --no-lock          Skip flock (testing only)
  --force            Ignore missing input artifacts
  --list-stages      Print topo-sorted node names
  --show-plan        Print execution plan
  --help             Usage
```

## Appendix B — Run directory layout

```
~/.mempalace/palace/.ingest-run/
├── current/
│   ├── run_id
│   ├── failed_stage          (if last run failed)
│   └── last_success_run_id
└── runs/<run_id>/
    ├── events.jsonl          ← source of truth for run state
    ├── ingest.log            ← human-readable log
    ├── execution.plan        ← derived DAG order
    ├── preflight.env
    ├── lock.acquired
    ├── manifest.raw.json
    ├── manifest.normalized.json
    ├── mining.plan.env
    ├── storage.sessions.done
    ├── index.env
    ├── verify.env
    └── post-verify.status.txt
```

## Appendix C — Install post-merge hook (one-time per clone)

```bash
cd ~/alltrue
bash scripts/install-git-hooks.sh
```

Verify: `.git/hooks/post-merge` calls `scripts/mempalace-ingest.sh`.

---

*Document synthesized from repository implementation as of 2026-06-27. Architecture: DAG manifest + event-sourced runner. Entry point: `scripts/mempalace-ingest.sh` only.*
