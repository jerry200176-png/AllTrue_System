# Phase 1 Founder Decision Memo

Date: 2026-09-14
Scope: read-only verification of existing AllTrue boundaries.
Status: findings recorded; no finding below has been applied.

## Confirmed security-boundary findings

| Finding | Evidence inspected | Actual risk | Minimal remediation | Operational cost |
|---|---|---|---|---|
| Production environment permits self-review | GitHub environment `production-activation`: required reviewer `jerry200176-png`; `prevent_self_review=false` | One principal can satisfy the environment review boundary | Enable prevent-self-review and require an independent reviewer | At least one additional available reviewer; slower emergency activation |
| Production-looking secrets are repository-scoped | Repository secret inventory includes `PI_SSH_HOST`, `PI_SSH_KEY`, `PI_SSH_USER`, `SENTRY_DSN`, and smoke credentials; no environment-scoped secret values were exposed by the read-only API | A workflow with repository access may be able to request secrets outside the production activation context | Move production credentials to the production environment and split smoke/non-production scopes | Secret migration, workflow testing, and recovery runbook update |
| Action SHA pinning is not enforced | Repository Actions policy reported allow-all actions; workflows use mutable action tags such as `actions/checkout@v7` | A tag movement or compromised action release can alter runner behavior | Enforce an approved action allowlist with immutable commit SHAs | Dependency update process and periodic pin refresh |
| Multiple human write principals exist | Collaborator inventory reported `MonkeyJeng` and `captain-balung` with write, and `jerry200176-png` with admin | More principals can merge or alter workflow-relevant code | Reduce write access to least privilege and use named/bot identities for automation | Access review, onboarding friction, and possible emergency-access procedure |
| Portfolio customer-email authority conflicts with this Founder boundary | The current authoritative portfolio policy is marked superseded and explicitly says send/reply or any Gmail mutation is not autonomous; AllTrue's current policy also requires Founder approval | The previously reported conflict is not confirmed in the current policy snapshot; stale policy copies could still create ambiguity | Keep customer communication Founder-required and synchronize any stale policy copies before granting access | No Phase 1 operational change; periodic policy synchronization |

The independent read-only checks used for this memo were the GitHub environment, environment branch-policy, Actions permissions, collaborator, and repository/environment secret-inventory APIs, plus the current portfolio policy file. Secret APIs returned names and timestamps only; no values were exposed. No secrets, credentials, permissions, rulesets, or environment settings were changed.

## Deployment and migration activation finding

**Confirmed fact:** `.github/workflows/deploy.yml` checks for pending migrations on the target checkout and runs `php artisan migrate --force` during the production executor. The same workflow can classify a current change as low-risk/control-plane-only while pending migration state already exists on `main`.

**Risk:** change risk and activation risk are separate. A low-risk current PR can therefore activate a previously pending schema/data operation when deployment reaches the migration step. The existing rollback path attempts a one-step migration rollback, but that is not proof that arbitrary schema or data changes are semantically reversible.

**Minimal remediation proposal for Founder decision:** split migration activation from routine application activation, or require an explicit Founder-approved migration receipt that identifies the pending migration set and rollback/recovery evidence before the production executor can run it. Keep the exact-SHA and environment gates. Do not implement either option in Phase 1.

**Operational cost:** additional activation latency and a migration inventory/recovery runbook; a split job also adds release coordination and another protected approval point.

## Decision boundary

Phase 1 does not change production activation, migration behavior, GitHub permissions/rulesets, environment protection, credential scope, action policy, or customer-communication authority. Founder approval is required before any remediation in this memo is implemented.
