# Merge → Production Activation Runbook

**Status:** active when this file is on `main`.

**Execution authority:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml)
only. This runbook is reference material; it does not execute production
changes.

## Minimal state machine

```text
PR checks → merged on main → main CI success → deploy.yml preflight
                              ├─ non-deployable diff → merged / no-op
                              ├─ validated reversible T0/T1 → ready
                              │                         → automatic deploy
                              └─ protected T2/T3 or ambiguous evidence
                                                        → awaiting-approval
                                                        → Founder approves the
                                                          same run's Environment
                                                        → approved → deploy

deploy executor → exact target SHA + health + critical smoke
                ├─ all pass → deployed → production-verified
                └─ any fail → automatic rollback → rollback verified or escalate
```

`ready`, `awaiting-approval`, and `approved` are workflow evidence states; none
means production changed. Green CI or workflow success is never a deployment
claim. A failed health or smoke check invokes the existing rollback behavior.

## Tier behavior

| Tier | Merge eligibility | Auto-deploy | Activation path |
|---|---|---|---|
| T0/R0 | Required checks and docs gates | No-op for docs-only changes | None |
| T1/R1 | Required checks, regression test, review, rollback evidence | Yes, with matching declaration and no protected production side effect | `workflow_run` |
| T2/R2 | Required checks, independent review, rollback and production evidence | No | Same-run Founder Environment approval |
| T3/R3 | Prepared with protected-action evidence; no autonomous protected execution | No | Founder-controlled activation / mutation boundary |

The authoritative classifiers are in `scripts/governance/autonomy_gate.py`.
The deploy workflow classifies the full production-manifest SHA range. Tests,
docs, Exo metadata, and the read-only CI scheduler cannot force a normal runtime
release through the human gate; deploy, migrations, repairs, auth/permission,
billing, credentials, destructive, and other production-side-effect paths remain
protected. A declaration may raise effective risk but may never lower the
machine-derived minimum. Missing, mismatched, or understated evidence is held.

## Founder boundary

Protected application deployment stays in the same `deploy.yml` run. The
Founder approves its pending `production-activation` Environment deployment
once, after which the sole deploy executor proceeds. The run still requires the
exact current `main` SHA and successful CI for that SHA.

All supported activation events (`workflow_run`, `repository_dispatch`, and
`workflow_dispatch`) use one static Environment policy: Founder required
reviewer, self-review allowed, administrator bypass disabled, and custom
deployment branch policy containing only `main`. The workflow verifies this
configuration and fails closed on drift. `workflow_dispatch` typed confirmation
remains only for exceptional manual phases; it is not a second normal approval
path.

No migration, production data repair, credential change, or billing/entitlement
operation is authorized by this activation input.

## Live settings still outside this PR

This PR does not change GitHub Rulesets, branch protection, repository secrets,
credentials, or Environment settings. For protected activations, if
`production-activation` lacks the required reviewer, permits administrator
bypass, or is not restricted to `main`, activation fails closed with a hard
boundary error. Founder must configure the static policy separately before a
protected production activation can proceed.

## Single executor and recovery

Side-effecting jobs in `deploy.yml` share the non-cancelling
`alltrue-production-side-effects-v2` concurrency group; preflight and
classification do not hold it. Legacy case-specific repair workflows are not
the release executor and remain under the Control Plane contract.

Before production, the executor records the previous commit and backups.
Health or critical-smoke failure invokes the existing automatic rollback and a
second health check. An unrecoverable rollback remains failed and escalates.
Migration, credential, repair, and data-changing operations retain protected
semantics; they are not low-risk automatic changes.

## Runtime verification

Production verification requires the exact target SHA in `deployment.json`,
`/api/v1/health` reporting `ok`, and the critical read-path checks in
`scripts/post-merge-smoke.sh`. Operators read the workflow evidence; the normal
flow does not dispatch a second deploy or run a second production smoke.
