"""H4 dispatch: revalidate PlanResult → DispatchAttempt → CAS LeaseBinding → LEASED.

Founder amendments (binding):
  A1 — execution-critical revalidation (not time-dependent snapshot equality)
  A2 — structured multi-resource LeaseBinding
  A3 — lease heartbeat / renew while mutation authority active
  A4 — durable DispatchAttempt written before spawn
  A5 — stale-worker fencing on handoff/result ingestion
  A6 — fail-closed partial rollback
  A7 — release execution leases at PR_READY / structured handoff
  A8 — concurrent dispatch exactly-one-winner (attempt index + CAS)
"""

from __future__ import annotations

import hashlib
import json
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Callable, Mapping

from .governance_adapter import check_risk_declaration, check_scope_drift
from .leases import LeaseBusyError, LeaseError, acquire, reclaim_stale, release, renew
from .planner import required_resources
from .states import ACTIVE_MUTATING, TaskState
from .store import HarnessStore
from .transitions import TransitionError, transition
from .worker_run import observe_worker_handoff, start_or_resume_worker

SpawnHook = Callable[[Mapping[str, Any]], Mapping[str, Any] | None]


def _now() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


def _iso(dt: datetime | None = None) -> str:
    return (dt or _now()).astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


@dataclass(frozen=True)
class LeaseResourceBinding:
    resource_key: str
    lease_id: str
    fencing_token: int
    task_id: str
    worker: str

    def to_dict(self) -> dict[str, Any]:
        return {
            "resource_key": self.resource_key,
            "lease_id": self.lease_id,
            "fencing_token": self.fencing_token,
            "task_id": self.task_id,
            "worker": self.worker,
        }

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> LeaseResourceBinding:
        return cls(
            resource_key=str(data["resource_key"]),
            lease_id=str(data["lease_id"]),
            fencing_token=int(data["fencing_token"]),
            task_id=str(data["task_id"]),
            worker=str(data.get("worker") or ""),
        )


@dataclass(frozen=True)
class LeaseBinding:
    """Multi-resource ownership set for one dispatch attempt (A2)."""

    bindings: tuple[LeaseResourceBinding, ...] = ()

    def to_dict(self) -> dict[str, Any]:
        return {"bindings": [b.to_dict() for b in self.bindings]}

    @classmethod
    def from_dict(cls, data: Mapping[str, Any] | None) -> LeaseBinding:
        raw = (data or {}).get("bindings") or []
        return cls(bindings=tuple(LeaseResourceBinding.from_dict(x) for x in raw))

    def resource_keys(self) -> list[str]:
        return [b.resource_key for b in self.bindings]


@dataclass(frozen=True)
class DispatchResult:
    ok: bool
    reason: str
    plan_id: str
    attempt_id: str | None = None
    task_id: str | None = None
    goal_id: str | None = None
    goal_contract_fingerprint: str | None = None
    observed_main_sha: str | None = None
    execution_critical_fingerprint: str = ""
    required_leases: list[str] = field(default_factory=list)
    lease_binding: LeaseBinding | None = None
    would_mutate: bool = False
    governance: dict[str, Any] | None = None
    spawn: dict[str, Any] | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "ok": self.ok,
            "reason": self.reason,
            "plan_id": self.plan_id,
            "attempt_id": self.attempt_id,
            "task_id": self.task_id,
            "goal_id": self.goal_id,
            "goal_contract_fingerprint": self.goal_contract_fingerprint,
            "observed_main_sha": self.observed_main_sha,
            "execution_critical_fingerprint": self.execution_critical_fingerprint,
            "required_leases": list(self.required_leases),
            "lease_binding": self.lease_binding.to_dict() if self.lease_binding else None,
            "would_mutate": self.would_mutate,
            "governance": self.governance,
            "spawn": self.spawn,
        }


def execution_critical_fingerprint(
    *,
    task_id: str,
    status: str,
    goal_id: str,
    goal_contract_fingerprint: str,
    subject_sha: str,
    observed_main_sha: str | None,
    required_leases: list[str],
    paths: list[str],
    program_blockers: list[str],
) -> str:
    """A1: stable critical world bind — excludes planner_clock / aging / volatile snapshot."""
    blob = {
        "task_id": task_id,
        "status": status,
        "goal_id": goal_id,
        "goal_contract_fingerprint": goal_contract_fingerprint,
        "subject_sha": subject_sha,
        "observed_main_sha": observed_main_sha or "",
        "required_leases": list(required_leases),
        "paths": sorted(paths),
        "program_blockers": list(program_blockers),
        "kind": "h4_execution_critical",
    }
    raw = json.dumps(blob, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(raw.encode()).hexdigest()[:32]


def _plan_selected_id(plan: Mapping[str, Any]) -> str | None:
    selected = plan.get("selected")
    if isinstance(selected, dict):
        return str(selected.get("task_id") or "") or None
    sid = plan.get("selected_task_id")
    return str(sid) if sid else None


def revalidate_plan(
    store: HarnessStore,
    plan: Mapping[str, Any],
    *,
    main_sha: str | None = None,
    apply_governance: bool = True,
) -> tuple[str | None, dict[str, Any]]:
    """Return (error_code, context). error_code None means ok to dispatch."""
    if not plan.get("would_execute"):
        return "plan_not_executable", {}
    plan_id = str(plan.get("plan_id") or "")
    task_id = _plan_selected_id(plan)
    if not task_id:
        return "task_missing", {"plan_id": plan_id}
    task = store.get_task(task_id)
    if task is None:
        return "task_missing", {"plan_id": plan_id, "task_id": task_id}
    if task.status != TaskState.READY:
        return "task_not_ready", {"task_id": task_id, "status": task.status.value}
    program = store.get_program(task.program_id)
    if program is None:
        return "unknown_program", {"task_id": task_id}
    if program.blockers:
        return "program_blocked", {"blockers": list(program.blockers)}
    wip = [t for t in store.list_tasks(program_id=task.program_id) if t.status in ACTIVE_MUTATING]
    if wip:
        return "wip_active", {"wip": wip[0].task_id}

    goal_id = plan.get("goal_id")
    if not goal_id:
        return "missing_goal_contract", {"task_id": task_id}
    goal = store.get_goal(str(goal_id))
    if goal is None:
        return "missing_goal_contract", {"goal_id": goal_id}
    plan_fp = str(plan.get("goal_contract_fingerprint") or "")
    if not plan_fp or plan_fp != goal.contract_fp:
        return "stale_goal_fp", {"expected": plan_fp, "live": goal.contract_fp}

    observed = main_sha if main_sha is not None else plan.get("observed_main_sha")
    observed_s = str(observed) if observed else None
    if observed_s and goal.subject_sha and goal.subject_sha != observed_s:
        return "stale_goal_sha", {"goal_sha": goal.subject_sha, "observed": observed_s}

    live_leases = required_resources(task)
    plan_leases = [str(x) for x in (plan.get("required_leases") or [])]
    if sorted(plan_leases) != sorted(live_leases):
        return "lease_set_mismatch", {"plan": plan_leases, "live": live_leases}

    paths = list(task.affected_paths or task.scope or [])
    gov: dict[str, Any] | None = None
    if apply_governance:
        if not paths:
            return "missing_paths", {"task_id": task_id}
        drift = check_scope_drift(goal, paths)
        if not drift.autonomous or drift.founder_required:
            return "scope_drift", {"governance": drift.to_dict()}
        decision = check_risk_declaration(
            paths,
            declared_risk=goal.declared_risk or task.risk_declaration,
            declared_tier=goal.declared_tier,
        )
        gov = decision.to_dict()
        if decision.founder_required or not decision.autonomous:
            return "founder_required", {"governance": gov}

    crit = execution_critical_fingerprint(
        task_id=task.task_id,
        status=task.status.value,
        goal_id=goal.goal_id,
        goal_contract_fingerprint=goal.contract_fp,
        subject_sha=goal.subject_sha,
        observed_main_sha=observed_s,
        required_leases=live_leases,
        paths=paths,
        program_blockers=list(program.blockers),
    )
    # A1: ignore time-dependent input_snapshot_fingerprint equality.
    return None, {
        "plan_id": plan_id,
        "task": task,
        "program": program,
        "goal": goal,
        "required_leases": live_leases,
        "observed_main_sha": observed_s,
        "execution_critical_fingerprint": crit,
        "governance": gov,
        "paths": paths,
    }


def _rollback_leases(
    store: HarnessStore,
    acquired: list[LeaseResourceBinding],
) -> str | None:
    """Release in reverse order; return error code if any release fails (A6)."""
    for binding in reversed(acquired):
        try:
            release(
                store,
                binding.resource_key,
                lease_id=binding.lease_id,
                fencing_token=binding.fencing_token,
                task_id=binding.task_id,
            )
        except LeaseError:
            return "partial_acquire_rollback_failed"
    return None


def _default_spawn(ctx: Mapping[str, Any]) -> dict[str, Any]:
    """H4b: start or resume canonical worker; record WorkerRun identity."""
    store = ctx.get("store")
    if store is None:
        return {
            "spawned": False,
            "deferred": True,
            "reason": "worktree_launcher_deferred",
            "attempt_id": ctx.get("attempt_id"),
            "task_id": ctx.get("task_id"),
        }
    return start_or_resume_worker(
        store, ctx, dry_run=bool(ctx.get("spawn_dry_run", True)),
    )


def dispatch(
    store: HarnessStore,
    plan: Mapping[str, Any],
    *,
    worker: str,
    apply: bool = False,
    main_sha: str | None = None,
    reclaim_expired: bool = True,
    apply_governance: bool = True,
    spawn_hook: SpawnHook | None = None,
    now: datetime | None = None,
    spawn_dry_run: bool = True,
) -> DispatchResult:
    """Dispatch a PlanResult. Dry-run by default. Writes DispatchAttempt before spawn (A4)."""
    now = now or _now()
    plan_id = str(plan.get("plan_id") or "")
    err, ctx = revalidate_plan(
        store, plan, main_sha=main_sha, apply_governance=apply_governance,
    )
    base = DispatchResult(
        ok=False,
        reason=err or "ok",
        plan_id=plan_id,
        task_id=_plan_selected_id(plan),
        goal_id=str(plan.get("goal_id") or "") or None,
        goal_contract_fingerprint=str(plan.get("goal_contract_fingerprint") or "") or None,
        observed_main_sha=main_sha if main_sha is not None else plan.get("observed_main_sha"),  # type: ignore[arg-type]
        required_leases=list(plan.get("required_leases") or []),
        would_mutate=False,
    )
    if err:
        return base

    task = ctx["task"]
    goal = ctx["goal"]
    leases = list(ctx["required_leases"])
    crit = str(ctx["execution_critical_fingerprint"])
    gov = ctx.get("governance")

    if not apply:
        return DispatchResult(
            ok=True,
            reason="dry_run_ok",
            plan_id=plan_id,
            task_id=task.task_id,
            goal_id=goal.goal_id,
            goal_contract_fingerprint=goal.contract_fp,
            observed_main_sha=ctx.get("observed_main_sha"),
            execution_critical_fingerprint=crit,
            required_leases=leases,
            would_mutate=True,
            governance=gov,
        )

    if reclaim_expired:
        reclaim_stale(store, now=now)

    attempt_id = f"da_{uuid.uuid4().hex[:16]}"
    created = _iso(now)
    attempt = {
        "attempt_id": attempt_id,
        "plan_id": plan_id,
        "task_id": task.task_id,
        "goal_id": goal.goal_id,
        "goal_contract_fingerprint": goal.contract_fp,
        "status": "pending",
        "worker": worker,
        "payload": {
            "execution_critical_fingerprint": crit,
            "required_leases": leases,
            "lease_binding": None,
            "spawn": None,
        },
        "created_at": created,
        "updated_at": created,
    }
    # A4: durable attempt BEFORE any spawn (and before acquire completes).
    try:
        store.insert_dispatch_attempt_open(attempt)
    except RuntimeError:
        return DispatchResult(
            ok=False,
            reason="dispatch_attempt_conflict",
            plan_id=plan_id,
            attempt_id=None,
            task_id=task.task_id,
            goal_id=goal.goal_id,
            goal_contract_fingerprint=goal.contract_fp,
            observed_main_sha=ctx.get("observed_main_sha"),
            execution_critical_fingerprint=crit,
            required_leases=leases,
            governance=gov,
        )

    acquired: list[LeaseResourceBinding] = []
    try:
        for key in leases:
            row = acquire(store, key, task_id=task.task_id, worker=worker, now=now)
            acquired.append(LeaseResourceBinding(
                resource_key=key,
                lease_id=str(row["lease_id"]),
                fencing_token=int(row["fencing_token"]),
                task_id=task.task_id,
                worker=worker,
            ))
    except LeaseBusyError:
        rb = _rollback_leases(store, acquired)
        attempt["status"] = "failed"
        attempt["updated_at"] = _iso()
        attempt["payload"] = {
            **attempt["payload"],
            "failure": "lease_conflict",
            "rollback": rb or "ok",
        }
        store.put_dispatch_attempt(attempt)
        return DispatchResult(
            ok=False,
            reason="lease_conflict" if not rb else rb,
            plan_id=plan_id,
            attempt_id=attempt_id,
            task_id=task.task_id,
            goal_id=goal.goal_id,
            goal_contract_fingerprint=goal.contract_fp,
            observed_main_sha=ctx.get("observed_main_sha"),
            execution_critical_fingerprint=crit,
            required_leases=leases,
            governance=gov,
        )

    binding = LeaseBinding(bindings=tuple(acquired))
    primary = acquired[0] if acquired else None
    try:
        transition(
            store,
            task,
            TaskState.LEASED,
            actor=f"dispatch:{worker}",
            evidence={
                "lease_id": primary.lease_id if primary else "",
                "lease_fencing_token": primary.fencing_token if primary else 0,
                "lease_binding": binding.to_dict(),
                "attempt_id": attempt_id,
                "plan_id": plan_id,
                "assignee": worker,
                "next_action": "execute_or_resume",
            },
        )
    except TransitionError as exc:
        rb = _rollback_leases(store, acquired)
        attempt["status"] = "failed"
        attempt["updated_at"] = _iso()
        attempt["payload"] = {
            **attempt["payload"],
            "failure": f"transition_error:{exc}",
            "rollback": rb or "ok",
        }
        store.put_dispatch_attempt(attempt)
        return DispatchResult(
            ok=False,
            reason="transition_failed" if not rb else rb,
            plan_id=plan_id,
            attempt_id=attempt_id,
            task_id=task.task_id,
            goal_id=goal.goal_id,
            goal_contract_fingerprint=goal.contract_fp,
            observed_main_sha=ctx.get("observed_main_sha"),
            execution_critical_fingerprint=crit,
            required_leases=leases,
            lease_binding=binding,
            governance=gov,
        )

    attempt["status"] = "acquired"
    attempt["updated_at"] = _iso()
    attempt["payload"] = {
        **attempt["payload"],
        "lease_binding": binding.to_dict(),
    }
    store.put_dispatch_attempt(attempt)

    hook = spawn_hook or _default_spawn
    spawn_info = dict(hook({
        "attempt_id": attempt_id,
        "task_id": task.task_id,
        "plan_id": plan_id,
        "worker": worker,
        "lease_binding": binding.to_dict(),
        "goal_id": goal.goal_id,
        "store": store,
        "task": task,
        "spawn_dry_run": spawn_dry_run,
        "project": "alltrue",
    }) or {})

    # Spawn failure is durable on the attempt but does not roll back leases —
    # Supervisor may retry attach/resume with same fencing (H4b).
    if spawn_info.get("ok") is False:
        attempt["status"] = "active"
        attempt["updated_at"] = _iso()
        attempt["payload"] = {
            **attempt["payload"],
            "spawn": spawn_info,
            "spawn_failed": True,
        }
        store.put_dispatch_attempt(attempt)
        return DispatchResult(
            ok=False,
            reason=str(spawn_info.get("reason") or "spawn_failed"),
            plan_id=plan_id,
            attempt_id=attempt_id,
            task_id=task.task_id,
            goal_id=goal.goal_id,
            goal_contract_fingerprint=goal.contract_fp,
            observed_main_sha=ctx.get("observed_main_sha"),
            execution_critical_fingerprint=crit,
            required_leases=leases,
            lease_binding=binding,
            would_mutate=True,
            governance=gov,
            spawn=spawn_info,
        )

    attempt["status"] = "active"
    attempt["updated_at"] = _iso()
    attempt["payload"] = {**attempt["payload"], "spawn": spawn_info}
    store.put_dispatch_attempt(attempt)

    return DispatchResult(
        ok=True,
        reason="dispatched",
        plan_id=plan_id,
        attempt_id=attempt_id,
        task_id=task.task_id,
        goal_id=goal.goal_id,
        goal_contract_fingerprint=goal.contract_fp,
        observed_main_sha=ctx.get("observed_main_sha"),
        execution_critical_fingerprint=crit,
        required_leases=leases,
        lease_binding=binding,
        would_mutate=True,
        governance=gov,
        spawn=spawn_info,
    )


def heartbeat(
    store: HarnessStore,
    attempt_id: str,
    *,
    worker: str,
    ttl_sec: int = 3600,
    now: datetime | None = None,
) -> DispatchResult:
    """A3: renew all LeaseBinding resources while mutation authority is active."""
    now = now or _now()
    attempt = store.get_dispatch_attempt(attempt_id)
    if not attempt:
        return DispatchResult(ok=False, reason="attempt_missing", plan_id="")
    if attempt["status"] not in {"acquired", "active"}:
        return DispatchResult(
            ok=False, reason="attempt_not_active", plan_id=attempt["plan_id"],
            attempt_id=attempt_id, task_id=attempt["task_id"],
        )
    binding = LeaseBinding.from_dict(attempt["payload"].get("lease_binding"))
    renewed: list[LeaseResourceBinding] = []
    for b in binding.bindings:
        try:
            row = renew(
                store,
                b.resource_key,
                lease_id=b.lease_id,
                fencing_token=b.fencing_token,
                task_id=b.task_id,
                worker=worker,
                ttl_sec=ttl_sec,
                now=now,
            )
            renewed.append(LeaseResourceBinding(
                resource_key=b.resource_key,
                lease_id=str(row["lease_id"]),
                fencing_token=int(row["fencing_token"]),
                task_id=b.task_id,
                worker=worker,
            ))
        except LeaseError:
            return DispatchResult(
                ok=False,
                reason="renew_fencing_mismatch",
                plan_id=attempt["plan_id"],
                attempt_id=attempt_id,
                task_id=attempt["task_id"],
                lease_binding=binding,
            )
    new_binding = LeaseBinding(bindings=tuple(renewed))
    attempt["payload"] = {**attempt["payload"], "lease_binding": new_binding.to_dict()}
    attempt["updated_at"] = _iso(now)
    attempt["worker"] = worker
    store.put_dispatch_attempt(attempt)
    return DispatchResult(
        ok=True,
        reason="heartbeat_ok",
        plan_id=attempt["plan_id"],
        attempt_id=attempt_id,
        task_id=attempt["task_id"],
        goal_id=attempt["goal_id"],
        goal_contract_fingerprint=attempt["goal_contract_fingerprint"],
        lease_binding=new_binding,
        would_mutate=True,
    )


def ingest_handoff(
    store: HarnessStore,
    attempt_id: str,
    *,
    claimed_bindings: Mapping[str, Any] | list[Mapping[str, Any]],
    result: Mapping[str, Any] | None = None,
) -> DispatchResult:
    """A5: accept handoff only if claimed fencing matches live leases (stale worker denied)."""
    attempt = store.get_dispatch_attempt(attempt_id)
    if not attempt:
        return DispatchResult(ok=False, reason="attempt_missing", plan_id="")
    if isinstance(claimed_bindings, Mapping) and "bindings" in claimed_bindings:
        claimed = LeaseBinding.from_dict(claimed_bindings)
    else:
        claimed = LeaseBinding(
            bindings=tuple(LeaseResourceBinding.from_dict(x) for x in claimed_bindings)  # type: ignore[arg-type]
        )
    durable = LeaseBinding.from_dict(attempt["payload"].get("lease_binding"))
    if not claimed.bindings:
        return DispatchResult(
            ok=False, reason="missing_fencing_claim", plan_id=attempt["plan_id"],
            attempt_id=attempt_id, task_id=attempt["task_id"],
        )
    for b in claimed.bindings:
        live = store.get_lease(b.resource_key)
        if live is None:
            return DispatchResult(
                ok=False, reason="stale_worker_lease_missing", plan_id=attempt["plan_id"],
                attempt_id=attempt_id, task_id=attempt["task_id"], lease_binding=durable,
            )
        if (
            str(live.get("lease_id")) != b.lease_id
            or int(live.get("fencing_token") or -1) != int(b.fencing_token)
            or str(live.get("holder_task_id")) != b.task_id
        ):
            return DispatchResult(
                ok=False, reason="stale_worker_fencing", plan_id=attempt["plan_id"],
                attempt_id=attempt_id, task_id=attempt["task_id"], lease_binding=durable,
            )
    # Prefer durable binding tokens if claim matches live (claim may lag one renew).
    attempt["payload"] = {
        **attempt["payload"],
        "handoff_result": dict(result or {}),
        "handoff_accepted_at": _iso(),
    }
    attempt["updated_at"] = _iso()
    store.put_dispatch_attempt(attempt)
    worker_obs = observe_worker_handoff(
        store,
        attempt_id=attempt_id,
        claimed_bindings=claimed.to_dict(),
        result=result,
    )
    if worker_obs.get("reason") != "worker_run_missing":
        attempt["payload"] = {
            **attempt["payload"],
            "worker_handoff": worker_obs,
        }
        store.put_dispatch_attempt(attempt)
    return DispatchResult(
        ok=True,
        reason="handoff_accepted",
        plan_id=attempt["plan_id"],
        attempt_id=attempt_id,
        task_id=attempt["task_id"],
        goal_id=attempt["goal_id"],
        goal_contract_fingerprint=attempt["goal_contract_fingerprint"],
        lease_binding=durable,
        would_mutate=False,
        spawn=worker_obs if worker_obs.get("reason") != "worker_run_missing" else None,
    )


def release_on_pr_ready(
    store: HarnessStore,
    attempt_id: str,
    *,
    handoff: Mapping[str, Any] | None = None,
) -> DispatchResult:
    """A7: release execution leases at PR_READY / structured handoff."""
    attempt = store.get_dispatch_attempt(attempt_id)
    if not attempt:
        return DispatchResult(ok=False, reason="attempt_missing", plan_id="")
    binding = LeaseBinding.from_dict(attempt["payload"].get("lease_binding"))
    if handoff is not None:
        check = ingest_handoff(
            store, attempt_id,
            claimed_bindings=handoff.get("lease_binding") or binding.to_dict(),
            result=handoff.get("result"),
        )
        if not check.ok:
            return check
        # reload after ingest
        attempt = store.get_dispatch_attempt(attempt_id) or attempt
        binding = LeaseBinding.from_dict(attempt["payload"].get("lease_binding"))

    rb = _rollback_leases(store, list(binding.bindings))
    if rb:
        attempt["status"] = "failed"
        attempt["updated_at"] = _iso()
        attempt["payload"] = {**attempt["payload"], "release_error": rb}
        store.put_dispatch_attempt(attempt)
        return DispatchResult(
            ok=False, reason=rb, plan_id=attempt["plan_id"],
            attempt_id=attempt_id, task_id=attempt["task_id"], lease_binding=binding,
        )

    attempt["status"] = "released"
    attempt["updated_at"] = _iso()
    attempt["payload"] = {
        **attempt["payload"],
        "released_at": _iso(),
        "release_reason": "pr_ready_handoff",
    }
    store.put_dispatch_attempt(attempt)
    return DispatchResult(
        ok=True,
        reason="leases_released_pr_ready",
        plan_id=attempt["plan_id"],
        attempt_id=attempt_id,
        task_id=attempt["task_id"],
        goal_id=attempt["goal_id"],
        goal_contract_fingerprint=attempt["goal_contract_fingerprint"],
        lease_binding=binding,
        would_mutate=True,
    )


__all__ = [
    "LeaseBinding",
    "LeaseResourceBinding",
    "DispatchResult",
    "execution_critical_fingerprint",
    "revalidate_plan",
    "dispatch",
    "heartbeat",
    "ingest_handoff",
    "release_on_pr_ready",
]
