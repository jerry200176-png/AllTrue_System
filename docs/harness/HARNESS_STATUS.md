# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-16T17:50:00Z |
| Branch | `chore/task-harness-h2-governance-adapter` |
| Landed slice | **H2** governance_adapter + drift/reconcile |
| Prior | H0+H1 [#2977](https://github.com/jerry200176-png/AllTrue_System/pull/2977) |
| Durable DB | `/home/jerry/workspace/state/alltrue/harness.sqlite` (schema v2) |
| Founder required | **none** for this local tooling slice |

## Plan

| Slice | Status |
|-------|--------|
| H0 capability map | landed #2977 |
| H1 Program/Task/SQLite/CLI | landed #2977 |
| H2 governance_adapter | **this PR** |
| H3 planner | next |
| H4–H8 | subsequent PRs (≤1300 lines each) |
| H9 staging | blocked |

## Commands

```bash
python3 -m scripts.harness status --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness graph
python3 scripts/tests/test_harness_core.py
python3 scripts/tests/test_harness_h2.py
```

## Architecture choice

AllTrue-local harness under `scripts/harness/` calling `autonomy_gate`.
Do **not** widen portfolio-ops graph allowlist (control-plane Founder boundary).
