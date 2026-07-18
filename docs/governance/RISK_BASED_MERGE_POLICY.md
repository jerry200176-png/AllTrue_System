# Risk-Based Merge Policy

**Version:** 1.0.0  
**Effective:** 2026-07-18  
**Owner:** Founder / CTO Agent  
**Status:** Canonical  
**Founder Decision:** 2026-07-18 — risk-tiered approvals; **not** universal Founder rubber-stamp  

## Purpose

Preserve autonomous delivery for low-risk changes while requiring independent review for medium/high/irreversible work.  
**Forbidden:** inventing a second Agent identity to approve your own PR.

## Risk classes

| Class | Examples | Merge requirements |
|-------|----------|-------------------|
| **R0** | Docs, generated evidence, radar run artifacts, INDEX links — **no** production behavior, permissions, workflow execution, dependencies, or data | CI green + docs/link checks; Agent may merge per Capability Registry |
| **R1** | Display-only UX; isolated bugfix; no migration; no authz/billing/deploy change | CI green + regression test + independent verifier note + rollback stated; Agent may merge |
| **R2** | Scheduling domain; billing/sessions/payment; authz; cron/jobs; deploy workflows; dependency major; schema migration; cross-campus data | ≥1 **independent** approval (different implementation context) + required checks + risk evidence + rollback + production verification plan |
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
| CODEOWNERS | Review routing for high-risk paths; does **not** replace R2/R3 independence |
| Data Repair Gate / Repair Manifest | **R3** data writes only |
| Capability Registry | Who may merge / dispatch |
| This policy + Merge SOP | Human/Agent behavior contract |

**Not required:** GitHub “all PRs need Founder approving review” — rejected by Founder Decision 3 as a single-person bottleneck.

## Independent approval (R2/R3)

Independent means a **different** review context than the implementer (another human, or a separately launched verifier Agent with no write mandate).  
Self-approval and same-chat “LGTM” do **not** count.

## Rollback

Every R1+ PR must state rollback in one of: revert commit, feature flag off, prior deploy SHA, or data rollback command (R3).

## Related

- [`docs/sop/MERGE_SOP.md`](../sop/MERGE_SOP.md)  
- [`docs/governance/COMPANY_CONSTITUTION.md`](./COMPANY_CONSTITUTION.md)  
- [`.github/pull_request_template.md`](../../.github/pull_request_template.md)  
- Capability Registry / Evidence Contract  
