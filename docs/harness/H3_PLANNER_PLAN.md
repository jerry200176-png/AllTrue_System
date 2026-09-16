# H3 Planner Plan — AllTrue Harness

**Status:** APPROVED WITH AMENDMENTS — implementation in progress on this branch  
**Date:** 2026-09-16T18:20:00Z (plan); amendments applied 2026-09-17  
**Branch:** `chore/task-harness-h3-plan`  
**Base:** main @ H2.1 landed (#3009) + H3 Plan (#3011)  
**Author lane:** Night Shift Worker A (`DISPATCH_H3_IMPL`)  
**Prerequisite:** H0–H1 (#2977), H2 (#3007), H2.1 (#3009), H3 Plan (#3011) — **landed**

---

## 1. Objectives

H3 lets the harness **select the next safely executable task/node from durable graph state** so H4 can dispatch without conversational assumption.

| Outcome | Non-outcome |
|---------|-------------|
| Deterministic `PlanResult` from SQLite graph | No second autonomy classifier |
| Respect READY / BLOCKED / WAITING + deps + leases | No worker launcher / worktree create (H4) |
| Use `autonomy_gate` via existing adapter only | No production / app runtime mutation |
| Deny-and-continue peer selection | No multi-program tick loop as H7 product |
| Starvation-aware deterministic ordering | No Temporal/Celery/Redis |

**Success criterion:** Given a harness DB fixture, `select_next_task` / `select_across_programs` return a stable, evidence-backed plan that H4 can consume without re-deriving governance.

---

## 2. Module boundaries

| Module | Owns | Must not own |
|--------|------|--------------|
| `scripts/harness/planner.py` (new) | Candidate filter, scoring, PlanResult | Risk reclassification |
| `scripts/governance/autonomy_gate.py` | Tier / founder / activation truth | Selection |
| `scripts/harness/governance_adapter.py` | `classify_task_paths`, continue_safe packaging | Planner policy invention |
| `scripts/harness/leases.py` | Lease **availability probe** (read + optional dry CAS check) | Acquire for dispatch (H4) |
| `scripts/harness/contracts.py` | GoalContract bind checks when goal present | Minting receipts for execution |
| `scripts/harness/reconcile.py` | Optional pre-plan stale reconcile helpers | Live gh CI observe (H6) |
| `scripts/harness/graph.py` | `deny_and_continue_peers`, graph snapshot | Selection scoring |
| `scripts/harness/cli.py` | `plan` subcommand (dry-run JSON) | `dispatch` / `tick` (H4/H7) |

Reuse the pre-split draft planner shape (`PlanResult`, `select_next_task`, `select_across_programs`) but **upgrade** it for H2.1 semantics (leases CAS, GoalContract, deny-and-continue, starvation).

---

## 3. Planner inputs

Read-only inputs (no side effects in default `dry_run=True`):

| Input | Source |
|-------|--------|
| Programs + Tasks | `HarnessStore` |
| Task graph edges | `dependencies`, program `blockers`, `ACTIVE_MUTATING` |
| Lease map | `store.list_leases()` — resource keys for program WIP + `affected_contracts` |
| GoalContract (optional per task) | `store.list_goals(task_id=…)` — if present, bind scope/tier |
| World observation (optional) | Caller-supplied `main_sha` for stale-goal skip; default omit |
| Clock | Injected `now` for expiry / aging |

Planner **must not** call `gh`, mutate production, or invent CI green.

---

## 4. READY / BLOCKED / WAITING semantics

Planner-facing buckets (derived; do not add new TaskState enums in H3 unless proven necessary):

| Bucket | TaskState / condition | Selectable? |
|--------|----------------------|-------------|
| **READY** | `READY` (and optionally promote-eligible `DISCOVERED` only if `designed_slice` and deps met — **default: require READY**) | Yes, if gates pass |
| **BLOCKED** | `BLOCKED`, or non-empty `task.blocker`, or unmet deps, or program.blockers | No |
| **WAITING** | `CI_PENDING`, `STAGING_PENDING`, `PRODUCTION_PENDING`, `FOUNDER_REQUIRED` | No (observe-only) |
| **WIP** | `ACTIVE_MUTATING` | No new select; return WIP as reason |
| **TERMINAL / PAUSED** | `DONE`, `FAILED`, `PAUSED` | No |

**Program WIP rule (keep):** If any task in `ACTIVE_MUTATING` for that program → do not select another mutating task for that program (`would_execute=False`, reason `wip_active:…`). Deny-and-continue applies **across** programs / peer READY tasks, not by double-mutating one program.

---

## 5. Dependency evaluation

- `_dep_satisfied`: every `task.dependencies` id exists and `status == DONE`.
- Missing dep id → treat unmet (fail closed).
- Circular deps: detect via DFS on candidate set; mark all cycle members skipped with `reason=dependency_cycle` (no select).

---

## 6. Lease availability

Before marking `would_execute=True`:

1. Map required resources:
   - `program:{program_id}:mutating`
   - `contract:{name}` for each `task.affected_contracts` ∈ `SHARED_CONTRACTS`
2. For each resource:
   - No lease → available
   - Lease expired (`expires_at <= now`) → available for planning (H4 will CAS-acquire; planner does not delete)
   - Live lease owned only with matching `lease_id` + `fencing_token` + `holder_task_id`
     (`task_id` alone is **not** ownership)
   - Otherwise live lease → skip `lease_busy:{resource}`

Planner does **not** call `acquire`/`renew`/`reclaim`/probe-CAS. All lease mutation is H4.

---

## 7. GoalContract / governance / effective tier

1. Paths for classification: `task.affected_paths` or `task.scope` (fail closed if both empty when `apply_governance=True` → skip `missing_paths`).
2. Call **only** `classify_task_paths` / `check_risk_declaration` from `governance_adapter` (wraps `autonomy_gate`).
3. If GoalContract exists for task:
   - `check_scope_drift(goal, paths)` must pass
   - Prefer goal `declared_risk`/`declared_tier` when validating declaration consistency
4. If `founder_required` → select may surface task with `would_execute=False`, reason `founder_required_by_governance` (escalation is not auto-enqueued in H3 dry-run unless `enqueue_escalation=False` default).
5. **Never** reimplement tier tables in planner.

---

## 8. Deny-and-continue peer selection

When a high-priority candidate is skipped due to:

- `FOUNDER_REQUIRED` / production-blocked governance
- `lease_busy` on a resource that peers do not need

…continue evaluating remaining candidates (same program if not WIP-locked; always other programs in `select_across_programs`).

Reuse `deny_and_continue_peers(store, blocked_task_id)` as a **hint list** after a blocked selection attempt; primary algorithm remains global candidate filter + score (do not only walk peers).

Cross-program: one program `program_blocked` must not suppress other programs’ `would_execute=True` plans.

---

## 9. Deterministic ordering

Stable total order (higher first), all fields pure functions of durable state:

```
(
  selectable_bucket_rank,          # READY > DISCOVERED
  business_value,                  # int
  designed_slice,                  # 1/0
  reversible,                      # 1/0
  -len(affected_contracts),        # fewer shared contracts first
  aging_boost,                     # see starvation
  task_id,                         # lexicographic tie-break
)
```

Identical inputs → identical `PlanResult` (golden JSON tests).

---

## 10. Starvation prevention

Problem: perpetual high `business_value` READY tasks can shadow older READY tasks forever.

**Approved amendment:** aging must change selection order.

- `effective_priority = business_value + aging_boost`
- `aging_boost = min(MAX_AGING_BOOST, floor(age_hours / AGING_UNIT_HOURS) * AGING_POINTS_PER_UNIT)`
  - defaults: unit=24h, points=10, max_boost=200
  - `age_hours` from `task.ready_since` only (not `updated_at`)
- Bounded aging can raise an older READY task above a newer higher-BV task
- Deterministic tie-breaks retained (`designed_slice`, `reversible`, contract count, `task_id`)
- Does **not** override FOUNDER_REQUIRED / lease_busy / unmet deps

---

## 11. Stale state reconciliation (planner-scoped)

Before scoring (optional flag `reconcile_stale=True` default **False** in unit tests; **True** in CLI `plan --sync` path):

| Check | Action |
|-------|--------|
| Goal `subject_sha` ≠ caller `main_sha` (if provided) | Skip `stale_goal_sha` |
| Task `READY` but open DecisionReceipt for founder-only action without matching contract_fp | Do not auto-execute; leave WAITING/FOUNDER path |
| Expired leases | Treat as available (do not reclaim in planner; CLI may `reclaim_stale` separately) |

Full GitHub/main writeback of task DONE remains Supervisor / H6 — H3 only **skips** stale goals when evidence is supplied.

---

## 12. Planner output contract

```python
@dataclass(frozen=True)
class PlanResult:
    program_id: str
    selected: Task | None
    reason: str
    skipped: list[dict]   # {task_id, reason}
    would_execute: bool
    governance: GovernanceDecision | None
    required_leases: list[str]
    goal_id: str | None
    goal_contract_fingerprint: str | None
    observed_main_sha: str | None
    snapshot_fingerprint: str
    peer_candidates: list[str]
    plan_id: str
    effective_priority: int | None
```

`would_execute=true` requires a valid GoalContract (else surface with `missing_goal_contract`).
H4 must revalidate plan binding before dispatch.

---

## 13. H4 handoff boundary

| H3 delivers | H4 owns |
|-------------|---------|
| `PlanResult` with `would_execute=True` | `acquire` CAS leases using `required_leases` |
| Governance snapshot | Worktree / agent-start bind + fencing enforcement at mutations |
| `goal_id` reference | Worker Goal file write / codex-route |
| Peer hints | Parallel worker dispatch policy |

H3 **never** transitions task `READY → LEASED` unless explicit opt-in `apply=True` (default False). Recommend H4 owns that transition with lease evidence.

---

## 14. State transitions used (H3)

| Transition | By whom |
|------------|---------|
| None by default | Planner dry-run |
| Optional `DISCOVERED → READY` | **Out of H3** unless separately approved (prefer YAML sync / Supervisor) |
| `READY → LEASED` | H4 |
| `READY → FOUNDER_REQUIRED` | Only if `enqueue_escalation=True` (off by default) |

---

## 15. Evidence schema (plan eval)

Planner tests assert on `PlanResult.to_dict()` fields above. No production evidence. Optional CLI:

```bash
python3 -m scripts.harness plan --sync --json
python3 -m scripts.harness plan --program truefit --json
```

---

## 16. Failure modes

| Mode | Planner behavior |
|------|------------------|
| Empty DB / no programs | `no_programs` |
| All blocked / waiting | `no_executable_tasks` + skipped reasons |
| WIP active | `wip_active` |
| Lease contention | skip + continue peers |
| Founder governance | `would_execute=False` |
| Missing paths | skip fail-closed |
| Dependency cycle | skip cycle members |
| Gate import/error | raise (fail closed; do not soft-pass) |

---

## 17. Founder boundaries

| Action | H3 |
|--------|----|
| Select docs/T0–T1 autonomous candidates | Allowed to recommend |
| Select founder-required paths | Surface only; `would_execute=False` |
| Approve production / merge receipts | Forbidden |
| Weaken `autonomy_gate` | Forbidden |
| Fake Founder DecisionReceipt | Forbidden (H2.1) |

---

## 18. Proposed files + size estimate

| File | Change | Est. lines |
|------|--------|------------|
| `scripts/harness/planner.py` | new | ~220 |
| `scripts/harness/cli.py` | add `plan` | ~40 |
| `scripts/tests/test_harness_planner.py` | new | ~250 |
| `docs/harness/HARNESS_STATUS.md` | H3 status | ~20 |
| `docs/harness/ARCHITECTURE_V1.md` | H3 section | ~25 |
| **Total** | | **~550** (≤1300 gate OK as follow-up PR) |

---

## 19. Tests / evals (required before merge of implementation)

1. Highest-value READY designed slice wins  
2. Unmet deps skipped  
3. Program WIP blocks second mutate  
4. Program A blocked → Program B still plans (`deny-and-continue`)  
5. Live foreign lease → skip; expired lease → selectable  
6. Auth path → founder_required, `would_execute=False`  
7. Starvation: older lower-value READY beats newer higher-value after aging_boost  
8. Determinism: same fixture → identical `plan_id` / JSON  
9. Dependency cycle → no select  
10. Empty paths + governance on → skip  
11. Goal scope drift → skip  
12. Regression: H1/H2.1 suites still green  

---

## 20. Non-scope (explicit)

- H4 worker launcher, worktree create, codex-route  
- H5–H8 loop/tick productization  
- H6 live `gh pr checks` observer  
- H9 staging acceptance  
- portfolio-ops graph allowlist expansion  
- Second classifier / shadow risk engine  
- Production activation, app/backend/frontend edits  
- Auto-merging planner-selected PRs  

---

## 21. Stop conditions (implementation phase — after Plan approval)

Stop and re-ask if:

- Need to change Founder authority / DecisionReceipt vocabulary  
- Need non-SQLite coordination for selection  
- Selection requires weakening exact-SHA or gate fail-closed  
- Size gate forces dropping starvation or lease checks  
- Another worker holds conflicting `scripts/harness/**` lease for implementation  

---

## 22. Implementation sequence (only after Plan approval)

1. Land `planner.py` + tests (no apply transitions)  
2. Wire CLI `plan`  
3. Docs status  
4. Exact-head CI → PR ≤1300  
5. Supervisor merge  

**This document records the approved plan + amendments; implementation lands separately.**

---

## 23. Open questions for Reviewer (non-blocking preferences)

1. Allow `DISCOVERED →` candidate, or **READY-only**? (Plan default: **READY-only**)  
2. Default `reconcile_stale` on CLI `--sync`: on or off? (Plan default: **on** for CLI, **off** in unit tests)  
3. Persist `PlanResult` to SQLite in H3 or defer to H4? (Plan default: **defer**)
