> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# SOP Enforcement Layer

> **Core principle**: *SOP is not documentation. SOP is executable policy.*  
> **Source SOP**: [`current-engineering-sop.md`](current-engineering-sop.md)  
> **Principle**: *SOP is law. Enforcement is reality.*

---

## 1. Purpose

The SOP Enforcement Layer converts rules in `docs/current-engineering-sop.md` into **runtime validation** that runs **before** the Decision Intelligence Layer and Execution Layer.

It does **not** merge, deploy, or modify application code.

---

## 2. Architecture

```
PR / commit / AI output
        │
        ▼
┌───────────────────┐
│  sop-enforce.sh   │  ← SOP gate (this layer)
└─────────┬─────────┘
          │ pass only
          ▼
┌───────────────────┐
│ decision-engine   │  ← advisory analysis (DEPLOY/RISKY required stamp)
└─────────┬─────────┘
          │
          ▼
┌───────────────────┐
│  release-exec.sh  │  ← merge/deploy executor
└─────────┬─────────┘
          │
          ▼
     Production
```

| Component | Role |
|-----------|------|
| **`scripts/sop-enforce.sh`** | Core engine — JSON pass/fail |
| **`scripts/sop-checklist.sh`** | Per-PR step checklist + `--run` stamps |
| **`scripts/lib/sop_enforce.py`** | Deterministic rule evaluation |
| **`pre-commit-exec-guard.sh`** | Runs `sop-enforce.sh --commit` first |

**State files**: `.cursor/.local/sop-enforcement/pr-{N}.json` (step completion stamps)  
**Audit log**: `.cursor/.local/sop-enforcement-audit.jsonl`

---

## 3. Enforcement rules (from current-engineering-sop.md)

| Rule | Enforced by |
|------|-------------|
| No direct commit on `main` | `sop-enforce --commit` |
| Branch naming `feat/fix/chore/hotfix/*` | `sop-enforce --commit`, PR mode |
| `Release-Class: SAFE\|DEPLOY\|RISKY` in PR body | PR mode |
| Release-Class aligned with diff inference | PR mode |
| PR summary / test plan / rollback (class-dependent) | PR mode |
| `decision-engine.sh analyze` before DEPLOY/RISKY merge/deploy | PR mode + stamps |
| `decision-simulate.sh` before RISKY merge/deploy | PR mode + stamps |
| `release-exec.sh validate` before merge action | PR `--action merge` |
| No deploy on SAFE class | PR `--action deploy` |
| `Deploy-Approved: yes` for deploy action | PR `--action deploy` |
| AI output markers (Release-Class, Risk-Tier, modules, summary) | `sop-enforce --ai` |
| No AI deploy/merge claims | `sop-enforce --ai` |

**SOP violation blocks execution** — `release-exec.sh` should only be invoked after SOP pass for the same PR.

**SOP applies equally to AI and humans** — same checks; AI commits may set `ALLTRUE_AI_COMMIT=1` to require handoff file.

**Bypass**: Only CEO emergency via `ALLTRUE_SOP_OVERRIDE=yes` or `ALLTRUE_EXEC_APPROVED=yes` (logged). Routine bypass is **not allowed**.

---

## 4. Commands

```bash
# PR validation (strict JSON)
./scripts/sop-enforce.sh --pr 937

# Before merge / deploy
./scripts/sop-enforce.sh --pr 937 --action merge
./scripts/sop-enforce.sh --pr 937 --action deploy

# With release-exec result
./scripts/release-exec.sh validate --pr 937 > /tmp/exec.json
./scripts/sop-enforce.sh --pr 937 --action merge --exec-json-file /tmp/exec.json

# Checklist
./scripts/sop-checklist.sh --pr 937

# Record automated steps
./scripts/sop-checklist.sh --pr 937 --run decision_engine
./scripts/sop-checklist.sh --pr 937 --run decision_simulate
./scripts/sop-checklist.sh --pr 937 --run release_exec_validate

# Local commit (pre-commit hook)
./scripts/sop-enforce.sh --commit

# AI handoff
./scripts/sop-enforce.sh --ai --file .cursor/.local/ai-handoff.md
```

---

## 5. JSON output contract

```json
{
  "status": "pass | fail",
  "violations": [],
  "sop_stage": "validated | partial | missing",
  "allowed_next_step": [],
  "pr_id": "937",
  "release_class": "DEPLOY",
  "checklist_completion": "5/9"
}
```

Exit code: `0` = pass, `1` = fail.

---

## 6. Layer stack (full)

| Order | Layer | Enforces |
|-------|-------|----------|
| 1 | **SOP Enforcement** | Process completeness |
| 2 | **Decision Intelligence** | Risk & strategy |
| 3 | **Execution** | Merge/deploy gates |
| 4 | **Audit** | JSONL + version.json |

---

## 7. Related documents

- [`current-engineering-sop.md`](current-engineering-sop.md)
- [`execution-layer.md`](execution-layer.md)
- [`decision-intelligence-layer.md`](decision-intelligence-layer.md)
- [`engineering-system.md`](engineering-system.md)
