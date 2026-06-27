---
owner: Principal Architect
status: normative (L3 index)
derives_from: CONSTITUTION.md Article III
---

# Engineering SOP Index (L3)

Every engineering process derives from the [Constitution](CONSTITUTION.md) and has a **canonical doc** + an **executable enforcement** (test/CI/script) so the process is run, not just read. `scripts/arch-fitness-check.mjs` FIT-13 verifies the core SOP docs exist.

| Process | Canonical SOP | Executable enforcement |
|---|---|---|
| Feature development | `.cursor/rules/plan-as-prd-cross-functional.mdc` | `ci.yml` (PHPUnit/Vite/coverage), `presubmit.yml` (branch naming) |
| Bug fix | `.cursor/rules/bug-fix-plan.mdc` + `CHAT_BUG_SYSTEM.md §3.7` | revert-proof test rule (§10); `missing-tests-warn.yml` |
| Refactor | `bug-fix-plan.mdc` (no behavior change) | full test suite + `arch-fitness-check.mjs` (no new ratchet regressions) |
| DB migration | `RULE_MIGRATION_COMPAT.md` | `migration-dryrun.yml`, `backup-restore-test.yml` |
| Domain change (ownership/event/invariant) | `RULE_ARCHITECTURE_GOVERNANCE.md` + new ADR | `arch-fitness-check.mjs` FIT-1/5/6/7/8/9/11/12 + `ArchitectureInvariantsTest` |
| CI/CD change | `OPERATIONS_RUNBOOK.md` | required-check set in branch protection; `arch-fitness.yml` |
| Deployment | `OPERATIONS_RUNBOOK.md §A-B` | `deploy.yml` (health + smoke + auto-rollback) |
| Rollback | `RUNBOOK_ROLLBACK.md` | `rollback-readiness.yml`, `deploy.yml` auto-rollback |
| Incident response | `OPERATIONS_RUNBOOK.md` + `DANGEROUS_OPERATIONS.md` | `pi-health.yml`, Sentry alerts |
| Break glass | `RUNBOOK_BREAK_GLASS.md` | ADR-0009 reconcile obligation + break-glass record |
| Release / versioning | `OPERATIONS_RUNBOOK.md §X` (CalVer) + Handbook (policy SemVer) | `release.yml` |
| Feature flags / staging | `OPERATIONS_RUNBOOK.md §U/§V` | `deploy-staging.yml` (manual dispatch) |
| Tech-debt retirement | `.cursor/rules/tech-debt.mdc` + `TECH_DEBT.md` 架構治理債 | `arch-fitness-check.mjs` ratchets (debt closes when baseline hits target) |

## Enforcement
FIT-13 asserts the core SOP docs (`CONSTITUTION`, `RUNBOOK_BREAK_GLASS`, `RUNBOOK_ROLLBACK`, `OPERATIONS_RUNBOOK`, `RULE_MIGRATION_COMPAT`, `SOP_INDEX`) are present. A process without a canonical SOP **or** without an executable enforcement is a governance gap.
