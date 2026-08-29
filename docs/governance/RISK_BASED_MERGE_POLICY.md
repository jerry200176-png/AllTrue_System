# Risk-Based Merge Policy

**Version:** 1.5.0
**Effective:** 2026-08-29 (Founder T0–T3 autonomy decision; supersedes the prior solo-mode R2/R3 merge wording)
**Owner:** Founder / CTO Agent  
**Status:** Canonical  
**Founder Decision:** 2026-07-18 — risk-tiered approvals; **not** universal Founder rubber-stamp  
**Founder Decision:** 2026-08-29 — T0/T1 work is autonomous after required gates; T2 requires independent review, CI, and rollback evidence; T3/protected work may be prepared autonomously but stops before protected execution or activation for Founder approval. This decision supersedes the prior solo-mode R2/R3 merge wording. Fleet policy remains the general capability table; this AllTrue overlay retains stricter product safety boundaries.

## Purpose

Preserve autonomous delivery for low-risk changes while requiring independent review for T2 work and a Founder gate at the T3 protected boundary.
**Forbidden:** inventing a second Agent identity to approve your own PR.

## Risk classes

| Class | Examples | Merge requirements |
|-------|----------|-------------------|
| **R0 / T0** | Docs, generated evidence, radar run artifacts, INDEX links — **no** production behavior, permissions, workflow execution, dependencies, or data | Required checks and docs/link checks; Agent may merge and close evidence-backed issues. |
| **R1 / T1** | Display-only UX; isolated bugfix; no migration; no authz/billing/deploy change | Required CI, regression test, review, and rollback statement; Agent may merge and close when evidence is sufficient. |
| **R2 / T2** | Scheduling domain; billing/sessions/payment; authz; cron/jobs; deploy workflows; dependency major; schema migration; cross-campus data | Required CI, independent review, documented risk/rollback/production-verification plan, and resolved bot/reviewer threads; Agent may merge only when no protected Founder decision is involved. |
| **R3 / T3** | Production data repair; destructive migration; privilege expansion; financial correction; security boundary; backup/restore; mass recalculation; protected product direction | Agent may prepare implementation, tests, dry-run, Repair Manifest, recovery plan, and evidence package. Stop for Founder approval before production activation, mutation/repair, migration/schema cutover, billing/entitlement semantics, identity/authz, destructive action, backup restore, security-sensitive credential change, or major product/brand direction. |

## How to classify (PR author)

1. Pick the **highest** class that applies to any file or behavior in the PR.  
2. Declare in PR body: `Risk-Class: R0|R1|R2|R3` and `Autonomy-Tier: T0|T1|T2|T3` (see PR template).
3. If unsure between R1/R2, choose **R2/T2**. If any protected boundary applies, choose **R3/T3**.

## Enforcement (current + target)

| Mechanism | Role |
|-----------|------|
| PR template `Risk-Class` | Declaration (CI warns if missing) |
| Required status checks on `main` | Always on (existing branch protection) |
| CODEOWNERS | Review routing for high-risk paths; T2 requires independent review |
| Data Repair Gate / Repair Manifest | **R3/T3** preparation and protected execution evidence |
| Capability Registry | Who may merge / dispatch |
| This policy + Merge SOP | Human/Agent behavior contract |

**Not required:** GitHub “all PRs need Founder approving review.” T0–T2 use risk-appropriate review and required checks; T3 uses a Founder decision at the protected action boundary, not a blanket PR approval rule.

## Review checklist (R2/T2)

R2/T2 requires an independent review context, required CI, a documented risk/rollback/production-verification checklist, and resolved bot/reviewer threads. Policy-defined evidence is either a current-head GitHub `APPROVED` review from a distinct authorized identity or a current-head attestation from a separately launched verifier Agent whose identity and exact HEAD pass a trusted machine check. The implementing Agent remains responsible for the final evidence and may merge autonomously when no protected activation is coupled to the merge.

The current repository/CI adapter implements the verifier form through the existing Cursor Bugbot GitHub App: only a completed `success` check named `Cursor Bugbot`, with App slug `cursor`, App ID `1210556`, and the exact PR HEAD SHA is accepted. `.agent-session` manifests remain structural provenance only and repository-authored claims remain invalid. Missing, stale, neutral, failed, or foreign-App checks fail closed. This does not change the global approval count or require a second GitHub identity.

## T3/protected boundary

Independent review and required checks do not authorize protected execution. The Agent may prepare the complete evidence package and, once merge is decoupled from activation, may merge a T3 change after required evidence. It must stop before production activation, mutation/repair, migration/schema cutover, billing/entitlement semantics, identity/authz decisions, destructive action, backup restore, security-sensitive credential change, or major product/brand direction, and request Founder approval with the exact action, worst credible downside, rollback/reversibility, and post-action verification.

## Rollback

Every R1+ PR must state rollback in one of: revert commit, feature flag off, prior deploy SHA, or data rollback command (R3).

## Review topology (#876)

This repo currently has **one** human maintainer (Jerry), who is not a universal approval queue. GitHub-level `required_approving_review_count` stays at **0**. T0/T1 use required checks and risk-appropriate review; T2 requires independent review; T3 requires a Founder decision at the protected action boundary.

**What changes when a second maintainer joins** (do this switch explicitly, not implicitly):

| Setting | Solo mode (current) | Multi-maintainer mode (switch to when a second person can review) |
|---|---|---|
| `required_approving_review_count` (ruleset `main-protection`) | `0` | `1` |
| `require_code_owner_review` | `false` | `true` — CODEOWNERS becomes a real blocking gate, not just a review request |
| T2 review | Current-head GitHub approval from a distinct authorized identity, or the trusted exact-head Cursor Bugbot App check | A human second maintainer or separately-launched verifier Agent, plus implementing Agent evidence |
| T3 boundary | Founder decision before protected action; review does not replace the gate | Same protected boundary, with the additional human review if ruleset policy later requires it |
| `dismiss_stale_reviews_on_push` | `false` | `true` — a stale approval shouldn't survive a force-push-equivalent re-push |

**How to switch**: update ruleset `main-protection` via `gh api repos/OWNER/REPO/rulesets/{id} -X PUT` with the new `pull_request` rule parameters above, then update this table's "current" column and bump this doc's version. Do not silently enable required review without updating this doc — the whole point of this section is that the switch is a visible, deliberate decision, not a drift.

## Related

- Fleet merge procedure: [portfolio-ops `docs/fleet-merge-policy.md`](https://github.com/jerry200176-png/portfolio-ops/blob/main/docs/fleet-merge-policy.md)  
- [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
