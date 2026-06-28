> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# AllTrue Engineering System — Production-Controlled Governance

> **Status**: Active (2026-06-27)  
> **Owner**: CEO / Engineering  
> **Scope**: Workflow, release, AI, CI/CD policy — **not** product business rules.

This document is the **root contract** for how AllTrue is engineered. Product logic lives in code; **this system controls how code reaches production**.

---

## 1. Design goals

| Goal | Mechanism |
|------|-----------|
| Production is source of truth | [`production-truth-model.md`](production-truth-model.md) — L0–L2 over L3 git |
| AI cannot silently ship | [`ai-agent-policy.md`](ai-agent-policy.md) — branch-only writes, no merge/deploy |
| Deterministic releases | [`release-flow.md`](release-flow.md) — SAFE/DEPLOY/RISKY + CEO deploy gate |
| Runtime enforcement | [`execution-layer.md`](execution-layer.md) — `release-exec.sh` single executor |
| Decision intelligence | [`decision-intelligence-layer.md`](decision-intelligence-layer.md) — risk scoring & strategy |
| CI non-blocking | CI = observability; merge/deploy decoupled when minutes scarce |
| Full auditability | CHANGELOG + merge commits + `release-check.sh` artifacts |

**Principle**: *Control the system first, then control the code.*

---

## 2. Environment boundaries

| Environment | Location | Purpose | Writes |
|-------------|----------|---------|--------|
| **Dev** | WSL2 `~/alltrue` | All implementation | Agents + developers |
| **Staging** | *Not yet provisioned* (#868) | Pre-prod validation | Future |
| **Production** | Pi `/home/admin` | Live users | **CEO-controlled deploy only** |

**No implicit promotion**: merge to `main` ≠ production until deploy step completes and L1/L2 verify.

---

## 3. Branch strategy

| Branch | Lifetime | Who merges | Notes |
|--------|----------|------------|-------|
| `main` | Permanent | CEO only | Integration; must be deployable |
| `feat/*`, `fix/*`, `chore/*`, `hotfix/*` | Short | via PR → CEO | Agent work happens here |
| `dependabot/*` | PR-scoped | CEO | Dependency updates |
| Backup / long-lived experiment | ❌ Avoid | — | Close or merge within 2 weeks |

**Rules**:

- ⛔ No direct push to `main` (including agents).
- One PR per bounded context; prefer ≤400 lines (`module-industry-standards.mdc`).
- Docs batch on `chore/docs-*` with Release-Class **SAFE**.

---

## 4. Release rules

### 4.1 Classification

Every change uses [`release-flow.md`](release-flow.md) classes: **SAFE** | **DEPLOY** | **RISKY**.

### 4.2 Merge gate

- **CEO** approves merge (explicit instruction or GitHub merge by human).
- Required GitHub checks: **Platform Gate** + **Control Plane Verify** must pass for deployable PRs (ADR-001).
- Advisory failures may be waived only with documented CEO comment — **never** for PDP/control-plane failures.
- Agents propose; they do not merge.

### 4.3 Deploy gate (ADR-001 — single authority)

- **Only** `.github/workflows/deploy-production.yml` may SSH-deploy to production.
- Path: `merge main` → `Deploy Staging` (PDP verify + staging artifact) → `Deploy Production` (PDP verify + SSH).
- Legacy `deploy.yml` auto-deploy is **permanently disabled** (fail-closed).
- See [`docs/adr/ADR-001-single-production-authority.md`](adr/ADR-001-single-production-authority.md).
- Post-deploy: `./scripts/release-check.sh` + health smoke (inside Deploy Production job).

### 4.4 Version truth

- **Frontend live version**: `version.json` (`hash`, `t`) — see production-truth-model.
- **Backend live version**: Pi git HEAD (audit via SSH read-only).
- **Intent version**: `origin/main`.

Drift resolution is mandatory before claiming “fixed in production.”

**Principle**: *Governance defines rules. Execution enforces reality.*

---

## 4.5 Execution layer (enforcement)

Governance docs describe **what** should happen. The execution layer **blocks** what must not.

| Component | Purpose |
|-----------|---------|
| [`scripts/release-exec.sh`](../scripts/release-exec.sh) | Single entry: `validate`, `merge`, `deploy`, `rollback`, `status` |
| [`scripts/pre-commit-exec-guard.sh`](../scripts/pre-commit-exec-guard.sh) | Blocks main commits & unclassified critical paths |
| [`scripts/ai-write-gate.sh`](../scripts/ai-write-gate.sh) | Validates agent output markers; blocks deploy-ready language |
| [`.github/workflows/execution-gate.yml`](../.github/workflows/execution-gate.yml) | Advisory PR comment (non-blocking) |

Full architecture: [`execution-layer.md`](execution-layer.md).

---

## 4.6 Decision intelligence (advisory brain)

The Decision Intelligence Layer analyzes PRs **before** execution. It never merges or deploys.

| Component | Purpose |
|-----------|---------|
| [`scripts/decision-engine.sh`](../scripts/decision-engine.sh) | `analyze --pr N` → risk JSON |
| [`scripts/decision-simulate.sh`](../scripts/decision-simulate.sh) | Pre-deploy SAFE/WARNING/DANGEROUS |
| [`scripts/release-orchestrator.sh`](../scripts/release-orchestrator.sh) | Combines DIL + execution validate |

Full architecture: [`decision-intelligence-layer.md`](decision-intelligence-layer.md).

**CEO-only approval env vars** (never set by agents): `ALLTRUE_MERGE_APPROVED`, `ALLTRUE_DEPLOY_APPROVED`, `ALLTRUE_RISKY_APPROVED`, `ALLTRUE_DRIFT_ACK`.

---

## 5. CI/CD rules (ADR-001 enforced)

### 5.1 Production deploy authority

| Workflow | Role | Deploy authority? |
|----------|------|-------------------|
| `CI — PHPUnit Tests` | Test input | **No** |
| `Platform Gate` | PDP assemble on PR | **No** (merge gate) |
| `Deploy Staging` | PDP staging promotion on `main` | **No** |
| **`Deploy Production`** | **PDP verify + Pi SSH** | **Yes — ONLY path** |
| `Deploy to Pi` (`deploy.yml`) | Legacy block | **Disabled (exit 1)** |

### 5.2 Policy

| Job type | Blocking merge? | Blocking deploy? |
|----------|-----------------|------------------|
| Platform Gate + Control Plane Verify | **Yes** (deployable PRs) | N/A |
| PHPUnit / presubmit / high-risk gate | **Yes** (when required) | No |
| Advisory (Dependency Review, etc.) | CEO may waive with comment | No |
| `deploy.yml` | N/A | **Always blocked (ADR-001)** |

**Agents**: do not trigger legacy deploy paths. Use `Deploy Production` only.

### 5.3 What CI must never do alone

- Auto-merge to production Pi without CEO deploy approval (existing deploy.yml is tool, not authority).
- Auto-mark bugs resolved.
- Auto-modify `.env` or database.

---

## 6. Production sync rules

1. **Before claiming prod status**: run `./scripts/release-check.sh`.
2. **After merge (DEPLOY/RISKY)**: CEO schedules deploy; agent waits.
3. **After manual prod change**: merge branch to `main` within 24h or revert prod.
4. **Backend-only release**: expect BACKEND_ONLY_LAG — do not force frontend rebuild unless needed.
5. **In-app bug closure**: requires production verification (`CHAT_BUG_SYSTEM.md`).

---

## 7. AI agent permission model (summary)

Full detail: [`ai-agent-policy.md`](ai-agent-policy.md).

| Action | Agent | CEO |
|--------|-------|-----|
| Code on branch | ✅ | ✅ |
| Open PR | ✅ | ✅ |
| Merge PR | ❌ | ✅ |
| Deploy | ❌ | ✅ |
| Prod SSH edit | ❌ | ✅ (emergency) |
| Drift analysis (`release-check.sh`) | ✅ read-only | ✅ |

Risk tier T0–T3 required before implementation proposals.

---

## 8. Audit checklist (weekly, CEO or delegate)

- [ ] `./scripts/release-check.sh` — no PROD_AHEAD / DIVERGED
- [ ] Open DEPLOY PRs have Deploy-Approved or explicit defer
- [ ] Manual deploy exceptions recorded in CHANGELOG or issue
- [ ] `docs/SOP_MATURITY.md` 進行中狀態 current or empty
- [ ] No agent merged to `main` without CEO record

---

## 9. Document map

| File | Purpose |
|------|---------|
| [`production-truth-model.md`](production-truth-model.md) | L0–L4 truth layers, drift, rollback |
| [`release-flow.md`](release-flow.md) | SAFE/DEPLOY/RISKY, CI ignore rules, deploy steps |
| [`ai-agent-policy.md`](ai-agent-policy.md) | Agent prohibitions & risk classification |
| [`execution-layer.md`](execution-layer.md) | Runtime enforcement — release-exec, hooks, AI gate |
| [`decision-intelligence-layer.md`](decision-intelligence-layer.md) | Risk scoring, simulation, release strategy |
| [`engineering-system.md`](engineering-system.md) | This file — system overview |
| `scripts/release-check.sh` | Drift detector |
| `scripts/release-exec.sh` | Single executor for merge/deploy/validate |
| [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) §I | Legacy Pi deploy mechanics |
| [`SOP_MATURITY.md`](SOP_MATURITY.md) | Handoff / roadmap state |
| `.cursorrules` §P0 | Non-negotiable safety red lines |

---

## 10. Adoption (rollout)

1. **Immediate**: CEO and agents read this + run `release-check.sh` before release discussions.
2. **Next SAFE PR**: add INDEX links (done in same batch as these docs).
3. **Optional**: reference `Release-Class:` in `.github/pull_request_template.md` (future SAFE PR).
4. **#868**: staging environment reduces DEPLOY/RISKY validation pressure on prod.

---

## 11. Related historical incidents (why this exists)

- **2026-04-21** force push deploy overwrite (Incident A)
- **2026-04-22** Pi phpunit wiped DB (Incident C)
- **2026-06-20** Actions minutes + manual prod frontend ahead of main (#174 / `acf1251`)
- **Multi-agent** duplicate triage and conflicting prod/git assumptions

This system exists so those failures become **process impossibilities**, not memory exercises.
