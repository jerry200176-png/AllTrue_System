# Merge → Production Activation Runbook

**Status:** active when this file is on `main`.

**Execution authority:** `.github/workflows/deploy.yml` only. This runbook is
reference material; it does not execute production changes.

## Current trigger

`Deploy to Pi` currently receives a `workflow_run` event when `CI — PHPUnit
Tests` completes successfully on `main`. It remains the sole production
executor. The workflow separates merge from activation: merge safety remains
conservative, while activation evaluates only the undeployed runtime and
production-side-effect paths.

## State machine

```text
PR checks → merged on main → main CI success
                              ├─ exact-SHA runtime range is reversible T0/T1,
                              │  declaration is valid, and no protected
                              │  production-side-effect path is present
                              │    → automatic deploy + verify
                              └─ T2/T3, protected executor/security/data path,
                                 missing/invalid evidence, or classifier failure
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
| T1/R1 | Required checks, regression test, review, rollback evidence | Yes, with an explicit matching declaration and no protected production side effect | Existing `workflow_run` |
| T2/R2 | Required checks, independent review, rollback and production evidence | No | `merged-awaiting-activation` → Founder GO |
| T3/R3 | Prepared with protected-action evidence; no autonomous protected execution | No | Founder-controlled activation / mutation boundary |

The authoritative classifiers are both in `scripts/governance/autonomy_gate.py`.
`classify_scope` remains the conservative PR merge classifier. The deploy
workflow uses `classify_activation_scope` against the full production-manifest
SHA range: tests, docs, Exo metadata, and the read-only CI scheduler cannot
force a normal runtime release through the human gate, while `deploy.yml`,
migrations, repairs, auth/permission, billing, credential, destructive, and
other production-side-effect paths remain protected. A declaration may raise
effective risk but may never lower the machine-derived minimum; missing,
mismatched, or understated evidence is held.

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
tag. The `production-activation` GitHub Environment is attached only to this
manual protected path. In solo-Founder mode it is a deployment boundary, not
a second self-approval queue: it must have no required-reviewer rule, while
the workflow dispatch, exact typed confirmation, main-only policy, and
administrator-bypass prohibition remain mandatory and are verified live.

The typed phrase is confirmation, not authentication. GitHub limits
`workflow_dispatch` to repository users with write-level permission. The live
Environment must have no required-reviewer rule, administrator bypass
disabled, and a custom deployment branch policy containing only `main`. The
workflow verifies these settings at activation time; the exact typed phrase
is the Founder decision for the protected action in solo mode.

No migration, production data repair, credential change, or billing/entitlement
operation is authorized by this activation input.

## Live settings still outside this PR

This PR does not change GitHub Rulesets, branch protection, repository secrets,
or credentials. Automatic reversible application deploys do not reference the
Environment. For protected activations, if `production-activation` contains a
required reviewer rule, permits administrator bypass, or is not restricted to
`main`, activation fails closed with a hard boundary error. Solo mode requires
the live required-reviewer rule to be removed once, as a deliberate
Founder-authorized control-plane change; the Environment itself remains in
use for the protected production job.
