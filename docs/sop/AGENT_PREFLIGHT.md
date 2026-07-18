# Agent Preflight

Run **before any write**. Non-zero exit ⇒ stop (no edit, no commit).

1. Read [WORKTREE_POLICY.md](../governance/WORKTREE_POLICY.md).
2. From an allowlisted task worktree: `make agent-preflight`.
3. Confirm capability in [AGENT_CAPABILITY_REGISTRY.md](../governance/AGENT_CAPABILITY_REGISTRY.md).
4. For production claims: `make production-identity` (RED if scheduler/deploy drift).

**Enforced by:** `scripts/agent-preflight.sh` + `scripts/agent-preflight.config.json` (also `AGENT_PREFLIGHT_MODE=ci` in Presubmit).
