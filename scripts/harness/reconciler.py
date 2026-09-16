"""PR / CI reconciliation (H6) — observe evidence; fail closed on SHA drift."""

from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from typing import Any

from .models import Task
from .states import TaskState
from .store import HarnessStore
from .transitions import TransitionError, transition


@dataclass
class CheckSummary:
    head_sha: str
    conclusion: str  # success | failure | pending | unknown
    required: list[dict[str, Any]]
    raw: dict[str, Any]

    def to_dict(self) -> dict[str, Any]:
        return {
            "head_sha": self.head_sha,
            "conclusion": self.conclusion,
            "required": list(self.required),
            "raw": dict(self.raw),
        }


def observe_pr_checks(pr_number: int, *, repo: str = "jerry200176-png/AllTrue_System") -> CheckSummary:
    """Call ``gh pr checks`` / ``gh pr view``; never invent green."""
    view = subprocess.run(
        ["gh", "pr", "view", str(pr_number), "--repo", repo, "--json", "headRefOid,url,number,state"],
        capture_output=True,
        text=True,
        check=False,
    )
    if view.returncode != 0:
        return CheckSummary(
            head_sha="",
            conclusion="unknown",
            required=[],
            raw={"error": view.stderr, "stdout": view.stdout},
        )
    meta = json.loads(view.stdout)
    head = str(meta.get("headRefOid") or "")
    checks = subprocess.run(
        ["gh", "pr", "checks", str(pr_number), "--repo", repo, "--json", "name,state,bucket,link"],
        capture_output=True,
        text=True,
        check=False,
    )
    items: list[dict[str, Any]] = []
    if checks.returncode == 0 and checks.stdout.strip():
        items = json.loads(checks.stdout)
    states = {str(i.get("state", "")).upper() for i in items}
    if not items:
        conclusion = "pending"
    elif any(s in {"FAILURE", "FAIL", "CANCELLED", "ERROR", "TIMED_OUT"} for s in states):
        conclusion = "failure"
    elif any(s in {"PENDING", "QUEUED", "IN_PROGRESS", "EXPECTED"} for s in states):
        conclusion = "pending"
    elif states and states.issubset({"SUCCESS", "PASS", "SKIPPED", "NEUTRAL"}):
        conclusion = "success"
    else:
        conclusion = "unknown"
    return CheckSummary(head_sha=head, conclusion=conclusion, required=items, raw={"pr": meta})


def reconcile_task_ci(
    store: HarnessStore,
    task: Task,
    *,
    checks: CheckSummary | None = None,
    expected_head_sha: str | None = None,
) -> Task:
    """Advance CI_* states from evidence. SHA mismatch → fail closed (no transition to green)."""
    if task.status not in {TaskState.PR_OPEN, TaskState.CI_PENDING, TaskState.CI_FAILED, TaskState.CI_GREEN}:
        return task

    if checks is None:
        pr_number = int((task.pr or {}).get("number") or 0)
        if not pr_number:
            raise TransitionError("missing pr.number for CI reconcile")
        checks = observe_pr_checks(pr_number)

    expected = expected_head_sha or (task.pr or {}).get("head_sha") or ""
    if expected and checks.head_sha and expected != checks.head_sha:
        raise TransitionError(
            f"PR head changed: expected={expected} observed={checks.head_sha}"
        )

    evidence = {
        "ci": checks.to_dict(),
        "pr": {**(task.pr or {}), "head_sha": checks.head_sha or (task.pr or {}).get("head_sha")},
    }

    if task.status == TaskState.PR_OPEN:
        return transition(store, task, TaskState.CI_PENDING, actor="reconciler", evidence=evidence)

    if checks.conclusion == "success":
        if task.status == TaskState.CI_GREEN:
            return task
        return transition(
            store,
            task,
            TaskState.CI_GREEN,
            actor="reconciler",
            evidence={**evidence, "exact_sha": checks.head_sha, "observed_sha": checks.head_sha},
        )
    if checks.conclusion == "failure":
        if task.status == TaskState.CI_FAILED:
            return task
        return transition(store, task, TaskState.CI_FAILED, actor="reconciler", evidence=evidence)
    # pending / unknown stay or move to pending
    if task.status != TaskState.CI_PENDING:
        return transition(store, task, TaskState.CI_PENDING, actor="reconciler", evidence=evidence)
    task.ci = checks.to_dict()
    store.upsert_task(task)
    return task
