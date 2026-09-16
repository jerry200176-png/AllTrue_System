"""Worker Goal generation + thin adapter contract (H5).

Harness owns orchestration. Workers own bounded implementation.
Default dispatch is dry-run / file-queue — does not fake Codex capabilities.
"""

from __future__ import annotations

import json
import os
import subprocess
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Protocol

from .models import Task
from .states import TaskState
from .store import HarnessStore
from .transitions import transition

GOAL_REL = Path(".agent-session") / "harness-goal.json"


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def build_worker_goal(
    task: Task,
    *,
    repository: str = "jerry200176-png/AllTrue_System",
    authority: str = "scripts/governance/autonomy_gate.py",
) -> dict[str, Any]:
    """Structured Goal for a worker — not a giant static prompt."""
    return {
        "schema_version": 1,
        "generated_at": _now(),
        "outcome": task.outcome,
        "repository": repository,
        "worktree": task.worktree,
        "branch": task.branch,
        "exact_sha": (task.evidence.code or {}).get("base_sha", ""),
        "scope": list(task.scope),
        "non_scope": list(task.non_scope),
        "affected_paths": list(task.affected_paths),
        "affected_contracts": list(task.affected_contracts),
        "existing_contracts": [
            "docs/governance/EVIDENCE_CONTRACT.md",
            "docs/governance/WORKTREE_POLICY.md",
            "docs/harness/ARCHITECTURE_V1.md",
            authority,
        ],
        "relevant_evidence": task.evidence.to_dict(),
        "authority": authority,
        "governance_constraints": task.governance_result,
        "required_tests": [
            "python3 scripts/tests/test_harness_core.py",
        ],
        "acceptance_criteria": [
            "changes limited to scope",
            "tests green for touched surface",
            "evidence recorded; no 'done because agent said done'",
        ],
        "stop_conditions": [
            "founder_required_from_autonomy_gate",
            "production_mutation_requested",
            "protected_scope_touched",
            "ambiguous_product_semantics",
        ],
        "required_final_evidence": [
            "code.branch",
            "code.commit",
            "test.command+result",
            "pr.number+head_sha when PR opened",
        ],
        "task_id": task.task_id,
        "program_id": task.program_id,
        "risk_declaration": task.risk_declaration,
    }


class WorkerAdapter(Protocol):
    def dispatch(self, task: Task, goal: dict[str, Any]) -> dict[str, Any]: ...
    def status(self, worker_id: str) -> dict[str, Any]: ...
    def collect_evidence(self, task: Task) -> dict[str, Any]: ...
    def resume(self, task: Task) -> dict[str, Any]: ...
    def cancel(self, task: Task) -> dict[str, Any]: ...


@dataclass
class FileQueueWorkerAdapter:
    """Durable Goal file + optional non-interactive Codex when explicitly enabled."""

    enable_codex: bool = False
    codex_route: str = os.environ.get("CODEX_ROUTE", "/home/jerry/.local/bin/codex-route")

    def dispatch(self, task: Task, goal: dict[str, Any]) -> dict[str, Any]:
        if not task.worktree:
            return {"ok": False, "error": "missing_worktree", "mode": "file_queue"}
        wt = Path(task.worktree)
        goal_path = wt / GOAL_REL
        goal_path.parent.mkdir(parents=True, exist_ok=True)
        goal_path.write_text(json.dumps(goal, indent=2) + "\n", encoding="utf-8")
        result: dict[str, Any] = {
            "ok": True,
            "mode": "file_queue",
            "goal_path": str(goal_path),
            "worker_id": task.assignee or "file-queue",
        }
        if self.enable_codex:
            prompt = (
                f"Execute harness task {task.task_id}. "
                f"Read Goal at {goal_path}. Stay in scope. Stop on Founder boundaries."
            )
            cmd = [
                self.codex_route,
                "--complexity",
                "medium",
                "--risk",
                "low",
                "--",
                "-C",
                str(wt),
                "-s",
                "workspace-write",
                "--ephemeral",
                prompt,
            ]
            proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
            result["mode"] = "codex_exec"
            result["rc"] = proc.returncode
            result["stdout_tail"] = (proc.stdout or "")[-2000:]
            result["stderr_tail"] = (proc.stderr or "")[-2000:]
            result["ok"] = proc.returncode == 0
        return result

    def status(self, worker_id: str) -> dict[str, Any]:
        return {"worker_id": worker_id, "alive": None, "note": "file_queue_has_no_daemon"}

    def collect_evidence(self, task: Task) -> dict[str, Any]:
        if not task.worktree:
            return {}
        result_path = Path(task.worktree) / ".agent-session" / "harness-result.json"
        if result_path.is_file():
            return json.loads(result_path.read_text(encoding="utf-8"))
        return {"result_path": str(result_path), "present": False}

    def resume(self, task: Task) -> dict[str, Any]:
        goal = build_worker_goal(task)
        return self.dispatch(task, goal)

    def cancel(self, task: Task) -> dict[str, Any]:
        return {"ok": True, "cancelled": task.task_id, "note": "lease_release_is_caller_duty"}


def dispatch_task(
    store: HarnessStore,
    task: Task,
    *,
    adapter: WorkerAdapter | None = None,
    enable_codex: bool = False,
) -> dict[str, Any]:
    """Move LEASED → PLANNING → EXECUTING and dispatch Goal."""
    adapter = adapter or FileQueueWorkerAdapter(enable_codex=enable_codex)
    if task.status != TaskState.LEASED:
        return {"ok": False, "error": f"expected_LEASED_got_{task.status.value}"}

    planning = transition(
        store,
        task,
        TaskState.PLANNING,
        actor="worker_adapter",
        evidence={"next_action": "write_goal"},
    )
    goal = build_worker_goal(planning)
    if planning.worktree:
        # Persist goal even before dispatch return for crash resume.
        path = Path(planning.worktree) / GOAL_REL
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(goal, indent=2) + "\n", encoding="utf-8")

    dispatched = adapter.dispatch(planning, goal)
    executing = transition(
        store,
        planning,
        TaskState.EXECUTING,
        actor="worker_adapter",
        evidence={"dispatch": dispatched, "goal": {"task_id": goal["task_id"]}},
    )
    return {"ok": bool(dispatched.get("ok")), "task": executing.to_dict(), "dispatch": dispatched, "goal": goal}
