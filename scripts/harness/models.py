"""Durable Program / Task / Escalation models."""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any

from .states import TaskState


def _now_iso() -> str:
    from datetime import datetime, timezone

    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


@dataclass
class EvidenceBundle:
    code: dict[str, Any] = field(default_factory=dict)
    test: dict[str, Any] = field(default_factory=dict)
    pr: dict[str, Any] = field(default_factory=dict)
    ci: dict[str, Any] = field(default_factory=dict)
    merge: dict[str, Any] = field(default_factory=dict)
    deploy: dict[str, Any] = field(default_factory=dict)
    runtime: dict[str, Any] = field(default_factory=dict)
    rollback: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any] | None) -> EvidenceBundle:
        data = data or {}
        return cls(**{k: dict(data.get(k) or {}) for k in cls.__dataclass_fields__})


@dataclass
class Task:
    task_id: str
    program_id: str
    outcome: str
    scope: list[str] = field(default_factory=list)
    non_scope: list[str] = field(default_factory=list)
    dependencies: list[str] = field(default_factory=list)
    risk_declaration: str = "R1"
    governance_result: dict[str, Any] = field(default_factory=dict)
    affected_contracts: list[str] = field(default_factory=list)
    affected_paths: list[str] = field(default_factory=list)
    worktree: str = ""
    branch: str = ""
    assignee: str = ""
    lease_id: str = ""
    status: TaskState = TaskState.DISCOVERED
    evidence: EvidenceBundle = field(default_factory=EvidenceBundle)
    pr: dict[str, Any] = field(default_factory=dict)
    ci: dict[str, Any] = field(default_factory=dict)
    merge_sha: str = ""
    deploy_sha: str = ""
    rollback_evidence: dict[str, Any] = field(default_factory=dict)
    runtime_evidence: dict[str, Any] = field(default_factory=dict)
    blocker: str = ""
    next_action: str = ""
    business_value: int = 50
    reversible: bool = True
    designed_slice: bool = True
    updated_at: str = field(default_factory=_now_iso)

    def to_dict(self) -> dict[str, Any]:
        d = asdict(self)
        d["status"] = self.status.value
        return d

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Task:
        payload = dict(data)
        payload["status"] = TaskState(payload.get("status", TaskState.DISCOVERED.value))
        payload["evidence"] = EvidenceBundle.from_dict(payload.get("evidence"))
        return cls(**{k: payload[k] for k in cls.__dataclass_fields__ if k in payload})


@dataclass
class Program:
    program_id: str
    name: str
    owner: str
    repository: str
    goal: str
    canonical_status_source: str
    allowed_scope: list[str] = field(default_factory=list)
    protected_scope: list[str] = field(default_factory=list)
    current_milestone: str = ""
    current_task_id: str = ""
    blockers: list[str] = field(default_factory=list)
    dependencies: list[str] = field(default_factory=list)
    last_verified_main_sha: str = ""
    last_runtime_evidence: dict[str, Any] = field(default_factory=dict)
    next_action: str = ""
    stop_conditions: list[str] = field(default_factory=list)
    acceptance_contract: list[str] = field(default_factory=list)
    updated_at: str = field(default_factory=_now_iso)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Program:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})


@dataclass
class Escalation:
    escalation_id: str
    program_id: str
    task_id: str
    decision_required: str
    why_automation_cannot_decide: str
    governance_rule: str
    evidence: dict[str, Any] = field(default_factory=dict)
    options: list[str] = field(default_factory=list)
    consequences: str = ""
    recommended_default: str = ""
    blocked_task_ids: list[str] = field(default_factory=list)
    status: str = "open"  # open | resolved | superseded
    dedupe_key: str = ""
    created_at: str = field(default_factory=_now_iso)
    updated_at: str = field(default_factory=_now_iso)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Escalation:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})


SHARED_CONTRACTS = (
    "schema",
    "auth",
    "billing",
    "deployment",
    "shared_frontend",
    "shared_domain",
)
