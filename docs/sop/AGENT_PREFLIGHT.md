# Agent Preflight

Run before any write to AllTrue or sunrise-cafe.

1. Read [COMPANY_CONSTITUTION.md](../governance/COMPANY_CONSTITUTION.md) + [WORKTREE_POLICY.md](../governance/WORKTREE_POLICY.md).
2. From the repo root of a **safe** worktree: `make agent-preflight` (or `bash scripts/agent-preflight.sh`). Non-zero ⇒ stop.
3. Confirm capability in [AGENT_CAPABILITY_REGISTRY.md](../governance/AGENT_CAPABILITY_REGISTRY.md) — do not invent deploy/reply powers.
4. Classify risk (T0–T3 / billing-attendance-auth).
5. For bugs: [BUG_INTAKE_TO_PRODUCTION.md](./BUG_INTAKE_TO_PRODUCTION.md).
6. Plan evidence using [EVIDENCE_CONTRACT.md](../governance/EVIDENCE_CONTRACT.md).
7. Prefer independent verify for high-risk before merge.
8. End with [AGENT_HANDOFF.md](./AGENT_HANDOFF.md) if session may stop.

**Forbidden:** `/home/jerry/alltrue` — see WORKTREE_POLICY (do not redefine paths in adapters).
