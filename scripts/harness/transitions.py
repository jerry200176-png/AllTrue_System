"""Deterministic task transitions with evidence requirements."""

from __future__ import annotations

from dataclasses import replace
from datetime import datetime, timezone
from typing import Any

from .models import Escalation, Task
from .states import ALLOWED_TRANSITIONS, TaskState
from .store import HarnessStore


class TransitionError(ValueError):
    pass


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


# Minimum evidence keys required for selected transitions (fail closed).
REQUIRED_EVIDENCE: dict[tuple[TaskState, TaskState], frozenset[str]] = {
    (TaskState.TESTING, TaskState.PR_OPEN): frozenset({"test"}),
    (TaskState.PR_OPEN, TaskState.CI_PENDING): frozenset({"pr"}),
    (TaskState.CI_PENDING, TaskState.CI_GREEN): frozenset({"ci"}),
    (TaskState.CI_PENDING, TaskState.CI_FAILED): frozenset({"ci"}),
    (TaskState.MERGE_READY, TaskState.MERGED): frozenset({"merge"}),
    (TaskState.PRODUCTION_PENDING, TaskState.PRODUCTION_VERIFIED): frozenset(
        {"deploy", "runtime"}
    ),
}


def _evidence_ok(task: Task, keys: frozenset[str], extra: dict[str, Any]) -> bool:
    bundle = task.evidence.to_dict()
    for key in keys:
        blob = extra.get(key) or bundle.get(key) or {}
        if key == "pr":
            blob = extra.get("pr") or task.pr or blob
        if key == "ci":
            blob = extra.get("ci") or task.ci or blob
        if key == "merge":
            if not (extra.get("merge_sha") or task.merge_sha or blob.get("sha")):
                return False
            continue
        if key == "deploy":
            if not (extra.get("deploy_sha") or task.deploy_sha or blob.get("sha")):
                return False
            continue
        if not blob:
            return False
    return True


def transition(
    store: HarnessStore,
    task: Task,
    to_state: TaskState,
    *,
    actor: str,
    evidence: dict[str, Any] | None = None,
    force_reason: str | None = None,
) -> Task:
    """Apply a state transition or raise TransitionError (fail closed)."""
    evidence = evidence or {}
    allowed = ALLOWED_TRANSITIONS.get(task.status, frozenset())
    if to_state not in allowed and not force_reason:
        raise TransitionError(
            f"illegal transition {task.status.value} -> {to_state.value} "
            f"for {task.task_id}"
        )

    req = REQUIRED_EVIDENCE.get((task.status, to_state))
    if req and not _evidence_ok(task, req, evidence):
        raise TransitionError(
            f"contradictory/missing evidence for {task.status.value}->{to_state.value}: "
            f"need {sorted(req)}"
        )

    # Exact-SHA mismatch fail-closed when both present.
    claimed = evidence.get("exact_sha") or evidence.get("head_sha")
    observed = evidence.get("observed_sha") or evidence.get("main_sha")
    if claimed and observed and claimed != observed:
        raise TransitionError(
            f"exact-SHA mismatch claimed={claimed} observed={observed}"
        )

    now = _now()
    updated = replace(task, status=to_state, updated_at=now)
    if "blocker" in evidence:
        updated.blocker = str(evidence["blocker"])
    if "next_action" in evidence:
        updated.next_action = str(evidence["next_action"])
    if "governance_result" in evidence:
        updated.governance_result = dict(evidence["governance_result"])
    if "pr" in evidence:
        updated.pr = dict(evidence["pr"])
    if "ci" in evidence:
        updated.ci = dict(evidence["ci"])
    if evidence.get("merge_sha"):
        updated.merge_sha = str(evidence["merge_sha"])
    if evidence.get("deploy_sha"):
        updated.deploy_sha = str(evidence["deploy_sha"])
    if "lease_id" in evidence:
        updated.lease_id = str(evidence["lease_id"])
    if "assignee" in evidence:
        updated.assignee = str(evidence["assignee"])

    store.upsert_task(updated)
    store.record_transition(
        task.task_id,
        task.status,
        to_state,
        actor,
        {**evidence, **({"force_reason": force_reason} if force_reason else {})},
        now,
    )
    return updated


def enqueue_founder_escalation(
    store: HarnessStore,
    task: Task,
    *,
    decision_required: str,
    why: str,
    governance_rule: str,
    options: list[str],
    consequences: str,
    recommended_default: str,
    evidence: dict[str, Any] | None = None,
) -> Escalation:
    """Create or return existing open escalation (deduplicated)."""
    dedupe = f"{task.program_id}:{task.task_id}:{governance_rule}:{decision_required}"
    existing = store.find_open_escalation(dedupe)
    if existing:
        return existing

    now = _now()
    esc = Escalation(
        escalation_id=f"esc_{task.task_id}_{int(datetime.now(timezone.utc).timestamp())}",
        program_id=task.program_id,
        task_id=task.task_id,
        decision_required=decision_required,
        why_automation_cannot_decide=why,
        governance_rule=governance_rule,
        evidence=evidence or dict(task.governance_result),
        options=options,
        consequences=consequences,
        recommended_default=recommended_default,
        blocked_task_ids=[task.task_id],
        dedupe_key=dedupe,
        created_at=now,
        updated_at=now,
    )
    store.upsert_escalation(esc)
    transition(
        store,
        task,
        TaskState.FOUNDER_REQUIRED,
        actor="governance_adapter",
        evidence={
            "governance_result": task.governance_result,
            "escalation_id": esc.escalation_id,
            "next_action": "await_founder_decision",
        },
    )
    return esc
