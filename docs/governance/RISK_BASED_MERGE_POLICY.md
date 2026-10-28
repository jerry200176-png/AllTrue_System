# Risk-Based Merge Policy

**Version:** 1.8.2
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

## Founder standing exception (2026-10-12; merge eligibility superseded by `FOUNDER_STANDING_DECISIONS.md` 2026-10-21 item 2(a))

The Founder pre-approved one R3 path ([`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) ①): R3 **code** that implements billing or permission rules the Founder has already decided may be **merged** by the Agent (merge only) when all of these hold: a focused regression test for each changed money/permission path added or updated in the PR and run in exact-head CI (not only the paths `high-risk-test-gate.yml` recognizes), exact-head required checks green, an independent review (Codex or a reviewer agent; the Agent checks this before adding `queue`, and the queue rechecks exact-head review evidence before squash), the full review gate of [`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-12 ④ satisfied (no open P0/P1, every regression this PR introduces fixed at any severity, and its required P2 remediation round done), and after release the direct affected-path production evidence required by [`EVIDENCE_CONTRACT.md`](./EVIDENCE_CONTRACT.md) for R2/R3. It does **not** cover new billing/entitlement semantics, an identity/authz boundary change, production data mutation or repair, production migration, credential rotation, or irreversible operations; those keep the Founder stop above. Deployment: since [`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-21（二）之二 an R3 application range activates through `production-auto` with no Founder click when `autonomy_gate.decide_r3_auto_activation` accepts it (final-head Codex review, no open threads, no deploy freeze, rollback evidence; no migration, data-repair, identity/credential or control-plane effect); any other range still ships through the release train / production-activation Founder approval.

A second pre-approved path ([`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-14（二）③) covers only the ADR-POP-015 implementation PRs that replace #3943/#3955 (the `pop_inapp_agent` ApiClient scope, policy approval with `approver_role='policy'`, and the three in-app lifecycle write strategies through `pop:execute-approved`). Those PRs may be **merged** by the Agent under the same conditions as above: focused exact-head regression tests for every affected permission/write path, including cross-campus and empty-scope requests failing closed; exact-head required checks green; independent review; and the full 2026-10-12 ④ review gate. That identity/scope is the one this decision approves; any other identity/authz boundary change stays excluded. Deployment and turning on `POP_INAPP_STANDING_POLICY` stay Founder-approved.

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

Agents and humans add label `queue` instead of racing `gh pr update-branch` or `--auto`. `.github/workflows/land-queue.yml` runs its script from the base branch and never executes PR code. It updates a BEHIND head; a conflict, failed required check, or unresolved review thread removes `queue` with one per-head explanation. Unknown thread metadata never merges. Green required checks and zero unresolved threads still need exact-head review evidence: missing or incomplete review waits as `review-pending`, retaining `queue`. Immediately before every serial or batch squash, the queue re-reads both threads and review for the tested head. With strict up-to-date off it first tests 2–4 eligible heads together, then lands only a green batch whose base and member heads remain unchanged. GitHub's native merge queue is unavailable for this user-owned repo (the `merge_queue` ruleset probe returned 422).

#### Final-head review evidence

The shared `.github/scripts/review-gate.mjs` accepts a trusted Codex summary only when it is completed for the full lowercase head SHA and explicitly has no unresolved findings. An actual independent review may instead be recorded by the exact repository-owner account with the full-head `independent-review:v1` pass marker when Codex has not reviewed that head. The marker is provenance, not a second identity. Current-head findings, an active or otherwise incomplete current-head Codex review, unreadable evidence, or malformed state fail closed; an owner marker cannot override them.

`review-gate.yml` publishes `review/final-head` from default-branch code. Its event and scheduled publishers share one non-cancelling lock with pending-run retention, and comment deletion re-evaluates withdrawn evidence. Before the main ruleset pins this check to the GitHub Actions app, native auto-merge is not enabled and existing unreviewed auto-merges are disabled; use the land queue. The Founder may add the required check only after the workflow is on main and has published its first sweep. This change makes no ruleset mutation. Batch heads have no PR review check; each member is checked in-process before squash.

Installing this control-plane gate remains a T3 merge decision. Existing scoped approvals and accepted review evidence remain valid under [approval continuity](#approval-continuity-within-the-authorized-task); this gate does not retroactively reopen completed approved work or grant new merge, deployment, identity, credential or production authority.

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
- Deploys: only `deploy.yml`. A range whose every PR is R0-R2, or an R3 range accepted by the R3 auto controls (2026-10-21（二）之二), deploys on its own CI; otherwise it waits for the release train, two windows a day at 12:17 and 18:17 Asia/Taipei (Founder 1A, 2026-10-07; batch approvals, 2026-10-14 — see `FOUNDER_STANDING_DECISIONS.md`).

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

When an Agent may merge an R3 PR is defined only in
[`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-21
item 2(a).

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
