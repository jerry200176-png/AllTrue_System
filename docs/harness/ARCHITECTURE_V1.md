# AllTrue Autonomous Execution Harness — Architecture V1

## Separation

| Layer | Owns | Location |
|-------|------|----------|
| Governance | risk, autonomy eligibility, Founder-required, exact-SHA, rollback, production activation | `scripts/governance/` |
| Harness | program/task state, selection, leases, worker dispatch, evidence ledger, resume | `scripts/harness/` |
| Session gateway | worktree create, manifest, preflight | `agent-control` (`agent-start`) |
| Fleet graph | portfolio-ops self-dogfood only | **not** a runtime dependency for AllTrue V1 |

## State machine (canonical names)

Aligned to Goal §3; mapped to governance vocabulary where it already exists.

| State | Meaning | Typical actor |
|-------|---------|---------------|
| DISCOVERED | Candidate observed from program contract | planner |
| READY | Unblocked, dependencies satisfied | planner |
| BLOCKED | External/product blocker recorded | planner/reconciler |
| FOUNDER_REQUIRED | autonomy_gate / policy requires Founder | governance_adapter |
| LEASED | Exclusive lease held | lease manager |
| PLANNING | Worker Goal generated | harness |
| EXECUTING | Worker running | worker adapter |
| TESTING | Local/required tests running | worker |
| PR_OPEN | PR exists | reconciler |
| CI_PENDING | Waiting required checks | reconciler |
| CI_FAILED | Checks failed; retry/fix path | reconciler |
| CI_GREEN | Required checks green | reconciler |
| MERGE_READY | Governance permits merge | governance_adapter |
| MERGED | Merge SHA recorded | reconciler |
| STAGING_PENDING | Staging deploy requested | release (H9) |
| STAGING_VERIFIED | Staging acceptance evidence | release (H9) |
| PRODUCTION_ELIGIBLE | Gate says eligible | governance_adapter |
| PRODUCTION_PENDING | Activation waiting | release |
| PRODUCTION_VERIFIED | Exact-SHA + health | release |
| DONE | Acceptance met | harness |
| FAILED | Unrecoverable without human/policy | harness |
| PAUSED | Operator pause / interrupted | harness |

Every transition records: prerequisite, actor, evidence refs, allowed next, failure behavior.

## Persistence

- **Repo-backed:** program contracts in `scripts/harness/programs/*.yaml`
- **Host durable:** SQLite WAL at `/home/jerry/workspace/state/alltrue/harness.sqlite`
  (override `HARNESS_DB`). Never store sole execution state in model context.
- **Evidence:** structured JSON blobs + external refs (PR number, run id, log path)

## Autonomy default

`CONTINUE` when `governance_adapter` reports autonomous.
Pause and enqueue Founder inbox only when gate says Founder-required or Goal §22 stop conditions.

## Concurrency (V1)

- One mutating Task lease per Program
- Named shared-contract leases: schema, auth, billing, deployment, shared_frontend, shared_domain
- Stale lease recoverable by TTL + process identity check (H4)

## Out of scope V1

Multi-tenant SaaS, generic MCP/RAG, vector DB, agent marketplace, K8s, custom LLM router, Temporal/Airflow/Celery.
