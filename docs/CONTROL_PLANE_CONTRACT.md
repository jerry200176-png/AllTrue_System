# Control Plane Contract

> **contract-version:** 2  
> **Single runtime spec for AllTrue production operations.**  
> **Supersedes:** all other docs on conflict. See [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md).  
> **Audit:** [`CONTROL_PLANE_AUDIT.md`](CONTROL_PLANE_AUDIT.md)  
> **POP (Production Operations Platform):** [`docs/pop/adr/README.md`](pop/adr/README.md) — Architecture Freeze 2026-07-16. Amends I1 per [ADR-POP-010](pop/adr/ADR-POP-010-contract-i1.md).

---

## Formal model (frozen)

```
SIGNAL → INFERENCE → STATE → POLICY → FINAL_ACTION → EXECUTION
  │         │          │        │            │              │
observe   classify   label   optimize    decide what    POP Executor + deploy.yml
```

| Stage | Authority file | Role |
|-------|----------------|------|
| Observe | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) | Collect signals + CONTEXT |
| Inference | [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) | SIGNAL → STATE |
| State | [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) | Transition rules |
| Policy | [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) | STATE + CONTEXT → FINAL_ACTION |
| Loop | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) | Orchestration |
| Execution | POP Executor + [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) | **Approved** production operations (see I1) |

**Registry (no runtime logic):** [`INDEX.md`](INDEX.md)

---

## Invariants (hard rules — non-negotiable)

| ID | Invariant |
|----|-----------|
| **I1** | **Only POP Executor and `deploy.yml` may execute production changes.** POP Executor runs **approved** catalog operations (repairs, backfills, reconciles, etc.) via self-hosted runner or claimed token. `deploy.yml` runs **`application-deploy`** (code, migration, frontend). One-off SSH repair workflows are **deprecated** (K11). Runbooks describe steps; they do not execute. |
| **I2** | **INDEX is registry only** — no decision logic, no deploy behavior description, no authority. |
| **I3** | **INCIDENT docs define ALL runtime decisions** — inference, state, policy, FINAL_ACTION. No other doc may decide incident course. |
| **I4** | **INCIDENT policy selects FINAL_ACTION; POP policy gates operation approval** — incident policy does not bypass POP approval for data operations. Execution layer runs only approved POP operations or `deploy.yml` deploy. |
| **I5** | **No document may introduce a new authority layer** — no ADR, runbook, audit, or constraint doc becomes decision authority. |

---

## Decision authority (exactly one stack)

These files **together** are the decision system (I3):

1. [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md)
2. [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md)
3. [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md)
4. [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md)
5. [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)

**Precedence within stack:** POLICY > STATE > SIGNAL (policy modifies FINAL_ACTION only).

---

## Execution authority (POP + deploy)

| Authority | Role |
|-----------|------|
| **POP Executor** | Approved production operations per [`operations/catalog.yaml`](../operations/catalog.yaml) — repairs, backfills, reconciles, mitigations |
| [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) | **`application-deploy`** — code deploy, migration, frontend build |

No runbook, incident doc, or INDEX entry may override, bypass, or replace these paths. Legacy case-specific repair workflows (e.g. `173-supersede-repair.yml`) are **deprecated** — see K11.

**POP governance:** [`docs/pop/adr/README.md`](pop/adr/README.md). Approval SoT is database (ADR-POP-002), not Git history.

---

## Demoted components (reference only)

These files **MUST NOT** be treated as decision authority:

| File | Permitted use | Forbidden |
|------|---------------|-----------|
| [`SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) | Lookup table: STATE + signal → severity label | Decision authority; free severity judgment |
| [`SMOKE_TEST_RUNBOOK.md`](SMOKE_TEST_RUNBOOK.md) | Documents checks invoked by deploy/VERIFY | Deploy logic; incident decisions |
| [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) | Execution helper: how to run FINAL_ACTION | Rollback decision; override policy |
| [`OPERATIONAL_CONSTRAINTS.md`](OPERATIONAL_CONSTRAINTS.md) | Invariant checklist (mirrors I1–I5) | Override policy engine or this contract |
| [`OPERATIONAL_CONSISTENCY_CHECK.md`](OPERATIONAL_CONSISTENCY_CHECK.md) | Manual drift audit | Runtime decisions |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Setup reference | Deploy authority |
| [`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md) | Forbidden ops list | Incident routing |

---

## Explicit outcomes (no hidden decision systems)

| Situation | Explicit rule | Outcome |
|-----------|---------------|---------|
| Unknown symptom | Inference Rule 2 | STATE = TRIAGE |
| T+15 unstable | Inference Rule 4 + Policy P0/P1 | FINAL_ACTION = rollback or recover_db |
| Data-loss risk | Policy P0 | FINAL_ACTION = recover_db; no deploy rollback |
| Repeat incident >3×/24h | Policy SH-2 | STATE = ESCALATED_FAILURE |
| Override needed | **Only** ESCALATED_FAILURE | Documented override + CEO LINE — no other manual state pick |
| Severity label | SEVERITY_MATRIX lookup | Derived from STATE + signal — not subjective |

**Forbidden phrases (invalid at runtime):** operator intuition fallback · manual override except emergency (undefined) · implicit severity judgment · feeling-based triage.

**Valid replacement:** explicit rule → deterministic outcome (inference + policy tables).

---

## MemPalace (frozen statement)

> **MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.**

---

## Contract change process

Changes to I1–I5 or the formal model require:

1. Update this file first
2. Update [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md)
3. Run [`CONTROL_PLANE_AUDIT.md`](CONTROL_PLANE_AUDIT.md) checklist
4. PR to `main` — no working-tree or untracked docs as active truth
