# Contradiction Registry

> **Conflict resolution:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) **always wins.**  
> If two docs disagree → follow contract I1–I5; demote the conflicting doc to reference-only.

---

## Registered contradictions

| ID | Conflict | Wrong source says | Contract resolution | Repo resolution (2026-06-27) |
|----|----------|-------------------|---------------------|------------------------------|
| **K1** | INDEX vs INCIDENT | INDEX describes deploy steps, incident routing, or "SSOT" for ops | **I2:** INDEX = registry only. Incident stack decides. | **Resolved:** `docs/INDEX.md` demoted to pointer registry; no dual-authority table. |
| **K2** | POLICY vs STATE_MACHINE | STATE guarantees a specific ACTION | **I4:** Policy may override mapping; STATE = classification only. | **Resolved:** contract I4 + incident stack aligned. |
| **K3** | RUNBOOK vs deploy.yml | Runbook is deploy authority or can SSH-deploy independently | **I1:** Only `deploy.yml` executes. Runbook = helper. | **Resolved:** `RUNBOOK_ROLLBACK.md` banner REFERENCE ONLY. |
| **K4** | OPERATIONAL_CONSTRAINTS vs POLICY | Constraints override FINAL_ACTION or policy precedence | Constraints mirror I1–I5; **cannot override** policy engine. | **Resolved:** duplicate authority block removed; REFERENCE ONLY banner. |
| **K5** | SEVERITY_MATRIX vs INCIDENT | Severity doc decides incident course or escalation | **Demoted:** lookup table only; escalation from policy/INCIDENT rules. | **Resolved:** REFERENCE ONLY banner. |
| **K6** | MemPalace vs production | MemPalace stale/missing → production incident or health inference | **Frozen statement:** no incident authority, no SLO, no execution impact. | **Resolved:** frozen statement in INDEX + contract. |
| **K7** | ADR / untracked docs vs main | ADR-001, deploy-production.yml, execution-layer docs claim active authority | **I1 + C2:** Only committed `main` + contract. Untracked = ignore. | **Resolved:** HISTORICAL banners on ADR + execution-layer docs; `deploy-production.yml` marked NON-RUNTIME draft. |
| **K8** | DEPLOYMENT.md vs deploy.yml | Manual deploy as normal path | Setup reference only; production path = `deploy.yml` only. | **Resolved:** REFERENCE ONLY banner. |
| **K9** | deploy.yml vs INCIDENT | Deploy run outcome decides STATE or skips policy | **I4:** deploy executes FINAL_ACTION only; re-infer after observe. | **Resolved:** committed `deploy.yml` restored; no ADR fail-closed WIP in tree. |
| **K10** | Override vs inference | Undefined "emergency" manual state pick | **Explicit rule:** Override **only** in ESCALATED_FAILURE + documented + CEO LINE. | **Resolved:** contract I3 + INCIDENT stack explicit override gate. |
| **K11** | POP vs deploy.yml vs legacy repair workflows | Multiple execution paths (SSH repair workflows, Pi manual artisan) | **I1 v2:** POP Executor + `deploy.yml` only. Legacy workflows deprecated, not parallel authority. | **Resolved (2026-07-16):** ADR-POP-010; contract-version 2. |

---

## Resolution procedure

1. Identify conflict ID (K1–K10) or add new row
2. Apply contract invariant (I1–I5)
3. Demote losing doc to reference-only (add banner if missing)
4. Log in CHANGELOG `ops:` if production ops doc changed

---

## Sample conflict walkthrough

**Scenario:** INDEX workflow table says "Deploy to Pi rolls back on failure" and operator treats INDEX as deploy spec.

| Step | Action |
|------|--------|
| Detect | K1 — INDEX describing runtime behavior |
| Resolve | **I2** — read [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) for behavior; INDEX link only |
| Fix | INDEX row → `→ see deploy.yml` (no behavior prose) |

**Scenario:** Operator assigns P0 severity by gut feel without STATE/signal lookup.

| Step | Action |
|------|--------|
| Detect | K5 — implicit severity judgment |
| Resolve | SEVERITY_MATRIX lookup from STATE + signal ID |
| Fix | No escalation path from severity doc alone — follow policy SH-2 / INCIDENT Step 4 |

---

## Adding new conflicts

New row required when:

- A doc claims decision or execution authority outside contract
- Two incident docs give different FINAL_ACTION for same STATE+CONTEXT
- Untracked file appears in INDEX or incident flow

**Do not** add new authority layers to resolve conflicts — update contract + incident stack only.
