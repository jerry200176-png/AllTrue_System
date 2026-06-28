#!/usr/bin/env python3
"""
policy-engine.py — Policy Decision Point (PDP v3). Single decision kernel.

PURE: reads no git, CI env, runtime files, or filesystem state.
All inputs must be supplied in the JSON payload.
"""
from __future__ import annotations

import argparse
import json
import sys
from typing import Any

POLICY_VERSION = "pdp-v3"
RISK_BLOCK_THRESHOLD = 0.7
RISK_PRODUCTION_THRESHOLD = 0.5
SLO_ERROR_BUDGET_BASELINE = 0.05

DEFAULT_RUNTIME = {"error_rate": 0.0, "latency_p95": 0, "slo_breach": False}
DEFAULT_FLAG_KEYS = ("calendar_v2", "new_checkout", "substitute_v2")


def normalize_payload(payload: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any], list[dict[str, Any]]]:
    if isinstance(payload.get("ci_signal"), dict):
        ci = payload["ci_signal"]
        sources = ci.get("sources") if isinstance(ci.get("sources"), dict) else ci
    elif isinstance(payload.get("sources"), dict):
        sources = payload["sources"]
    else:
        sources = payload if isinstance(payload, dict) else {}

    runtime = payload.get("runtime_metrics") or payload.get("runtime_signal") or {}
    if not isinstance(runtime, dict):
        runtime = {}
    merged_runtime = {**DEFAULT_RUNTIME, **runtime}

    flag_defs = payload.get("flag_definitions") or []
    if not isinstance(flag_defs, list):
        flag_defs = []

    return sources, merged_runtime, flag_defs


def infer_release_class(sources: dict[str, Any]) -> str:
    sop = sources.get("sop") or {}
    decision = sources.get("decision") or {}
    exec_r = sources.get("exec") or {}
    for key in ("release_class", "declared_class", "inferred_class"):
        val = sop.get(key)
        if val in ("SAFE", "DEPLOY", "RISKY"):
            return val
    if decision.get("change_class") in ("SAFE", "DEPLOY", "RISKY"):
        return decision["change_class"]
    if exec_r.get("release_class") in ("SAFE", "DEPLOY", "RISKY"):
        return exec_r["release_class"]
    return "UNKNOWN"


def extract_risk_score(sources: dict[str, Any], runtime: dict[str, Any]) -> float:
    decision = sources.get("decision") or {}
    exec_r = sources.get("exec") or {}
    if decision.get("risk_score") is not None:
        base = float(decision["risk_score"])
    else:
        rl = exec_r.get("risk_level") or decision.get("risk_level") or "unknown"
        mapping = {"low": 0.2, "medium": 0.5, "high": 0.75, "critical": 0.95, "unknown": 0.5}
        base = float(mapping.get(str(rl), 0.5))
    if runtime.get("slo_breach"):
        return round(min(0.99, base + 0.15), 4)
    return round(base, 4)


def build_violations(sources: dict[str, Any], runtime: dict[str, Any]) -> list[str]:
    violations: list[str] = []
    sop = sources.get("sop") or {}
    consistency = sources.get("consistency") or {}
    decision = sources.get("decision") or {}
    exec_r = sources.get("exec") or {}
    if sop.get("status") == "fail":
        violations.extend(sop.get("violations") or ["sop-enforce failed"])
    if consistency.get("result") == "FAIL":
        for m in consistency.get("mismatches") or []:
            if isinstance(m, dict):
                violations.append(f"consistency: {m.get('pair', 'consistency')} — {m.get('detail', '')}")
            else:
                violations.append(f"consistency: {m}")
        if not any(v.startswith("consistency:") for v in violations):
            violations.append("consistency-check failed")
    if decision.get("recommended_strategy") == "block":
        violations.append(f"decision-engine recommends block (risk={decision.get('risk_level')})")
    if exec_r.get("status") == "blocked":
        violations.extend(exec_r.get("reasons") or ["release-exec validate blocked"])
    if runtime.get("slo_breach"):
        violations.append(
            f"runtime: SLO breach (error_rate={runtime.get('error_rate')}, latency_p95={runtime.get('latency_p95')})"
        )
    return list(dict.fromkeys(v for v in violations if v))


def flag_enabled_for_policy(flag: dict[str, Any], slo_freeze: bool) -> bool:
    if not flag.get("enabled"):
        return False
    if slo_freeze and flag.get("risk_level") in ("HIGH", "MEDIUM"):
        return False
    return int(flag.get("rollout_pct", 0)) > 0


def build_runtime_flags(flag_definitions: list[dict[str, Any]], slo_freeze: bool) -> dict[str, bool]:
    known = {key: False for key in DEFAULT_FLAG_KEYS}
    for flag in flag_definitions:
        key = flag.get("flag_key")
        if key:
            known[key] = flag_enabled_for_policy(flag, slo_freeze)
    return known


def error_budget_burn(runtime: dict[str, Any]) -> float:
    rate = float(runtime.get("error_rate") or 0.0)
    if SLO_ERROR_BUDGET_BASELINE <= 0:
        return 0.0
    return round(min(1.0, rate / SLO_ERROR_BUDGET_BASELINE), 4)


def decide(
    sources: dict[str, Any],
    release_class: str | None = None,
    runtime_metrics: dict[str, Any] | None = None,
    flag_definitions: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    runtime_metrics = {**DEFAULT_RUNTIME, **(runtime_metrics or {})}
    flag_definitions = flag_definitions or []
    slo_breach = bool(runtime_metrics.get("slo_breach"))
    release_class = release_class or infer_release_class(sources)
    risk_score = extract_risk_score(sources, runtime_metrics)
    violations = build_violations(sources, runtime_metrics)

    sop = sources.get("sop") or {}
    consistency = sources.get("consistency") or {}
    sop_fail = sop.get("status") == "fail"
    consistency_fail = consistency.get("result") == "FAIL"
    reason_codes: list[str] = []

    allow_staging = False
    allow_production = False
    block_merge = False

    if release_class == "SAFE":
        allow_staging = True
        allow_production = True
        block_merge = False
    elif release_class in ("DEPLOY", "RISKY"):
        if sop_fail or consistency_fail or risk_score > RISK_BLOCK_THRESHOLD:
            block_merge = True
            allow_staging = False
            allow_production = False
            if sop_fail:
                reason_codes.append("SOP_FAIL")
            if consistency_fail:
                reason_codes.append("CONSISTENCY_FAIL")
            if risk_score > RISK_BLOCK_THRESHOLD:
                reason_codes.append("RISK_THRESHOLD")
        else:
            block_merge = False
            allow_staging = True
            allow_production = risk_score <= RISK_PRODUCTION_THRESHOLD
            if not allow_production:
                reason_codes.append("PRODUCTION_RISK")
    else:
        allow_staging = not sop_fail
        allow_production = False
        reason_codes.append("UNKNOWN_RELEASE_CLASS")

    freeze_deploy = slo_breach
    if slo_breach:
        block_merge = True
        allow_staging = False
        allow_production = False
        freeze_deploy = True
        reason_codes.append("SLO_BREACH")

    pdp_v3: dict[str, Any] = {
        "block_merge": block_merge,
        "allow_staging": allow_staging,
        "allow_production": allow_production,
        "runtime_flags": build_runtime_flags(flag_definitions, freeze_deploy),
        "slo": {
            "freeze_deploy": freeze_deploy,
            "error_budget_burn": error_budget_burn(runtime_metrics),
        },
        "promotion": {
            "staging_required": True,
            "production_requires_staging_artifact": True,
            "artifact_max_age_minutes": 1440,
        },
        "reason_codes": list(dict.fromkeys(reason_codes)),
    }

    status = "FAIL" if block_merge else "PASS"
    if release_class == "SAFE" and not slo_breach:
        status = "PASS"

    decision_reason = "; ".join(reason_codes) if reason_codes else "POLICY_OK"

    return {
        "policy_version": POLICY_VERSION,
        "pdp_v3": pdp_v3,
        "release_class": release_class,
        "risk_score": risk_score,
        "violations": violations,
        "status": status,
        "runtime_metrics": runtime_metrics,
        "decision_reason": decision_reason,
    }


def decide_from_payload(payload: dict[str, Any], release_class: str | None = None) -> dict[str, Any]:
    sources, runtime, flag_defs = normalize_payload(payload)
    return decide(sources, release_class, runtime, flag_defs)


def main() -> None:
    parser = argparse.ArgumentParser(description="Platform Gate PDP v3 (pure kernel)")
    parser.add_argument("--input", help="Path to aggregated JSON")
    parser.add_argument("--stdin", action="store_true")
    parser.add_argument("--release-class", default=None)
    args = parser.parse_args()

    if args.stdin:
        payload = json.load(sys.stdin)
    elif args.input:
        with open(args.input, encoding="utf-8") as f:
            payload = json.load(f)
    else:
        print(json.dumps({"error": "provide --input or --stdin"}))
        sys.exit(2)

    result = decide_from_payload(payload, args.release_class)
    print(json.dumps(result, indent=2, ensure_ascii=False))
    sys.exit(0)


if __name__ == "__main__":
    main()
