# Tool-neutral targets for agents (see docs/governance/WORKTREE_POLICY.md)

.PHONY: agent-preflight governance-health validate-capabilities overlay-pin instruction-invariants

agent-preflight:
	bash scripts/agent-preflight.sh

validate-capabilities:
	python3 scripts/governance/validate-capability-registry.py

overlay-pin:
	bash scripts/governance/check-overlay-pin.sh

instruction-invariants:
	bash scripts/governance/check-instruction-invariants.sh

governance-health:
	bash scripts/radars/governance-health.sh
