"""H4b WorkerRun — durable start/resume binding after DispatchAttempt CAS.

Flow: PlanResult → revalidate → CAS → start|resume canonical worker →
record child/session identity → observe handoff (fencing fail-closed).
"""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Any, Mapping

from .launcher import LaunchResult, get_launcher, resolve_launcher_task_id
from .store import HarnessStore


def _now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _primary_fencing(lease_binding: Mapping[str, Any] | None) -> int | None:
    bindings = (lease_binding or {}).get("bindings") or []
    if not bindings:
        return None
    try:
        return int(bindings[0].get("fencing_token"))
    except (TypeError, ValueError, AttributeError):
        return None


def start_or_resume_worker(
    store: HarnessStore,
    ctx: Mapping[str, Any],
    *,
    dry_run: bool = True,
) -> dict[str, Any]:
    """Spawn hook body: attach/resume existing worktree or start via agent-control."""
    attempt_id = str(ctx.get("attempt_id") or "")
    task_id = str(ctx.get("task_id") or "")
    worker = str(ctx.get("worker") or "")
    project = str(ctx.get("project") or "alltrue")
    lease_binding = ctx.get("lease_binding") or {}
    task = ctx.get("task") or (store.get_task(task_id) if task_id else None)

    prior = store.list_worker_runs(attempt_id=attempt_id) if attempt_id else []
    open_prior = [r for r in prior if r.get("status") in {"starting", "running"}]
    prefer_resume = bool(open_prior) or bool(ctx.get("prefer_resume"))

    launcher_task_id = resolve_launcher_task_id(task, ctx)
    launcher = ctx.get("launcher") or get_launcher()
    result: LaunchResult = launcher.start_or_attach(
        project=project,
        launcher_task_id=launcher_task_id,
        prefer_resume=prefer_resume,
        dry_run=dry_run,
    )

    # Soft-defer when create path is unavailable — leases stay held for later attach.
    soft_defer_reasons = {
        "worktree_missing_use_agent_start",
        "agent_start_missing",
        "worktree_launcher_deferred",
    }
    hard_fail_prefixes = ("unsafe_worktree", "branch_mismatch", "invalid_launcher_task_id")
    if not result.ok and result.reason in soft_defer_reasons:
        result = LaunchResult(
            ok=True,
            mode=result.mode or "start",
            reason=result.reason,
            session_id=result.session_id,
            worktree_path=result.worktree_path,
            branch=result.branch,
            base_sha=result.base_sha,
            launcher_task_id=result.launcher_task_id or launcher_task_id,
            project=result.project or project,
            manifest_path=result.manifest_path,
            deferred=True,
            raw=result.raw,
        )

    run_id = f"wr_{uuid.uuid4().hex[:16]}"
    created = _now_iso()
    if result.deferred:
        status = "deferred"
    elif result.ok:
        status = "running"
    else:
        status = "failed"

    payload = {
        "plan_id": ctx.get("plan_id"),
        "goal_id": ctx.get("goal_id"),
        "launch": result.to_dict(),
        "prefer_resume": prefer_resume,
    }
    row = {
        "run_id": run_id,
        "attempt_id": attempt_id,
        "task_id": task_id,
        "mode": result.mode,
        "status": status,
        "session_id": result.session_id,
        "worktree_path": result.worktree_path,
        "branch": result.branch,
        "worker": worker,
        "fencing_token": _primary_fencing(lease_binding if isinstance(lease_binding, Mapping) else {}),
        "lease_binding": lease_binding if isinstance(lease_binding, Mapping) else {},
        "payload": payload,
        "created_at": created,
        "updated_at": created,
    }
    store.put_worker_run(row)

    if task is not None and result.ok and not result.deferred:
        task.worktree = result.worktree_path or task.worktree
        task.branch = result.branch or task.branch
        task.assignee = worker or task.assignee
        task.next_action = "worker_running"
        task.updated_at = created
        store.upsert_task(task)

    hard = (not result.ok) or any(result.reason.startswith(p) for p in hard_fail_prefixes)
    spawn = {
        "spawned": bool(result.ok and not result.deferred),
        "deferred": bool(result.deferred),
        "ok": not hard,
        "reason": result.reason,
        "mode": result.mode,
        "run_id": run_id,
        "attempt_id": attempt_id,
        "task_id": task_id,
        "session_id": result.session_id,
        "worktree_path": result.worktree_path,
        "branch": result.branch,
        "launcher_task_id": launcher_task_id,
        "project": project,
        "manifest_path": result.manifest_path,
        "fencing_token": row["fencing_token"],
    }
    return spawn


def observe_worker_handoff(
    store: HarnessStore,
    *,
    attempt_id: str,
    claimed_bindings: Mapping[str, Any] | list[Mapping[str, Any]],
    result: Mapping[str, Any] | None = None,
) -> dict[str, Any]:
    """Record handoff observation on WorkerRun after ingest_handoff fencing passed.

    Fencing authenticity is enforced by ingest_handoff against *live* leases.
    This function only binds the accepted handoff to the durable child session row.
    """
    runs = store.list_worker_runs(attempt_id=attempt_id)
    active = [r for r in runs if r.get("status") in {"starting", "running", "deferred"}]
    target = active[-1] if active else (runs[-1] if runs else None)
    if target is None:
        return {"ok": False, "reason": "worker_run_missing", "attempt_id": attempt_id}

    if isinstance(claimed_bindings, Mapping) and "bindings" in claimed_bindings:
        claimed_list = list(claimed_bindings.get("bindings") or [])
    else:
        claimed_list = list(claimed_bindings or [])  # type: ignore[arg-type]

    target["status"] = "handed_off"
    target["updated_at"] = _now_iso()
    # Refresh fencing snapshot from the accepted claim (post-heartbeat tokens).
    if claimed_list:
        target["lease_binding"] = {"bindings": claimed_list}
        try:
            target["fencing_token"] = int(claimed_list[0].get("fencing_token"))
        except (TypeError, ValueError, AttributeError, IndexError):
            pass
    target["payload"] = {
        **(target.get("payload") or {}),
        "handoff_result": dict(result or {}),
        "handoff_accepted_at": _now_iso(),
    }
    store.put_worker_run(target)
    return {
        "ok": True,
        "reason": "worker_handoff_observed",
        "run_id": target["run_id"],
        "attempt_id": attempt_id,
        "session_id": target.get("session_id"),
    }


__all__ = ["start_or_resume_worker", "observe_worker_handoff"]
