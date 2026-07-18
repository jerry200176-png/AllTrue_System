# Tool-neutral targets for agents (see docs/governance/WORKTREE_POLICY.md)

.PHONY: agent-preflight

agent-preflight:
	bash scripts/agent-preflight.sh
