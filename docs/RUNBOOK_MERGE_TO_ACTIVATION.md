# Merge → Production Activation Runbook

**Status:** active when this file is on `main`.

**Execution authority:** `.github/workflows/deploy.yml` only. This runbook is
reference material; it does not execute production changes.

## Current trigger

`Deploy to Pi` currently receives a `workflow_run` event when `CI — PHPUnit
Tests` completes successfully on `main`. It remains the sole production
executor. The workflow now separates merge from activation and only permits
automatic execution after authoritative machine classification.

## State machine

```text
PR checks → merged on main → main CI success
                              ├─ authoritative #2180 classifier says T0/T1,
                              │  declaration is valid, no workflow change
                              │    → auto-deploy eligible
                              └─ T2/T3, workflow change, missing/invalid evidence,
                                 or classifier unavailable
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

The authoritative classifier is `scripts/governance/autonomy_gate.py` from
#2180. This PR does not duplicate its path/risk parser. Until that classifier
is present in the checked-out main revision, activation fails closed into
`merged-awaiting-activation`. The declaration may raise effective risk but may
never lower the machine-derived minimum; missing, mismatched, or understated
evidence is held.

## Founder boundary

For a held commit, Founder GO is the `workflow_dispatch` of `Deploy to Pi`:

```text
phase=application-deploy
target_sha=<exact current main 40-character SHA>
confirm=ACTIVATE_PRODUCTION:<same exact SHA>
```

The workflow must be dispatched from `refs/heads/main`, requires the exact
current main SHA, and requires a successful `CI — PHPUnit Tests` run for that
SHA. It refuses stale targets and revisions dispatched from another branch or
tag. The `production-activation` GitHub Environment is attached to this manual
gate, and the workflow fails closed unless its live protection is configured.

The typed phrase is confirmation, not authentication. The live Environment
must have a Founder-controlled required reviewer, prevent self-review enabled,
administrator bypass disabled, and a custom deployment branch policy containing
only `main`. Those Settings changes are Founder-approved actions outside this
PR; the workflow verifies them at activation time.

No migration, production data repair, credential change, or billing/entitlement
operation is authorized by this activation input.

## Live settings still outside this PR

This PR does not change GitHub Rulesets, branch protection, repository secrets,
credentials, or Environment reviewers. If `production-activation` has no
required reviewer, has self-review enabled, permits administrator bypass, or is
not restricted to `main`, activation fails closed with a hard boundary error.
The Founder must configure the required reviewer and branch policy in live
Settings before any held production activation can proceed.
