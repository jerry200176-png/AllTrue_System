"""Governed worktree binding via agent-start (no second worktree policy)."""

from __future__ import annotations

import json
import os
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .models import Task

AGENT_START = os.environ.get("AGENT_START", "/home/jerry/.local/bin/agent-start")
TASKS_ROOT = Path("/home/jerry/workspace/tasks/alltrue")


def sanitize_task_id(task_id: str) -> str:
    """agent-start task-id: lowercase slug."""
    slug = re.sub(r"[^a-zA-Z0-9._-]+", "-", task_id.strip()).strip("-").lower()
    return slug or "task"


@dataclass
class WorktreeBinding:
    task_id: str
    agent_task_id: str
    worktree: str
    branch: str
    base_sha: str
    dry_run: bool
    raw: dict[str, Any]

    def to_dict(self) -> dict[str, Any]:
        return {
            "task_id": self.task_id,
            "agent_task_id": self.agent_task_id,
            "worktree": self.worktree,
            "branch": self.branch,
            "base_sha": self.base_sha,
            "dry_run": self.dry_run,
            "raw": self.raw,
        }


def expected_worktree_path(task_id: str) -> Path:
    return TASKS_ROOT / sanitize_task_id(task_id)


def prepare_worktree(
    task: Task,
    *,
    dry_run: bool = True,
    agent_start: str = AGENT_START,
) -> WorktreeBinding:
    """Create or describe a governed worktree for the task.

    dry_run=True invokes ``agent-start … --dry-run`` (creates WT + manifest,
    does not launch a coding CLI). Set dry_run=False only when a worker will
    attach; still does not enable production_mutation.
    """
    agent_task_id = sanitize_task_id(task.task_id)
    cmd = [agent_start, "alltrue", agent_task_id]
    if dry_run:
        cmd.append("--dry-run")
    else:
        # Non-interactive: create session only; worker adapter launches Codex separately.
        cmd.append("--dry-run")

    proc = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        check=False,
    )
    stdout = proc.stdout or ""
    stderr = proc.stderr or ""
    if proc.returncode != 0:
        raise RuntimeError(
            f"agent-start failed rc={proc.returncode}\nstdout={stdout}\nstderr={stderr}"
        )

    worktree = str(expected_worktree_path(task.task_id))
    # Prefer path printed by agent-start
    for line in (stdout + "\n" + stderr).splitlines():
        if "worktree=" in line:
            worktree = line.split("worktree=", 1)[1].strip()
        if "OK: worktree=" in line:
            worktree = line.split("OK: worktree=", 1)[1].strip()

    branch = ""
    base_sha = ""
    manifest_path = Path(worktree) / ".agent-session" / "manifest.json"
    if manifest_path.is_file():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        branch = str(manifest.get("branch") or "")
        base_sha = str(manifest.get("base_sha") or "")

    return WorktreeBinding(
        task_id=task.task_id,
        agent_task_id=agent_task_id,
        worktree=worktree,
        branch=branch,
        base_sha=base_sha,
        dry_run=True,
        raw={"cmd": cmd, "stdout": stdout[-4000:], "stderr": stderr[-2000:]},
    )
