# Incident State Machine

> **STATE = logical classification** (inference). **POLICY = execution constraint layer** (may override action).  
> **Controller:** [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) · **Policy:** [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md)  
> **Executor:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) · **Loop:** [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)  
> **Mandatory:** State is **inferred or escalated**, never arbitrarily chosen.

---

## Two-layer model

```
┌─────────────────────────────────────┐
│  STATE MACHINE (classification)      │  ← inference engine
│  DETECT → TRIAGE → … → RESOLVE       │
└─────────────────┬───────────────────┘
                  │ STATE + CONTEXT
                  ▼
┌─────────────────────────────────────┐
│  POLICY LAYER (execution modifier)   │  ← policy engine
│  P0 safety > P1 fast > P2 min > P3   │
└─────────────────┬───────────────────┘
                  │ FINAL_ACTION
                  ▼
            deploy.yml / runbook
```

**STATE does NOT guarantee action.** Policy may override state→action mapping.  
**STATE transitions remain inference-driven.** Policy does not assign STATE (except SH-2 → ESCALATED_FAILURE).

---

## State set

```
STATE ∈ { DETECT, TRIAGE, CONTAIN, RECOVER, VERIFY, RESOLVE, ESCALATED_FAILURE }
```

States are **logical labels**, not strictly linear — policy may skip TRIAGE (P1) or jump to RESOLVE (SH-1).

```
DETECT → TRIAGE ⇄ (P1 skip) → CONTAIN → RECOVER → VERIFY → RESOLVE
              ↓                      ↓ (P0)              ↓
         CONTAIN (forced)         RECOVER direct      ESCALATED_FAILURE
              ↓ (SH-2)                                  ↓ Override
         ESCALATED_FAILURE
```

---

## Deterministic transitions (classification layer)

| State | Entry condition | Exit condition | Allowed next states |
|-------|-----------------|----------------|---------------------|
| **DETECT** | Alert/report; `T0` recorded | Signals + CONTEXT collected | **TRIAGE** (or **CONTAIN** if P1 applies) |
| **TRIAGE** | Inference complete | Policy resolved **or** T+15 timeout | **CONTAIN**, **RECOVER**, **VERIFY** (P2) |
| **CONTAIN** | P1 / timeout / multi_subsystem | FINAL_ACTION started | **RECOVER**, **VERIFY** (SH-1) |
| **RECOVER** | P0 or recovery path | Health OK or restore done | **VERIFY** |
| **VERIFY** | Post-action check | Smoke pass/fail | **RESOLVE**, **ESCALATED_FAILURE**, re-loop |
| **RESOLVE** | VERIFY passed or SH-1 | Documentation done | **Terminal** |
| **ESCALATED_FAILURE** | SH-2 / verify fail / unrecoverable | Override documented | **TRIAGE**, **CONTAIN**, **RECOVER** (Override only) |

**Policy-aware skips (non-linear):**

| Policy | Transition shortcut |
|--------|---------------------|
| P1 | DETECT/TRIAGE → **CONTAIN** (skip TRIAGE wait) |
| P2 | TRIAGE → **VERIFY** (defer execution) |
| P0 | any → **RECOVER** (block CONTAIN rollback) |
| SH-1 | CONTAIN/RECOVER → **RESOLVE** (skip VERIFY loop) |
| SH-2 | any → **ESCALATED_FAILURE** |

---

## Auto-transition rule

1. Inference engine assigns **STATE** — immediate transition.
2. Policy engine resolves **FINAL_ACTION** — may differ from naive STATE→ACTION table.
3. Execute FINAL_ACTION if deploy-eligible.
4. Manual override forbidden except **ESCALATED_FAILURE**.

---

## Execution binding (policy-aware)

| FINAL_ACTION | deploy.yml? | Typical STATE |
|--------------|-------------|---------------|
| `recover_db` | Maybe | RECOVER |
| `rollback_deploy` | **Yes** | CONTAIN |
| `verify_only` | No | VERIFY / TRIAGE |
| fallback inference ACTION | per table | any |

**Only FINAL_ACTIONs that map to deploy paths may trigger `deploy.yml`.**  
`deploy.yml` MUST NOT assign STATE or select policy.

---

## Forced transitions

| Trigger | From | To | Source |
|---------|------|-----|--------|
| P0 match | any | RECOVER (+ block rollback) | Policy engine |
| P1 match | DETECT/TRIAGE | CONTAIN | Policy engine |
| P2 match | TRIAGE | VERIFY | Policy engine |
| P3 match | any | root-state action | Policy engine |
| SH-1 health OK | CONTAIN/RECOVER | RESOLVE | Policy engine |
| SH-2 repeat >3 | any | ESCALATED_FAILURE | Policy engine |
| T+15 timeout | TRIAGE | CONTAIN or RECOVER | Inference Rule 4 |

---

## Quick reference

1. [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) — adaptive loop  
2. [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) — POLICY > STATE > SIGNAL  
3. [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) — signals → STATE  
4. [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) — commands + runbook paths
