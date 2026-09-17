# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-17T11:10:00Z |
| Branch | `chore/task-harness-h4b-worker-run` |
| Slice | **H4b** WorkerRun / runtime binding |
| Landed | H0–H1 #2977; H2 #3007; H2.1 #3009; H3 #3014; H4 #3022 (PARTIAL) |
| Durable DB | schema **v4** (`worker_runs`) |
| Founder | none for this tooling slice |

| Slice | Status |
|-------|--------|
| H0–H3 | ACCEPTED |
| H4 dispatch | MERGED PARTIAL (#3022) — CAS/revalidate only |
| H4b WorkerRun | **this PR** — start\|attach\|resume + session identity |
| H5+ | backlog after H4 ACCEPTED (needs e2e wake proof) |

```bash
python3 scripts/tests/test_harness_core.py
python3 scripts/tests/test_harness_h2.py
python3 scripts/tests/test_harness_planner.py
python3 scripts/tests/test_harness_dispatch.py
python3 scripts/tests/test_harness_worker_run.py
python3 -m scripts.harness dispatch --dry-run --program <id>
python3 -m scripts.harness dispatch --apply --program <id> --worker <id>
# Attach existing task worktree (agent-control ≥0.5.1):
agent-start alltrue <task-id> --attach --dry-run
```

H4b: default spawn records durable `worker_runs` (session_id, worktree, fencing).
Create remains soft-deferred unless `HARNESS_SPAWN_CREATE=1`; attach/resume is
the primary path when the task worktree already exists.
