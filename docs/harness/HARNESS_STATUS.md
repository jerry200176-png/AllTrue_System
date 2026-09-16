# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-16T11:25:00Z |
| PR | https://github.com/jerry200176-png/AllTrue_System/pull/2977 |
| Branch | `chore/task-harness-h0-capability-map` |
| Landed slice | **H0 + H1** (size-split; H2–H8 follow-up PRs) |
| Durable DB | `/home/jerry/workspace/state/alltrue/harness.sqlite` |
| Founder required | **none** for this local tooling slice |

## Plan

| Slice | Status |
|-------|--------|
| H0 capability map | this PR |
| H1 Program/Task/SQLite/CLI/leases | this PR |
| H2 governance_adapter | next PR |
| H3 planner | next PR |
| H4–H8 | subsequent PRs (≤1300 lines each) |
| H9 staging | blocked |

## Commands

```bash
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
python3 scripts/tests/test_harness_core.py
```

## Architecture choice

AllTrue-local harness under `scripts/harness/` calling `autonomy_gate`.
Do **not** widen portfolio-ops graph allowlist (control-plane Founder boundary).
