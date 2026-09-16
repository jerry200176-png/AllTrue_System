"""Deterministic next-task selection (H3) — read-only; H4 owns lease mutation."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any

from .contracts import GoalContract
from .governance_adapter import GovernanceDecision, check_scope_drift, classify_task_paths
from .leases import contract_resource, program_resource
from .models import Program, SHARED_CONTRACTS, Task
from .states import ACTIVE_MUTATING, TaskState
from .store import HarnessStore

# Starvation: aging can raise an older READY task above a newer higher business_value.
AGING_UNIT_HOURS = 24
AGING_POINTS_PER_UNIT = 10
MAX_AGING_BOOST = 200


def _parse_iso(iso: str) -> datetime | None:
    if not iso:
        return None
    try:
        return datetime.fromisoformat(iso.replace("Z", "+00:00"))
    except ValueError:
        return None


def _now(now: datetime | None = None) -> datetime:
    return (now or datetime.now(timezone.utc)).replace(microsecond=0)


def ready_age_hours(task: Task, *, now: datetime | None = None) -> float:
    """READY waiting age from ready_since only (not updated_at)."""
    if task.status != TaskState.READY or not task.ready_since:
        return 0.0
    started = _parse_iso(task.ready_since)
    if not started:
        return 0.0
    delta = _now(now) - started.astimezone(timezone.utc).replace(microsecond=0)
    return max(0.0, delta.total_seconds() / 3600.0)


def aging_boost(task: Task, *, now: datetime | None = None) -> int:
    hours = ready_age_hours(task, now=now)
    units = int(hours // AGING_UNIT_HOURS)
    return min(MAX_AGING_BOOST, units * AGING_POINTS_PER_UNIT)


def effective_priority(task: Task, *, now: datetime | None = None) -> int:
    """business_value + bounded aging — starvation can eventually dominate BV."""
    return int(task.business_value) + aging_boost(task, now=now)


def _dep_satisfied(task: Task, by_id: dict[str, Task]) -> bool:
    for dep in task.dependencies:
        other = by_id.get(dep)
        if other is None or other.status != TaskState.DONE:
            return False
    return True


def _detect_cycles(tasks: list[Task]) -> set[str]:
    by_id = {t.task_id: t for t in tasks}
    visiting: set[str] = set()
    visited: set[str] = set()
    cyclic: set[str] = set()

    def dfs(tid: str) -> bool:
        if tid in visiting:
            cyclic.add(tid)
            return True
        if tid in visited or tid not in by_id:
            return False
        visiting.add(tid)
        hit = False
        for dep in by_id[tid].dependencies:
            if dfs(dep):
                hit = True
                cyclic.add(tid)
        visiting.remove(tid)
        visited.add(tid)
        return hit

    for t in tasks:
        dfs(t.task_id)
    return cyclic


def required_lease_keys(task: Task) -> list[str]:
    keys = [program_resource(task.program_id)]
    for name in task.affected_contracts:
        if name in SHARED_CONTRACTS:
            keys.append(contract_resource(name))
    return sorted(set(keys))


def lease_is_available(
    store: HarnessStore,
    resource_key: str,
    *,
    task: Task,
    now: datetime | None = None,
) -> tuple[bool, str]:
    """Read-only availability. Ownership requires lease_id + fencing match (not task_id alone)."""
    now_iso = _now(now).astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
    row = store.get_lease(resource_key)
    if row is None:
        return True, "vacant"
    expires = str(row.get("expires_at") or "")
    if expires and expires <= now_iso:
        return True, "expired_potentially_available"  # H4 CAS-acquires
    # Live lease: only "ours" if identity + fencing evidence matches
    if (
        task.lease_id
        and task.lease_fencing_token
        and str(row.get("lease_id")) == task.lease_id
        and int(row.get("fencing_token") or 0) == int(task.lease_fencing_token)
        and str(row.get("holder_task_id")) == task.task_id
    ):
        return True, "owned_with_fencing"
    return False, f"lease_busy:{resource_key}"


@dataclass(frozen=True)
class PlanResult:
    program_id: str
    selected: Task | None
    reason: str
    skipped: list[dict[str, Any]]
    would_execute: bool
    governance: GovernanceDecision | None = None
    required_leases: list[str] = field(default_factory=list)
    goal_id: str | None = None
    goal_contract_fingerprint: str | None = None
    observed_main_sha: str | None = None
    snapshot_fingerprint: str = ""
    peer_candidates: list[str] = field(default_factory=list)
    plan_id: str = ""
    effective_priority: int | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "program_id": self.program_id,
            "selected_task_id": self.selected.task_id if self.selected else None,
            "reason": self.reason,
            "skipped": list(self.skipped),
            "would_execute": self.would_execute,
            "governance": self.governance.to_dict() if self.governance else None,
            "required_leases": list(self.required_leases),
            "goal_id": self.goal_id,
            "goal_contract_fingerprint": self.goal_contract_fingerprint,
            "observed_main_sha": self.observed_main_sha,
            "snapshot_fingerprint": self.snapshot_fingerprint,
            "peer_candidates": list(self.peer_candidates),
            "plan_id": self.plan_id,
            "effective_priority": self.effective_priority,
        }


def _snapshot_fingerprint(
    program: Program,
    tasks: list[Task],
    leases: list[dict[str, Any]],
    *,
    observed_main_sha: str | None,
) -> str:
    payload = {
        "program_id": program.program_id,
        "blockers": list(program.blockers),
        "main_sha": observed_main_sha or "",
        "tasks": [
            {
                "task_id": t.task_id,
                "status": t.status.value,
                "business_value": t.business_value,
                "ready_since": t.ready_since,
                "dependencies": list(t.dependencies),
                "lease_id": t.lease_id,
                "lease_fencing_token": t.lease_fencing_token,
                "affected_contracts": list(t.affected_contracts),
                "affected_paths": list(t.affected_paths),
                "designed_slice": t.designed_slice,
            }
            for t in sorted(tasks, key=lambda x: x.task_id)
        ],
        "leases": [
            {
                "resource_key": L.get("resource_key"),
                "lease_id": L.get("lease_id"),
                "fencing_token": L.get("fencing_token"),
                "holder_task_id": L.get("holder_task_id"),
                "expires_at": L.get("expires_at"),
            }
            for L in sorted(leases, key=lambda x: str(x.get("resource_key") or ""))
        ],
    }
    blob = json.dumps(payload, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(blob.encode()).hexdigest()[:24]


def _plan_id(snapshot_fp: str, selected_id: str | None, reason: str, would: bool) -> str:
    raw = f"{snapshot_fp}|{selected_id or ''}|{reason}|{int(would)}"
    return hashlib.sha256(raw.encode()).hexdigest()[:20]


def _goal_for_task(store: HarnessStore, task: Task) -> GoalContract | None:
    goals = store.list_goals(task_id=task.task_id)
    if not goals:
        return None
    # Prefer newest by updated_at
    return sorted(goals, key=lambda g: g.updated_at, reverse=True)[0]


def select_next_task(
    store: HarnessStore,
    program: Program,
    *,
    dry_run: bool = True,  # noqa: ARG001 — H3 always read-only; kept for API compat
    apply_governance: bool = True,
    observed_main_sha: str | None = None,
    now: datetime | None = None,
    reconcile_stale: bool = False,
) -> PlanResult:
    """Select next task. Never mutates leases or task state."""
    now = _now(now)
    tasks = store.list_tasks(program_id=program.program_id)
    leases = store.list_leases()
    snap_fp = _snapshot_fingerprint(
        program, tasks, leases, observed_main_sha=observed_main_sha
    )
    by_id = {t.task_id: t for t in tasks}
    skipped: list[dict[str, Any]] = []
    cyclic = _detect_cycles(tasks)

    def finish(
        selected: Task | None,
        reason: str,
        *,
        would: bool,
        gov: GovernanceDecision | None = None,
        req: list[str] | None = None,
        goal: GoalContract | None = None,
        peers: list[str] | None = None,
        eff: int | None = None,
    ) -> PlanResult:
        return PlanResult(
            program_id=program.program_id,
            selected=selected,
            reason=reason,
            skipped=skipped,
            would_execute=would,
            governance=gov,
            required_leases=list(req or []),
            goal_id=goal.goal_id if goal else None,
            goal_contract_fingerprint=goal.contract_fp if goal else None,
            observed_main_sha=observed_main_sha,
            snapshot_fingerprint=snap_fp,
            peer_candidates=list(peers or []),
            plan_id=_plan_id(snap_fp, selected.task_id if selected else None, reason, would),
            effective_priority=eff,
        )

    active = [t for t in tasks if t.status in ACTIVE_MUTATING]
    if active:
        return finish(
            active[0],
            f"wip_active:{active[0].task_id}:{active[0].status.value}",
            would=False,
        )

    if program.blockers:
        return finish(
            None,
            f"program_blocked:{';'.join(program.blockers)}",
            would=False,
        )

    candidates: list[Task] = []
    for task in tasks:
        if task.task_id in cyclic:
            skipped.append({"task_id": task.task_id, "reason": "dependency_cycle"})
            continue
        if task.status in {TaskState.DONE, TaskState.FAILED, TaskState.PAUSED}:
            skipped.append({"task_id": task.task_id, "reason": f"status:{task.status.value}"})
            continue
        if task.status == TaskState.FOUNDER_REQUIRED:
            skipped.append({"task_id": task.task_id, "reason": "founder_required"})
            continue
        if task.status in {
            TaskState.CI_PENDING, TaskState.STAGING_PENDING, TaskState.PRODUCTION_PENDING,
        }:
            skipped.append({"task_id": task.task_id, "reason": f"waiting:{task.status.value}"})
            continue
        if task.status == TaskState.BLOCKED or task.blocker:
            skipped.append({
                "task_id": task.task_id,
                "reason": f"blocked:{task.blocker or 'unspecified'}",
            })
            continue
        if task.status != TaskState.READY:
            skipped.append({"task_id": task.task_id, "reason": f"not_selectable:{task.status.value}"})
            continue
        if not task.designed_slice:
            skipped.append({"task_id": task.task_id, "reason": "not_designed_slice"})
            continue
        if not _dep_satisfied(task, by_id):
            skipped.append({"task_id": task.task_id, "reason": "dependencies_unmet"})
            continue
        candidates.append(task)

    if not candidates:
        return finish(None, "no_executable_tasks", would=False)

    # Deterministic order: higher effective priority first; stable tie-breaks.
    candidates.sort(
        key=lambda t: (
            -effective_priority(t, now=now),
            -int(t.designed_slice),
            -int(t.reversible),
            len(t.affected_contracts),
            t.task_id,
        )
    )

    peers_all = [t.task_id for t in candidates]

    for idx, chosen in enumerate(candidates):
        goal = _goal_for_task(store, chosen)
        if reconcile_stale and goal and observed_main_sha and goal.subject_sha != observed_main_sha:
            skipped.append({"task_id": chosen.task_id, "reason": "stale_goal_sha"})
            continue

        req = required_lease_keys(chosen)
        lease_ok = True
        lease_reason = ""
        for key in req:
            ok, why = lease_is_available(store, key, task=chosen, now=now)
            if not ok:
                lease_ok = False
                lease_reason = why
                break
        if not lease_ok:
            skipped.append({"task_id": chosen.task_id, "reason": lease_reason})
            continue

        paths = list(chosen.affected_paths or chosen.scope)
        gov: GovernanceDecision | None = None
        if apply_governance:
            if not paths:
                skipped.append({"task_id": chosen.task_id, "reason": "missing_paths"})
                continue
            if goal:
                drift = check_scope_drift(goal, paths)
                if not drift.autonomous or drift.drift_codes:
                    skipped.append({"task_id": chosen.task_id, "reason": "scope_drift"})
                    continue
            gov = classify_task_paths(paths)
            if gov.founder_required:
                # Surface diagnostically; deny-and-continue to next candidate
                skipped.append({
                    "task_id": chosen.task_id,
                    "reason": "founder_required_by_governance",
                })
                continue

        # Mark lower-priority unchosen once we settle on this candidate
        for other in candidates[idx + 1:]:
            if not any(s["task_id"] == other.task_id for s in skipped):
                skipped.append({"task_id": other.task_id, "reason": "lower_priority"})

        if goal is None:
            return finish(
                chosen,
                "missing_goal_contract",
                would=False,
                gov=gov,
                req=req,
                peers=[p for p in peers_all if p != chosen.task_id],
                eff=effective_priority(chosen, now=now),
            )

        return finish(
            chosen,
            "highest_effective_priority_ready",
            would=True,
            gov=gov,
            req=req,
            goal=goal,
            peers=[p for p in peers_all if p != chosen.task_id],
            eff=effective_priority(chosen, now=now),
        )

    # All candidates skipped (leases / founder / stale). Surface first skipped READY diagnostically.
    first = candidates[0]
    return finish(
        first,
        skipped[-1]["reason"] if skipped else "no_executable_tasks",
        would=False,
        req=required_lease_keys(first),
        peers=[p for p in peers_all if p != first.task_id],
        eff=effective_priority(first, now=now),
    )


def select_across_programs(
    store: HarnessStore,
    programs: list[Program],
    *,
    dry_run: bool = True,
    observed_main_sha: str | None = None,
    now: datetime | None = None,
    reconcile_stale: bool = False,
) -> list[PlanResult]:
    """Per-program plans; blocked programs do not block others (deny-and-continue)."""
    return [
        select_next_task(
            store,
            p,
            dry_run=dry_run,
            observed_main_sha=observed_main_sha,
            now=now,
            reconcile_stale=reconcile_stale,
        )
        for p in programs
    ]
