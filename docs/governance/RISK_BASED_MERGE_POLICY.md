# Risk-Based Merge Policy

**Version:** 1.2.0  
**Effective:** 2026-07-18 (§Solo vs Multi-maintainer gate added 2026-08-08, #876; R2 independent-approval requirement dropped in solo mode 2026-08-14)  
**Owner:** Founder / CTO Agent  
**Status:** Canonical  
**Founder Decision:** 2026-07-18 — risk-tiered approvals; **not** universal Founder rubber-stamp  
**Founder Decision:** 2026-08-14 — in solo-maintainer mode, R2 no longer requires a separately-launched independent verifier; a documented self-review checklist + CI green is sufficient. R3 is unchanged (still requires Founder approval + independent technical verification) — this change is scoped to R2 only.  

## Purpose

Preserve autonomous delivery for low-risk changes while requiring independent review for medium/high/irreversible work.  
**Forbidden:** inventing a second Agent identity to approve your own PR.

## Risk classes

| Class | Examples | Merge requirements |
|-------|----------|-------------------|
| **R0** | Docs, generated evidence, radar run artifacts, INDEX links — **no** production behavior, permissions, workflow execution, dependencies, or data | CI green + docs/link checks; Agent may merge per Capability Registry |
| **R1** | Display-only UX; isolated bugfix; no migration; no authz/billing/deploy change | CI green + regression test + independent verifier note + rollback stated; Agent may merge |
| **R2** | Scheduling domain; billing/sessions/payment; authz; cron/jobs; deploy workflows; dependency major; schema migration; cross-campus data | CI green (all required status checks) + self-review checklist documented in PR body (risk evidence + rollback + production verification plan) + resolve any bot/reviewer threads; **solo mode:** implementing Agent may merge itself, no separate verifier required (Founder Decision 2026-08-14) |
| **R3** | Production data repair; destructive migration; privilege expansion; financial correction; security boundary; backup/restore policy; mass recalculation; enabling autonomous loop | **Founder** approval + independent technical verification + dry-run/simulation + recovery point + explicit execution gate (e.g. Repair Manifest) |

## How to classify (PR author)

1. Pick the **highest** class that applies to any file or behavior in the PR.  
2. Declare in PR body: `Risk-Class: R0|R1|R2|R3` (see PR template).  
3. If unsure between R1/R2, choose **R2**.

## Enforcement (current + target)

| Mechanism | Role |
|-----------|------|
| PR template `Risk-Class` | Declaration (CI warns if missing) |
| Required status checks on `main` | Always on (existing branch protection) |
| CODEOWNERS | Review routing for high-risk paths; does **not** replace R3 independence |
| Data Repair Gate / Repair Manifest | **R3** data writes only |
| Capability Registry | Who may merge / dispatch |
| This policy + Merge SOP | Human/Agent behavior contract |

**Not required:** GitHub “all PRs need Founder approving review” — rejected by Founder Decision 3 as a single-person bottleneck.

## Self-review checklist (R2)

R2 merges on the implementing Agent's own review — no separate verifier Agent or human required in solo mode. The self-review must still be a documented pass, not a rubber stamp: confirm and state in the PR body (PR template's Risk-Class/Checklist/Threat-Note sections) that you actually checked auth/scoping boundaries, migration reversibility, and rollback — not just "CI is green." Resolve every bot/reviewer thread (e.g. Cursor Bugbot) before merging; do not merge over an unresolved finding.

## Independent approval (R3)

Independent means a **different** review context than the implementer (another human, or a separately launched verifier Agent with no write mandate).  
Self-approval and same-chat “LGTM” do **not** count. This requirement now applies to **R3 only** — R2 uses the self-review checklist above (Founder Decision 2026-08-14).

## Rollback

Every R1+ PR must state rollback in one of: revert commit, feature flag off, prior deploy SHA, or data rollback command (R3).

## Solo vs Multi-maintainer review gate (#876)

This repo currently has **one** human maintainer (Jerry). GitHub-level `required_approving_review_count` is deliberately kept at **0** (see §Enforcement above, Founder Decision 3) — the Risk-Class self-review checklist + CODEOWNERS routing + required status checks are the substitute for a second reviewer at R0/R1/R2, and "independent" R3 review is satisfied by a separately-launched verifier Agent (no write mandate) or the Founder, per the "Independent approval (R3)" section above.

**What changes when a second maintainer joins** (do this switch explicitly, not implicitly):

| Setting | Solo mode (current) | Multi-maintainer mode (switch to when a second person can review) |
|---|---|---|
| `required_approving_review_count` (ruleset `main-protection`) | `0` | `1` |
| `require_code_owner_review` | `false` | `true` — CODEOWNERS becomes a real blocking gate, not just a review request |
| R2 review | Implementing Agent's own documented self-review checklist, no separate reviewer | A human second maintainer (or separately-launched verifier Agent), *in addition to* the self-review checklist |
| R3 "independent approval" | Separately-launched verifier Agent, or Founder | A human second maintainer, *in addition to* the Agent self-review checklist (both, not either/or) |
| `dismiss_stale_reviews_on_push` | `false` | `true` — a stale approval shouldn't survive a force-push-equivalent re-push |

**How to switch**: update ruleset `main-protection` via `gh api repos/OWNER/REPO/rulesets/{id} -X PUT` with the new `pull_request` rule parameters above, then update this table's "current" column and bump this doc's version. Do not silently enable required review without updating this doc — the whole point of this section is that the switch is a visible, deliberate decision, not a drift.

## Related

- [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
