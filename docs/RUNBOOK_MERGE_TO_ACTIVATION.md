# Merge → Production Activation Runbook

**Status:** active when this file is on `main`.

**Execution authority:** `.github/workflows/deploy.yml` only. This runbook is
reference material; it does not execute production changes.

## Current trigger

`Deploy to Pi` currently receives a `workflow_run` event when `CI — PHPUnit
Tests` completes successfully on `main`. Before this change, every deployable
main commit entered the production SSH job immediately; the workflow had no
autonomy-tier gate.

## State machine

```text
PR checks → merged on main → main CI success
                              ├─ T0/R0 or T1/R1, no workflow change
                              │    → auto-deploy eligible
                              └─ T2/T3, missing/invalid provenance, or workflow change
                                   → merged-awaiting-activation

merged-awaiting-activation → Founder exact-SHA dispatch
                            → production-activation environment gate
                            → production SSH deploy
                            → health + smoke verification
```

Non-deployable changes remain `no-op`. A failed health or smoke check keeps the
existing deploy rollback behavior; this change does not alter rollback or
database migration semantics.

## Tier behavior

| Tier | Merge eligibility | Auto-deploy | Activation path |
|---|---|---|---|
| T0/R0 | Required checks and docs gates | No-op for docs-only changes | None |
| T1/R1 | Required checks, regression test, review, rollback evidence | Yes, only with an explicit matching declaration and no workflow change | Existing `workflow_run` |
| T2/R2 | Required checks, independent review, rollback and production evidence | No | `merged-awaiting-activation` → Founder GO |
| T3/R3 | Prepared with protected-action evidence; no autonomous protected execution | No | Founder-controlled activation / mutation boundary |

Missing or inconsistent tier metadata fails closed into
`merged-awaiting-activation`; it never silently becomes auto-deploy.

## Founder boundary

For a held commit, Founder GO is the `workflow_dispatch` of `Deploy to Pi`:

```text
phase=application-deploy
target_sha=<exact current main 40-character SHA>
confirm=ACTIVATE_PRODUCTION:<same exact SHA>
```

The workflow also requires a successful `CI — PHPUnit Tests` run for that exact
SHA and refuses a stale target. The existing `production-activation` GitHub
Environment is attached to this manual gate.

No migration, production data repair, credential change, or billing/entitlement
operation is authorized by this activation input.

## Live settings still outside this PR

This PR does not change GitHub Rulesets, branch protection, repository secrets,
or Environment reviewers. The repository's live Ruleset / Environment settings
must still be checked by the Founder. If `production-activation` has no required
reviewer configured, the machine-enforced boundary remains write access plus
the exact confirmation phrase; configuring a required Founder reviewer is a
separate live Settings change and must be Founder-approved.
