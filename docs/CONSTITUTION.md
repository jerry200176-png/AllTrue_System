---
owner: Principal Architect (CEO delegate)
status: ratified
supersedes: none
review_cycle: quarterly
last_reviewed: 2026-06-27
---

# AllTrue Engineering Constitution (Level 0 — Highest Engineering Law)

> This is the **apex** of engineering governance. Every ADR, SOP, CI rule, PR review, deployment, standard, and feature **must derive from and conform to** this document. Where any lower artifact conflicts with the Constitution, the Constitution wins and the lower artifact is amended.
>
> This document defines *authority and precedence*. The concrete architecture rules live one level down in the **[Architecture Governance Handbook](RULE_ARCHITECTURE_GOVERNANCE.md)** (L1); decisions in **[ADRs](adr/README.md)** (L2); processes in **SOPs** (L3); standards in **[Standards](#l4-pointers)** (L4); enforcement in **[fitness functions](../scripts/arch-fitness-check.mjs)** (L5).

---

## Article I — Engineering Philosophy
1. **Correctness over simplicity over speed.** A change that is fast and simple but lets a business fact have two owners is rejected.
2. **State integrity is the product.** This is a system of record for money, schedules and contractual session-units; its primary value is that those facts are *true and singular*.
3. **Make illegal states unrepresentable.** Prefer structural prevention (one writer, one owner, invariants in aggregates) over detect-and-reconcile.
4. **Automate enforcement.** Humans must not police what a machine can. Every rule that can be a check, is a check (L5).
5. **Reversibility is non-negotiable.** Every change is revertable or has a tested rollback.

## Article II — Core Values
Truthful state · Single ownership · Determinism · Reversibility · Auditability · Least surprise.

## Article III — Decision Hierarchy & Rule Precedence
Precedence, highest first. A lower level may **refine** but never **contradict** a higher one.

```
L0  Constitution (this doc)            ← apex; amend only via Article VIII
L1  Architecture Governance Handbook   ← RULE_ARCHITECTURE_GOVERNANCE.md (principles, invariants, ownership, events)
L2  ADRs                               ← docs/adr/*  (immutable decisions; supersede, never edit)
L3  SOPs                               ← process (feature/migration/deploy/rollback/incident/break-glass…)
L4  Standards                          ← coding/testing/review/observability/security/perf/versioning
L5  Automation                         ← CI fitness functions + invariant tests (the executable form of L0–L4)
```
**Precedence rule:** on conflict, the higher level governs. P0 red lines (`.cursorrules` / `p0-gate.mdc`) are L0-equivalent safety law and are never overridden by convenience at any lower level.

## Article IV — Authority Model
| Role | Authority |
|---|---|
| **Constitution (this doc)** | Final authority on architecture & process precedence |
| **Principal Architect** | Owns L0/L1; approves ADRs, ownership changes, fitness-baseline raises |
| **Context Owner (CODEOWNER)** | Owns one bounded context's code, invariants, events |
| **CI Decision Kernel** | Deterministic admission gate; required checks + fitness functions |
| **Engineer (human/AI)** | Proposes changes that conform; cannot self-grant exceptions |
No actor may **both** make a change and waive the rule that governs it. Exceptions follow Article VII only.

## Article V — Definition of Ownership
1. **Every business fact has exactly one owning context** that is its sole writer. The authoritative list is the **[Ownership Registry](OWNERSHIP_REGISTRY.md)**; a fact absent from it is an undocumented-ownership defect.
2. **Every code module, document, and process has a named owner** (frontmatter `owner:` / `CODEOWNERS`). Unowned artifacts are debt.
3. **Projections are not ownership.** Holding a read copy of a fact never grants the right to write it (see Handbook SO-1).

## Article VI — Engineering Ethics
1. **Report state truthfully** — never mark a thing done/resolved/deployed that isn't; surface failing tests and skipped steps.
2. **Do not silently mutate money or schedule data** without the owning process and (for billing) explicit human authority (`DIRECTOR_PAYMENT_ALERT_RULES`).
3. **No hidden decision engines** — every place that decides authorization, pricing, scheduling-validity or deployment-admission must be declared (Registry/Handbook). Inline shadow decisions are violations.
4. **Leave the campsite cleaner** — a fix in a recurring-defect family must strengthen the family invariant, not just patch the symptom.

## Article VII — Exception / Break-Glass Policy
An emergency may bypass a normal gate **only** under [`SOP: Break Glass`](RUNBOOK_BREAK_GLASS.md), which requires, in order:
1. A declared trigger (production outage / data-loss risk) — *convenience is not a trigger*.
2. An **immutable break-glass record** emitted **before** the action: actor, target SHA/artifact, CI status, reason.
3. The minimum action that resolves the emergency.
4. **Reconciliation obligation**: converge `origin/main` to the deployed state and restore the normal gate within the SLA; auto-open a P1 reconcile issue.
Break-glass never authorizes: force-push to `main`, running tests/migrations on prod data, or permanent artifact↔SoT divergence. (Grounded precedent: ADR-0009.)

## Article VIII — Governance Model & Amendment
- **Amending L0/L1**: requires a superseding **ADR** + Principal Architect ratification; the old text is retained as superseded, never silently edited (immutability).
- **Continuous fitness**: the L5 fitness functions run in CI; a red fitness check blocks merge exactly like a failing test.
- **Quarterly constitutional review**: re-audit ownership, ADR validity, fitness baselines; challenge prior assumptions; supersede incorrect decisions rather than preserve them.
- **Internal consistency is invariant**: every L1–L5 artifact must reference its parent; an artifact with no path back to the Constitution is a governance gap.

---

## L4 pointers (Standards live in existing canonical docs)
Coding/Review → `.cursorrules`, `module-frontend.mdc`; Testing → `module-test.mdc` + revert-proof rule (`bug-fix-plan.mdc §10`); Migration → `RULE_MIGRATION_COMPAT.md`; Observability/Logging/Metrics → `SRE_POLICY.md`, `LogSlowRequests`, Sentry; Security → `SECURITY.md`, `security/`; Versioning → `OPERATIONS_RUNBOOK.md §X` (CalVer) + policy-contract SemVer (Handbook). Gaps tracked in `docs/TECH_DEBT.md`.

## Cross-reference map
- L1: [RULE_ARCHITECTURE_GOVERNANCE.md](RULE_ARCHITECTURE_GOVERNANCE.md) · Ownership: [OWNERSHIP_REGISTRY.md](OWNERSHIP_REGISTRY.md)
- L2: [ADR index](adr/README.md)
- L3 SOPs: [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) · [RUNBOOK_ROLLBACK.md](RUNBOOK_ROLLBACK.md) · [RUNBOOK_BREAK_GLASS.md](RUNBOOK_BREAK_GLASS.md) · `bug-fix-plan.mdc` · [CHAT_BUG_SYSTEM.md](CHAT_BUG_SYSTEM.md §3.7)
- L5: [`scripts/arch-fitness-check.mjs`](../scripts/arch-fitness-check.mjs) · [`arch-fitness.yml`](../.github/workflows/arch-fitness.yml)

*Ratified founding revision — 2026-06-27.*
