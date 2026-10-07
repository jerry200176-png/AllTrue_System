# Risk-Based Merge Policy

**Version:** 1.8.1
**Effective:** 2026-08-29 (Founder T0–T3 autonomy decision; supersedes the prior solo-mode R2/R3 merge wording)
**Owner:** Founder / CTO Agent  
**Status:** Canonical  
**Founder Decision:** 2026-07-18 — risk-tiered approvals; **not** universal Founder rubber-stamp  
**Founder Decision:** 2026-08-29 — T0/T1 work is autonomous after required gates; reversible T2 may auto-deploy after exact-target CI, rollback readiness, and deterministic scope checks; T3/protected work may be prepared autonomously but stops before protected execution or activation for Founder approval. This decision supersedes the prior solo-mode R2/R3 merge wording. Fleet policy remains the general capability table; this AllTrue overlay retains stricter product safety boundaries.

## Purpose

Preserve autonomous delivery for reversible changes while keeping a Founder gate at the T3 protected boundary.
**Forbidden:** inventing a second Agent identity to approve your own PR.

## Risk classes

| Class | Examples | Merge requirements |
|-------|----------|-------------------|
| **R0 / T0** | Docs, generated evidence, radar run artifacts, INDEX links — **no** production behavior, permissions, workflow execution, dependencies, or data | Required checks and docs/link checks; Agent may merge and close evidence-backed issues. |
| **R1 / T1** | Display-only UX; isolated bugfix; no migration; no authz/billing/deploy change | Required CI, regression test, review, and rollback statement; Agent may merge and close when evidence is sufficient. |
| **R2 / T2** | Reversible scheduling/runtime changes without a protected boundary | Exact-target required CI, rollback readiness, documented risk/production-verification plan, and resolved bot/reviewer threads; Agent may merge/deploy automatically when deterministic classification stays non-protected. |
| **R3 / T3** | Production data repair; destructive migration; privilege expansion; financial correction; security boundary; backup/restore; mass recalculation; protected product direction | Agent may prepare implementation, tests, dry-run, Repair Manifest, recovery plan, and evidence package. Stop for Founder approval before production activation, mutation/repair, migration/schema cutover, billing/entitlement semantics, identity/authz, destructive action, backup restore, security-sensitive credential change, or major product/brand direction. |

## Approval continuity within the authorized task

A Founder approval applies to the concrete actions, targets, intended result and
recovery boundary approved in the current task. The Agent records that scope and
continues its necessary preparation, merge, deployment, verification and approved
cleanup without asking the Founder to approve each step again. A pause/resume,
session restart, routine conflict resolution or a new CI-tested release SHA does
not by itself expire that approval; exact-target checks and scope comparison still
run for the final artifact.

Ask again only when a new protected action was excluded from the approved scope,
the target/financial result/recovery boundary materially changes, live data no
longer matches the approved outcome, or the authorization is genuinely unclear.
For an approved data repair, derive the immutable Manifest from a fresh signed
snapshot and verify it against the approved outcome; preparing that Manifest is
not a second conversational approval gate. Code-only approval does not authorize
historical data mutation. Never invent, approve as another person, or bypass a
required GitHub Environment reviewer, POP approval, backup/recovery check or
executor control. Report a machine gate that needs Founder interaction once with
the exact artifact and reason, and continue independent authorized work.

This clarifies how existing explicit authorization persists; it grants no new
unapproved capability and changes no production, security or money-path gate.

## How to classify (PR author)

1. Pick the **highest** class that applies to any file or behavior in the PR.  
2. Generate the declaration from the actual branch diff with
   `scripts/governance/pr_declaration.py`, then put the resulting
   `Risk-Class: R0|R1|R2|R3` and `Autonomy-Tier: T0|T1|T2|T3` in the PR body.
3. If unsure between R1/R2, choose **R2/T2**. If any protected boundary applies, choose **R3/T3**.

## Enforcement (current + target)

| Mechanism | Role |
|-----------|------|
| PR template + machine declaration gate | Generated declaration; missing, malformed, or understated values fail before merge |
| Required status checks on `main` | Always on (existing branch protection) |
| CODEOWNERS | Review routing for high-risk paths; not a blanket T2 executor gate |
| Data Repair Gate / Repair Manifest | **R3/T3** preparation and protected execution evidence |
| Capability Registry | Who may merge / dispatch |
| This policy + Merge SOP | Human/Agent behavior contract |

Read-only `production-case-dump` probe cases (a PR touching only `.github/workflows/production-case-dump.yml` with no write, trigger, secret, ssh or env change in the diff) are T2 (Agent merges after green CI + review); writes remain T3 Founder GO.

**Not required:** GitHub “all PRs need Founder approving review.” T0–T2 use risk-appropriate review and required checks; T3 uses a Founder decision at the protected action boundary, not a blanket PR approval rule.

## Autonomous delivery path

For same-repository, non-draft PRs, `.github/workflows/presubmit.yml` and
`.github/workflows/auto-merge-safe.yml` independently evaluate
the diff from the base revision using `scripts/governance/autonomy_gate.py`.
Only an exact, declared, machine-validated T0/T1 result can enable GitHub
server-side squash auto-merge. GitHub still waits for every required status
check and branch rule, and the workflow rechecks the PR head SHA immediately
before requesting auto-merge.

### Land queue

Agents and humans add label `queue` instead of racing `gh pr update-branch` or `--auto`. `.github/workflows/land-queue.yml` (script `.github/scripts/land-queue.mjs`, base-branch code only, never PR code) processes the oldest queued PR: BEHIND -> update-branch; DIRTY or failed required check -> remove label with one comment (hidden marker per head SHA and reason); CLEAN with all required checks green -> `gh pr merge --squash` without `--admin`. With strict up-to-date off it first tests 2-4 queued PRs together on a `chore/land-queue-batch-*` branch (see CLAUDE.md, Land queue) and lands them only from a green batch. It adds no gate: the ruleset still decides. GitHub's native merge queue is not used because it is unavailable here (a `merge_queue` ruleset probe returned 422).

After merge, `deploy.yml` remains the only application production executor. Its
deploy and principal-rotation jobs, together with the guarded repair workflows,
share the non-cancelling `alltrue-production-side-effects-v2` concurrency lock;
preflight/classification runs do not occupy that side-effect queue. The deploy
workflow compares the exact production manifest SHA to current `main` and uses
`classify_activation_scope`: tests, docs, Exo metadata, and the read-only
convergence scheduler do not turn an ordinary runtime release into a manual
activation. T0/T1/T2 deploys do not reference the protected
`production-activation` environment; exact-SHA, required CI, preflight,
health/smoke, rollback, and fail-closed behavior remain mandatory.

Control-plane-only changes are reported as `control-plane-verified` once merged
to `main`; they do not require an application runtime deployment and are never
reported as `production-verified`. A mixed control-plane/application change
remains an application release and is classified from its full effect.

Reversible T2 changes with successful exact-target CI, rollback readiness, and
non-protected scope may auto-deploy. Missing or contradictory deterministic
evidence is ambiguous and stays held. T3, unknown classifications, production executor
changes, security/data boundaries, and irreversible operations stay held for
risk-appropriate review or the protected Founder boundary. Only
Founder-required/T3 activation references `production-activation`; all supported events use the same
static policy: Founder required reviewer, self-review allowed, administrator
bypass disabled, and main-only deployment branch policy. Workflow-dispatch typed
confirmation remains only for exceptional manual phases; it is not a second
normal approval path. No fake reviewer or admin bypass is introduced.

Founder 1A (2026-10-07): a deploy run skips the manual approve and uses the reviewer-less,
main-only `production-auto` Environment only when `autonomy_gate.evaluate_founder_go_range`
(see its docstrings for the exact evidence and GO token) accepts every PR between the
production manifest SHA and the target, under both the deployed and the target policy,
again right before the executor. Anything else keeps the unchanged `production-activation`
reviewer gate, and the run summary names the PRs that lack a GO.

This governance change itself is T3: it must pass the governance cool-off and
protected review process before its new capability is used in production.

### Activation classification by effect

For production activation, a sensitive path is a signal for inspection, not an
automatic T3 decision. The deterministic classifier uses three outcomes:

| Activation class | Machine rule | Result |
|---|---|---|
| `routine` | T0/T1/T2 behavior remains unchanged; no protected effect is found | Existing automatic path, with T2 exact-target CI and rollback evidence |
| `guarded-sensitive` | Sensitive runtime path, inspectable read-only diff, at most 3 sensitive files and 240 changed code lines, and no protected effect | Machine minimum T2; existing exact-SHA, rollback, health, critical-smoke, production-verification and automatic abort/rollback path |
| `founder-required` | Control plane, migration/repair, entitlement/deduction, auth trust boundary, billing/ledger semantics, privilege/credential/privacy boundary, uninspectable diff, or boundedness failure | T3/protected; hold for Founder approval |

Generated historical release-note bundles are excluded from current-effect
marker scanning. This removes false T3 classifications caused by old words in a
generated asset while preserving the real business/security operation when the
changed code expresses it. Unknown or missing evidence fails closed.

### Parallel agents

- Before starting: run `node scripts/pr-overlap.mjs` (or read the PR's `<!-- pr-overlap -->` sticky comment). If another open PR touches the same files, coordinate with that session or wait. Prefer small PRs (under ~400 lines) that merge fast.
- Landing: add label `queue`. Don't loop `update-branch`, `--auto` or custom merge scripts.
- No stacked PRs: branch from main after the dependency merges.
- Before merging an agent PR, read every `-` line of `git diff origin/main...HEAD`; nothing outside the PR's scope may be removed (2026-10-06 PR-C2 #3631 stale-copy revert).
- Release notes: change fragments only (`docs/changes/…`). Never edit `CHANGELOG.md`, the generated JS or the exemption lists; `phpstan-baseline.neon` may only shrink.
- Deploys: only `deploy.yml`. A range whose every PR is R0-R2 or carries a Founder GO deploys on its own CI; otherwise it waits for the release train (Founder 1A/3A, 2026-10-07).

## Review checklist (R2/T2)

R2/T2 requires exact-target required CI, rollback readiness, a documented
risk/production-verification checklist, and resolved bot/reviewer threads. A
second human or AI verifier is not a prerequisite in the single-Founder model.
The implementing Agent remains responsible for the final evidence and may
merge autonomously when no T3/protected action is involved.

## T3/protected boundary

Required checks do not authorize protected execution. The Agent may prepare the
complete evidence package, but must stop before the protected action and request
Founder approval with the exact action, worst credible downside,
rollback/reversibility, and post-action verification.

## Rollback

Workflow/control-plane changes require the Founder decision at merge
authorization. Once merged, the workflow revision is effective; the
production Environment gate protects only later production side effects and
cannot make a merged control-plane change pending.

Every R1+ PR must state rollback in one of: revert commit, feature flag off, prior deploy SHA, or data rollback command (R3).

## Review topology (#876)

This repo currently has **one** human maintainer (Jerry), who is not a universal approval queue. GitHub-level `required_approving_review_count` stays at **0**. T0–T2 use deterministic required checks and risk-appropriate review; T3 requires a Founder decision at the protected action boundary.

**What changes when a second maintainer joins** (do this switch explicitly, not implicitly):

| Setting | Solo mode (current) | Multi-maintainer mode (switch to when a second person can review) |
|---|---|---|
| `required_approving_review_count` (ruleset `main-protection`) | `0` | `1` |
| `require_code_owner_review` | `false` | `true` — CODEOWNERS becomes a real blocking gate, not just a review request |
| T2 review | Risk-appropriate review is advisory; no second identity is required for automatic delivery | A human second maintainer may add review assurance, but is not required by the executor contract |
| T3 boundary | Founder decision before protected action; review does not replace the gate | Same protected boundary, with the additional human review if ruleset policy later requires it |
| `dismiss_stale_reviews_on_push` | `false` | `true` — a stale approval shouldn't survive a force-push-equivalent re-push |

**How to switch**: update ruleset `main-protection` via `gh api repos/OWNER/REPO/rulesets/{id} -X PUT` with the new `pull_request` rule parameters above, then update this table's "current" column and bump this doc's version. Do not silently enable required review without updating this doc — the whole point of this section is that the switch is a visible, deliberate decision, not a drift.

## Related

- Fleet merge procedure: [portfolio-ops `docs/fleet-merge-policy.md`](https://github.com/jerry200176-png/portfolio-ops/blob/main/docs/fleet-merge-policy.md)  
- [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
