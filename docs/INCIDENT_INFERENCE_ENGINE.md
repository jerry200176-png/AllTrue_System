# Incident Inference Engine

> **Purpose:** Deterministic mapping `symptoms → STATE`. **Actions resolved by** [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) (POLICY > STATE > SIGNAL).  
> **Controller:** [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) · **States:** [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) · **Loop:** [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)

---

## Input signals (text-based)

Collect from Step 1 checks (`curl` health, `gh run list`, user report). Match **first matching row** in priority order (top wins).

| Priority | Signal ID | Match condition |
|----------|-----------|-----------------|
| P0 | `rollback_unsafe` | Rollback safety exception true — DB corruption risk, irreversible migration, data loss amplification |
| P1 | `multi_subsystem` | ≥2 of: health fail, core flow fail, CI fail, deploy fail, DB anomaly |
| P2 | `system_down` | Health ≠ `{"status":"ok",...}` **OR** login / RFID / today schedule broken |
| P3 | `db_anomaly` | Wrong/corrupt data, bad deductions/billing, migration suspicion |
| P4 | `deploy_failure` | Latest `Deploy to Pi` run = failure (within 24h) |
| P5 | `ci_failure` | Required CI checks failing |
| P6 | `partial_degradation` | Health OK but non-core feature broken |
| P7 | `unknown_error` | No row P0–P6 matches |

**MemPalace signals are out of scope** — do not infer production state from MemPalace.

---

## Inference rules

### Rule 1 — Direct mapping

| Signal ID | Inferred STATE | Rationale |
|-----------|----------------|-----------|
| `rollback_unsafe` | **RECOVER** | Recovery override — no deploy rollback |
| `multi_subsystem` | **CONTAIN** | Risk escalation — stop bleeding |
| `system_down` | **CONTAIN** | Production impaired — contain immediately |
| `db_anomaly` | **RECOVER** | Data/schema path — restore known good |
| `deploy_failure` | **TRIAGE** | Classify deploy vs code vs DB before contain |
| `ci_failure` | **TRIAGE** | Classify prod impact (prod OK → fix CI; prod down → re-infer) |
| `partial_degradation` | **TRIAGE** | Assess core-flow impact within 15 min |
| `unknown_error` | **TRIAGE** | Default unknown → triage |

### Rule 2 — Unknown symptom

If no signal matches P0–P6 → signal = `unknown_error` → **STATE = TRIAGE**.

### Rule 3 — Risk escalation

If `multi_subsystem` = true → **STATE = CONTAIN** (overrides TRIAGE from individual signals).

### Rule 4 — Time constraint (TRIAGE timeout)

If STATE = **TRIAGE** and `T0 + 15 min` elapsed and (not stable **or** root cause still unknown):

→ re-infer → **STATE = CONTAIN** (rollback) **unless** `rollback_unsafe` → **RECOVER**.

### Rule 5 — Execution failure escalation

If CONTAIN or RECOVER action completes but health still fail after VERIFY attempt:

→ **STATE = ESCALATED_FAILURE** → Override Mode only.

---

## State → action mapping (baseline — policy may override)

> **Policy precedence:** [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) FINAL_ACTION overrides this table when P0–P3 match.

| STATE | Baseline action | May trigger `deploy.yml`? |
|-------|-----------------|---------------------------|
| **DETECT** | Observe only | **No** |
| **TRIAGE** | Classify; apply Rules 1–4 | **No** |
| **CONTAIN** | Rollback or freeze | **Yes** (unless P0 blocks) |
| **RECOVER** | Restore known good | **Yes** |
| **VERIFY** | Smoke tests | **Yes** (verification only) |
| **RESOLVE** | Close incident | **No** |
| **ESCALATED_FAILURE** | CEO LINE + override | Per override |

**Hard rule:** Execution MUST follow **FINAL_ACTION** (policy-resolved or fallback table) — not ad-hoc choice.

---

## Symptom → state → policy → execution (closed loop)

```
signals → [Inference Rules 1–5] → STATE
STATE + CONTEXT → [Policy P0–P3, SH-1–SH-3] → FINAL_ACTION
FINAL_ACTION → deploy.yml OR runbook path
outcome → re-observe → loop until RESOLVE or ESCALATED_FAILURE
```

| Example symptom | STATE | Policy | FINAL_ACTION |
|-----------------|-------|--------|--------------|
| Deploy failed, health OK | TRIAGE | P2 | `verify_only` |
| System down, no DB risk | CONTAIN | P1 | `rollback_deploy` |
| DB corruption, rollback unsafe | RECOVER | P0 | `recover_db` |
| Health OK post-rollback | RESOLVE | SH-1 | skip loop → close |

---

## Severity binding (no free interpretation)

Lookup only — [`SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md):

| Inferred STATE | Default severity |
|----------------|------------------|
| CONTAIN, RECOVER, ESCALATED_FAILURE | P0 |
| TRIAGE + `system_down` or `multi_subsystem` | P0 |
| TRIAGE + `ci_failure` + prod OK | P1 |
| TRIAGE + `partial_degradation` | P2 |

Severity is **derived** from STATE + signal ID — not subjectively assigned.

---

## Forbidden operations

| Invalid | Replace with |
|---------|--------------|
| "Operator decides state" | Run inference engine → assign STATE |
| "Manual triage selection" | Signal match → Rule 1–5 |
| "Interpret severity freely" | STATE + signal → SEVERITY_MATRIX lookup |
| "Feeling-based rollback" | TRIAGE timeout Rule 4 → CONTAIN |

State is **inferred or escalated** (ESCALATED_FAILURE), never arbitrarily chosen.
