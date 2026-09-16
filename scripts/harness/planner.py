"""Deterministic next-task selection (read-only / dry-run safe)."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .governance_adapter import GovernanceDecision, classify_task_paths
from .models import Program, Task
from .states import ACTIVE_MUTATING, TaskState
from .store import HarnessStore


@dataclass(frozen=True)
class PlanResult:
    program_id: str
    selected: Task | None
    reason: str
    skipped: list[dict[str, Any]]
    would_execute: bool
    governance: GovernanceDecision | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "program_id": self.program_id,
            "selected_task_id": self.selected.task_id if self.selected else None,
            "reason": self.reason,
            "skipped": list(self.skipped),
            "would_execute": self.would_execute,
            "governance": self.governance.to_dict() if self.governance else None,
        }


def _dep_satisfied(task: Task, by_id: dict[str, Task]) -> bool:
    for dep in task.dependencies:
        other = by_id.get(dep)
        if other is None or other.status != TaskState.DONE:
            return False
    return True


def _score(task: Task) -> tuple:
    """Higher is better. Prefer unblock, value, designed slice, reversible, complete deps."""
    return (
        1 if task.status == TaskState.READY else 0,
        task.business_value,
        1 if task.designed_slice else 0,
        1 if task.reversible else 0,
        -len(task.affected_contracts),
        1 if not task.blocker else 0,
        task.task_id,  # stable tie-break
    )


def select_next_task(
    store: HarnessStore,
    program: Program,
    *,
    dry_run: bool = True,
    apply_governance: bool = True,
) -> PlanResult:
    tasks = store.list_tasks(program_id=program.program_id)
    by_id = {t.task_id: t for t in tasks}
    skipped: list[dict[str, Any]] = []

    active = [t for t in tasks if t.status in ACTIVE_MUTATING]
    if active:
        return PlanResult(
            program_id=program.program_id,
            selected=active[0],
            reason=f"wip_active:{active[0].task_id}:{active[0].status.value}",
            skipped=[{"task_id": t.task_id, "reason": "program_wip"} for t in tasks if t not in active],
            would_execute=False,
            governance=None,
        )

    if program.blockers:
        return PlanResult(
            program_id=program.program_id,
            selected=None,
            reason=f"program_blocked:{';'.join(program.blockers)}",
            skipped=[],
            would_execute=False,
        )

    candidates: list[Task] = []
    for task in tasks:
        if task.status in {TaskState.DONE, TaskState.FAILED, TaskState.PAUSED}:
            skipped.append({"task_id": task.task_id, "reason": f"status:{task.status.value}"})
            continue
        if task.status == TaskState.FOUNDER_REQUIRED:
            skipped.append({"task_id": task.task_id, "reason": "founder_required"})
            continue
        if task.status == TaskState.BLOCKED or task.blocker:
            skipped.append(
                {"task_id": task.task_id, "reason": f"blocked:{task.blocker or 'unspecified'}"}
            )
            continue
        if task.status not in {TaskState.DISCOVERED, TaskState.READY}:
            skipped.append({"task_id": task.task_id, "reason": f"not_selectable:{task.status.value}"})
            continue
        if not _dep_satisfied(task, by_id):
            skipped.append({"task_id": task.task_id, "reason": "dependencies_unmet"})
            continue
        # Avoid speculative / undesigned work
        if not task.designed_slice:
            skipped.append({"task_id": task.task_id, "reason": "not_designed_slice"})
            continue
        candidates.append(task)

    if not candidates:
        return PlanResult(
            program_id=program.program_id,
            selected=None,
            reason="no_executable_tasks",
            skipped=skipped,
            would_execute=False,
        )

    candidates.sort(key=_score, reverse=True)
    chosen = candidates[0]
    for other in candidates[1:]:
        skipped.append({"task_id": other.task_id, "reason": "lower_priority"})

    gov: GovernanceDecision | None = None
    if apply_governance:
        paths = chosen.affected_paths or chosen.scope
        gov = classify_task_paths(paths)
        if gov.founder_required:
            return PlanResult(
                program_id=program.program_id,
                selected=chosen,
                reason="founder_required_by_governance",
                skipped=skipped,
                would_execute=False,
                governance=gov,
            )

    return PlanResult(
        program_id=program.program_id,
        selected=chosen,
        reason="highest_value_unblocked_designed_slice",
        skipped=skipped,
        would_execute=True,
        governance=gov,
    )


def select_across_programs(
    store: HarnessStore,
    programs: list[Program],
    *,
    dry_run: bool = True,
) -> list[PlanResult]:
    """Yield per-program plans; blocked programs do not block others."""
    return [select_next_task(store, p, dry_run=dry_run) for p in programs]
