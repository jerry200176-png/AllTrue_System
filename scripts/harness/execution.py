"""Prepare a leased execution context for a Task (H4)."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .governance_adapter import classify_task_paths
from .leases import LeaseBusyError, acquire, contract_resource, program_resource
from .models import Task
from .states import TaskState
from .store import HarnessStore
from .transitions import TransitionError, enqueue_founder_escalation, transition
from .worktree import WorktreeBinding, prepare_worktree


@dataclass
class PreparedExecution:
    task: Task
    binding: WorktreeBinding | None
    leases: list[dict[str, Any]]
    skipped_reason: str | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "task_id": self.task.task_id,
            "status": self.task.status.value,
            "worktree": self.binding.worktree if self.binding else self.task.worktree,
            "leases": self.leases,
            "skipped_reason": self.skipped_reason,
            "governance": self.task.governance_result,
        }


def prepare_task_execution(
    store: HarnessStore,
    task: Task,
    *,
    worker: str = "harness",
    create_worktree: bool = False,
    dry_run: bool = True,
) -> PreparedExecution:
    """Acquire WIP/contract leases and optionally create a governed worktree.

    Default create_worktree=False so unit tests and dry planners do not spawn
    real agent-start sessions. Operator/H5 paths may set create_worktree=True.
    """
    if task.status not in {TaskState.READY, TaskState.DISCOVERED}:
        return PreparedExecution(
            task=task,
            binding=None,
            leases=[],
            skipped_reason=f"not_preparable:{task.status.value}",
        )

    if task.status == TaskState.DISCOVERED:
        task = transition(
            store,
            task,
            TaskState.READY,
            actor="execution_prepare",
            evidence={"next_action": "prepare_lease"},
        )

    paths = task.affected_paths or task.scope
    gov = classify_task_paths(paths)
    task.governance_result = gov.to_dict()
    store.upsert_task(task)

    if gov.founder_required:
        enqueue_founder_escalation(
            store,
            task,
            decision_required="approve founder-required scope before lease",
            why="; ".join(gov.reasons) or "governance founder_required",
            governance_rule="autonomy_gate.classify_activation_scope",
            options=["approve_narrowed_scope", "reject", "defer"],
            consequences=f"blocks {task.program_id}/{task.task_id}",
            recommended_default="defer_until_scope_narrowed",
            evidence=gov.to_dict(),
        )
        refreshed = store.get_task(task.task_id) or task
        return PreparedExecution(
            task=refreshed,
            binding=None,
            leases=[],
            skipped_reason="founder_required",
        )

    leases: list[dict[str, Any]] = []
    try:
        leases.append(
            acquire(
                store,
                program_resource(task.program_id),
                task_id=task.task_id,
                worker=worker,
            )
        )
        for contract in task.affected_contracts:
            leases.append(
                acquire(
                    store,
                    contract_resource(contract),
                    task_id=task.task_id,
                    worker=worker,
                )
            )
    except LeaseBusyError as exc:
        blocked = transition(
            store,
            task,
            TaskState.BLOCKED,
            actor="lease_manager",
            evidence={"blocker": str(exc), "next_action": "retry_when_lease_free"},
        )
        return PreparedExecution(
            task=blocked,
            binding=None,
            leases=leases,
            skipped_reason=str(exc),
        )

    binding: WorktreeBinding | None = None
    if create_worktree:
        binding = prepare_worktree(task, dry_run=dry_run)
        task.worktree = binding.worktree
        task.branch = binding.branch
        task.evidence.code = {
            "worktree": binding.worktree,
            "branch": binding.branch,
            "base_sha": binding.base_sha,
        }

    task.assignee = worker
    task.lease_id = leases[0]["lease_id"] if leases else ""
    task.next_action = "generate_worker_goal"
    store.upsert_task(task)

    try:
        leased = transition(
            store,
            task,
            TaskState.LEASED,
            actor="lease_manager",
            evidence={
                "leases": [{"resource_key": x["resource_key"], "lease_id": x["lease_id"]} for x in leases],
                "worktree": task.worktree,
                "governance_result": gov.to_dict(),
            },
        )
    except TransitionError as exc:
        return PreparedExecution(
            task=task,
            binding=binding,
            leases=leases,
            skipped_reason=str(exc),
        )

    return PreparedExecution(task=leased, binding=binding, leases=leases)
