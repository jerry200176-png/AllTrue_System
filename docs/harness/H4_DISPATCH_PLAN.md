# H4 Dispatch Plan — AllTrue Harness

**Status:** PLAN ONLY — awaiting Supervisor Plan Review  
**Date:** 2026-09-17T00:20:00Z  
**Branch (docs draft):** `chore/task-harness-h4-plan`  
**Base:** `origin/main` @ `f69b14ea…` (includes H3 squash merge [#3014](https://github.com/jerry200176-png/AllTrue_System/pull/3014) @ `51b4d30de…`)  
**Prerequisite:** H3 **ACCEPTED** — `/home/jerry/workspace/state/alltrue/H3_ACCEPTANCE.json`  
**Dispatch:** `/home/jerry/workspace/state/alltrue/DISPATCH_H4_PLAN.json` (`PLAN_ONLY`, `implementation_authorized=false`)  
**Author lane:** Night Shift Worker A  
**Non-goal this PR:** any H4 implementation code, production approve, second autonomy classifier, worktree-create agent runtime product

---

## 1. Objectives

H4 turns an H3 `PlanResult` into a **fail-closed, lease-fenced dispatch attempt** without re-deriving selection or inventing GoalContracts.

| Outcome | Non-outcome |
|---------|-------------|
| Revalidate PlanResult world-bind before any CAS | Conversational “just run it” |
| CAS acquire / renew / reclaim with `lease_id` + `fencing_token` + `task_id` | Steal via `task_id` alone |
| Consume only H3 handoff when `would_execute=true` | Second autonomy classifier |
| Deny-and-continue: typed reject leaves peers free | Multi-program tick product (H7) |
| Transition `READY → LEASED` only with lease evidence | Production / app / deploy |
| Evidence-backed failure modes for Plan Review tests | H5–H9; RFID; TrueFit flag activation |

**Success criterion (impl, after Plan Review ACCEPT):** Given a fixture `PlanResult` with world-bind fields and `would_execute=true`, H4 either (a) revalidates, CAS-acquires `required_leases`, records fencing identity, transitions task, or (b) rejects with a typed reason, rolls back partial acquires, and leaves peer READY work unblocked.

**This document is not approval to implement.**

---

## 2. Module boundaries

| Module | Owns | Must not own |
|--------|------|--------------|
| `scripts/harness/dispatch.py` (new) | PlanResult revalidation → CAS acquire set → state transition → DispatchResult | Risk reclassification; inventing GoalContract |
| `scripts/harness/leases.py` (existing) | CAS `acquire` / `renew` / `release` / `reclaim_stale` | Planner selection; governance tiers |
| `scripts/harness/planner.py` (H3) | Read-only `PlanResult` selection | Acquire / renew / reclaim / dispatch |
| `scripts/harness/governance_adapter.py` | Re-check paths via existing `autonomy_gate` only | Second classifier |
| `scripts/harness/contracts.py` | GoalContract load + fingerprint / scope bind | Minting Founder receipts |
| `scripts/harness/reconcile.py` | Optional world SHA helpers | Live `gh` CI observer (H6) |
| `scripts/harness/cli.py` | `dispatch` dry-run / apply (post-approval) | Silent founder-only apply |
| Session / `agent-start` | **Plan text only** for later bind of fencing into session | Product launcher in H4.0 unless Plan Review expands scope |

Reuse H2.1 CAS store APIs. Do not invent Redis/Temporal or a second lease table.

### 2.1 Lease module (CAS) — H4 consumption contract

| Op | Identity required | H4 use |
|----|-------------------|--------|
| `acquire(resource, task_id, worker, ttl)` | Creates `lease_id`, `fencing_token=1` | Initial hold of each `required_leases` key |
| `renew(..., lease_id, fencing_token, task_id, worker)` | All three | Heartbeat / resume; increments fencing |
| `release(..., lease_id, fencing_token, task_id)` | All three | Rollback partial acquire; terminal cleanup |
| `reclaim_stale(now)` | Conditional delete by identity+expiry | Pre-dispatch hygiene for expired rows only |

**A2 invariant (unchanged):** live ownership is never inferred from `holder_task_id` alone.

### 2.2 Deny-and-continue at dispatch boundary

- Rejecting one `PlanResult` must not mutate peer programs’ READY tasks or their leases.
- On `lease_conflict` / stale-plan, return typed `DispatchResult`; Supervisor/H3 may plan peers next.
- Do not mark unrelated tasks BLOCKED because this dispatch failed.

---

## 3. Handoff from H3 — PlanResult world-bind fields

H4 **consumes** H3 `PlanResult.to_dict()` and **never invents** a GoalContract after selection.

| Field | H4 revalidation rule |
|-------|----------------------|
| `would_execute` | Must be `true` else `plan_not_executable` |
| `goal_id` | Load live GoalContract; missing → fail closed |
| `goal_contract_fingerprint` | Must equal live contract fingerprint |
| `observed_main_sha` | If plan or caller supplies SHA, fail on diverge vs goal `subject_sha` / caller main when reconcile policy on |
| `input_snapshot_fingerprint` | Re-derive snapshot from current store+params; mismatch → `stale_snapshot` |
| `required_leases` | Must equal recomputed `required_resources(selected)` |
| `governance` | Re-run adapter classify on task paths; founder/deny → no apply |
| `plan_id` | Stable key for dispatch-attempt uniqueness |
| `effective_priority` / `aging_boost` | Audit / evidence only; do not re-rank at dispatch |
| `selected` / `program_id` | Task still READY; no program WIP conflict |
| `peer_candidates` | Hints only; H4 does not auto-dispatch peers in H4.0 |

**Hard rule (dispatch packet):** revalidate world-bind **before** any CAS or state transition.

---

## 4. State transitions

| Transition | Actor | Evidence |
|------------|-------|----------|
| *(none)* | `dispatch --dry-run` | DispatchResult JSON only |
| `READY → LEASED` | H4 apply after full acquire set | Lease rows + fencing identity on task/checkpoint |
| `LEASED → READY` | H4 rollback on mid-acquire / launch abort | Release with identity+fencing |
| Lease row create / fencing bump | `leases.acquire` / `renew` | Store CAS |
| Expired lease remove | `reclaim_stale` | Conditional CAS by identity+expiry |
| `LEASED → …` execution progress | Worker / later slices | Out of H4.0 minimal unless Review expands |
| Founder / production | Forbidden | No DecisionReceipt forgery |

H3 remains read-only (A4). Planner must not call acquire/renew/reclaim.

---

## 5. Dispatch algorithm (impl sketch — not authorized yet)

1. **Ingress:** accept PlanResult dict; refuse if `would_execute` is false.  
2. **Revalidate:** world-bind fields (§3); adapter governance re-check (no second classifier).  
3. **Optional reclaim:** `reclaim_stale` for expired only (does not steal live foreign leases).  
4. **Acquire loop:** deterministic order over `required_leases`; on busy → rollback acquired → `lease_conflict`.  
5. **Bind:** persist `lease_id` + `fencing_token` + `task_id` (+ worker) for renew/release.  
6. **Transition:** `READY → LEASED` with evidence.  
7. **Result:** `DispatchResult` with acquired leases or typed failure.  

Default CLI: dry-run. `--apply` only after Plan Review → `DISPATCH_H4_IMPL`.

Launcher / worktree-create / agent-start bind: **documented as follow-on** inside approved H4 impl if Review accepts; not required for CAS+revalidation core; not started in this PLAN PR.

---

## 6. Evidence schema

### 6.1 DispatchResult (proposed)

```python
@dataclass(frozen=True)
class DispatchResult:
    ok: bool
    reason: str                      # machine code
    plan_id: str
    task_id: str | None
    goal_id: str | None
    goal_contract_fingerprint: str | None
    observed_main_sha: str | None
    input_snapshot_fingerprint: str
    required_leases: list[str]
    acquired: list[dict]             # {resource_key, lease_id, fencing_token, task_id}
    would_mutate: bool               # dry-run: would have acquired
    governance: dict | None          # adapter snapshot; not a new classifier
```

### 6.2 Persist (optional H4.0)

- Durable dispatch-attempt row keyed by `(plan_id, task_id, goal_contract_fingerprint)` while LEASED — prevents duplicate apply.
- Or rely on program mutating lease + task state; prefer explicit attempt record if cheap within size gate.

### 6.3 CLI evidence

```bash
python3 -m scripts.harness dispatch --dry-run --json   # post-impl
# Inputs: PlanResult JSON path or inline from `plan --json`
```

No production evidence. No approve of GitHub Actions production runs.

---

## 7. Failure modes

| Code | Behavior |
|------|----------|
| `plan_not_executable` | Input `would_execute=false`; no CAS |
| `missing_goal_contract` | Fail closed; no invent |
| `stale_goal_fp` / `stale_goal_sha` | No CAS |
| `stale_snapshot` | Fingerprint diverge; no CAS |
| `lease_set_mismatch` | `required_leases` ≠ recomputed resources |
| `task_not_ready` / `wip_active` | State changed since plan |
| `lease_conflict` | CAS busy; rollback partial; peers free |
| `renew_fencing_mismatch` | Fail closed; do not overwrite newer lease |
| `founder_required` | Surface; no apply |
| `gate_error` | Raise / fail closed (do not soft-pass) |
| `partial_acquire_rollback_failed` | Stop; Supervisor alert; never leave inconsistent silent success |

---

## 8. Founder boundaries

| Action | H4 |
|--------|----|
| Dispatch T0–T1 autonomous PlanResult after revalidate | Allowed (after Plan Review + impl auth) |
| Founder-required / production paths | Surface only; no apply |
| Approve production / GHA production run | **Forbidden** |
| Weaken `autonomy_gate` or add second classifier | **Forbidden** |
| Forge DecisionReceipt | **Forbidden** |
| Bypass GoalContract / world-bind | **Forbidden** |
| Implement before Plan Review ACCEPT | **Forbidden** (this dispatch) |

---

## 9. Proposed files + size estimate (later impl)

| File | Change | Est. lines |
|------|--------|------------|
| `scripts/harness/dispatch.py` | new: revalidate + acquire set + rollback + DispatchResult | ~320 |
| `scripts/harness/cli.py` | `dispatch` dry-run/apply | ~60 |
| `scripts/tests/test_harness_dispatch.py` | new | ~350 |
| `docs/harness/HARNESS_STATUS.md` | H4 status | ~25 |
| `docs/harness/ARCHITECTURE_V1.md` | H4 section | ~40 |
| **Total** | | **~795** (≤1300 gate) |

If size pressure: keep revalidation + CAS acquire/rollback + duplicate-apply tests; defer agent-start launcher wiring to a follow-up PR still under H4 umbrella only after Review says so.

**This PLAN PR:** docs only (`H4_DISPATCH_PLAN.md`, brief `HARNESS_STATUS.md`) — no runtime code.

---

## 10. Test plan (required before H4 impl merge)

1. Happy path: valid world-bound PlanResult → all `required_leases` acquired → `READY→LEASED` → DispatchResult.ok  
2. `would_execute=false` → reject; leases unchanged  
3. Stale `goal_contract_fingerprint` / `observed_main_sha` / `input_snapshot_fingerprint` → reject; no lease change  
4. Missing GoalContract → reject (never invent)  
5. Foreign live lease → `lease_conflict`; partial acquire rolled back with identity+fencing  
6. Expired lease → `reclaim_stale` or acquire CAS succeeds without blind delete  
7. `renew` with wrong fencing → fail closed  
8. Duplicate concurrent `dispatch --apply` → exactly one winner  
9. Governance founder-required on revalidate → no apply  
10. Deny-and-continue: failed dispatch leaves other program READY intact  
11. Regression: `test_harness_core` / `test_harness_h2` / `test_harness_planner` green  
12. Diff scope: no `backend/app` product edits; no production approve  

---

## 11. Non-scope (explicit)

- H4 **implementation** in this PLAN dispatch / PR  
- H5–H9 product slices  
- Production / app / deploy; TrueFit flag activation; RFID merge  
- Approve GHA production run `35111700889`  
- Second autonomy classifier  
- `probe_acquire` inside planner (remains H3 read-only)  
- Worktree-create launcher as shipped product beyond plan text (optional later under separate impl authorization)  
- Auto-merge of worker PRs; Temporal/Celery/Redis  

---

## 12. Stop conditions (implementation phase — after Plan approval)

Stop and re-ask if:

- Need to weaken CAS / fencing / exact-SHA / GoalContract fail-closed  
- Need non-SQLite coordination  
- Launch must bypass world-bind revalidation  
- Size gate forces dropping stale-plan or rollback tests  
- Conflicting exclusive lease on `scripts/harness/**` for impl  

---

## 13. Implementation sequence (only after Plan Review → `DISPATCH_H4_IMPL`)

1. `dispatch.py` revalidate + CAS acquire/rollback + DispatchResult  
2. CLI `--dry-run` / `--apply`  
3. Tests in §10  
4. Docs status + ARCHITECTURE H4 note  
5. Exact-head CI → PR ≤1300 → Supervisor merge  

**STOP here for Supervisor Plan Review. Do not implement until authorized.**

---

## 14. Open questions for Reviewer (non-blocking)

1. H4.0 minimal = CAS+revalidate+LEASED only, with agent-start launcher deferred? (Plan default: **yes**)  
2. Persist dispatch-attempt table in H4.0 or rely on lease+task state? (Plan default: **explicit attempt if fits size**)  
3. Default `reclaim_stale` before acquire on apply? (Plan default: **yes**)
