> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Self-Healing + Consistency Layer (SHCL)

> **Core principle**: *Detection is not enough. The system must converge.*  
> **Rule**: *Any system state divergence must converge or be explicitly approved.*

---

## 1. Purpose

The Self-Healing + Consistency Layer (SHCL) sits **above** Observability and cross-validates **all** Engineering OS layers:

| Layer | SHCL validates |
|-------|----------------|
| Governance | Drift vs documented truth model |
| Decision Intelligence | Class/strategy vs SOP |
| SOP Enforcement | Stamps vs required chain |
| Execution | Audit log vs SOP pass |
| Observability | `release-check.sh` drift vs layer states |

SHCL **does not** auto-deploy, auto-merge, or modify application code. It produces **analysis, consistency reports, and reconciliation plans** for CEO approval.

---

## 2. Architecture

```
Governance (rules, SOP)
        ↓
Decision Intelligence (risk, simulation)
        ↓
SOP Enforcement (runtime gate)
        ↓
Execution Layer (merge/deploy)
        ↓
Observability (release-check, drift)
        ↓
┌───────────────────────────────────────┐
│  SELF-HEALING + CONSISTENCY LAYER     │
│  self-heal-engine · consistency-check │
│  reconcile-plan                       │
└───────────────────────────────────────┘
        ↓
   Convergence plan (human / CEO executes)
```

**Data feeds**:

| Source | Feed mechanism |
|--------|----------------|
| `release-check.sh` | `SHCL_FEED=1` → `.cursor/.local/shcl/drift-latest.json` |
| `decision-engine.sh` | Writes `.cursor/.local/shcl/decision-pr-{N}.json` |
| `sop-enforce.sh` | Failures → `healing-triggers.jsonl` |
| Audit logs | `release-exec-audit.jsonl`, `sop-enforcement-audit.jsonl` |

---

## 3. Components

| Script | Purpose |
|--------|---------|
| **`scripts/self-heal-engine.sh analyze`** | Drift graph, severity, repair plan (JSON) |
| **`scripts/consistency-check.sh`** | Cross-layer PASS/FAIL |
| **`scripts/reconcile-plan.sh`** | Ordered PR/rollback plan (no execution) |
| **`scripts/lib/self_heal_analyze.py`** | Core analyzer |

---

## 4. Severity taxonomy

| Class | Typical cause |
|-------|----------------|
| **SYNCED** | L1/L3 aligned; no layer conflicts |
| **MINOR_DRIFT** | MAIN_AHEAD, BACKEND_ONLY_LAG |
| **MAJOR_DRIFT** | SOP/decision mismatch; partial chain |
| **CRITICAL_CONFLICT** | PROD_AHEAD, DIVERGED, exec without SOP |

---

## 5. Healing strategies

| Strategy | When | Approval |
|----------|------|----------|
| **forward-fix** | PROD_AHEAD — merge prod work into main | CEO |
| **rollback** | DIVERGED / failed deploy | CEO |
| **freeze** | CRITICAL_CONFLICT + SLO impact | CEO + SRE_POLICY |
| **manual_review** | MINOR/MAJOR drift | CEO |

---

## 6. Commands

```bash
# Full system analysis
./scripts/self-heal-engine.sh analyze

# Deep PR cross-validation (live sop + decision calls)
SHCL_DEEP=1 ./scripts/self-heal-engine.sh analyze

# Cross-layer consistency
./scripts/consistency-check.sh

# Reconciliation plan (does not execute)
./scripts/reconcile-plan.sh
```

---

## 7. JSON output (self-heal-engine)

```json
{
  "status": "SYNCED | DRIFT_DETECTED | CRITICAL_CONFLICT",
  "severity": "low | medium | high | critical",
  "severity_class": "SYNCED | MINOR_DRIFT | MAJOR_DRIFT | CRITICAL_CONFLICT",
  "drift_sources": [],
  "conflicts": [],
  "recommended_action": "auto_PR | rollback | manual_review | freeze",
  "approval_required": "CEO | none",
  "proposed_repair_plan": [],
  "risk_score": 0.05,
  "confidence": 0.85,
  "drift_graph": { "nodes": {}, "edges": [] }
}
```

---

## 8. Engineering OS completion

| Stage | Capability |
|-------|------------|
| Governance | Defines law |
| Decision | Scores risk |
| SOP | Enforces process |
| Execution | Acts on approval |
| Observability | Measures truth |
| **SHCL** | **Detects divergence + proposes convergence** |

---

## 9. Related documents

- [`current-engineering-sop.md`](current-engineering-sop.md)
- [`production-truth-model.md`](production-truth-model.md)
- [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)
- [`SRE_POLICY.md`](SRE_POLICY.md)
