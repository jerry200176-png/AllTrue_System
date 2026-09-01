# Tool-neutral targets for agents (see docs/governance/WORKTREE_POLICY.md)

.PHONY: agent-preflight production-identity governance-health technical-health technical-health-scorecard validate-capabilities overlay-pin instruction-invariants catalog-validate catalog-index


agent-preflight:
	bash scripts/agent-preflight.sh

# Fast CI governance gate (Cloud Agent + local). See docs/governance/CI_GOVERNANCE.md
ci-preflight:
	node scripts/ci-preflight.mjs

test-gov:
	npm run test:gov

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

technical-health-scorecard:
	python3 scripts/radars/technical-health-scorecard.py

catalog-validate:
	python3 scripts/catalog/validate-services.py

catalog-index:
	python3 scripts/catalog/generate-index.py

production-identity:
	bash scripts/production-identity.sh
