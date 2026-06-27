# Incident Policy Engine

> **Purpose:** Policy-driven self-healing — compress decision steps, optimize recovery path, override naive state→action mapping.  
> **Precedence:** **POLICY > STATE > SIGNAL** (policy modifies execution; does not replace inference authority)  
> **Resolver input:** STATE + CONTEXT → POLICY → FINAL_ACTION  
> **Loop:** [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) · **Baseline actions:** [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md)

---

## Policy hierarchy

```
SIGNAL (observe)
  ↓
STATE (inference — logical classification)
  ↓
POLICY (execution constraint + path optimization)  ← this document
  ↓
FINAL_ACTION (shortest auditable recovery path)
  ↓
deploy.yml / runbook (execution only)
```

| Layer | Role | Authority |
|-------|------|-----------|
| **SIGNAL** | Raw observations | None — input only |
| **STATE** | Logical incident classification | Inference engine |
| **POLICY** | Path compression + safety override | Policy engine (this doc) |
| **FINAL_ACTION** | What to execute | Policy-resolved; auditable |
| **Execution** | SSH deploy, rollback, restore | `deploy.yml` only |

**Policy layer is an execution modifier, not a decision authority.** It cannot invent STATE or bypass OPERATIONAL_CONSTRAINTS.

---

## Policy types

### P0 — Safety-first policy (highest priority)

**Trigger (any):**

- Signal `rollback_unsafe` **OR**
- Signal `db_anomaly` with data-loss risk **OR**
- CONTEXT `migration_irreversible = true`

**Effect:**

- **ALWAYS** choose RECOVER path (backup → restore / migrate rollback)
- **NEVER** CONTAIN via deploy rollback
- Skip TRIAGE wait — immediate FINAL_ACTION = `recover_db`

**Audit field:** `policy_applied=P0_safety_first`

---

### P1 — Fast recovery policy

**Trigger (all):**

- Signal `system_down` **AND**
- NOT `rollback_unsafe` **AND**
- NOT `db_anomaly` **AND**
- CI not blocking hotfix (`ci_failure` absent **OR** prod down overrides CI-only triage)

**Effect:**

- **Skip TRIAGE** — compress loop: OBSERVE → INFER → **CONTAIN** directly
- FINAL_ACTION = `rollback_deploy` (revert PR → deploy.yml **OR** re-run last successful deploy)
- Max path length: 5 steps (see SH-3)

**Audit field:** `policy_applied=P1_fast_recovery`

---

### P2 — Minimal intervention policy

**Trigger (all):**

- Single subsystem signal only (`partial_degradation` **OR** isolated `ci_failure` with prod health OK)
- CONTEXT `user_facing_impact = false` (core flows: login, RFID, today schedule OK)
- NOT `multi_subsystem`

**Effect:**

- **VERIFY first** — FINAL_ACTION = `verify_only` (health + smoke)
- **Defer execution** — no deploy.yml until VERIFY fails → re-infer
- If VERIFY pass → jump to RESOLVE (SH-1)

**Audit field:** `policy_applied=P2_minimal_intervention`

---

### P3 — Cascade suppression policy

**Trigger:**

- ≥2 signal IDs active simultaneously (`multi_subsystem` **OR** conflicting signals)

**Effect:**

- **Collapse** to single root-state: highest-priority signal wins (inference P0–P7 order)
- **Single** FINAL_ACTION — no multi-action chains in one loop iteration
- Suppress secondary actions until primary completes + re-observe

**Audit field:** `policy_applied=P3_cascade_suppression` · `root_signal=<id>`

---

## Policy resolver

### Rule 1 — Highest priority wins

Evaluate policies in order: **P0 → P1 → P2 → P3**.  
First matching policy **overrides** the inference engine ACTION table.

### Rule 2 — Conflict resolution

If two policies could match → choose **safest**:  
**data-loss prevention (P0) > uptime (P1) > minimal intervention (P2) > cascade tidy (P3)**

### Rule 3 — Fallback (legacy mode)

If no policy matches → FINAL_ACTION = inference engine ACTION table for current STATE.

---

## STATE + CONTEXT → POLICY → FINAL_ACTION

**CONTEXT fields** (collect at observe step):

| Field | Values |
|-------|--------|
| `user_facing_impact` | true / false (core flows) |
| `migration_irreversible` | true / false |
| `incident_repeat_count` | integer (same signal fingerprint, 24h window) |
| `ci_blocks_hotfix` | true / false |

### Resolution table (deterministic)

| STATE | CONTEXT | Winning policy | FINAL_ACTION |
|-------|---------|----------------|--------------|
| any | `rollback_unsafe` or P0 trigger | **P0** | `recover_db` |
| CONTAIN / TRIAGE | `system_down`, no DB risk, CI OK | **P1** | `rollback_deploy` |
| TRIAGE | single subsystem, no user impact | **P2** | `verify_only` |
| any | ≥2 signals | **P3** | root signal → single action |
| * | no policy match | fallback | inference ACTION table |

### FINAL_ACTION → execution mapping

| FINAL_ACTION | Execution | deploy.yml? |
|--------------|-----------|-------------|
| `recover_db` | RUNBOOK §3c + OPERATIONS_RUNBOOK §P | Maybe (schema rollback only) |
| `rollback_deploy` | revert PR → merge → deploy **OR** re-run successful deploy | **Yes** |
| `verify_only` | curl health + post-merge-smoke | No |
| `contain_freeze` | freeze writes + CEO LINE | No |
| fallback ACTION | per inference table | per STATE |

---

## Self-healing shortcut rules (global)

### SH-1 — Rollback success short-circuit

If FINAL_ACTION = `rollback_deploy` **and** post-execution health = OK **and** core flows OK:

→ **Skip remaining loop** → STATE = **RESOLVE**  
→ Document MTTR; do not re-enter TRIAGE

### SH-2 — Repeat incident auto-escalate

If `incident_repeat_count` > 3 (same signal fingerprint within 24h):

→ STATE = **ESCALATED_FAILURE** (Override Mode)  
→ CEO LINE mandatory  
→ Policy engine frozen until human documents root cause

### SH-3 — Policy path compression

If any policy P0–P3 matches:

→ **NEVER** run full 7-step loop — use **adaptive 4–5 step** model only:

```
OBSERVE → INFER STATE → APPLY POLICY → EXECUTE → VERIFY → (optional re-loop)
```

Skip explicit ASSIGN + MAP steps — policy resolver emits FINAL_ACTION directly.

---

## Auditability (mandatory)

Every loop iteration MUST log:

```
T0, signal_ids[], inferred_STATE, context{}, policy_applied, FINAL_ACTION, execution_ref, outcome
```

Store in incident MTTR note (`RUNBOOK_ROLLBACK.md` §4) or CHANGELOG `ops:` line.

---

## Forbidden

| Invalid | Correct |
|---------|---------|
| Policy chooses STATE | Inference chooses STATE; policy chooses FINAL_ACTION |
| Policy bypasses P0 safety | P0 always wins |
| Ad-hoc "fastest path" | Must map to P1–P3 or fallback table |
| Policy triggers undeclared execution | FINAL_ACTION table only |
