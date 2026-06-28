#!/usr/bin/env python3
"""
decision_analyze.py — Decision Intelligence Layer core analyzer (read-only).

Invoked by decision-engine.sh. Does NOT execute merge/deploy or modify code.
"""
from __future__ import annotations

import json
import math
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any


def repo_root() -> Path:
    return Path(os.environ.get("REPO_ROOT", Path(__file__).resolve().parents[2]))


def load_risk_model() -> dict[str, Any]:
    path = repo_root() / "scripts" / "risk-model.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def run_cmd(cmd: list[str], cwd: Path | None = None) -> tuple[int, str]:
    try:
        r = subprocess.run(
            cmd,
            cwd=cwd or repo_root(),
            capture_output=True,
            text=True,
            timeout=60,
        )
        return r.returncode, (r.stdout or "") + (r.stderr or "")
    except (subprocess.TimeoutExpired, FileNotFoundError):
        return 1, ""


def fetch_pr_context(pr_id: str) -> dict[str, Any]:
    code, out = run_cmd(
        [
            "gh",
            "pr",
            "view",
            str(pr_id),
            "--json",
            "number,title,body,state,files,additions,deletions,changedFiles,statusCheckRollup,headRefName,baseRefName",
        ]
    )
    if code != 0 or not out.strip():
        return {"error": "pr_not_found", "pr_id": pr_id}
    return json.loads(out)


def pr_file_paths(pr: dict[str, Any]) -> list[str]:
    files = pr.get("files") or []
    return [f.get("path", "") for f in files if f.get("path")]


def pr_body_class(body: str) -> str | None:
    m = re.search(r"Release-Class:\s*(SAFE|DEPLOY|RISKY)", body or "", re.I)
    return m.group(1).upper() if m else None


def classify_file(path: str, model: dict[str, Any]) -> dict[str, float]:
    """Return category -> weight hits for a single file path."""
    weights = model["weights"]
    hits: dict[str, float] = {}
    p = path.replace("\\", "/")

    if re.match(r"^docs/", p) or p.endswith(".md"):
        hits["docs_only"] = weights["docs_only"]
    if re.match(r"^frontend/src/", p) and p.endswith((".vue", ".js", ".css")):
        hits["frontend_ui"] = max(hits.get("frontend_ui", 0), weights["frontend_ui"])
    if "migration" in p or re.match(r"^backend/database/", p):
        hits["db_migration"] = weights["db_migration"]
    if any(x in p for x in ("AuthController", "Middleware/", "ParentSession", "sanctum")):
        hits["auth_change"] = weights["auth_change"]
    if any(
        x in p
        for x in (
            "Payment",
            "Invoice",
            "tuition",
            "DIRECTOR_PAYMENT",
            "Charge",
            "StudentClassController",
        )
    ):
        hits["payment_change"] = weights["payment_change"]
    if any(
        x in p
        for x in (
            "ClassSession",
            "SessionDeduction",
            "RemainingSessions",
            "ApprovalSessionSync",
        )
    ):
        hits["session_logic"] = weights["session_logic"]
    if any(x in p for x in ("SwipeRfid", "StudentSingIn", "Attendance")):
        hits["attendance_logic"] = weights["attendance_logic"]
    if re.match(r"^backend/app/", p) and not hits:
        # Generic backend logic change — deploy-tier baseline
        hits["backend_logic"] = 0.45
    if any(x in p for x in (".cursor/rules", "AGENTS.md", ".cursorrules", "ai-", "decision-")):
        hits["ai_prompt_change"] = weights["ai_prompt_change"]
    if p == "backend/routes/api.php" or re.match(r"^backend/routes/", p):
        hits["api_route_change"] = model["modifiers"].get("api_route_change", 0.1)

    return hits


def blast_radius_modules(paths: list[str], model: dict[str, Any]) -> list[str]:
    modules: set[str] = set()
    patterns = model.get("module_patterns", {})
    for path in paths:
        for module, needles in patterns.items():
            if any(n in path for n in needles):
                modules.add(module)
    return sorted(modules)


def infer_change_class(
    paths: list[str], model: dict[str, Any], body_class: str | None
) -> str:
    if body_class in ("SAFE", "DEPLOY", "RISKY"):
        return body_class

    if paths and all(p.startswith("docs/") for p in paths):
        return "SAFE"

    max_weight = 0.0
    risky = False
    deployable = False

    for path in paths:
        hits = classify_file(path, model)
        for cat, w in hits.items():
            if w >= 0.75 or cat in ("auth_change", "payment_change", "db_migration", "session_logic"):
                if w >= 0.75:
                    risky = True
            if cat != "docs_only" and w > 0:
                deployable = True
            max_weight = max(max_weight, w)
        if re.match(r"^backend/", path) and not path.startswith("backend/tests/"):
            deployable = True
            max_weight = max(max_weight, 0.45)

    if risky or max_weight >= 0.8:
        return "RISKY"
    if deployable or max_weight > 0:
        return "DEPLOY"
    return "SAFE"


def baseline_risk_score(paths: list[str], model: dict[str, Any]) -> float:
    all_weights: list[float] = []
    for path in paths:
        hits = classify_file(path, model)
        all_weights.extend(h for h in hits.values() if h > 0)

    if not all_weights:
        return 0.0

    top = sorted(all_weights, reverse=True)[:3]
    w_max = top[0]
    w_mean = sum(top) / len(top)
    # Safety-biased blend: favor worst-case category
    score = min(1.0, w_max * 0.65 + w_mean * 0.35)
    return round(score, 4)


def drift_snapshot() -> dict[str, Any]:
    env = {**os.environ, "JSON_OUT": "1"}
    try:
        r = subprocess.run(
            [str(repo_root() / "scripts" / "release-check.sh")],
            cwd=repo_root(),
            capture_output=True,
            text=True,
            timeout=30,
            env=env,
        )
        text = r.stdout.strip()
        if text:
            return json.loads(text)
    except (json.JSONDecodeError, subprocess.TimeoutExpired, FileNotFoundError):
        pass
    return {"drift_status": "UNKNOWN", "risk_level": "medium"}


def read_audit_tail(n: int = 20) -> list[dict[str, Any]]:
    log_path = repo_root() / ".cursor" / ".local" / "release-exec-audit.jsonl"
    if not log_path.exists():
        return []
    lines = log_path.read_text(encoding="utf-8").strip().splitlines()
    rows: list[dict[str, Any]] = []
    for line in lines[-n:]:
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return rows


def historical_pattern_score(
    audit_rows: list[dict[str, Any]], change_class: str
) -> tuple[float, list[str]]:
    reasons: list[str] = []
    boost = 0.0
    blocked = [r for r in audit_rows if r.get("status") == "blocked"]
    risky_blocks = [
        r
        for r in blocked
        if r.get("release_class") == "RISKY" or r.get("action") in ("merge", "deploy")
    ]
    if risky_blocks and change_class == "RISKY":
        boost += 0.08
        reasons.append(
            f"Historical audit: {len(risky_blocks)} recent blocked execution attempt(s) on RISKY/merge/deploy paths"
        )
    drift_blocks = [r for r in blocked if "drift" in json.dumps(r).lower()]
    if drift_blocks:
        boost += 0.05
        reasons.append(
            f"Historical audit: {len(drift_blocks)} recent block(s) involved production drift"
        )
    return boost, reasons


def ci_failures(pr: dict[str, Any]) -> list[str]:
    fails: list[str] = []
    for check in pr.get("statusCheckRollup") or []:
        if check.get("conclusion") == "FAILURE":
            name = check.get("name") or "unknown"
            fails.append(name)
    return fails


def drift_modifier(drift: dict[str, Any], model: dict[str, Any]) -> tuple[float, list[str]]:
    mods = model["modifiers"]
    status = drift.get("drift_status", "UNKNOWN")
    boost = 0.0
    reasons: list[str] = []
    if status == "DIVERGED":
        boost += mods["drift_diverged"]
        reasons.append("Production and main are DIVERGED — merge amplifies reconciliation risk")
    elif status == "PROD_AHEAD":
        boost += mods["drift_prod_ahead"]
        reasons.append("Production is ahead of main — merge may not reflect live state")
    elif status == "BACKEND_ONLY_LAG":
        boost += mods["drift_backend_lag"]
        reasons.append("Backend-only lag detected — deploy scope must be explicit")
    elif status == "SYNCED":
        reasons.append("Production frontend hash matches main — drift sensitivity low")
    return boost, reasons


def cost_impact_estimate(paths: list[str], pr: dict[str, Any]) -> tuple[float, list[str]]:
    """Estimate infra/AI workflow cost delta (advisory)."""
    boost = 0.0
    reasons: list[str] = []
    workflow_paths = [p for p in paths if p.startswith(".github/") or "workflow" in p]
    ai_paths = [p for p in paths if ".cursor" in p or p.endswith("AGENTS.md")]
    additions = pr.get("additions") or 0

    if workflow_paths:
        boost += 0.05
        reasons.append(
            f"CI/workflow files changed ({len(workflow_paths)}) — expect increased Actions minutes"
        )
    if ai_paths:
        boost += 0.04
        reasons.append(
            f"AI policy/rules changed ({len(ai_paths)}) — agent sessions may re-run longer validation loops"
        )
    if additions > 800:
        boost += 0.03
        reasons.append(f"Large diff (+{additions} lines) — review and CI cost scale with change size")
    if not reasons:
        reasons.append("No significant AI/infra workflow cost signals detected")
    return boost, reasons


def coupling_risk(modules: list[str], model: dict[str, Any]) -> tuple[float, list[str]]:
    high_coupling = {"auth", "billing", "session_logic", "student_records"}
    overlap = high_coupling.intersection(set(modules))
    if len(overlap) >= 2:
        boost = model["modifiers"]["multi_module_coupling"]
        return boost, [
            f"Multi-module coupling detected: {', '.join(sorted(overlap))} — blast radius spans critical domains"
        ]
    return 0.0, []


def migration_irreversible(paths: list[str], model: dict[str, Any]) -> tuple[float, list[str]]:
    mig = [p for p in paths if "database/migrations" in p]
    if mig:
        return model["modifiers"]["migration_irreversible"], [
            f"Database migration(s) present ({len(mig)}) — rollback complexity elevated"
        ]
    return 0.0, []


def score_to_level(score: float) -> str:
    if score >= 0.75:
        return "critical"
    if score >= 0.55:
        return "high"
    if score >= 0.25:
        return "medium"
    return "low"


def recommend_strategy(
    change_class: str, risk_level: str, drift: dict[str, Any], ci_fails: list[str]
) -> str:
    drift_status = drift.get("drift_status", "UNKNOWN")
    if risk_level == "critical" or (change_class == "RISKY" and ci_fails):
        return "block"
    if change_class == "SAFE" and drift_status in ("SYNCED", "MAIN_AHEAD", "BACKEND_ONLY_LAG"):
        return "direct_merge"
    if change_class == "RISKY" or drift_status in ("DIVERGED", "PROD_AHEAD"):
        return "staged_release"
    if change_class == "DEPLOY":
        if drift_status == "SYNCED" and not ci_fails:
            return "staged_release"
        return "canary"
    return "staged_release"


def rollback_complexity(paths: list[str], modules: list[str]) -> str:
    if any("database/migrations" in p for p in paths):
        return "high"
    if "auth" in modules or "session_logic" in modules or "billing" in modules:
        return "high"
    if paths and all(p.startswith(("frontend/", "docs/")) for p in paths):
        return "low"
    if modules:
        return "medium"
    return "low"


def compute_confidence(
    pr: dict[str, Any], drift: dict[str, Any], audit_rows: list[dict[str, Any]], has_gh: bool
) -> float:
    c = 0.5
    if has_gh and "error" not in pr:
        c += 0.2
    if drift.get("drift_status") not in (None, "UNKNOWN"):
        c += 0.15
    if audit_rows:
        c += min(0.1, len(audit_rows) * 0.005)
    if pr.get("statusCheckRollup"):
        c += 0.05
    return round(min(0.98, c), 2)


def analyze(pr_id: str) -> dict[str, Any]:
    model = load_risk_model()
    pr = fetch_pr_context(pr_id)
    has_gh = "error" not in pr

    paths = pr_file_paths(pr) if has_gh else []
    body_class = pr_body_class(pr.get("body", "")) if has_gh else None
    change_class = infer_change_class(paths, model, body_class)

    base = baseline_risk_score(paths, model)
    drift = drift_snapshot()
    audit_rows = read_audit_tail(20)

    reasoning: list[str] = []
    modifiers = 0.0

    d_mod, d_reasons = drift_modifier(drift, model)
    modifiers += d_mod
    reasoning.extend(d_reasons)

    h_mod, h_reasons = historical_pattern_score(audit_rows, change_class)
    modifiers += h_mod
    reasoning.extend(h_reasons)

    ci_fails = ci_failures(pr) if has_gh else []
    if ci_fails:
        modifiers += model["modifiers"]["ci_failure"]
        reasoning.append(f"CI failures on required checks: {', '.join(ci_fails[:5])}")

    modules = blast_radius_modules(paths, model)
    c_mod, c_reasons = coupling_risk(modules, model)
    modifiers += c_mod
    reasoning.extend(c_reasons)

    m_mod, m_reasons = migration_irreversible(paths, model)
    modifiers += m_mod
    reasoning.extend(m_reasons)

    cost_mod, cost_reasons = cost_impact_estimate(paths, pr if has_gh else {})
    modifiers += cost_mod
    reasoning.extend(cost_reasons)

    risk_score = round(min(1.0, base + modifiers * (1 - base * 0.3)), 4)
    risk_level = score_to_level(risk_score)

    if body_class and body_class != change_class:
        reasoning.insert(
            0,
            f"PR declares Release-Class {body_class}; engine inferred {change_class} from paths — verify alignment",
        )

    strategy = recommend_strategy(change_class, risk_level, drift, ci_fails)
    rollback = rollback_complexity(paths, modules)

    # Map internal module keys to output blast_radius labels
    blast_out = modules if modules else (["docs"] if change_class == "SAFE" else ["application_core"])

    next_actions = [
        f"Run ./scripts/release-exec.sh validate --pr {pr_id}",
        f"Run ./scripts/decision-simulate.sh --pr {pr_id}",
    ]
    if change_class != "SAFE":
        next_actions.append("Require CEO approval before merge/deploy")
    if change_class == "RISKY" or risk_level in ("high", "critical"):
        next_actions.append("Document backup + rollback plan in PR body")
    if drift.get("drift_status") not in ("SYNCED", "MAIN_AHEAD", "BACKEND_ONLY_LAG"):
        next_actions.append("Resolve drift via ./scripts/release-check.sh before execution")
    if strategy == "block":
        next_actions.insert(0, "Do NOT merge until risk drivers are mitigated")
    elif strategy in ("staged_release", "canary"):
        next_actions.append("Consider staging deployment (#868) when available")

    confidence = compute_confidence(pr, drift, audit_rows, has_gh)

    if not has_gh:
        reasoning.append("GitHub PR metadata unavailable — analysis based on limited inputs")
        risk_score = min(1.0, risk_score + 0.1)
        risk_level = score_to_level(risk_score)
        strategy = "block"
        next_actions.insert(0, "Install/authenticate gh CLI and re-run analyze")

    return {
        "pr_id": str(pr_id),
        "risk_score": risk_score,
        "risk_level": risk_level,
        "change_class": change_class,
        "blast_radius": blast_out,
        "recommended_strategy": strategy,
        "rollback_complexity": rollback,
        "confidence": confidence,
        "reasoning": reasoning[:12],
        "next_actions": next_actions[:8],
    }


def main() -> None:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "usage: decision_analyze.py <pr_id>"}))
        sys.exit(2)
    pr_id = sys.argv[1]
    result = analyze(pr_id)
    print(json.dumps(result, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
