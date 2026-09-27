"""Agent-control launcher adapter — start new task worktree or attach/resume existing.

H4b: closes the gap where `agent-start` failed closed on existing worktrees.
Harness prefers the installed gateway; falls back to in-process attach when
`--attach` is unavailable (older gateway) so resume stays fail-closed only on
real path/branch policy violations — not on "already exists".
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping, Protocol

DEFAULT_AGENT_START = Path(
    os.environ.get("AGENT_START_BIN", "/home/jerry/workspace/agent-control/bin/agent-start")
)
DEFAULT_SESSIONS_DIR = Path(
    os.environ.get("AGENT_SESSIONS_DIR", "/home/jerry/workspace/agent-control/sessions")
)
DEFAULT_TASK_ROOTS = {
    "alltrue": Path(os.environ.get("ALLTRUE_TASK_ROOT", "/home/jerry/workspace/tasks/alltrue")),
    "sunrise": Path(os.environ.get("SUNRISE_TASK_ROOT", "/home/jerry/workspace/tasks/sunrise")),
    "portfolio-ops": Path(
        os.environ.get("PORTFOLIO_OPS_TASK_ROOT", "/home/jerry/workspace/tasks/portfolio-ops")
    ),
}
DEFAULT_REMOTES = {
    "alltrue": "https://github.com/jerry200176-png/AllTrue_System.git",
    "sunrise": "https://github.com/jerry200176-png/sunrise-cafe.git",
    "portfolio-ops": "https://github.com/jerry200176-png/portfolio-ops.git",
}

_TASK_ID_RE = re.compile(r"^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$")
_FORBIDDEN_SUBSTR = (
    "/actions-runner-alltrue/",
    "/workspace-backups/",
    "/mnt/c/",
    "/AllTrue_System-clean",
    "/workspace/AllTrue_System",
    "/workspace/sunrise-cafe",
)


def _now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def sanitize_launcher_task_id(raw: str) -> str:
    """Map harness task_id → agent-control task_id pattern."""
    s = (raw or "").strip().replace("/", "-").replace(" ", "-")
    s = re.sub(r"[^0-9A-Za-z._-]", "-", s)
    s = re.sub(r"-{2,}", "-", s).strip("-._")
    if not s:
        s = "task"
    if s[0] in ".-":
        s = "t" + s
    return s[:64]


def resolve_launcher_task_id(task: Any | None, ctx: Mapping[str, Any]) -> str:
    override = ctx.get("launcher_task_id") or ctx.get("agent_task_id")
    if override:
        tid = sanitize_launcher_task_id(str(override))
        if not _TASK_ID_RE.match(tid):
            raise ValueError(f"invalid launcher_task_id: {override}")
        return tid
    if task is not None:
        wt = getattr(task, "worktree", "") or ""
        if wt:
            return sanitize_launcher_task_id(Path(wt).name)
        branch = getattr(task, "branch", "") or ""
        if branch.startswith("chore/task-"):
            return sanitize_launcher_task_id(branch[len("chore/task-") :])
    return sanitize_launcher_task_id(str(ctx.get("task_id") or "task"))


def worktree_for(project: str, launcher_task_id: str) -> Path:
    root = DEFAULT_TASK_ROOTS.get(project) or DEFAULT_TASK_ROOTS["alltrue"]
    return root / launcher_task_id


def assert_safe_worktree(project: str, path: Path) -> None:
    resolved = path.resolve()
    if str(resolved) == "/home/jerry/alltrue":
        raise ValueError("forbidden checkout: /home/jerry/alltrue")
    text = str(resolved)
    for bad in _FORBIDDEN_SUBSTR:
        if bad in text:
            raise ValueError(f"forbidden path class: {bad}")
    root = DEFAULT_TASK_ROOTS.get(project, DEFAULT_TASK_ROOTS["alltrue"]).resolve()
    try:
        resolved.relative_to(root)
    except ValueError as exc:
        raise ValueError(f"worktree not under task root {root}: {resolved}") from exc


@dataclass(frozen=True)
class LaunchResult:
    ok: bool
    mode: str  # start | attach | resume
    reason: str
    session_id: str = ""
    worktree_path: str = ""
    branch: str = ""
    base_sha: str = ""
    launcher_task_id: str = ""
    project: str = "alltrue"
    manifest_path: str = ""
    deferred: bool = False
    raw: dict[str, Any] | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "ok": self.ok,
            "mode": self.mode,
            "reason": self.reason,
            "session_id": self.session_id,
            "worktree_path": self.worktree_path,
            "branch": self.branch,
            "base_sha": self.base_sha,
            "launcher_task_id": self.launcher_task_id,
            "project": self.project,
            "manifest_path": self.manifest_path,
            "deferred": self.deferred,
            "raw": self.raw,
        }


class Launcher(Protocol):
    def start_or_attach(
        self,
        *,
        project: str,
        launcher_task_id: str,
        prefer_resume: bool = False,
        dry_run: bool = True,
    ) -> LaunchResult: ...


def _write_session_manifest(
    *,
    project: str,
    launcher_task_id: str,
    worktree: Path,
    branch: str,
    base_sha: str,
    sessions_dir: Path,
    mode: str,
) -> tuple[str, Path]:
    session_id = uuid.uuid4().hex
    started_at = _now_iso()
    remote = DEFAULT_REMOTES.get(project, DEFAULT_REMOTES["alltrue"])
    obj = {
        "schema_version": "1.0",
        "session_id": session_id,
        "project": project,
        "task_id": launcher_task_id,
        "repo_remote": remote,
        "base_sha": base_sha,
        "branch": branch,
        "worktree_path": str(worktree),
        "started_at": started_at,
        "production_mutation": False,
        "preflight_result": "pass",
        "provenance_type": "agent-session",
        "agent_cli": None,
        "gateway_version": "0.5.1-h4b",
    }
    sessions_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = sessions_dir / f"{session_id}.json"
    text = json.dumps(obj, indent=2) + "\n"
    manifest_path.write_text(text, encoding="utf-8")
    sess_dir = worktree / ".agent-session"
    sess_dir.mkdir(parents=True, exist_ok=True)
    (sess_dir / "manifest.json").write_text(text, encoding="utf-8")
    # Best-effort launch log (same shape as agent-control).
    logs = sessions_dir.parent / "logs"
    try:
        logs.mkdir(parents=True, exist_ok=True)
        row = {
            "logged_at": _now_iso(),
            "session_id": session_id,
            "project": project,
            "task_id": launcher_task_id,
            "base_sha": base_sha,
            "branch": branch,
            "worktree_path": str(worktree),
            "preflight_result": "pass",
            "agent_cli": None,
            "mode": mode,
        }
        with (logs / "launches.jsonl").open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")
    except OSError:
        pass
    return session_id, manifest_path


def _git_head_sha(worktree: Path) -> str:
    try:
        out = subprocess.check_output(
            ["git", "-C", str(worktree), "rev-parse", "HEAD"],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()
        if re.match(r"^[0-9a-f]{40}$", out):
            return out
    except (OSError, subprocess.CalledProcessError):
        pass
    return "0" * 40


def _git_branch(worktree: Path) -> str:
    try:
        out = subprocess.check_output(
            ["git", "-C", str(worktree), "rev-parse", "--abbrev-ref", "HEAD"],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()
        return out or "HEAD"
    except (OSError, subprocess.CalledProcessError):
        return "HEAD"


class InProcessLauncher:
    """Attach without shelling out; start requires an already-present worktree or fails.

    Used for tests and as fallback when gateway lacks --attach.
    Does **not** create new git worktrees (WORKTREE_POLICY — create via agent-start).
    """

    def __init__(self, sessions_dir: Path | None = None) -> None:
        self.sessions_dir = sessions_dir or DEFAULT_SESSIONS_DIR

    def start_or_attach(
        self,
        *,
        project: str,
        launcher_task_id: str,
        prefer_resume: bool = False,
        dry_run: bool = True,
    ) -> LaunchResult:
        if not _TASK_ID_RE.match(launcher_task_id):
            return LaunchResult(
                ok=False, mode="start", reason="invalid_launcher_task_id",
                launcher_task_id=launcher_task_id, project=project,
            )
        wt = worktree_for(project, launcher_task_id)
        try:
            assert_safe_worktree(project, wt)
        except ValueError as exc:
            return LaunchResult(
                ok=False, mode="attach", reason=f"unsafe_worktree:{exc}",
                launcher_task_id=launcher_task_id, project=project,
                worktree_path=str(wt),
            )
        if not wt.is_dir():
            return LaunchResult(
                ok=False,
                mode="start",
                reason="worktree_missing_use_agent_start",
                launcher_task_id=launcher_task_id,
                project=project,
                worktree_path=str(wt),
                deferred=False,
            )
        expected_branch = f"chore/task-{launcher_task_id}"
        branch = _git_branch(wt)
        # Allow Supervisor-minted branches that already match intent, or exact expected.
        if branch not in {expected_branch, "HEAD"} and not branch.startswith("chore/task-"):
            # Still allow if .agent-session exists (supervisor-minted resume).
            if not (wt / ".agent-session" / "manifest.json").is_file():
                return LaunchResult(
                    ok=False,
                    mode="attach",
                    reason="branch_mismatch",
                    launcher_task_id=launcher_task_id,
                    project=project,
                    worktree_path=str(wt),
                    branch=branch,
                )
        base_sha = _git_head_sha(wt)
        mode = "resume" if prefer_resume else "attach"
        session_id, manifest = _write_session_manifest(
            project=project,
            launcher_task_id=launcher_task_id,
            worktree=wt,
            branch=branch if branch != "HEAD" else expected_branch,
            base_sha=base_sha,
            sessions_dir=self.sessions_dir,
            mode=mode,
        )
        return LaunchResult(
            ok=True,
            mode=mode,
            reason="attached" if mode == "attach" else "resumed",
            session_id=session_id,
            worktree_path=str(wt),
            branch=branch if branch != "HEAD" else expected_branch,
            base_sha=base_sha,
            launcher_task_id=launcher_task_id,
            project=project,
            manifest_path=str(manifest),
            deferred=False,
            raw={"dry_run": dry_run},
        )


class AgentControlLauncher:
    """Shell out to agent-start; use --attach when worktree exists."""

    def __init__(
        self,
        agent_start: Path | None = None,
        fallback: Launcher | None = None,
    ) -> None:
        self.agent_start = Path(agent_start) if agent_start else DEFAULT_AGENT_START
        self.fallback = fallback or InProcessLauncher()

    def start_or_attach(
        self,
        *,
        project: str,
        launcher_task_id: str,
        prefer_resume: bool = False,
        dry_run: bool = True,
    ) -> LaunchResult:
        wt = worktree_for(project, launcher_task_id)
        exists = wt.exists()
        if not exists and os.environ.get("HARNESS_SPAWN_CREATE", "0") != "1":
            # Fail soft: Supervisor/agent-start creates worktrees; harness attaches.
            return LaunchResult(
                ok=False,
                mode="start",
                reason="worktree_missing_use_agent_start",
                launcher_task_id=launcher_task_id,
                project=project,
                worktree_path=str(wt),
            )
        if not self.agent_start.is_file():
            if exists:
                return self.fallback.start_or_attach(
                    project=project,
                    launcher_task_id=launcher_task_id,
                    prefer_resume=prefer_resume,
                    dry_run=dry_run,
                )
            return LaunchResult(
                ok=False,
                mode="start",
                reason="agent_start_missing",
                launcher_task_id=launcher_task_id,
                project=project,
                worktree_path=str(wt),
            )

        cmd = [str(self.agent_start), project, launcher_task_id]
        if exists:
            cmd.append("--attach")
        if dry_run:
            cmd.append("--dry-run")
        try:
            proc = subprocess.run(
                cmd, capture_output=True, text=True, timeout=120, check=False,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            if exists:
                return self.fallback.start_or_attach(
                    project=project,
                    launcher_task_id=launcher_task_id,
                    prefer_resume=prefer_resume,
                    dry_run=dry_run,
                )
            return LaunchResult(
                ok=False, mode="start", reason=f"launcher_exec_error:{exc}",
                launcher_task_id=launcher_task_id, project=project,
            )

        stdout = proc.stdout or ""
        stderr = proc.stderr or ""
        combined = stdout + "\n" + stderr
        # Prefer machine line if present.
        machine: dict[str, Any] | None = None
        for line in combined.splitlines():
            if line.startswith("WORKER_RUN_JSON="):
                try:
                    machine = json.loads(line[len("WORKER_RUN_JSON=") :])
                except json.JSONDecodeError:
                    machine = None
                break

        if proc.returncode != 0:
            # Legacy gateway: worktree exists without --attach support.
            if exists and (
                "worktree exists" in combined
                or "unknown arg: --attach" in combined
                or "unknown arg: --attach" in stderr
            ):
                return self.fallback.start_or_attach(
                    project=project,
                    launcher_task_id=launcher_task_id,
                    prefer_resume=prefer_resume or True,
                    dry_run=dry_run,
                )
            return LaunchResult(
                ok=False,
                mode="attach" if exists else "start",
                reason="agent_start_failed",
                launcher_task_id=launcher_task_id,
                project=project,
                worktree_path=str(wt),
                raw={"stdout": stdout[-2000:], "stderr": stderr[-2000:], "rc": proc.returncode},
            )

        mode = "resume" if (exists and prefer_resume) else ("attach" if exists else "start")
        session_id = ""
        for line in combined.splitlines():
            if "session_id=" in line:
                session_id = line.split("session_id=", 1)[-1].strip()
        if machine:
            session_id = str(machine.get("session_id") or session_id)
            mode = str(machine.get("mode") or mode)
        branch = f"chore/task-{launcher_task_id}"
        base_sha = ""
        manifest = ""
        if machine:
            branch = str(machine.get("branch") or branch)
            base_sha = str(machine.get("base_sha") or "")
            manifest = str(machine.get("manifest_path") or "")
            wt_s = str(machine.get("worktree_path") or wt)
        else:
            wt_s = str(wt)
            # Parse ok lines from agent-start
            for line in combined.splitlines():
                if "worktree=" in line:
                    wt_s = line.split("worktree=", 1)[-1].strip()
                if "manifest=" in line:
                    manifest = line.split("manifest=", 1)[-1].strip()
        if not session_id and (wt / ".agent-session" / "manifest.json").is_file():
            try:
                session_id = json.loads(
                    (wt / ".agent-session" / "manifest.json").read_text(encoding="utf-8")
                ).get("session_id", "")
            except (OSError, json.JSONDecodeError):
                session_id = ""
        return LaunchResult(
            ok=True,
            mode=mode,
            reason=(
                "started" if mode == "start"
                else "resumed" if mode == "resume"
                else "attached"
            ),
            session_id=session_id,
            worktree_path=wt_s,
            branch=branch,
            base_sha=base_sha,
            launcher_task_id=launcher_task_id,
            project=project,
            manifest_path=manifest,
            deferred=False,
            raw={"stdout": stdout[-1000:], "rc": 0, "machine": machine},
        )


_LAUNCHER: Launcher | None = None


def get_launcher() -> Launcher:
    global _LAUNCHER
    if _LAUNCHER is not None:
        return _LAUNCHER
    kind = os.environ.get("HARNESS_LAUNCHER", "agent-control").strip().lower()
    if kind in {"inprocess", "in-process", "fake-attach"}:
        return InProcessLauncher()
    if kind in {"noop", "deferred"}:
        return _NoopLauncher()
    return AgentControlLauncher()


def set_launcher_for_tests(launcher: Launcher | None) -> None:
    global _LAUNCHER
    _LAUNCHER = launcher


class _NoopLauncher:
    def start_or_attach(
        self,
        *,
        project: str,
        launcher_task_id: str,
        prefer_resume: bool = False,
        dry_run: bool = True,
    ) -> LaunchResult:
        return LaunchResult(
            ok=True,
            mode="start",
            reason="worktree_launcher_deferred",
            launcher_task_id=launcher_task_id,
            project=project,
            deferred=True,
        )


__all__ = [
    "LaunchResult",
    "Launcher",
    "InProcessLauncher",
    "AgentControlLauncher",
    "sanitize_launcher_task_id",
    "resolve_launcher_task_id",
    "worktree_for",
    "assert_safe_worktree",
    "get_launcher",
    "set_launcher_for_tests",
]
