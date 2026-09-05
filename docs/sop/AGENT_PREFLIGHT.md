# Agent Preflight

Run **before any write**. Non-zero exit ⇒ stop (no edit, no commit).

1. Read [WORKTREE_POLICY.md](../governance/WORKTREE_POLICY.md).
2. From an allowlisted task worktree: `make agent-preflight`.
3. Confirm capability in [AGENT_CAPABILITY_REGISTRY.md](../governance/AGENT_CAPABILITY_REGISTRY.md).
4. For production claims: `make production-identity` (RED if scheduler/deploy drift).

**Enforced by:** `scripts/agent-preflight.sh` + `scripts/agent-preflight.config.json` (also `AGENT_PREFLIGHT_MODE=ci` in Presubmit).

## Session-generated records after a passing preflight

Run preflight on the clean task branch before starting Exo. If Exo then writes
session records, inspect both tracked diffs and untracked files individually.
Do not treat all `.exo/**` files as exempt: policy, config, locks and tickets
must still be reviewed for their actual changes. Never ignore product changes.

For the Founder-authorized onboarding recovery (2026-09-05), only the verified
session/bootstrap, lease/fencing records, ticket linkage and reflection hit
counts were checkpointed in a separate commit, then the unchanged preflight
was rerun successfully. Preserve this evidence and use that clean checkpoint
as the start of implementation; subsequent task edits are expected work in
progress, not a reason to restart the session. No dirty-tree bypass flag,
blanket exclusion, or preflight/config change is authorized by this recovery.
