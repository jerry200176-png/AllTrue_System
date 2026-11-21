"""Drift-aware reconciliation loop + checkpoint/resume (H2)."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
import re
from typing import Any

from .contracts import Checkpoint, DecisionReceipt, EvidenceEnvelope, GoalContract
from .governance_adapter import (
    GovernanceDecision,
    check_runtime_deploy_agreement,
    check_risk_declaration,
    check_scope_drift,
    reject_production_direct_write,
    validate_decision_receipt,
    validate_evidence,
)
from .models import Task
from .states import TaskState
from .store import HarnessStore

_WORKER_NEXT = {
    "founder_required": "Use the existing Founder inbox and scoped decision receipt.",
    "external_wait_recorded": "Read the external run or PR before deciding whether to resume.",
    "launcher_deferred": "Check the task worktree and native session before choosing a supported launcher.",
    "ownership_unknown": "Locate the durable attempt and its leases before starting another writer.",
    "lease_lost": "Confirm the earlier worker stopped. Re-read the checkpoint, exact head and side-effect receipts before restoring ownership.",
    "progress_unconfirmed": "Inspect the current worker and checkpoint. A live lease alone does not show progress.",
}


def _observation_time(value: Any) -> datetime | None:
    if not isinstance(value, str):
        return None
    try:
        timestamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return timestamp if timestamp.tzinfo is not None else None


def observe_workers(store: HarnessStore, *, now: datetime, stale_after: timedelta) -> list[dict[str, Any]]:
    """An independent read of durable authority and progress, never a takeover."""
    leases = {lease["resource_key"]: lease for lease in store.list_leases()}
    result = []
    for run in store.list_worker_runs():
        if run["status"] not in {"starting", "running", "deferred"}:
            continue
        task = store.get_task(run["task_id"])
        attempt = store.get_dispatch_attempt(run["attempt_id"])
        binding = (attempt or {}).get("payload", {}).get("lease_binding", {})
        claims = binding.get("bindings", []) if isinstance(binding, dict) else []
        valid_claims = isinstance(claims, list) and len(claims) > 0 and all(isinstance(claim, dict) for claim in claims)
        latest_progress = max(
            (stamp for value in [run.get("updated_at"), task.updated_at if task else None]
             if (stamp := _observation_time(value)) is not None),
            default=None,
        )
        state = "running"
        if task and task.status == TaskState.FOUNDER_REQUIRED:
            state = "founder_required"
        elif task and task.status in {TaskState.PAUSED, TaskState.DONE}:
            state = "inactive_task_recorded"
        elif task and task.status in {TaskState.CI_PENDING, TaskState.STAGING_PENDING, TaskState.PRODUCTION_PENDING}:
            state = "external_wait_recorded"
        elif run["status"] == "deferred":
            state = "launcher_deferred"
        elif not task or not attempt or attempt.get("task_id") != run["task_id"] or not valid_claims:
            state = "ownership_unknown"
        else:
            for claim in claims:
                current = leases.get(claim.get("resource_key"), {})
                expires = _observation_time(current.get("expires_at"))
                if (current.get("lease_id") != claim.get("lease_id")
                        or current.get("fencing_token") != claim.get("fencing_token")
                        or current.get("holder_task_id") != run["task_id"]
                        or expires is None or expires <= now):
                    state = "lease_lost"
                    break
            if state == "running" and (latest_progress is None or now - latest_progress >= stale_after):
                state = "progress_unconfirmed"
        launch = (run.get("payload") or {}).get("launch", {})
        result.append({
            **{key: run.get(key) for key in ("run_id", "attempt_id", "task_id", "status",
                                              "session_id", "worktree_path", "branch")},
            "observation": state,
            "task_state": task.status.value if task else None,
            "last_recorded_progress_at": latest_progress.astimezone(timezone.utc).isoformat() if latest_progress else None,
            "attention_key": None if state in {"running", "external_wait_recorded", "inactive_task_recorded"}
                             else f"{run['run_id']}:{state}",
            "next": _WORKER_NEXT.get(state),
            "launcher_reason": launch.get("reason") if state == "launcher_deferred" else None,
        })
    return result


@dataclass
class WorldObservation:
    main_sha: str = ""
    runtime_sha: str = ""
    worker_deploy_sha: str = ""
    claimed_paths: list[str] = field(default_factory=list)
    current_scope: list[str] | None = None
    production_attempt: dict[str, Any] | None = None
    declared_risk: str = ""
    declared_tier: str = ""
    patch: str = ""
    requested_action: str = ""


@dataclass
class ReconcileResult:
    ok: bool
    decisions: list[GovernanceDecision] = field(default_factory=list)
    drift_codes: list[str] = field(default_factory=list)
    continue_safe: bool = True
    blocked_action: str = ""
    summary: str = ""

    def to_dict(self) -> dict[str, Any]:
        return {
            "ok": self.ok,
            "decisions": [d.to_dict() for d in self.decisions],
            "drift_codes": list(self.drift_codes),
            "continue_safe": self.continue_safe,
            "blocked_action": self.blocked_action,
            "summary": self.summary,
        }


def _merge(decisions: list[GovernanceDecision]) -> ReconcileResult:
    drifts: list[str] = []
    continue_safe = True
    blocked = ""
    soft_deny = False
    for d in decisions:
        drifts.extend(d.drift_codes)
        denied = (not d.autonomous) or bool(d.drift_codes) or d.founder_required
        if denied:
            soft_deny = True
            if not d.continue_safe:
                continue_safe = False
            if not blocked and d.reasons:
                blocked = d.reasons[0]
    codes = sorted(set(drifts))
    return ReconcileResult(
        ok=not soft_deny,
        decisions=decisions,
        drift_codes=codes,
        continue_safe=(continue_safe if soft_deny else True),
        blocked_action=blocked if soft_deny else "",
        summary="ok" if not soft_deny else f"denied:{','.join(codes) or blocked}",
    )


def reconcile(
    *,
    goal: GoalContract | None = None,
    receipt: DecisionReceipt | None = None,
    evidence: list[EvidenceEnvelope] | None = None,
    world: WorldObservation | None = None,
) -> ReconcileResult:
    world = world or WorldObservation()
    decisions: list[GovernanceDecision] = []
    if receipt and goal:
        decisions.append(validate_decision_receipt(
            receipt, goal=goal,
            requested_action=world.requested_action,
            observed_main_sha=world.main_sha or None,
            current_scope=world.current_scope,
        ))
    for env in evidence or []:
        decisions.append(validate_evidence(env, goal=goal))
    if goal and world.claimed_paths:
        decisions.append(check_scope_drift(goal, world.claimed_paths))
    if world.claimed_paths and world.declared_risk and world.declared_tier:
        decisions.append(check_risk_declaration(
            world.claimed_paths,
            declared_risk=world.declared_risk,
            declared_tier=world.declared_tier,
            patch=world.patch,
        ))
    if world.worker_deploy_sha or world.runtime_sha:
        decisions.append(check_runtime_deploy_agreement(
            worker_deploy_sha=world.worker_deploy_sha,
            authoritative_runtime_sha=world.runtime_sha,
        ))
    if world.production_attempt is not None:
        decisions.append(reject_production_direct_write(world.production_attempt))
    if not decisions:
        return ReconcileResult(ok=True, summary="nothing_to_reconcile")
    return _merge(decisions)


def write_checkpoint(
    store: HarnessStore, task: Task, *,
    goal: GoalContract | None = None,
    receipt: DecisionReceipt | None = None,
    evidence_ids: list[str] | None = None,
) -> Checkpoint:
    leases = [L["lease_id"] for L in store.list_leases() if L.get("holder_task_id") == task.task_id]
    cp = Checkpoint(
        checkpoint_id=f"cp_{task.task_id}_{task.status.value}",
        goal_id=goal.goal_id if goal else "",
        task_id=task.task_id,
        task_state=task.status.value,
        subject_sha=(goal.subject_sha if goal else task.merge_sha) or "",
        lease_ids=leases,
        evidence_ids=list(evidence_ids or []),
        receipt_id=receipt.receipt_id if receipt else "",
        payload={
            "program_id": task.program_id,
            "assignee": task.assignee,
            "governance_result": task.governance_result,
        },
    )
    store.put_checkpoint(cp)
    return cp


def resume_from_checkpoint(store: HarnessStore, checkpoint_id: str) -> dict[str, Any]:
    cp = store.get_checkpoint(checkpoint_id)
    if not cp:
        return {"ok": False, "error": "checkpoint_not_found", "checkpoint_id": checkpoint_id}
    task = store.get_task(cp.task_id)
    goal = store.get_goal(cp.goal_id) if cp.goal_id else None
    receipt = store.get_decision_receipt(cp.receipt_id) if cp.receipt_id else None
    leases = [L for L in store.list_leases() if L.get("lease_id") in set(cp.lease_ids)]
    return {
        "ok": True,
        "checkpoint": cp.to_dict(),
        "task": task.to_dict() if task else None,
        "task_state": task.status.value if task else cp.task_state,
        "goal": goal.to_dict() if goal else None,
        "receipt": receipt.to_dict() if receipt else None,
        "leases": leases,
    }


def reconcile_ci_sha(
    *, expected_head_sha: str, observed_ci_sha: str, conclusion: str,
) -> GovernanceDecision:
    if not all(re.fullmatch(r"[0-9a-f]{40}", value) for value in (expected_head_sha, observed_ci_sha)):
        return GovernanceDecision(
            autonomous=False, founder_required=False, machine_tier="T?",
            effective_tier="T?", reasons=["ci_head_unverified"],
            continue_safe=True, drift_codes=("missing_exact_ci_sha",),
        )
    if expected_head_sha != observed_ci_sha:
        return GovernanceDecision(
            autonomous=False, founder_required=False, machine_tier="T?",
            effective_tier="T?",
            reasons=[f"ci_sha_mismatch: expected={expected_head_sha} ci={observed_ci_sha}"],
            raw={"expected": expected_head_sha, "ci": observed_ci_sha, "conclusion": conclusion},
            continue_safe=True, drift_codes=("stale_evidence", "sha_mismatch"),
        )
    if conclusion != "success":
        return GovernanceDecision(
            autonomous=False, founder_required=False, machine_tier="T?",
            effective_tier="T?", reasons=[f"ci_not_green:{conclusion}"],
            raw={"conclusion": conclusion}, continue_safe=True, drift_codes=("ci_not_green",),
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False, machine_tier="T0",
        effective_tier="T0", reasons=["ci_ok"],
    )
