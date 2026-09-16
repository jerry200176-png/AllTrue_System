"""Thin adapter over scripts.governance.autonomy_gate — no second classifier."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Iterable

from scripts.governance import autonomy_gate as gate


@dataclass(frozen=True)
class GovernanceDecision:
    autonomous: bool
    founder_required: bool
    machine_tier: str
    effective_tier: str
    reasons: list[str]
    raw: dict[str, Any]

    def to_dict(self) -> dict[str, Any]:
        return {
            "autonomous": self.autonomous,
            "founder_required": self.founder_required,
            "machine_tier": self.machine_tier,
            "effective_tier": self.effective_tier,
            "reasons": list(self.reasons),
            "raw": dict(self.raw),
        }


def classify_task_paths(
    paths: Iterable[str],
    patch: str = "",
    *,
    declared_risk: int | None = None,
    declared_tier: int | None = None,
) -> GovernanceDecision:
    """Classify intended/claimed scope using the existing gate only."""
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
            return GovernanceDecision(
                autonomous=False,
                founder_required=True,
                machine_tier=machine_tier,
                effective_tier=machine_tier,
                reasons=reasons,
                raw={"scope": scope, "activation": activation, "declaration_error": err},
            )
        effective_name = f"T{eff}"

    founder = bool(
        activation.get("founder_required")
        or activation.get("protected_activation")
        or machine_min >= 3
        or (declared_tier is not None and declared_tier >= 3)
    )

    return GovernanceDecision(
        autonomous=not founder,
        founder_required=founder,
        machine_tier=machine_tier,
        effective_tier=effective_name,
        reasons=reasons or (["tier_or_activation_requires_founder"] if founder else ["autonomous_eligible"]),
        raw={"scope": scope, "activation": activation},
    )


def decide_merge_readiness(
    *,
    paths: Iterable[str],
    patch: str,
    pr_body: str,
    ci_green: bool,
    exact_head_sha: str | None = None,
    observed_head_sha: str | None = None,
) -> GovernanceDecision:
    """Fail closed on SHA mismatch; use gate for tier + rollback evidence."""
    if exact_head_sha and observed_head_sha and exact_head_sha != observed_head_sha:
        return GovernanceDecision(
            autonomous=False,
            founder_required=False,
            machine_tier="T?",
            effective_tier="T?",
            reasons=["exact-SHA mismatch"],
            raw={"exact_head_sha": exact_head_sha, "observed_head_sha": observed_head_sha},
        )

    declared_risk, declared_tier = gate.parse_declaration(pr_body)
    decision = classify_task_paths(
        paths,
        patch,
        declared_risk=declared_risk,
        declared_tier=declared_tier,
    )
    reasons = list(decision.reasons)
    if declared_risk is None or declared_tier is None:
        reasons.append("missing_risk_tier_declaration")
        return GovernanceDecision(
            autonomous=False,
            founder_required=decision.founder_required,
            machine_tier=decision.machine_tier,
            effective_tier=decision.effective_tier,
            reasons=reasons,
            raw=decision.raw,
        )
    if not ci_green:
        reasons.append("ci_not_green")
        return GovernanceDecision(
            autonomous=False,
            founder_required=decision.founder_required,
            machine_tier=decision.machine_tier,
            effective_tier=decision.effective_tier,
            reasons=reasons,
            raw=decision.raw,
        )
    # T2+ requires rollback evidence per gate / merge policy.
    machine_min = int(decision.raw["scope"]["machine_minimum_tier"])
    if max(machine_min, declared_tier) >= 2 and not gate.has_rollback_evidence(pr_body):
        reasons.append("missing_rollback_evidence")
        return GovernanceDecision(
            autonomous=False,
            founder_required=False,
            machine_tier=decision.machine_tier,
            effective_tier=decision.effective_tier,
            reasons=reasons,
            raw=decision.raw,
        )
    return GovernanceDecision(
        autonomous=decision.autonomous,
        founder_required=decision.founder_required,
        machine_tier=decision.machine_tier,
        effective_tier=decision.effective_tier,
        reasons=reasons,
        raw=decision.raw,
    )


def reject_production_direct_write(attempt: dict[str, Any]) -> GovernanceDecision:
    """Encode production-is-runtime: default reject direct writes."""
    action = str(attempt.get("action") or "")
    banned = {
        "edit_production_source",
        "git_pull_production",
        "ad_hoc_sql_write",
        "unapproved_migration",
        "change_secrets",
        "change_dns",
        "bypass_release_control_plane",
        "ssh_mutate",
    }
    if action in banned or attempt.get("production_mutation") is True:
        return GovernanceDecision(
            autonomous=False,
            founder_required=True,
            machine_tier="T3",
            effective_tier="T3",
            reasons=[f"production_direct_write_rejected:{action or 'mutation'}"],
            raw={"attempt": attempt, "rule": "CONTROL_PLANE_CONTRACT.I1"},
        )
    return GovernanceDecision(
        autonomous=True,
        founder_required=False,
        machine_tier="T0",
        effective_tier="T0",
        reasons=["not_a_production_write"],
        raw={"attempt": attempt},
    )
