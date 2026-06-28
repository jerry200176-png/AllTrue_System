> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Decision Intelligence Layer (DIL)

> **Core principle**: *Execution enforces. Intelligence decides.*  
> **Companion**: [`execution-layer.md`](execution-layer.md) (muscles) · [`engineering-system.md`](engineering-system.md) (governance)

---

## 1. Purpose

The Decision Intelligence Layer **does not execute changes**. It evaluates PRs and system state to produce:

- Risk scores and levels
- Change classification (SAFE / DEPLOY / RISKY)
- Blast radius and coupling analysis
- Release strategy recommendations
- Pre-deploy simulation results

**Philosophy**: *Execution prevents mistakes. Intelligence prevents bad decisions.*

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Decision Intelligence Layer                  │
├─────────────────────────────────────────────────────────────────┤
│  INPUTS                                                          │
│  · PR diff / file paths (GitHub API)                             │
│  · production version.json (via release-check.sh)                │
│  · last 20 release-exec-audit.jsonl entries                      │
│  · CI status (GitHub checks)                                     │
├─────────────────────────────────────────────────────────────────┤
│  PROCESSING MODULES                                              │
│  1. Change Classification Engine                                 │
│  2. Blast Radius Analyzer                                        │
│  3. Historical Pattern Matcher (audit log)                       │
│  4. Drift Sensitivity Analyzer                                   │
│  5. Cost Impact Estimator (AI + CI minutes)                      │
├─────────────────────────────────────────────────────────────────┤
│  OUTPUT                                                          │
│  · Strict JSON decision document                                 │
│  · Simulation result (SAFE / WARNING / DANGEROUS)              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Execution Layer (release-exec.sh)            │
│  Validates · Blocks · Merges · Deploys (CEO approval only)       │
└─────────────────────────────────────────────────────────────────┘
```

| Component | Role |
|-----------|------|
| **`scripts/decision-engine.sh`** | Main entry: `analyze --pr N` |
| **`scripts/lib/decision_analyze.py`** | Deterministic scoring engine |
| **`scripts/risk-model.json`** | Weighted risk model (versioned) |
| **`scripts/decision-simulate.sh`** | Pre-deploy impact simulation |
| **`scripts/release-orchestrator.sh`** | Combines DIL + execution validate |

---

## 3. Relationship with Execution Layer

| Layer | Metaphor | Can merge? | Can deploy? |
|-------|----------|------------|-------------|
| **Decision Intelligence** | Brain (think) | ❌ | ❌ |
| **Execution** | Muscles (act) | ✅ (CEO + approval env) | ✅ (CEO + approval env) |

**Flow**:

1. Agent or developer opens PR.
2. Run `./scripts/decision-engine.sh analyze --pr N` → decision JSON.
3. Run `./scripts/decision-simulate.sh --pr N` → simulation result.
4. Run `./scripts/release-orchestrator.sh --pr N` → combined recommendation.
5. CEO runs `./scripts/release-exec.sh validate` then `merge` / `deploy` with approval env vars.

The execution layer **never** reads DIL output automatically — humans/agents use DIL to inform whether to proceed.

---

## 4. Rules

| Rule | Enforcement |
|------|-------------|
| Never merge | DIL scripts have no `gh pr merge` |
| Never deploy | No workflow dispatch, no SSH |
| Never modify code | Read-only git/gh/curl |
| Only analyze and recommend | JSON output only |
| AI is advisory | DIL informs; CEO + release-exec decide |
| Prefer safety | Scoring is max-biased toward worst-case categories |
| Reproducible | Same PR + same risk-model.json → same score |

---

## 5. Commands

```bash
# Core analysis (strict JSON)
./scripts/decision-engine.sh analyze --pr 937

# Pre-deploy simulation
./scripts/decision-simulate.sh --pr 937

# Combined control-plane view
./scripts/release-orchestrator.sh --pr 937
```

---

## 6. Risk model

Weights live in [`scripts/risk-model.json`](../scripts/risk-model.json):

| Category | Weight | Examples |
|----------|--------|----------|
| `payment_change` | 0.95 | Invoice, tuition, billing |
| `auth_change` | 0.90 | AuthController, middleware |
| `db_migration` | 0.85 | migrations/ |
| `session_logic` | 0.80 | ClassSession, deduction |
| `attendance_logic` | 0.75 | RFID, StudentSingIn |
| `ai_prompt_change` | 0.60 | .cursor/rules, AGENTS.md |
| `frontend_ui` | 0.20 | Vue components |
| `docs_only` | 0.00 | docs/*.md |

Modifiers (drift, CI failure, audit blocks) add to baseline score.

---

## 7. Output schema (decision-engine)

```json
{
  "pr_id": "937",
  "risk_score": 0.62,
  "risk_level": "high",
  "change_class": "DEPLOY",
  "blast_radius": ["session_logic", "calendar"],
  "recommended_strategy": "staged_release",
  "rollback_complexity": "medium",
  "confidence": 0.85,
  "reasoning": ["..."],
  "next_actions": ["..."]
}
```

**Strategies**: `direct_merge` | `staged_release` | `canary` | `block`

---

## 8. Engineering OS stack (complete)

| Layer | Question answered | Key artifact |
|-------|-------------------|--------------|
| **Governance** | What are the rules? | `engineering-system.md` |
| **Observability** | What is production truth? | `release-check.sh` |
| **Decision Intelligence** | Should we do this? How? | `decision-engine.sh` |
| **Execution** | Can we do this now? | `release-exec.sh` |
| **Audit** | What happened? | `release-exec-audit.jsonl`, `version.json` |

---

## 9. Related documents

- [`execution-layer.md`](execution-layer.md)
- [`release-flow.md`](release-flow.md)
- [`production-truth-model.md`](production-truth-model.md)
- [`ai-agent-policy.md`](ai-agent-policy.md)
