"""H2 durable contracts: Goal, Evidence, DecisionReceipt, Delegation, Checkpoint."""

from __future__ import annotations

import hashlib
import json
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Any


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def scope_fingerprint(scope: list[str]) -> str:
    norm = sorted({str(p).replace("\\", "/").strip() for p in scope if p})
    return hashlib.sha256("\n".join(norm).encode()).hexdigest()[:16]


def path_in_scope(path: str, pattern: str) -> bool:
    """Fail-closed path match: exact, directory boundary, or dir/** only."""
    path = str(path).replace("\\", "/").strip()
    pattern = str(pattern).replace("\\", "/").strip()
    if not path or not pattern:
        return False
    # Reject unsupported globs (prefix* , ?, [...], mid-path *)
    if "*" in pattern:
        if not pattern.endswith("/**") or pattern.count("*") != 2:
            raise ValueError(f"unsupported_scope_pattern:{pattern}")
        root = pattern[:-3].rstrip("/")
        if not root or "*" in root or "?" in root:
            raise ValueError(f"unsupported_scope_pattern:{pattern}")
        return path == root or path.startswith(root + "/")
    if "?" in pattern or "[" in pattern:
        raise ValueError(f"unsupported_scope_pattern:{pattern}")
    if path == pattern:
        return True
    root = pattern.rstrip("/")
    return path == root or path.startswith(root + "/")


def contract_fingerprint(goal_fields: dict[str, Any]) -> str:
    """Hash authorization-relevant Goal slice (not conversational extras)."""
    blob = json.dumps(goal_fields, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(blob.encode()).hexdigest()[:32]


ALLOWED_DECISIONS = frozenset({
    "approve_production",
    "approve_merge",
    "continue",
    "reject",
    "narrow_scope",
    "pause",
})

# Decisions that may only be issued by the Founder actor (not workers).
FOUNDER_ONLY_DECISIONS = frozenset({
    "approve_production",
    "approve_merge",
    "reject",
    "narrow_scope",
})

POLICY_AUTHORITY_GATE = "scripts/governance/autonomy_gate.py"


@dataclass
class DelegationContract:
    worker_id: str
    allowed_actions: list[str] = field(default_factory=list)
    allowed_paths: list[str] = field(default_factory=list)
    max_tier: str = "T1"
    deny_and_continue: bool = True

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any] | None) -> DelegationContract | None:
        if not data:
            return None
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})

    def permits(self, *, action: str, paths: list[str], machine_tier: str) -> tuple[bool, str]:
        if self.allowed_actions and action not in self.allowed_actions:
            return False, f"action_not_delegated:{action}"
        if int(str(machine_tier).lstrip("T") or "0") > int(self.max_tier.lstrip("T") or "0"):
            return False, f"machine_tier_exceeds_delegation:{machine_tier}>{self.max_tier}"
        for path in paths:
            if not self.allowed_paths:
                continue
            try:
                ok = any(path_in_scope(path, p) for p in self.allowed_paths)
            except ValueError as exc:
                return False, str(exc)
            if not ok:
                return False, f"path_outside_delegation:{path}"
        return True, "ok"


@dataclass
class GoalContract:
    goal_id: str
    program_id: str
    task_id: str
    outcome: str
    subject_sha: str
    scope: list[str] = field(default_factory=list)
    non_scope: list[str] = field(default_factory=list)
    declared_risk: str = "R1"
    declared_tier: str = "T1"
    authority: str = POLICY_AUTHORITY_GATE
    delegation: DelegationContract | None = None
    stop_conditions: list[str] = field(default_factory=list)
    created_at: str = field(default_factory=_now)
    updated_at: str = field(default_factory=_now)

    @property
    def scope_fp(self) -> str:
        return scope_fingerprint(self.scope)

    @property
    def contract_fp(self) -> str:
        return contract_fingerprint({
            "subject_sha": self.subject_sha,
            "scope": sorted(self.scope),
            "non_scope": sorted(self.non_scope),
            "declared_risk": self.declared_risk,
            "declared_tier": self.declared_tier,
            "authority": self.authority,
            "delegation": self.delegation.to_dict() if self.delegation else None,
            "stop_conditions": sorted(self.stop_conditions),
        })

    def to_dict(self) -> dict[str, Any]:
        d = asdict(self)
        d["scope_fingerprint"] = self.scope_fp
        d["contract_fingerprint"] = self.contract_fp
        if self.delegation is None:
            d["delegation"] = None
        return d

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> GoalContract:
        p = dict(data)
        p.pop("scope_fingerprint", None)
        p.pop("contract_fingerprint", None)
        p["delegation"] = DelegationContract.from_dict(p.get("delegation"))
        return cls(**{k: p[k] for k in cls.__dataclass_fields__ if k in p})


EVIDENCE_KINDS = frozenset({
    "code", "test", "pr", "ci", "merge", "deploy", "runtime", "rollback", "approval",
})


@dataclass
class EvidenceEnvelope:
    kind: str
    subject_sha: str
    source: str
    payload: dict[str, Any] = field(default_factory=dict)
    goal_id: str = ""
    observed_at: str = field(default_factory=_now)
    envelope_id: str = ""

    def __post_init__(self) -> None:
        if self.kind not in EVIDENCE_KINDS:
            raise ValueError(f"unknown evidence kind: {self.kind}")
        if not self.subject_sha:
            raise ValueError("EvidenceEnvelope requires subject_sha")
        if not self.envelope_id:
            digest = hashlib.sha256(
                f"{self.kind}:{self.subject_sha}:{self.source}:{self.observed_at}".encode()
            ).hexdigest()[:12]
            self.envelope_id = f"ev_{digest}"

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> EvidenceEnvelope:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})


@dataclass
class DecisionReceipt:
    """One receipt authorizes exactly one action (plus freshness/contract binding)."""

    receipt_id: str
    decision: str
    authorized_action: str
    subject_sha: str
    scope_fingerprint: str
    contract_fingerprint: str
    goal_id: str
    authority: str = POLICY_AUTHORITY_GATE  # policy authority, not human identity
    actor: str = "founder"  # issuing actor: founder | worker | system
    evidence_refs: list[str] = field(default_factory=list)
    decided_at: str = field(default_factory=_now)
    status: str = "active"

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> DecisionReceipt:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})


@dataclass
class Checkpoint:
    checkpoint_id: str
    goal_id: str
    task_id: str
    task_state: str
    subject_sha: str
    lease_ids: list[str] = field(default_factory=list)
    evidence_ids: list[str] = field(default_factory=list)
    receipt_id: str = ""
    payload: dict[str, Any] = field(default_factory=dict)
    created_at: str = field(default_factory=_now)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Checkpoint:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})
