# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-17T00:00:00Z |
| Branch | `chore/task-harness-h3-plan` |
| Slice | **H3** read-only deterministic planner |
| Landed | H0–H1 #2977; H2 #3007; H2.1 #3009; H3 Plan #3011 |
| Durable DB | `/home/jerry/workspace/state/alltrue/harness.sqlite` (schema v2) |
| Founder | **none** for this tooling slice |

| Slice | Status |
|-------|--------|
| H0–H1 | landed #2977 |
| H2 foundation | landed #3007 |
| H2.1 correctness | landed #3009 |
| H3 planner | **this PR** |
| H4 dispatch / lease mutate | next (out of scope here) |
| H9 staging | blocked |

```bash
python3 scripts/tests/test_harness_core.py
python3 scripts/tests/test_harness_h2.py
python3 scripts/tests/test_harness_planner.py
python3 -m scripts.harness plan --program <id>
```

H3 is **read-only**: no acquire / renew / reclaim / probe-CAS. `PlanResult` binds
`goal_id`, contract fingerprint, observed main SHA, snapshot fingerprint,
required leases, and governance for H4 revalidation. Starvation uses
`ready_since` aging into effective priority. Lease ownership requires
`lease_id` + fencing (not `task_id` alone). `would_execute=true` requires a
valid `GoalContract`.

Plan (approved + amendments): [`H3_PLANNER_PLAN.md`](H3_PLANNER_PLAN.md).
