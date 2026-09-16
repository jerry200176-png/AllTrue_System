"""Thin adapter over autonomy_gate — no second classifier. Adds drift-aware contracts."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Iterable

from scripts.governance import autonomy_gate as gate

from .contracts import (
    DecisionReceipt,
    DelegationContract,
    EvidenceEnvelope,
    GoalContract,
    scope_fingerprint,
)


@dataclass(frozen=True)
class GovernanceDecision:
    autonomous: bool
    founder_required: bool
    machine_tier: str
    effective_tier: str
    reasons: list[str]
    raw: dict[str, Any] = field(default_factory=dict)
    continue_safe: bool = True
    drift_codes: tuple[str, ...] = ()

    def to_dict(self) -> dict[str, Any]:
        return {
            "autonomous": self.autonomous,
            "founder_required": self.founder_required,
            "machine_tier": self.machine_tier,
            "effective_tier": self.effective_tier,
            "reasons": list(self.reasons),
            "raw": dict(self.raw),
            "continue_safe": self.continue_safe,
            "drift_codes": list(self.drift_codes),
        }


def _deny(
    reasons: list[str],
    *,
    drift: tuple[str, ...] = (),
    founder: bool = False,
    machine: str = "T?",
    effective: str = "T?",
    raw: dict[str, Any] | None = None,
    continue_safe: bool = True,
) -> GovernanceDecision:
    return GovernanceDecision(
        autonomous=False,
        founder_required=founder,
        machine_tier=machine,
        effective_tier=effective,
        reasons=reasons,
        raw=raw or {},
        continue_safe=continue_safe,
        drift_codes=drift,
    )


def classify_task_paths(
    paths: Iterable[str],
    patch: str = "",
    *,
    declared_risk: int | None = None,
    declared_tier: int | None = None,
) -> GovernanceDecision:
    path_list = list(paths)
    scope = gate.classify_scope(path_list, patch)
    activation = gate.classify_activation_scope(path_list, patch)
    machine_min = int(scope["machine_minimum_tier"])
    machine_tier = str(scope["tier_name"])
    reasons = [str(r) for r in (scope.get("reasons") or [])]
    reasons.extend(str(r) for r in (activation.get("reasons") or []) if str(r) not in reasons)
    effective_name = machine_tier
    if declared_risk is not None and declared_tier is not None:
        eff, err = gate.effective_tier(machine_min, declared_risk, declared_tier)
        if err:
            reasons.append(str(err))
            return _deny(
                reasons, drift=("risk_underdeclaration",), founder=True,
                machine=machine_tier, effective=machine_tier,
                raw={"scope": scope, "activation": activation, "declaration_error": err},
            )
        effective_name = f"T{eff}"
    founder = bool(
        activation.get("founder_required") or activation.get("protected_activation")
        or machine_min >= 3 or (declared_tier is not None and declared_tier >= 3)
    )
    return GovernanceDecision(
        autonomous=not founder, founder_required=founder,
        machine_tier=machine_tier, effective_tier=effective_name,
        reasons=reasons or (["tier_or_activation_requires_founder"] if founder else ["autonomous_eligible"]),
        raw={"scope": scope, "activation": activation},
    )


def bind_goal(
    *,
    program_id: str,
    task_id: str,
    outcome: str,
    subject_sha: str,
    scope: list[str],
    non_scope: list[str] | None = None,
    declared_risk: str = "R1",
    declared_tier: str = "T1",
    delegation: DelegationContract | None = None,
) -> GoalContract:
    if not subject_sha or len(subject_sha) < 7:
        raise ValueError("GoalContract requires subject_sha")
    return GoalContract(
        goal_id=f"{program_id}:{task_id}:{subject_sha[:12]}",
        program_id=program_id, task_id=task_id, outcome=outcome,
        subject_sha=subject_sha, scope=list(scope), non_scope=list(non_scope or []),
        declared_risk=declared_risk, declared_tier=declared_tier, delegation=delegation,
        stop_conditions=[
            "founder_required_from_autonomy_gate", "stale_approval",
            "stale_evidence", "scope_drift", "production_mutation_requested",
        ],
    )


def issue_decision_receipt(
    *, goal: GoalContract, decision: str, actor: str = "founder",
    evidence_refs: list[str] | None = None,
) -> DecisionReceipt:
    return DecisionReceipt(
        receipt_id=f"rcpt_{goal.goal_id.replace(':', '_')}",
        decision=decision, subject_sha=goal.subject_sha,
        scope_fingerprint=goal.scope_fp, goal_id=goal.goal_id,
        actor=actor, evidence_refs=list(evidence_refs or []),
    )


def validate_evidence(
    envelope: EvidenceEnvelope, *, goal: GoalContract | None = None,
    expected_sha: str | None = None,
) -> GovernanceDecision:
    expected = expected_sha or (goal.subject_sha if goal else "")
    if expected and envelope.subject_sha != expected:
        return _deny(
            [f"stale_evidence: kind={envelope.kind} evidence_sha={envelope.subject_sha} expected={expected}"],
            drift=("stale_evidence", "sha_mismatch"),
            raw={"envelope": envelope.to_dict(), "expected_sha": expected},
        )
    if goal and envelope.goal_id and envelope.goal_id != goal.goal_id:
        return _deny(
            [f"evidence_goal_mismatch:{envelope.goal_id}!={goal.goal_id}"],
            drift=("stale_evidence",), raw={"envelope": envelope.to_dict()},
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False, machine_tier="T0",
        effective_tier="T0", reasons=["evidence_ok"],
        raw={"envelope_id": envelope.envelope_id},
    )


def validate_decision_receipt(
    receipt: DecisionReceipt, *, goal: GoalContract,
    observed_main_sha: str | None = None, current_scope: list[str] | None = None,
) -> GovernanceDecision:
    if receipt.status != "active":
        return _deny([f"receipt_not_active:{receipt.status}"], drift=("stale_approval",), founder=True)
    if receipt.goal_id != goal.goal_id or receipt.subject_sha != goal.subject_sha:
        return _deny(
            [f"stale_approval: receipt_sha={receipt.subject_sha} goal_sha={goal.subject_sha}"],
            drift=("stale_approval", "sha_mismatch"), founder=True,
            raw={"receipt": receipt.to_dict()},
        )
    if observed_main_sha and receipt.subject_sha != observed_main_sha:
        return _deny(
            [f"stale_approval: founder approved {receipt.subject_sha}; main now {observed_main_sha}"],
            drift=("stale_approval", "main_advanced"), founder=True, continue_safe=True,
            raw={"receipt_sha": receipt.subject_sha, "main_sha": observed_main_sha},
        )
    if current_scope is not None and scope_fingerprint(current_scope) != receipt.scope_fingerprint:
        fp = scope_fingerprint(current_scope)
        return _deny(
            [f"scope_changed_after_approval: receipt_fp={receipt.scope_fingerprint} current_fp={fp}"],
            drift=("scope_drift", "stale_approval"), founder=True,
            raw={"receipt_fp": receipt.scope_fingerprint, "current_fp": fp},
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False,
        machine_tier=goal.declared_tier, effective_tier=goal.declared_tier,
        reasons=["receipt_fresh"], raw={"receipt_id": receipt.receipt_id},
    )


def check_scope_drift(goal: GoalContract, claimed_paths: Iterable[str]) -> GovernanceDecision:
    paths = [str(p).replace("\\", "/") for p in claimed_paths if p]
    if not goal.scope:
        return GovernanceDecision(
            autonomous=True, founder_required=False,
            machine_tier=goal.declared_tier, effective_tier=goal.declared_tier,
            reasons=["no_scope_constraint"],
        )
    for path in paths:
        if not any(
            path == a or (a.endswith("/**") and path.startswith(a[:-3]))
            or (a.endswith("*") and path.startswith(a[:-1]))
            or path.startswith(a.rstrip("/"))
            for a in goal.scope
        ):
            return _deny(
                [f"scope_drift: path={path} outside goal.scope"],
                drift=("scope_drift",), founder=True,
                machine=goal.declared_tier, effective=goal.declared_tier,
                raw={"path": path, "scope": goal.scope},
            )
    return GovernanceDecision(
        autonomous=True, founder_required=False,
        machine_tier=goal.declared_tier, effective_tier=goal.declared_tier,
        reasons=["scope_ok"],
    )


def check_risk_declaration(
    paths: Iterable[str], *, declared_risk: str, declared_tier: str, patch: str = "",
) -> GovernanceDecision:
    return classify_task_paths(
        paths, patch,
        declared_risk=int(str(declared_risk).lstrip("R") or "0"),
        declared_tier=int(str(declared_tier).lstrip("T") or "0"),
    )


def check_delegation(
    delegation: DelegationContract, *, action: str, paths: list[str], machine_tier: str,
) -> GovernanceDecision:
    ok, reason = delegation.permits(action=action, paths=paths, machine_tier=machine_tier)
    if not ok:
        return _deny(
            [reason], drift=("delegation_exceeded",),
            founder=machine_tier in {"T2", "T3"}, machine=machine_tier, effective=machine_tier,
            continue_safe=delegation.deny_and_continue,
            raw={"delegation": delegation.to_dict()},
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False, machine_tier=machine_tier,
        effective_tier=machine_tier, reasons=["delegation_ok"],
    )


def decide_merge_readiness(
    *, paths: Iterable[str], patch: str, pr_body: str, ci_green: bool,
    exact_head_sha: str | None = None, observed_head_sha: str | None = None,
) -> GovernanceDecision:
    if exact_head_sha and observed_head_sha and exact_head_sha != observed_head_sha:
        return _deny(
            ["exact-SHA mismatch"], drift=("sha_mismatch", "stale_evidence"),
            raw={"exact_head_sha": exact_head_sha, "observed_head_sha": observed_head_sha},
        )
    declared_risk, declared_tier = gate.parse_declaration(pr_body)
    decision = classify_task_paths(
        paths, patch, declared_risk=declared_risk, declared_tier=declared_tier,
    )
    reasons = list(decision.reasons)
    if declared_risk is None or declared_tier is None:
        reasons.append("missing_risk_tier_declaration")
        return _deny(
            reasons, drift=("missing_declaration",), founder=decision.founder_required,
            machine=decision.machine_tier, effective=decision.effective_tier, raw=decision.raw,
        )
    if not ci_green:
        reasons.append("ci_not_green")
        return _deny(
            reasons, drift=("ci_not_green",), founder=decision.founder_required,
            machine=decision.machine_tier, effective=decision.effective_tier, raw=decision.raw,
        )
    machine_min = int(decision.raw["scope"]["machine_minimum_tier"])
    if max(machine_min, declared_tier) >= 2 and not gate.has_rollback_evidence(pr_body):
        reasons.append("missing_rollback_evidence")
        return _deny(
            reasons, drift=("missing_rollback",),
            machine=decision.machine_tier, effective=decision.effective_tier, raw=decision.raw,
        )
    return GovernanceDecision(
        autonomous=decision.autonomous, founder_required=decision.founder_required,
        machine_tier=decision.machine_tier, effective_tier=decision.effective_tier,
        reasons=reasons, raw=decision.raw,
    )


def reject_production_direct_write(attempt: dict[str, Any]) -> GovernanceDecision:
    action = str(attempt.get("action") or "")
    banned = {
        "edit_production_source", "git_pull_production", "ad_hoc_sql_write",
        "unapproved_migration", "change_secrets", "change_dns",
        "bypass_release_control_plane", "ssh_mutate",
    }
    if action in banned or attempt.get("production_mutation") is True:
        return _deny(
            [f"production_direct_write_rejected:{action or 'mutation'}"],
            drift=("production_blocked",), founder=True, machine="T3", effective="T3",
            continue_safe=True, raw={"attempt": attempt, "rule": "CONTROL_PLANE_CONTRACT.I1"},
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False, machine_tier="T0",
        effective_tier="T0", reasons=["not_a_production_write"], raw={"attempt": attempt},
    )


def check_runtime_deploy_agreement(
    *, worker_deploy_sha: str, authoritative_runtime_sha: str,
) -> GovernanceDecision:
    if not worker_deploy_sha or not authoritative_runtime_sha:
        return _deny(["missing_deploy_or_runtime_sha"], drift=("runtime_mismatch",))
    if worker_deploy_sha != authoritative_runtime_sha:
        return _deny(
            [f"runtime_mismatch: worker={worker_deploy_sha} runtime={authoritative_runtime_sha}"],
            drift=("runtime_mismatch", "stale_evidence"), continue_safe=True,
            raw={"worker_deploy_sha": worker_deploy_sha, "authoritative_runtime_sha": authoritative_runtime_sha},
        )
    return GovernanceDecision(
        autonomous=True, founder_required=False, machine_tier="T0",
        effective_tier="T0", reasons=["runtime_agrees"],
    )
