# Risk-Based Merge Policy

**Version:** 1.4.0  
**Effective:** 2026-07-18 (§Solo vs Multi-maintainer gate added 2026-08-08, #876; R2 independent-approval requirement dropped in solo mode 2026-08-14)  
**Owner:** Founder / CTO Agent  
**Status:** Canonical  
**Founder Decision:** 2026-07-18 — risk-tiered approvals; **not** universal Founder rubber-stamp  
**Founder Decision:** 2026-08-14 — in solo-maintainer mode, R2 no longer requires a separately-launched independent verifier; a documented self-review checklist + CI green is sufficient. R3 is unchanged (still requires Founder approval + independent technical verification) — this change is scoped to R2 only.  
**Founder Decision:** 2026-08-15 — the implementing Agent is the operator. Required GitHub checks are acceptance. After they are green, squash-merge R0–R3 (R3: Repair Manifest in the PR). Close issues, send task mail, dispatch committed workflows. Do not wait for a human click. Fleet table: [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md). This file classifies **risk** and AllTrue P0; it does not add a Founder rubber-stamp.  

## Purpose

Preserve autonomous delivery for low-risk changes while requiring independent review for medium/high/irreversible work.  
**Forbidden:** inventing a second Agent identity to approve your own PR.

## Risk classes

| Class | Examples | Merge requirements |
|-------|----------|-------------------|
| **R0** | Docs, generated evidence, radar run artifacts, INDEX links — **no** production behavior, permissions, workflow execution, dependencies, or data | CI green + docs/link checks; Agent may merge per Capability Registry |
| **R1** | Display-only UX; isolated bugfix; no migration; no authz/billing/deploy change | CI green + regression test + independent verifier note + rollback stated; Agent may merge |
| **R2** | Scheduling domain; billing/sessions/payment; authz; cron/jobs; deploy workflows; dependency major; schema migration; cross-campus data | CI green (all required status checks) + self-review checklist documented in PR body (risk evidence + rollback + production verification plan) + resolve any bot/reviewer threads; **solo mode:** implementing Agent may merge itself, no separate verifier required (Founder Decision 2026-08-14) |
| **R3** | Production data repair; destructive migration; privilege expansion; financial correction; security boundary; backup/restore policy; mass recalculation | Required checks green + Repair Manifest / execution package **in the PR** + dry-run/recovery point + independent verifier Agent (or documented solo self-review if no second agent is available) + implementing Agent squash-merges. No Founder click. Enabling a previously disabled self-dispatch loop stays a **machine ban**. |

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

Independent means a **different** review context than the implementer (a separately launched verifier Agent with no write mandate). Same-chat “LGTM” does **not** count. The Founder is not a reviewer. After that note and required checks, the **implementing** Agent squash-merges.

## Rollback

Every R1+ PR must state rollback in one of: revert commit, feature flag off, prior deploy SHA, or data rollback command (R3).

## Solo vs Multi-maintainer review gate (#876)

This repo currently has **one** human maintainer (Jerry), who is **not** an approval queue. GitHub-level `required_approving_review_count` stays at **0**. R0–R2: self-review + required checks. R3: Repair Manifest + separately-launched verifier Agent, then the implementer merges.

**What changes when a second maintainer joins** (do this switch explicitly, not implicitly):

| Setting | Solo mode (current) | Multi-maintainer mode (switch to when a second person can review) |
|---|---|---|
| `required_approving_review_count` (ruleset `main-protection`) | `0` | `1` |
| `require_code_owner_review` | `false` | `true` — CODEOWNERS becomes a real blocking gate, not just a review request |
| R2 review | Implementing Agent's own documented self-review checklist, no separate reviewer | A human second maintainer (or separately-launched verifier Agent), *in addition to* the self-review checklist |
| R3 "independent approval" | Separately-launched verifier Agent, then implementer merges | A second maintainer **or** verifier Agent, then implementer merges |
| `dismiss_stale_reviews_on_push` | `false` | `true` — a stale approval shouldn't survive a force-push-equivalent re-push |

**How to switch**: update ruleset `main-protection` via `gh api repos/OWNER/REPO/rulesets/{id} -X PUT` with the new `pull_request` rule parameters above, then update this table's "current" column and bump this doc's version. Do not silently enable required review without updating this doc — the whole point of this section is that the switch is a visible, deliberate decision, not a drift.

## Related

- Fleet merge procedure: [portfolio-ops `docs/fleet-merge-policy.md`](https://github.com/jerry200176-png/portfolio-ops/blob/main/docs/fleet-merge-policy.md)  
- [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
