# AllTrue Autonomous Execution Harness — Status

**Purpose:** durable resume point for any agent/session. Chat history is not authority.

| Field | Value |
|-------|--------|
| Updated | 2026-09-16T11:40:00Z |
| Worktree | `/home/jerry/workspace/tasks/alltrue/harness-h0-capability-map` |
| Branch | `chore/task-harness-h0-capability-map` |
| Slice in flight | H0–H8 landed; H9 blocked on real staging |
| Durable DB | `/home/jerry/workspace/state/alltrue/harness.sqlite` |
| Dogfood Program | `alltrue-inapp` / `INAPP-DOCS-RECONCILE` (tick → EXECUTING + Goal file) |
| Production mutation | disabled |
| Founder decisions required | **none** for V1 local harness |

---

## A–F brief (locked)

### A. Governance capability map
See `docs/harness/CAPABILITY_MAP.yaml` (machine-readable).

### B. Orchestration gaps
Program/Task FSM, leases, planner, worker Goal, PR/CI reconcile were missing in-repo — now H1–H6. H7 loop + H8 multi-WIP next. H9 staging blocked.

### C. Minimal V1 architecture
AllTrue-local `scripts/harness/` + host SQLite + `autonomy_gate` + `agent-start`. **Not** portfolio-ops `agent_graph` runtime (avoid fleet allowlist expansion). Patterns only.

### D. H0–H8 plan

| Slice | Status |
|-------|--------|
| H0 capability map | done |
| H1 Program/Task/CLI | done |
| H2 governance_adapter | done |
| H3 planner dry-run | done |
| H4 worktree+lease prepare | done |
| H5 worker Goal / file-queue | done |
| H6 PR/CI reconciler | done (gh observe) |
| H7 autonomous bounded loop | done (`harness tick --program`) |
| H8 multi-program WIP | done (`harness tick` multi + program leases) |
| H9 staging/release | blocked |

### E. Files
`docs/harness/*`, `scripts/harness/*`, `scripts/tests/test_harness_core.py`, `docs/INDEX.md` pointer.

### F. Founder
None for continuing H0–H8 locally. Do **not** widen portfolio-ops effect allowlist without Founder.

---

## Operator commands

```bash
export HARNESS_DB=/home/jerry/workspace/state/alltrue/harness.sqlite
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness plan --program truefit --sync
python3 -m scripts.harness prepare --task INAPP-DOCS-RECONCILE --sync
python3 -m scripts.harness dispatch --task INAPP-DOCS-RECONCILE
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
python3 scripts/tests/test_harness_core.py
```

## Resume checklist

1. Read this file
2. `python3 -m scripts.harness resume --sync`
3. Continue H7 (select → prepare → dispatch → reconcile → next) without Founder ping for routine CONTINUE
