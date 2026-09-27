"""H3 planner: read-only selection of next safely executable READY task.

Founder amendments (binding over H3_PLANNER_PLAN.md):
  A1 — effective_priority = business_value + bounded aging_boost, then tie-breaks
  A2 — lease ownership needs lease_id + fencing_token + task_id (not task_id alone)
  A3 — GoalContract required for would_execute=True
  A4 — read-only: no acquire/renew/reclaim/probe_acquire
  A5 — PlanResult world-binds goal/fp/sha/snapshot/leases/governance
  A6 — aging from ready_since / latest transition-to-READY (not updated_at)
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Mapping

from .contracts import GoalContract
from .governance_adapter import (
    GovernanceDecision,
    check_risk_declaration,
    check_scope_drift,
)
from .graph import deny_and_continue_peers
from .leases import contract_resource, program_resource
from .models import SHARED_CONTRACTS, Program, Task
from .states import ACTIVE_MUTATING, TaskState
from .store import HarnessStore

# Starvation defaults (documented; override via kwargs in tests).
AGING_UNIT_HOURS = 24
AGING_MAX_BOOST = 5


def _parse_iso(value: str) -> datetime | None:
    if not value:
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        dt = datetime.fromisoformat(text)
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _now() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


@dataclass(frozen=True)
class LeaseOwnershipClaim:
    """H2.1 ownership evidence — all three fields required (A2)."""

    lease_id: str
    fencing_token: int
    task_id: str

    def matches(self, lease: Mapping[str, Any]) -> bool:
        return (
            str(lease.get("lease_id") or "") == self.lease_id
            and int(lease.get("fencing_token") or -1) == int(self.fencing_token)
            and str(lease.get("holder_task_id") or "") == self.task_id
        )


@dataclass(frozen=True)
class PlanResult:
    program_id: str
    selected: Task | None
    reason: str
    skipped: list[dict[str, Any]] = field(default_factory=list)
    would_execute: bool = False
    governance: GovernanceDecision | None = None
    required_leases: list[str] = field(default_factory=list)
    goal_id: str | None = None
    goal_contract_fingerprint: str | None = None
    observed_main_sha: str | None = None
    input_snapshot_fingerprint: str = ""
    peer_candidates: list[str] = field(default_factory=list)
    plan_id: str = ""
    effective_priority: int | None = None
    aging_boost: int | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "program_id": self.program_id,
            "selected": self.selected.to_dict() if self.selected else None,
            "reason": self.reason,
            "skipped": list(self.skipped),
            "would_execute": self.would_execute,
            "governance": self.governance.to_dict() if self.governance else None,
            "required_leases": list(self.required_leases),
            "goal_id": self.goal_id,
            "goal_contract_fingerprint": self.goal_contract_fingerprint,
            "observed_main_sha": self.observed_main_sha,
            "input_snapshot_fingerprint": self.input_snapshot_fingerprint,
            "peer_candidates": list(self.peer_candidates),
            "plan_id": self.plan_id,
            "effective_priority": self.effective_priority,
            "aging_boost": self.aging_boost,
        }


def required_resources(task: Task) -> list[str]:
    keys = [program_resource(task.program_id)]
    for name in task.affected_contracts:
        if name in SHARED_CONTRACTS:
            keys.append(contract_resource(name))
    return sorted(set(keys))


def ready_since_iso(store: HarnessStore, task: Task) -> str | None:
    """A6: prefer latest transition-to-READY; never use mutable updated_at."""
    transitions = store.list_transitions(task.task_id, limit=200)
    ready_times = [
        t["created_at"]
        for t in transitions
        if str(t.get("to_state")) == TaskState.READY.value and t.get("created_at")
    ]
    if ready_times:
        # list_transitions returns newest-first; pick max ISO for safety.
        return max(ready_times)
    return None


def aging_boost_for(
    store: HarnessStore,
    task: Task,
    *,
    now: datetime,
    aging_unit_hours: int = AGING_UNIT_HOURS,
    max_boost: int = AGING_MAX_BOOST,
) -> int:
    since = ready_since_iso(store, task)
    if not since:
        return 0
    ready_at = _parse_iso(since)
    if ready_at is None:
        return 0
    age_hours = max(0.0, (now - ready_at).total_seconds() / 3600.0)
    unit = max(1, int(aging_unit_hours))
    return min(int(max_boost), int(age_hours // unit))


def effective_priority(
    store: HarnessStore,
    task: Task,
    *,
    now: datetime,
    aging_unit_hours: int = AGING_UNIT_HOURS,
    max_boost: int = AGING_MAX_BOOST,
) -> tuple[int, int]:
    """A1: business_value + aging_boost as single primary score."""
    boost = aging_boost_for(
        store, task, now=now, aging_unit_hours=aging_unit_hours, max_boost=max_boost,
    )
    return int(task.business_value) + boost, boost


def _dep_satisfied(store: HarnessStore, task: Task, by_id: dict[str, Task]) -> bool:
    for dep_id in task.dependencies:
        dep = by_id.get(dep_id) or store.get_task(dep_id)
        if dep is None or dep.status != TaskState.DONE:
            return False
    return True


def _cycle_members(tasks: list[Task]) -> set[str]:
    by_id = {t.task_id: t for t in tasks}
    visiting: set[str] = set()
    visited: set[str] = set()
    cycles: set[str] = set()

    def dfs(tid: str, stack: list[str]) -> None:
        if tid in visited:
            return
        if tid in visiting:
            if tid in stack:
                cycles.update(stack[stack.index(tid):])
            cycles.add(tid)
            return
        visiting.add(tid)
        stack.append(tid)
        task = by_id.get(tid)
        if task:
            for dep in task.dependencies:
                if dep in by_id:
                    dfs(dep, stack)
        stack.pop()
        visiting.discard(tid)
        visited.add(tid)

    for t in tasks:
        dfs(t.task_id, [])
    return cycles


def _lease_availability(
    store: HarnessStore,
    task: Task,
    resources: list[str],
    *,
    now: datetime,
    ownership: Mapping[str, LeaseOwnershipClaim] | None,
) -> str | None:
    """Return skip reason or None if all resources available for planning (A2/A4)."""
    now_iso = _iso(now)
    claims = ownership or {}
    for key in resources:
        lease = store.get_lease(key)
        if lease is None:
            continue
        expires = str(lease.get("expires_at") or "")
        if expires and expires <= now_iso:
            # Expired → reclaimable/selectable; H4 performs CAS (A2/A4).
            continue
        claim = claims.get(key)
        if claim is not None and claim.task_id == task.task_id and claim.matches(lease):
            continue
        # Live foreign or unknown (including same holder_task_id without identity) → unavailable.
        holder = lease.get("holder_task_id") or "?"
        return f"lease_busy:{key}:holder={holder}"
    return None


def _task_goal(store: HarnessStore, task: Task) -> GoalContract | None:
    goals = store.list_goals(task_id=task.task_id)
    return goals[0] if goals else None


def _paths_for(task: Task) -> list[str]:
    return list(task.affected_paths or task.scope or [])


def _snapshot_fingerprint(
    *,
    program_ids: list[str],
    task_ids: list[str],
    lease_rows: list[dict[str, Any]],
    goal_ids: list[str],
    main_sha: str | None,
    now_iso: str,
    aging_unit_hours: int,
    max_boost: int,
) -> str:
    blob = {
        "program_ids": program_ids,
        "task_ids": task_ids,
        "leases": [
            {
                "resource_key": r.get("resource_key"),
                "lease_id": r.get("lease_id"),
                "fencing_token": r.get("fencing_token"),
                "holder_task_id": r.get("holder_task_id"),
                "expires_at": r.get("expires_at"),
            }
            for r in sorted(lease_rows, key=lambda x: str(x.get("resource_key") or ""))
        ],
        "goal_ids": goal_ids,
        "observed_main_sha": main_sha or "",
        "planner_clock": now_iso,
        "aging_unit_hours": aging_unit_hours,
        "aging_max_boost": max_boost,
        "planner": "h3",
    }
    raw = json.dumps(blob, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(raw.encode()).hexdigest()[:32]


def _plan_id(result_core: dict[str, Any], snapshot_fp: str) -> str:
    blob = json.dumps(
        {"snapshot": snapshot_fp, "core": result_core},
        sort_keys=True,
        separators=(",", ":"),
    )
    return hashlib.sha256(blob.encode()).hexdigest()[:24]


def _evaluate_candidate(
    store: HarnessStore,
    task: Task,
    program: Program,
    *,
    now: datetime,
    main_sha: str | None,
    ownership: Mapping[str, LeaseOwnershipClaim] | None,
    apply_governance: bool,
    reconcile_stale: bool,
    by_id: dict[str, Task],
    cycle: set[str],
    aging_unit_hours: int,
    max_boost: int,
) -> tuple[bool, str, GovernanceDecision | None, GoalContract | None, list[str], int, int]:
    """Return (ok_for_execute, reason, gov, goal, required_leases, eff, boost)."""
    resources = required_resources(task)
    eff, boost = effective_priority(
        store, task, now=now, aging_unit_hours=aging_unit_hours, max_boost=max_boost,
    )

    if task.task_id in cycle:
        return False, "dependency_cycle", None, None, resources, eff, boost
    if task.status != TaskState.READY:
        return False, f"not_ready:{task.status.value}", None, None, resources, eff, boost
    if task.blocker:
        return False, f"task_blocker:{task.blocker}", None, None, resources, eff, boost
    if program.blockers:
        return False, f"program_blocked:{','.join(program.blockers)}", None, None, resources, eff, boost
    if not _dep_satisfied(store, task, by_id):
        return False, "unmet_dependencies", None, None, resources, eff, boost

    siblings = store.list_tasks(program_id=task.program_id)
    wip = [t for t in siblings if t.status in ACTIVE_MUTATING]
    if wip:
        return False, f"wip_active:{wip[0].task_id}", None, None, resources, eff, boost

    lease_reason = _lease_availability(
        store, task, resources, now=now, ownership=ownership,
    )
    if lease_reason:
        return False, lease_reason, None, None, resources, eff, boost

    goal = _task_goal(store, task)
    if goal is None:
        # A3: diagnostic surface OK; never executable without GoalContract.
        return False, "missing_goal_contract", None, None, resources, eff, boost

    if reconcile_stale and main_sha and goal.subject_sha and goal.subject_sha != main_sha:
        return False, "stale_goal_sha", None, goal, resources, eff, boost

    paths = _paths_for(task)
    gov: GovernanceDecision | None = None
    if apply_governance:
        if not paths:
            return False, "missing_paths", None, goal, resources, eff, boost
        drift = check_scope_drift(goal, paths)
        if not drift.autonomous or drift.founder_required:
            return False, "scope_drift", drift, goal, resources, eff, boost
        gov = check_risk_declaration(
            paths,
            declared_risk=goal.declared_risk or task.risk_declaration,
            declared_tier=goal.declared_tier,
        )
        if gov.founder_required or not gov.autonomous:
            return False, "founder_required_by_governance", gov, goal, resources, eff, boost

    return True, "selected", gov, goal, resources, eff, boost


def select_next_task(
    store: HarnessStore,
    program_id: str | None = None,
    *,
    now: datetime | None = None,
    main_sha: str | None = None,
    ownership: Mapping[str, LeaseOwnershipClaim] | None = None,
    apply_governance: bool = True,
    reconcile_stale: bool = False,
    aging_unit_hours: int = AGING_UNIT_HOURS,
    max_boost: int = AGING_MAX_BOOST,
) -> PlanResult:
    """Select one READY task. Read-only (A4). Deny-and-continue across peers."""
    now = now or _now()
    programs = store.list_programs()
    if program_id:
        programs = [p for p in programs if p.program_id == program_id]
    if not programs:
        snap = _snapshot_fingerprint(
            program_ids=[], task_ids=[], lease_rows=store.list_leases(),
            goal_ids=[g.goal_id for g in store.list_goals()],
            main_sha=main_sha, now_iso=_iso(now),
            aging_unit_hours=aging_unit_hours, max_boost=max_boost,
        )
        return PlanResult(
            program_id=program_id or "",
            selected=None,
            reason="no_programs",
            observed_main_sha=main_sha,
            input_snapshot_fingerprint=snap,
            plan_id=_plan_id({"reason": "no_programs"}, snap),
        )

    prog_by_id = {p.program_id: p for p in programs}
    tasks = []
    for p in programs:
        tasks.extend(store.list_tasks(program_id=p.program_id))
    by_id = {t.task_id: t for t in tasks}
    # Cycle detection over READY candidate dependency subgraph + deps among candidates.
    ready = [t for t in tasks if t.status == TaskState.READY]
    cycle = _cycle_members(ready)

    # Ascending key with negated primary scores → higher effective_priority first;
    # task_id ascending for deterministic ties (A1).
    ordered = sorted(
        ready,
        key=lambda t: (
            -effective_priority(
                store, t, now=now, aging_unit_hours=aging_unit_hours, max_boost=max_boost,
            )[0],
            -(1 if t.designed_slice else 0),
            -(1 if t.reversible else 0),
            len([c for c in t.affected_contracts if c in SHARED_CONTRACTS]),
            -aging_boost_for(
                store, t, now=now, aging_unit_hours=aging_unit_hours, max_boost=max_boost,
            ),
            t.task_id,
        ),
    )

    skipped: list[dict[str, Any]] = []
    snap = _snapshot_fingerprint(
        program_ids=sorted(prog_by_id),
        task_ids=sorted(by_id),
        lease_rows=store.list_leases(),
        goal_ids=sorted(g.goal_id for g in store.list_goals()),
        main_sha=main_sha,
        now_iso=_iso(now),
        aging_unit_hours=aging_unit_hours,
        max_boost=max_boost,
    )

    for task in ordered:
        program = prog_by_id.get(task.program_id)
        if program is None:
            skipped.append({"task_id": task.task_id, "reason": "unknown_program"})
            continue
        ok, reason, gov, goal, resources, eff, boost = _evaluate_candidate(
            store, task, program,
            now=now, main_sha=main_sha, ownership=ownership,
            apply_governance=apply_governance, reconcile_stale=reconcile_stale,
            by_id=by_id, cycle=cycle,
            aging_unit_hours=aging_unit_hours, max_boost=max_boost,
        )
        if not ok:
            skipped.append({"task_id": task.task_id, "reason": reason})
            continue

        peers = deny_and_continue_peers(store, task.task_id)
        core = {
            "program_id": task.program_id,
            "selected": task.task_id,
            "reason": reason,
            "would_execute": True,
            "goal_id": goal.goal_id if goal else None,
            "required_leases": resources,
        }
        return PlanResult(
            program_id=task.program_id,
            selected=task,
            reason=reason,
            skipped=skipped,
            would_execute=True,
            governance=gov,
            required_leases=resources,
            goal_id=goal.goal_id if goal else None,
            goal_contract_fingerprint=goal.contract_fp if goal else None,
            observed_main_sha=main_sha,
            input_snapshot_fingerprint=snap,
            peer_candidates=peers,
            plan_id=_plan_id(core, snap),
            effective_priority=eff,
            aging_boost=boost,
        )

    # No executable: may still surface top diagnostic candidate (missing_goal_contract etc.)
    top_diag: Task | None = None
    top_reason = "no_executable_tasks"
    top_gov: GovernanceDecision | None = None
    top_goal: GoalContract | None = None
    top_resources: list[str] = []
    top_eff: int | None = None
    top_boost: int | None = None
    for task in ordered:
        program = prog_by_id.get(task.program_id)
        if not program:
            continue
        ok, reason, gov, goal, resources, eff, boost = _evaluate_candidate(
            store, task, program,
            now=now, main_sha=main_sha, ownership=ownership,
            apply_governance=apply_governance, reconcile_stale=reconcile_stale,
            by_id=by_id, cycle=cycle,
            aging_unit_hours=aging_unit_hours, max_boost=max_boost,
        )
        if reason == "missing_goal_contract":
            top_diag, top_reason, top_gov, top_goal = task, reason, gov, goal
            top_resources, top_eff, top_boost = resources, eff, boost
            break
        if top_diag is None and reason.startswith("founder_required"):
            top_diag, top_reason, top_gov, top_goal = task, reason, gov, goal
            top_resources, top_eff, top_boost = resources, eff, boost

    peers = deny_and_continue_peers(store, top_diag.task_id) if top_diag else []
    pid = top_diag.program_id if top_diag else (program_id or (programs[0].program_id if programs else ""))
    core = {
        "program_id": pid,
        "selected": top_diag.task_id if top_diag else None,
        "reason": top_reason if top_diag else "no_executable_tasks",
        "would_execute": False,
        "goal_id": top_goal.goal_id if top_goal else None,
        "required_leases": top_resources,
    }
    return PlanResult(
        program_id=pid,
        selected=top_diag,
        reason=top_reason if top_diag else "no_executable_tasks",
        skipped=skipped,
        would_execute=False,
        governance=top_gov,
        required_leases=top_resources,
        goal_id=top_goal.goal_id if top_goal else None,
        goal_contract_fingerprint=top_goal.contract_fp if top_goal else None,
        observed_main_sha=main_sha,
        input_snapshot_fingerprint=snap,
        peer_candidates=peers,
        plan_id=_plan_id(core, snap),
        effective_priority=top_eff,
        aging_boost=top_boost,
    )


def select_across_programs(
    store: HarnessStore,
    *,
    now: datetime | None = None,
    main_sha: str | None = None,
    ownership: Mapping[str, LeaseOwnershipClaim] | None = None,
    apply_governance: bool = True,
    reconcile_stale: bool = False,
    aging_unit_hours: int = AGING_UNIT_HOURS,
    max_boost: int = AGING_MAX_BOOST,
) -> PlanResult:
    """Cross-program deny-and-continue: one blocked program must not suppress others."""
    return select_next_task(
        store,
        program_id=None,
        now=now,
        main_sha=main_sha,
        ownership=ownership,
        apply_governance=apply_governance,
        reconcile_stale=reconcile_stale,
        aging_unit_hours=aging_unit_hours,
        max_boost=max_boost,
    )


__all__ = [
    "AGING_MAX_BOOST",
    "AGING_UNIT_HOURS",
    "LeaseOwnershipClaim",
    "PlanResult",
    "aging_boost_for",
    "effective_priority",
    "ready_since_iso",
    "required_resources",
    "select_across_programs",
    "select_next_task",
]
