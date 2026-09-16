"""H7: one-program autonomous tick — select → prepare → dispatch (no production)."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .execution import prepare_task_execution
from .models import Program
from .planner import select_next_task
from .store import HarnessStore
from .worker import dispatch_task


@dataclass
class LoopTickResult:
    program_id: str
    action: str
    detail: dict[str, Any]

    def to_dict(self) -> dict[str, Any]:
        return {"program_id": self.program_id, "action": self.action, "detail": self.detail}


def tick_program(
    store: HarnessStore,
    program: Program,
    *,
    worker: str = "harness",
    create_worktree: bool = False,
    enable_codex: bool = False,
) -> LoopTickResult:
    """Single deterministic tick. Never mutates production."""
    plan = select_next_task(store, program, dry_run=False)
    if not plan.would_execute or not plan.selected:
        return LoopTickResult(
            program_id=program.program_id,
            action="idle",
            detail=plan.to_dict(),
        )

    task = store.get_task(plan.selected.task_id) or plan.selected
    prepared = prepare_task_execution(
        store,
        task,
        worker=worker,
        create_worktree=create_worktree,
        dry_run=True,
    )
    if prepared.skipped_reason:
        return LoopTickResult(
            program_id=program.program_id,
            action="prepare_skipped",
            detail=prepared.to_dict(),
        )

    if not prepared.task.worktree and not create_worktree:
        # File-queue still needs a path; use ephemeral under state dir for Goal only.
        from pathlib import Path
        import os

        base = Path(os.environ.get("HARNESS_GOAL_ROOT", "/home/jerry/workspace/state/alltrue/goals"))
        goal_wt = base / program.program_id / prepared.task.task_id
        goal_wt.mkdir(parents=True, exist_ok=True)
        prepared.task.worktree = str(goal_wt)
        store.upsert_task(prepared.task)

    dispatched = dispatch_task(
        store,
        prepared.task,
        enable_codex=enable_codex,
    )
    return LoopTickResult(
        program_id=program.program_id,
        action="dispatched" if dispatched.get("ok") else "dispatch_failed",
        detail=dispatched,
    )
