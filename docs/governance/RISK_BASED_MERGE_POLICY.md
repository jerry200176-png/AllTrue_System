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
| **R3 / T3** | Production data repair; destructive migration; privilege expansion; financial correction; security boundary; backup/restore; mass recalculation; protected product direction | Agent may prepare implementation, tests, dry-run, Repair Manifest, recovery plan, and evidence package. Stop for Founder approval before production activation, mutation/repair, migration/schema cutover, billing/entitlement semantics, identity/authz, destructive action, backup restore, security-sensitive credential change, or major product/brand direction. Exceptions are defined only in [`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-28（二）之二 (not summarized here). When an Agent may *merge* an R3 PR is defined only in [`FOUNDER_STANDING_DECISIONS.md`](./FOUNDER_STANDING_DECISIONS.md) 2026-10-21 item 2(a). |

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

## Landing and delivery mechanics

Land queue, final-head review evidence, auto-merge, activation classification and
parallel-agent rules live only in [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md#land-queue-and-auto-delivery-mechanics).
This policy defines the tiers.

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
- [`docs/sop/DELIVERY_LIFECYCLE.md`](../sop/DELIVERY_LIFECYCLE.md) — stages, lanes and gatekeepers per risk class (this policy stays the only tier definition)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
