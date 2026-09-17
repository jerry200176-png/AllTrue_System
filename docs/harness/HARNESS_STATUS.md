# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-17T00:35:00Z |
| Branch | `chore/task-harness-h4-impl` |
| Slice | **H4** dispatch (Founder APPROVED_WITH_AMENDMENTS) |
| Landed | H0–H1 #2977; H2 #3007; H2.1 #3009; H3 Plan #3011; H3 #3014 |
| Plan docs | #3018 MERGED; amendments binding appended to H4_DISPATCH_PLAN.md |
| Durable DB | schema **v3** (`dispatch_attempts`) |
| Founder | none for this tooling slice |

| Slice | Status |
|-------|--------|
| H0–H3 | landed |
| H4 dispatch | **this PR** |
| H5+ | backlog after H4 ACCEPTED |

```bash
python3 scripts/tests/test_harness_core.py
python3 scripts/tests/test_harness_h2.py
python3 scripts/tests/test_harness_planner.py
python3 scripts/tests/test_harness_dispatch.py
python3 -m scripts.harness dispatch --dry-run --program <id>
python3 -m scripts.harness dispatch --apply --program <id> --worker <id>
```

H4 amendments: execution-critical revalidation (not clock snapshot), LeaseBinding,
heartbeat renew, DispatchAttempt before spawn, fencing handoff, partial rollback,
PR_READY lease release, concurrent exactly-one-winner.
