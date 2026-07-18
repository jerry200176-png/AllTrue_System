# Tool-neutral targets for agents (see docs/governance/WORKTREE_POLICY.md)

.PHONY: agent-preflight production-identity governance-health technical-health validate-capabilities overlay-pin instruction-invariants catalog-validate catalog-index


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

technical-health:
	bash scripts/radars/technical-health.sh

catalog-validate:
	python3 scripts/catalog/validate-services.py

catalog-index:
	python3 scripts/catalog/generate-index.py

production-identity:
	bash scripts/production-identity.sh
