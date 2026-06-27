# Incident Runtime Loop

> **Policy-driven self-healing loop (docs-driven).** Adaptive 4–5 steps when policy matches; 5–6 otherwise.  
> **Policy:** [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) · **Inference:** [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) · **Entry:** [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md)

---

## Loop model (adaptive)

### Policy-compressed path (default when P0–P3 matches — SH-3)

```
OBSERVE → INFER STATE → APPLY POLICY → EXECUTE → VERIFY → (optional re-loop)
         └─ 5 steps ─┘                              └─ RESOLVE or ESCALATED_FAILURE
```

### Full path (no policy match — fallback legacy mode)

```
OBSERVE → INFER STATE → APPLY POLICY (fallback) → RESOLVE FINAL ACTION → EXECUTE → VERIFY → (optional re-loop)
```

---

## Step detail

| Step | Input | Output | Doc |
|------|-------|--------|-----|
| **1. Observe** | Alerts, `curl`, `gh run list` | Signal IDs + CONTEXT fields | INCIDENT_START_HERE Step 1 |
| **2. Infer state** | Signals + `T0` | STATE (logical classification) | INCIDENT_INFERENCE_ENGINE |
| **3. Apply policy** | STATE + CONTEXT | `policy_applied`, FINAL_ACTION | INCIDENT_POLICY_ENGINE resolver |
| **4. Resolve final action path** | FINAL_ACTION | Execution plan (single action) | Policy + inference fallback table |
| **5. Execute** | FINAL_ACTION (if deploy-eligible) | deploy.yml / runbook invoked | RUNBOOK_ROLLBACK, deploy.yml |
| **6. Verify** | Execution outcome | Pass/fail; SH-1 short-circuit | VERIFY → RESOLVE or re-loop |
| **7. Optional loop** | New symptoms | Re-observe or terminal | SH-2 may force ESCALATED_FAILURE |

**Steps 3–4 replace former "MAP ACTION + EXECUTE" split.** Policy may override state→action mapping.

---

## Self-healing shortcuts

| Rule | Effect |
|------|--------|
| **SH-1** | Rollback restores health → skip loop → **RESOLVE** |
| **SH-2** | Same incident >3× in 24h → **ESCALATED_FAILURE** |
| **SH-3** | Policy matched → use 5-step compressed path only |

---

## Terminal conditions

| Outcome | STATE | Next |
|---------|-------|------|
| Success (incl. SH-1) | **RESOLVE** | Exit loop |
| Unrecoverable / SH-2 | **ESCALATED_FAILURE** | Override Mode — CEO LINE |
| VERIFY fail | Re-loop from OBSERVE | Re-infer + re-apply policy |

---

## Mode interaction

| Mode | When | Who assigns STATE / FINAL_ACTION |
|------|------|----------------------------------|
| **Inferred Mode** (default) | Normal incident | Inference + policy resolver |
| **Override Mode** | ESCALATED_FAILURE only | Human documents override |

---

## Single-page operator sequence

1. Open [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) — observe
2. Infer STATE — [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md)
3. Apply policy — [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) → FINAL_ACTION
4. Execute if FINAL_ACTION requires — Step 3 runbook paths
5. Verify → RESOLVE, re-loop, or ESCALATED_FAILURE

**Max docs during incident:** INCIDENT_START_HERE + INCIDENT_POLICY_ENGINE (inference inline via policy table).

---

## Precedence reminder

**POLICY > STATE > SIGNAL** — STATE classifies; POLICY optimizes and constrains execution.
