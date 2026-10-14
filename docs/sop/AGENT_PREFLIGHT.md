# Agent Preflight

Run **before any write** in a local WSL worktree. Non-zero exit ⇒ stop (no edit, no commit).

**Cloud sessions (default since 2026-10-14):** skip steps 1–2; follow the cloud section of [WORKTREE_POLICY.md](../governance/WORKTREE_POLICY.md). CI still runs `AGENT_PREFLIGHT_MODE=ci` in Presubmit.

1. Read [WORKTREE_POLICY.md](../governance/WORKTREE_POLICY.md).
2. From an allowlisted task worktree: `make agent-preflight`.
3. Confirm capability in [AGENT_CAPABILITY_REGISTRY.md](../governance/AGENT_CAPABILITY_REGISTRY.md).
4. For production claims: `make production-identity` (RED if scheduler/deploy drift).

**Enforced by:** `scripts/agent-preflight.sh` + `scripts/agent-preflight.config.json` (also `AGENT_PREFLIGHT_MODE=ci` in Presubmit).

ExoProtocol is retired (Founder 2026-10-14); do not start Exo and ignore any `.exo/` tree.
