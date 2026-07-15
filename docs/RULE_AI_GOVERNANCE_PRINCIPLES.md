---
owner: arbiter:founder
governance_version: "1.0.0"
ttl_days: 365
retirement: supersede-via-ADR-or-Arbiter-PR
last_reviewed: 2026-07-15
review_cycle: as-needed
verifier: scripts/repo-governance-check.mjs
---

# RULE: AI Governance Principles

> **Status:** Accepted (Founder-facing operating constitution)  
> **Scope:** All humans and AI agents touching this repository  
> **Priority:** Lower AI cognitive load → delivery speed → long-term extensibility  
> **Non-goals:** Cosmetically clean GitHub; process theater; copying BigCo tools blindly  
> **Companion:** `docs/reviews/REPO_GOVERNANCE_DESIGN_REVIEW_2026-07-15.md`

---

## 1. One domain → one Source of Truth

For every knowledge domain there is **exactly one** SoT.  
Pointers may link to it; copies and “also true” restatements are forbidden.  
If conflict appears → register it in `docs/CONTRADICTION_REGISTRY.md` and demote the loser.

See the SoT Matrix in the Design Review. Do not invent a parallel matrix in chat.

## 2. Single AI entry

An agent’s **first intentional read** of project docs must start from **one entry**:

- Target: `docs/AI_ENTRY.md` (thin)  
- Transition: only the INDEX “AI Entry Card” section until AI_ENTRY exists  

It must **not** open `.cursorrules` + `AGENTS.md` + `CLAUDE.md` + full `INDEX.md` as four equal authorities.

Always-applied P0 rules may inject safety constraints; they are not a second product encyclopedia.

## 3. Before creating a document

Answer in the PR description (one line each):

1. What existing SoT should this update instead?  
2. Why is a new file required (new domain, new Diátaxis type, or size split)?  
3. Which Index/portal row will point to it?  
4. What will we **delete or demote** to prevent dual-sourcing?

If you cannot answer (1)–(4), **do not add the file**.

## 4. Merge updates the SoT

If a PR changes behavior, contracts, or operator truth:

- Update the **SoT** in the same PR (or a blocking follow-up labeled `docs-debt` within 48h).  
- CHANGELOG is an announcement lane, not architecture SoT.  
- PR/issue commentary is rationale crumb, not lasting authority.

## 5. Prefer delete / demote / generate over new narrative

Order of preference:

1. Delete stale text  
2. Demote to `docs/archive/` with banner  
3. Generate from code/workflows (routes, runner facts)  
4. Write new prose  

Kubernetes’s dual-source warning applies here: duplicated content ages twice as fast.

## 6. Lazy load by default

Agents load:

1. Entry (≤ ~1k tokens target)  
2. Task SoT chapter only  
3. Code  

Forbidden by default: whole `AI_REGRESSION_LESSONS.md`, whole `OPERATIONS_RUNBOOK.md`, whole `TECH_DEBT.md`, whole `SOP_MATURITY.md`, `.cursor/plans/**` cover-to-cover.

## 7. Architecture decisions are rare ADRs

Write an ADR only for architecturally significant choices (Nygard).  
Use lifecycle: Proposed → Accepted → Superseded/Deprecated.  
Do not ADR bugfixes, copy, or sprint plans.

## 8. Trunk is sacred; branches are disposable

Strategy: **Trunk-Based Development with short-lived PR branches** (≤ 2–3 days).  
No parallel long-lived develop/release/env branches without an explicit ADR.  
Contaminated or >300-file unexplained diffs are closed and recreated — never “reviewed through.”

## 9. Issues track work; docs track truth; discussions explore

- Issues: actionable work with lifecycle → close or archive  
- Docs/ADR: lasting truth  
- Discussions (or short-lived issues): exploration without SoT weight  

Closed bugs remain searchable history; they are not open handoffs.

## 10. Measure cognitive load; reject process when ROI is negative

If a governance artifact increases startup tokens or decision forks without reducing incidents or rework, **remove it**.  
Big-company practices are hypotheses, not obligations.

---

### Compliance check (PR authors / agents)

- [ ] Touched knowledge domain has a single SoT update  
- [ ] No new file without the four questions in §3  
- [ ] No second entry encyclopedia  
- [ ] No plan/issue cited as runtime authority  
- [ ] Branch is short-lived / PR is reviewable size  
