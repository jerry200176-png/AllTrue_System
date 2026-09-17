# Harness H0 — Capability map (condensed)

Full inventory lived in the first delivery draft; this is the landed H0 summary.
**Authority remains** `scripts/governance/autonomy_gate.py` — harness must not re-classify risk.

| Capability | Source | Reuse | Gap |
|---|---|---|---|
| autonomy_gate | `scripts/governance/autonomy_gate.py` | yes | wrap as adapter (H2) |
| agent-start / manifest | agent-control + `.agent-session/` | yes | H4b WorkerRun (`--attach`) |
| risk T0–T3 / R0–R3 | gate + `RISK_BASED_MERGE_POLICY` | yes | ticket→scope before diff |
| PR/CI / exact-SHA / rollback | workflows + gate helpers | yes | reconcile loop (H6) |
| production activation | `deploy.yml` + Environment | yes | Founder inbox packet |
| worktrees | `WORKTREE_POLICY` + agent-start | yes | program WIP lease + WorkerRun |
| program status | `docs/truefit/`, `docs/programs/`, Chat bug SOP | partial | YAML registry (H1) |
| in-repo AEH | **missing before this work** | no | `scripts/harness/` |

**External:** portfolio-ops `agent_graph` exists but AllTrue is not on its effect allowlist; V1 stays AllTrue-local (no fleet allowlist expansion).

**Placement:** governance=`scripts/governance/`; harness=`scripts/harness/`; durable DB=`/home/jerry/workspace/state/alltrue/harness.sqlite`.
