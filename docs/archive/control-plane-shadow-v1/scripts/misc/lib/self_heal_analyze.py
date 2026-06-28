#!/usr/bin/env python3
"""
self_heal_analyze.py — Self-Healing + Consistency Layer (read-only analysis).

Does NOT deploy, merge, or modify application code.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def repo_root() -> Path:
    return Path(os.environ.get("REPO_ROOT", Path(__file__).resolve().parents[2]))


def shcl_dir() -> Path:
    d = repo_root() / ".cursor" / ".local" / "shcl"
    d.mkdir(parents=True, exist_ok=True)
    return d


def run_cmd(cmd: list[str], timeout: int = 120) -> tuple[int, str]:
    try:
        r = subprocess.run(
            cmd,
            cwd=repo_root(),
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        return r.returncode, (r.stdout or "") + (r.stderr or "")
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        return 1, str(e)


def parse_json_tail(text: str) -> dict[str, Any]:
    text = text.strip()
    if not text:
        return {}
    m = re.search(r"\{[\s\S]*\}\s*$", text)
    if m:
        try:
            return json.loads(m.group(0))
        except json.JSONDecodeError:
            pass
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return {}


def read_jsonl_tail(path: Path, n: int = 20) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    lines = path.read_text(encoding="utf-8").strip().splitlines()
    rows: list[dict[str, Any]] = []
    for line in lines[-n:]:
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return rows


def drift_snapshot() -> dict[str, Any]:
    cached = shcl_dir() / "drift-latest.json"
    if cached.exists():
        try:
            return json.loads(cached.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            pass
    env = {**os.environ, "JSON_OUT": "1", "SHCL_FEED": "1"}
    code, out = run_cmd([str(repo_root() / "scripts" / "release-check.sh")], timeout=60)
    data = parse_json_tail(out)
    data["_exit_code"] = code
    return data


def main_head() -> str:
    code, out = run_cmd(["git", "rev-parse", "--short=8", "origin/main"])
    if code == 0:
        return out.strip()
    code, out = run_cmd(["git", "rev-parse", "--short=8", "main"])
    return out.strip() if code == 0 else "unknown"


def open_prs() -> list[dict[str, Any]]:
    code, out = run_cmd(
        ["gh", "pr", "list", "--state", "open", "--json", "number,title,headRefName,body", "--limit", "20"]
    )
    if code != 0:
        return []
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        return []


def sop_stamp(pr_id: str) -> dict[str, Any]:
    p = repo_root() / ".cursor" / ".local" / "sop-enforcement" / f"pr-{pr_id}.json"
    if not p.exists():
        return {}
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def run_sop_enforce(pr_id: str) -> dict[str, Any]:
    script = repo_root() / "scripts" / "sop-enforce.sh"
    if not script.exists():
        return {}
    code, out = run_cmd([str(script), "--pr", str(pr_id)])
    return parse_json_tail(out)


def run_decision_engine(pr_id: str) -> dict[str, Any]:
    script = repo_root() / "scripts" / "decision-engine.sh"
    if not script.exists():
        return {}
    code, out = run_cmd([str(script), "analyze", "--pr", str(pr_id)])
    return parse_json_tail(out)


def parse_release_class(body: str) -> str | None:
    m = re.search(r"Release-Class:\s*(SAFE|DEPLOY|RISKY)", body or "", re.I)
    return m.group(1).upper() if m else None


def build_drift_graph(
    drift: dict[str, Any],
    main_short: str,
    pr_states: list[dict[str, Any]],
) -> dict[str, Any]:
    prod_hash = drift.get("prod_version_hash") or drift.get("prod_commit_resolved")
    pi_head = drift.get("pi_head_short")
    nodes = {
        "main": {"ref": main_short, "layer": "L3"},
        "production": {
            "frontend_hash": prod_hash,
            "backend_head": pi_head,
            "layer": "L1/L2",
        },
        "decision_state": {"open_pr_analyses": len(pr_states)},
        "sop_state": {
            "stamped_prs": sum(1 for p in pr_states if p.get("sop_stamp")),
        },
        "execution_state": {
            "recent_audit_events": len(
                read_jsonl_tail(repo_root() / ".cursor" / ".local" / "release-exec-audit.jsonl", 20)
            ),
        },
    }
    edges: list[dict[str, Any]] = []
    drift_status = drift.get("drift_status", "UNKNOWN")

    if drift_status == "SYNCED":
        edges.append({"from": "main", "to": "production", "type": "aligned"})
    elif drift_status == "MAIN_AHEAD":
        edges.append({"from": "main", "to": "production", "type": "divergence", "note": "main ahead — pending deploy"})
    elif drift_status == "BACKEND_ONLY_LAG":
        edges.append({"from": "main", "to": "production", "type": "outdated_dependency", "note": "backend-only lag"})
    elif drift_status == "PROD_AHEAD":
        edges.append({"from": "production", "to": "main", "type": "conflict", "note": "prod ahead of main"})
    elif drift_status == "DIVERGED":
        edges.append({"from": "main", "to": "production", "type": "conflict", "note": "lineages diverged"})
    else:
        edges.append({"from": "main", "to": "production", "type": "divergence", "note": drift_status})

    for ps in pr_states:
        if ps.get("sop_decision_mismatch"):
            edges.append(
                {
                    "from": "sop_state",
                    "to": "decision_state",
                    "type": "conflict",
                    "pr": ps["number"],
                    "note": ps["sop_decision_mismatch"],
                }
            )
        if ps.get("exec_without_sop"):
            edges.append(
                {
                    "from": "execution_state",
                    "to": "sop_state",
                    "type": "conflict",
                    "pr": ps.get("number"),
                    "note": "execution without SOP pass",
                }
            )

    return {"nodes": nodes, "edges": edges}


def classify_severity(
    drift_status: str,
    conflicts: list[str],
    drift_sources: list[str],
) -> tuple[str, str, float]:
    """Returns (status, severity_label, risk_score)."""
    if drift_status == "DIVERGED" or any("execution without SOP" in c for c in conflicts):
        return "CRITICAL_CONFLICT", "critical", 0.95
    if drift_status == "PROD_AHEAD" or len(conflicts) >= 2:
        return "CRITICAL_CONFLICT", "critical", 0.85
    if drift_status in ("MAIN_AHEAD", "UNKNOWN") or conflicts:
        if drift_status == "MAIN_AHEAD" and not conflicts:
            return "DRIFT_DETECTED", "medium", 0.35
        return "DRIFT_DETECTED", "high", 0.65
    if drift_status == "BACKEND_ONLY_LAG":
        return "DRIFT_DETECTED", "low", 0.15
    if conflicts or drift_sources:
        return "DRIFT_DETECTED", "medium", 0.45
    return "SYNCED", "low", 0.05


def map_drift_severity_class(drift_status: str) -> str:
    mapping = {
        "SYNCED": "SYNCED",
        "BACKEND_ONLY_LAG": "MINOR_DRIFT",
        "MAIN_AHEAD": "MINOR_DRIFT",
        "PROD_AHEAD": "MAJOR_DRIFT",
        "DIVERGED": "CRITICAL_CONFLICT",
        "UNKNOWN": "MAJOR_DRIFT",
    }
    return mapping.get(drift_status, "MAJOR_DRIFT")


def recommended_action(severity_class: str, conflicts: list[str]) -> str:
    if severity_class == "CRITICAL_CONFLICT":
        return "freeze"
    if severity_class == "MAJOR_DRIFT":
        return "manual_review"
    if severity_class == "MINOR_DRIFT":
        if any("MAIN_AHEAD" in c or "pending deploy" in c.lower() for c in conflicts):
            return "auto_PR"
        return "manual_review"
    return "manual_review" if conflicts else "manual_review"


def approval_level(severity_class: str, action: str) -> str:
    if severity_class == "CRITICAL_CONFLICT" or action == "rollback":
        return "CEO"
    if severity_class in ("MAJOR_DRIFT", "MINOR_DRIFT") or action == "auto_PR":
        return "CEO"
    return "none"


def analyze_pr_states(
    prs: list[dict[str, Any]], audit_rows: list[dict[str, Any]], deep: bool = False
) -> list[dict[str, Any]]:
    states: list[dict[str, Any]] = []
    for pr in prs[:10]:
        num = str(pr.get("number", ""))
        body = pr.get("body") or ""
        sop_class = parse_release_class(body)
        stamp = sop_stamp(num)

        dec_cache = shcl_dir() / f"decision-pr-{num}.json"
        if dec_cache.exists():
            try:
                dec_json = json.loads(dec_cache.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                dec_json = {}
        elif deep:
            dec_json = run_decision_engine(num)
        else:
            dec_json = {}

        if deep:
            sop_json = run_sop_enforce(num)
        else:
            sop_json = {"release_class": stamp.get("decision_engine", {}).get("change_class") or sop_class}
            # Load last sop audit for this pr if any
            for row in reversed(read_jsonl_tail(repo_root() / ".cursor" / ".local" / "sop-enforcement-audit.jsonl", 50)):
                if row.get("pr_id") == num or str(row.get("pr_id")) == num:
                    sop_json["status"] = row.get("status")
                    break

        dec_class = dec_json.get("change_class")
        sop_effective = sop_json.get("release_class") or sop_class

        mismatch = None
        if sop_effective and dec_class and sop_effective != dec_class:
            if not (sop_effective == "SAFE" and dec_class == "DEPLOY"):
                mismatch = f"SOP={sop_effective} but decision-engine={dec_class}"

        exec_without_sop = False
        for row in audit_rows:
            if row.get("action") in ("merge", "deploy") and row.get("status") == "allowed":
                if row.get("pr") == int(num) if num.isdigit() else False:
                    if sop_json.get("status") == "fail":
                        exec_without_sop = True
        # Also check recent merge without sop stamp
        if not stamp.get("decision_engine") and dec_class in ("DEPLOY", "RISKY"):
            for row in audit_rows:
                if row.get("action") == "merge" and row.get("status") == "allowed":
                    exec_without_sop = True

        states.append(
            {
                "number": num,
                "title": pr.get("title"),
                "sop_class": sop_effective,
                "decision_class": dec_class,
                "sop_status": sop_json.get("status"),
                "sop_stamp": bool(stamp),
                "sop_decision_mismatch": mismatch,
                "exec_without_sop": exec_without_sop,
                "decision_strategy": dec_json.get("recommended_strategy"),
            }
        )
    return states


def analyze() -> dict[str, Any]:
    drift = drift_snapshot()
    main_short = main_head()
    drift_status = drift.get("drift_status", "UNKNOWN")

    audit_exec = read_jsonl_tail(repo_root() / ".cursor" / ".local" / "release-exec-audit.jsonl", 20)
    audit_sop = read_jsonl_tail(repo_root() / ".cursor" / ".local" / "sop-enforcement-audit.jsonl", 20)
    triggers = read_jsonl_tail(shcl_dir() / "healing-triggers.jsonl", 30)

    prs = open_prs()
    deep = os.environ.get("SHCL_DEEP") == "1"
    pr_states = analyze_pr_states(prs, audit_exec, deep=deep) if prs else []

    drift_sources: list[str] = []
    conflicts: list[str] = []

    if drift_status != "SYNCED":
        drift_sources.append("production vs main")
    if drift_status == "PROD_AHEAD":
        drift_sources.append("manual deploy drift — prod ahead of main")
    if drift.get("pi_head_short") and drift.get("prod_version_hash"):
        if drift["pi_head_short"] != drift.get("prod_version_hash") and drift_status not in (
            "BACKEND_ONLY_LAG",
            "SYNCED",
        ):
            drift_sources.append("frontend version.json vs backend HEAD mismatch")

    for ps in pr_states:
        if ps.get("sop_decision_mismatch"):
            drift_sources.append("decision vs SOP mismatch")
            conflicts.append(f"PR #{ps['number']}: {ps['sop_decision_mismatch']}")
        if ps.get("exec_without_sop"):
            drift_sources.append("execution vs SOP enforcement gap")
            conflicts.append(f"PR #{ps['number']}: execution executed but SOP failed or incomplete")
        if ps.get("decision_class") in ("DEPLOY", "RISKY") and ps.get("sop_status") == "fail":
            conflicts.append(f"PR #{ps['number']}: SOP fail on {ps['decision_class']} class")
        if ps.get("decision_class") in ("DEPLOY", "RISKY") and not ps.get("sop_stamp"):
            if not any(f"#{ps['number']}" in c for c in conflicts):
                conflicts.append(
                    f"PR #{ps['number']}: missing SOP enforcement chain stamp for {ps['decision_class']}"
                )

    for row in audit_sop:
        if row.get("status") == "fail":
            triggers.append(row)

    severity_class = map_drift_severity_class(drift_status)
    if drift_status in ("DIVERGED", "PROD_AHEAD"):
        severity_class = "CRITICAL_CONFLICT"
    elif conflicts and severity_class in ("SYNCED", "MINOR_DRIFT"):
        severity_class = "MAJOR_DRIFT"

    status, severity, risk_score = classify_severity(drift_status, conflicts, drift_sources)
    if severity_class == "CRITICAL_CONFLICT":
        status, severity, risk_score = "CRITICAL_CONFLICT", "critical", max(risk_score, 0.85)
    elif severity_class == "MAJOR_DRIFT" and status == "SYNCED":
        status, severity, risk_score = "DRIFT_DETECTED", "high", max(risk_score, 0.6)
    elif severity_class == "MINOR_DRIFT" and status == "SYNCED":
        status, severity, risk_score = "DRIFT_DETECTED", "low", max(risk_score, 0.2)

    action = recommended_action(severity_class, conflicts)
    if drift_status == "PROD_AHEAD":
        action = "manual_review"
    if drift_status == "DIVERGED":
        action = "freeze"

    repair_plan: list[str] = []
    if drift_status == "MAIN_AHEAD":
        repair_plan.append("CEO deploy approved PRs or wait — main ahead of production is expected pre-deploy")
        repair_plan.append("Run ./scripts/release-exec.sh validate --pr <N> before deploy")
    if drift_status == "PROD_AHEAD":
        repair_plan.append("create reconciliation PR to merge prod-aligned commit into main (forward-fix preferred)")
        repair_plan.append("Run ./scripts/reconcile-plan.sh for ordered steps")
        repair_plan.append("Record incident in docs/AI_REGRESSION_LESSONS.md")
    if drift_status == "DIVERGED":
        repair_plan.append("freeze new merges on affected surface per docs/SRE_POLICY.md")
        repair_plan.append("CEO chooses forward-fix PR or rollback per docs/RUNBOOK_ROLLBACK.md")
    if drift_status == "BACKEND_ONLY_LAG":
        repair_plan.append("no action if backend-only release — document in CHANGELOG")
    pr_repair_added = False
    for ps in pr_states:
        if ps.get("sop_decision_mismatch"):
            pr_repair_added = True
            repair_plan.append(f"PR #{ps['number']}: align Release-Class with decision-engine class")
            repair_plan.append(f"PR #{ps['number']}: re-run ./scripts/decision-engine.sh analyze --pr {ps['number']}")
        if ps.get("sop_status") == "fail":
            pr_repair_added = True
            repair_plan.append(f"PR #{ps['number']}: re-run ./scripts/sop-enforce.sh --pr {ps['number']}")
            repair_plan.append(f"PR #{ps['number']}: ./scripts/sop-checklist.sh --pr {ps['number']} --run decision_engine")
    if not repair_plan and status == "SYNCED" and not conflicts:
        repair_plan.append("no repair required — run ./scripts/consistency-check.sh weekly")
    elif pr_repair_added and status == "SYNCED":
        repair_plan.insert(0, "production synced — open PRs need SOP alignment before merge")

    confidence = 0.5
    if drift.get("drift_status"):
        confidence += 0.2
    if drift.get("health") == "ok":
        confidence += 0.1
    if prs:
        confidence += 0.1
    confidence = round(min(0.98, confidence), 2)

    graph = build_drift_graph(drift, main_short, pr_states)

    result = {
        "status": status,
        "severity": severity,
        "severity_class": severity_class,
        "drift_sources": list(dict.fromkeys(drift_sources)),
        "conflicts": list(dict.fromkeys(conflicts))[:15],
        "recommended_action": action,
        "approval_required": approval_level(severity_class, action),
        "proposed_repair_plan": repair_plan[:12],
        "risk_score": round(risk_score, 4),
        "confidence": confidence,
        "drift_graph": graph,
        "drift_status": drift_status,
        "main_short": main_short,
        "open_pr_count": len(prs),
        "analyzed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }

    (shcl_dir() / "analysis-latest.json").write_text(
        json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    return result


def consistency_check() -> dict[str, Any]:
    drift = drift_snapshot()
    main_short = main_head()
    audit_exec = read_jsonl_tail(repo_root() / ".cursor" / ".local" / "release-exec-audit.jsonl", 20)
    prs = open_prs()
    deep = os.environ.get("SHCL_DEEP") == "1"
    pr_states = analyze_pr_states(prs, audit_exec, deep=deep) if prs else []

    checks = []
    mismatches: list[dict[str, Any]] = []

    # Production ↔ Git
    ds = drift.get("drift_status", "UNKNOWN")
    pg_ok = ds in ("SYNCED", "MAIN_AHEAD", "BACKEND_ONLY_LAG")
    checks.append({"pair": "Production ↔ Git", "pass": pg_ok, "detail": ds})
    if not pg_ok:
        mismatches.append({"pair": "Production ↔ Git", "severity": "critical", "detail": ds})

    # Execution ↔ Production
    recent_deploy = any(r.get("action") == "deploy" and r.get("status") == "allowed" for r in audit_exec)
    ep_ok = pg_ok or not recent_deploy
    checks.append({"pair": "Execution ↔ Production", "pass": ep_ok, "detail": "drift aligned with exec history"})
    if not ep_ok:
        mismatches.append({"pair": "Execution ↔ Production", "severity": "high", "detail": "deploy allowed but drift persists"})

    # SOP ↔ Decision (per open PR)
    sd_ok = True
    for ps in pr_states:
        if ps.get("sop_decision_mismatch"):
            sd_ok = False
            mismatches.append(
                {
                    "pair": "SOP ↔ Decision engine",
                    "severity": "high",
                    "detail": f"PR #{ps['number']}: {ps['sop_decision_mismatch']}",
                }
            )
    checks.append({"pair": "SOP ↔ Decision engine", "pass": sd_ok, "detail": f"{len(prs)} open PRs"})

    # Decision ↔ Execution
    de_ok = True
    for ps in pr_states:
        if ps.get("exec_without_sop"):
            de_ok = False
            mismatches.append(
                {
                    "pair": "Decision ↔ Execution",
                    "severity": "critical",
                    "detail": f"PR #{ps['number']}: exec without SOP chain",
                }
            )
    checks.append({"pair": "Decision ↔ Execution", "pass": de_ok, "detail": "audit cross-check"})

    # SOP ↔ Execution
    se_ok = all(ps.get("sop_status") != "fail" for ps in pr_states) if pr_states else True
    checks.append({"pair": "SOP ↔ Execution", "pass": se_ok, "detail": "open PR sop status"})
    for ps in pr_states:
        if ps.get("sop_status") == "fail":
            mismatches.append(
                {
                    "pair": "SOP ↔ Execution",
                    "severity": "medium",
                    "detail": f"PR #{ps['number']}: SOP fail blocks execution path",
                }
            )

    overall = "PASS" if not mismatches else "FAIL"
    severity_rank = sorted(
        mismatches,
        key=lambda m: {"critical": 0, "high": 1, "medium": 2, "low": 3}.get(m["severity"], 4),
    )

    return {
        "result": overall,
        "checks": checks,
        "mismatches": severity_rank,
        "mismatch_count": len(mismatches),
        "drift_status": ds,
        "main_short": main_short,
    }


def reconcile_plan() -> dict[str, Any]:
    analysis = analyze()
    drift_status = analysis.get("drift_status", "UNKNOWN")
    prs = open_prs()

    recommended_prs: list[dict[str, Any]] = []
    commits: list[str] = []
    rollback: list[str] = []
    ordering: list[str] = []

    if drift_status == "PROD_AHEAD":
        ordering.append("1. Run ./scripts/release-check.sh and archive output")
        ordering.append("2. Identify prod commit via version.json hash")
        ordering.append("3. Create fix/reconcile-* branch from origin/main")
        recommended_prs.append(
            {
                "type": "forward-fix",
                "title": "reconcile: align main with production truth",
                "branch": "fix/reconcile-prod-ahead",
                "release_class": "RISKY",
                "approval": "CEO",
            }
        )
        commits.append("Merge prod-aligned changes into main within 24h per production-truth-model.md")

    if drift_status == "DIVERGED":
        ordering.append("1. STOP merges — freeze per SRE_POLICY.md if SLO impacted")
        ordering.append("2. CEO incident decision: forward-fix vs rollback")
        rollback.append("git revert <bad-merge> → PR → release-exec merge (CEO)")
        rollback.append("Pi reset to PREV_COMMIT via deploy.yml re-run per RUNBOOK_ROLLBACK.md §3b")
        recommended_prs.append(
            {
                "type": "rollback",
                "title": "revert: restore known-good production state",
                "branch": "fix/rollback-diverged",
                "release_class": "RISKY",
                "approval": "CEO",
            }
        )

    if drift_status == "MAIN_AHEAD":
        ordering.append("1. Review open DEPLOY PRs for CEO deploy approval")
        ordering.append("2. ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy --pr N")
        ordering.append("3. Re-run ./scripts/release-check.sh until SYNCED")

    for ps in analyze_pr_states(prs, read_jsonl_tail(repo_root() / ".cursor" / ".local" / "release-exec-audit.jsonl", 20)) if prs else []:
        n = ps["number"]
        if ps.get("sop_status") == "fail" or ps.get("sop_decision_mismatch"):
            recommended_prs.append(
                {
                    "type": "sop-alignment",
                    "title": f"chore: align SOP/decision for PR #{n}",
                    "action": "update PR body Release-Class + re-run sop-checklist",
                    "approval": "none",
                }
            )
            ordering.append(f"PR #{n}: ./scripts/sop-checklist.sh --pr {n} --run decision_engine")

    if drift_status == "SYNCED" and not recommended_prs:
        ordering.append("No reconciliation required — system converged")

    return {
        "drift_status": drift_status,
        "severity_class": analysis.get("severity_class"),
        "recommended_prs": recommended_prs,
        "required_commits": commits,
        "rollback_instructions": rollback,
        "dependency_ordering": ordering,
        "note": "Plan only — does NOT execute merge, deploy, or git push",
        "source_analysis": {
            "status": analysis.get("status"),
            "risk_score": analysis.get("risk_score"),
        },
    }


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "analyze"
    if cmd == "analyze":
        print(json.dumps(analyze(), indent=2, ensure_ascii=False))
        sys.exit(0)
    if cmd == "consistency":
        out = consistency_check()
        print(json.dumps(out, indent=2, ensure_ascii=False))
        sys.exit(0 if out["result"] == "PASS" else 1)
    if cmd == "reconcile":
        print(json.dumps(reconcile_plan(), indent=2, ensure_ascii=False))
        sys.exit(0)
    print(json.dumps({"error": f"unknown cmd {cmd}"}))
    sys.exit(2)


if __name__ == "__main__":
    main()
