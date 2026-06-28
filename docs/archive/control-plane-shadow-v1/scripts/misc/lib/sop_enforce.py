#!/usr/bin/env python3
"""
sop_enforce.py — Runtime SOP validation (read-only on repo; writes stamp files only).

Enforces rules extracted from docs/current-engineering-sop.md.
Does NOT merge, deploy, or modify application code.
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


def sop_state_dir() -> Path:
    d = repo_root() / ".cursor" / ".local" / "sop-enforcement"
    d.mkdir(parents=True, exist_ok=True)
    return d


def audit_log_path() -> Path:
    return repo_root() / ".cursor" / ".local" / "sop-enforcement-audit.jsonl"


def log_audit(event: str, status: str, detail: dict[str, Any]) -> None:
    audit_log_path().parent.mkdir(parents=True, exist_ok=True)
    row = {
        "ts": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "event": event,
        "status": status,
        **detail,
    }
    with audit_log_path().open("a", encoding="utf-8") as f:
        f.write(json.dumps(row, ensure_ascii=False) + "\n")


def run_cmd(cmd: list[str], cwd: Path | None = None) -> tuple[int, str]:
    try:
        r = subprocess.run(
            cmd,
            cwd=cwd or repo_root(),
            capture_output=True,
            text=True,
            timeout=120,
        )
        return r.returncode, (r.stdout or "") + (r.stderr or "")
    except (subprocess.TimeoutExpired, FileNotFoundError) as e:
        return 1, str(e)


def parse_release_class(text: str) -> str | None:
    m = re.search(r"Release-Class:\s*(SAFE|DEPLOY|RISKY)", text or "", re.I)
    return m.group(1).upper() if m else None


def infer_class_from_paths(paths: list[str]) -> str:
    """Mirror release-exec-lib heuristics (read-only classification)."""
    risky_patterns = [
        "backend/database/migrations/",
        "backend/public/.htaccess",
        "backend/app/Http/Middleware/",
        "SwipeRfidController",
        "SessionDeductionService",
        "AuthController",
        "DIRECTOR_PAYMENT",
        "backend/routes/api.php",
    ]
    deploy_prefixes = [
        "backend/app/",
        "backend/routes/",
        "backend/database/migrations/",
        "frontend/src/",
        "frontend/package",
    ]
    risky = deployable = False
    safe_only = True
    for path in paths:
        if not path:
            continue
        for p in risky_patterns:
            if p in path:
                risky = True
                safe_only = False
        for p in deploy_prefixes:
            if path.startswith(p) or p in path:
                deployable = True
                safe_only = False
        if path.startswith(("backend/", "frontend/", "scripts/", ".github/")):
            if not path.startswith("docs/") and not path.endswith(".md"):
                safe_only = False
    if risky:
        return "RISKY"
    if deployable:
        return "DEPLOY"
    if safe_only:
        return "SAFE"
    return "DEPLOY"


def branch_name_valid(name: str) -> bool:
    if not name or name in ("main", "master"):
        return False
    patterns = [
        r"^feat/",
        r"^fix/",
        r"^chore/",
        r"^hotfix/",
        r"^td-batch\d+-",
        r"^dependabot/",
    ]
    return any(re.match(p, name) for p in patterns)


def load_pr(pr_id: str) -> dict[str, Any] | None:
    code, out = run_cmd(
        [
            "gh",
            "pr",
            "view",
            str(pr_id),
            "--json",
            "number,title,body,state,files,headRefName,baseRefName",
        ]
    )
    if code != 0 or not out.strip():
        return None
    return json.loads(out)


def load_stamp(pr_id: str) -> dict[str, Any]:
    p = sop_state_dir() / f"pr-{pr_id}.json"
    if not p.exists():
        return {}
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def save_stamp(pr_id: str, updates: dict[str, Any]) -> None:
    data = load_stamp(pr_id)
    data.update(updates)
    data["updated_at"] = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    (sop_state_dir() / f"pr-{pr_id}.json").write_text(
        json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8"
    )


def pr_body_sections(body: str) -> dict[str, bool]:
    b = body or ""
    return {
        "summary": bool(re.search(r"(?i)(##\s*summary|summary:|##\s*摘要|變更摘要)", b)),
        "test_plan": bool(re.search(r"(?i)(test plan|測試|##\s*test|QA)", b)),
        "rollback": bool(re.search(r"(?i)(rollback|回滾|git revert)", b)),
        "production_impact": bool(re.search(r"(?i)(production impact|##\s*production|部署影響)", b)),
        "deploy_approved": bool(re.search(r"(?i)Deploy-Approved:\s*yes", b)),
    }


def stamp_has(stamp: dict[str, Any], key: str) -> bool:
    return bool(stamp.get(key, {}).get("completed_at"))


def record_step(pr_id: str, step: str, meta: dict[str, Any] | None = None) -> None:
    stamp = load_stamp(pr_id)
    stamp[step] = {
        "completed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        **(meta or {}),
    }
    save_stamp(pr_id, stamp)


def run_decision_engine(pr_id: str) -> tuple[bool, dict[str, Any]]:
    script = repo_root() / "scripts" / "decision-engine.sh"
    if not script.exists():
        return False, {"error": "decision-engine.sh missing"}
    code, out = run_cmd([str(script), "analyze", "--pr", str(pr_id)])
    try:
        data = json.loads(out.strip().split("\n")[-1] if "\n" in out else out)
    except json.JSONDecodeError:
        # multi-line JSON
        m = re.search(r"\{[\s\S]*\}\s*$", out)
        data = json.loads(m.group(0)) if m else {"error": "parse_failed", "raw": out[:500]}
    ok = code == 0 and "pr_id" in data
    if ok:
        record_step(pr_id, "decision_engine", {"change_class": data.get("change_class")})
    return ok, data


def run_decision_simulate(pr_id: str) -> tuple[bool, dict[str, Any]]:
    script = repo_root() / "scripts" / "decision-simulate.sh"
    if not script.exists():
        return False, {"error": "decision-simulate.sh missing"}
    code, out = run_cmd([str(script), "--pr", str(pr_id)])
    try:
        m = re.search(r"\{[\s\S]*\}\s*$", out)
        data = json.loads(m.group(0)) if m else {}
    except json.JSONDecodeError:
        data = {}
    ok = code == 0 and data.get("simulation_result")
    if ok:
        record_step(pr_id, "decision_simulate", {"result": data.get("simulation_result")})
    return ok, data


def validate_ai_output(text: str) -> list[str]:
    violations: list[str] = []
    checks = [
        (r"Release-Class:\s*(SAFE|DEPLOY|RISKY)", "missing Release-Class (SAFE|DEPLOY|RISKY)"),
        (
            r"(Risk-Tier|T[0-3]|risk_level|risk level).*(low|medium|high|T0|T1|T2|T3)",
            "missing risk classification (Risk-Tier / T0–T3)",
        ),
        (
            r"(Affected modules|affected_modules|影響模組|Modules:)",
            "missing affected modules summary",
        ),
        (r"(Diff summary|diff summary|變更摘要|Summary:)", "missing diff summary"),
    ]
    for pattern, msg in checks:
        if not re.search(pattern, text, re.I):
            violations.append(msg)

    forbidden = r"(?<!NOT )ready to deploy|(?<!NOT )已上線|deployed to production|production is live|merge and deploy now|已 merge|PR merged|gh pr merge"
    if re.search(forbidden, text, re.I):
        violations.append("forbidden deploy/merge claim in AI output (use 'PR ready for CEO')")
    return violations


def checklist_steps(release_class: str, action: str | None = None) -> list[dict[str, Any]]:
    steps: list[dict[str, Any]] = [
        {"id": "branch_valid", "label": "Feature branch (not main)", "required": True},
        {"id": "release_class_declared", "label": "Release-Class in PR body", "required": True},
        {"id": "release_class_aligned", "label": "Release-Class matches diff inference", "required": True},
        {"id": "pr_summary", "label": "PR summary section", "required": True},
    ]
    if release_class in ("DEPLOY", "RISKY"):
        steps.extend(
            [
                {"id": "test_plan", "label": "Test plan in PR body", "required": True},
                {"id": "production_impact", "label": "Production impact section", "required": True},
                {"id": "decision_engine", "label": "decision-engine.sh analyze", "required": True},
                {"id": "decision_simulate", "label": "decision-simulate.sh", "required": release_class == "RISKY"},
                {"id": "rollback", "label": "Rollback plan in PR body", "required": release_class == "RISKY"},
                {"id": "release_exec_validate", "label": "release-exec.sh validate", "required": action in ("merge", "deploy")},
            ]
        )
    if action == "deploy" and release_class in ("DEPLOY", "RISKY"):
        steps.append(
            {
                "id": "deploy_approved",
                "label": "Deploy-Approved: yes in PR body",
                "required": True,
            }
        )
    if release_class == "SAFE":
        steps.append(
            {"id": "no_deploy_intent", "label": "No deploy approval on SAFE PR", "required": True}
        )
    return steps


def evaluate_pr(
    pr_id: str,
    action: str | None = None,
    exec_json: dict[str, Any] | None = None,
    decision_json: dict[str, Any] | None = None,
    auto_run_missing: bool = False,
) -> dict[str, Any]:
    violations: list[str] = []
    next_steps: list[str] = []
    pr = load_pr(pr_id)
    if not pr:
        return emit_result(
            "fail",
            ["PR not found or gh unavailable"],
            "missing",
            ["Install gh auth login", f"Verify PR #{pr_id} exists"],
            {"pr_id": pr_id, "mode": "pr"},
        )

    paths = [f.get("path", "") for f in (pr.get("files") or [])]
    body = pr.get("body") or ""
    branch = pr.get("headRefName") or ""
    declared = parse_release_class(body)
    inferred = infer_class_from_paths(paths)
    sections = pr_body_sections(body)
    stamp = load_stamp(pr_id)

    if not branch_name_valid(branch):
        violations.append(f"invalid branch name '{branch}' — use feat/fix/chore/hotfix/*")

    if not declared:
        violations.append("missing Release-Class in PR body (SAFE | DEPLOY | RISKY)")
        next_steps.append("Add first line: Release-Class: SAFE|DEPLOY|RISKY")

    if declared and inferred == "RISKY" and declared == "SAFE":
        violations.append(f"Release-Class {declared} contradicts inferred RISKY from diff")
    elif declared and inferred == "DEPLOY" and declared == "SAFE":
        violations.append(f"Release-Class {declared} contradicts inferred DEPLOY from diff")

    effective_class = declared or inferred

    if not sections["summary"]:
        violations.append("missing PR summary section")

    if effective_class in ("DEPLOY", "RISKY"):
        if not sections["test_plan"]:
            violations.append("missing test plan in PR body (DEPLOY/RISKY SOP)")
        if not sections["production_impact"]:
            violations.append("missing production impact section (DEPLOY/RISKY SOP)")
        if effective_class == "RISKY" and not sections["rollback"]:
            violations.append("missing rollback plan in PR body (RISKY SOP)")

    # Decision engine required before DEPLOY/RISKY actions
    if effective_class in ("DEPLOY", "RISKY"):
        if decision_json and decision_json.get("pr_id"):
            record_step(pr_id, "decision_engine", {"source": "input"})
        elif not stamp_has(stamp, "decision_engine"):
            if auto_run_missing:
                ok, _ = run_decision_engine(pr_id)
                stamp = load_stamp(pr_id)
                if not ok:
                    violations.append("missing decision-engine step (auto-run failed)")
            else:
                violations.append("missing decision-engine step")
                next_steps.append(f"./scripts/decision-engine.sh analyze --pr {pr_id}")
        if effective_class == "RISKY" and not stamp_has(stamp, "decision_simulate"):
            if auto_run_missing:
                ok, sim = run_decision_simulate(pr_id)
                stamp = load_stamp(pr_id)
                if not ok:
                    violations.append("missing decision-simulate step (auto-run failed)")
                elif sim.get("simulation_result") == "DANGEROUS":
                    violations.append("decision-simulate result DANGEROUS — resolve before merge/deploy")
            else:
                violations.append("missing decision-simulate step (RISKY)")
                next_steps.append(f"./scripts/decision-simulate.sh --pr {pr_id}")

    if action in ("merge", "deploy"):
        if not stamp_has(stamp, "decision_engine") and effective_class in ("DEPLOY", "RISKY"):
            if "missing decision-engine step" not in violations:
                violations.append("missing decision-engine step before execution action")

    if action == "deploy":
        if effective_class == "SAFE":
            violations.append("illegal direct deploy attempt on SAFE Release-Class")
        if effective_class in ("DEPLOY", "RISKY") and not sections["deploy_approved"]:
            violations.append("missing Deploy-Approved: yes in PR body for deploy action")

    if effective_class == "SAFE" and sections["deploy_approved"]:
        violations.append("SAFE PR must not include Deploy-Approved (SOP: SAFE never deploys)")

    if exec_json:
        if exec_json.get("status") == "blocked" and action in ("merge", "deploy"):
            violations.append("release-exec validation blocked — resolve before execution")
        if action == "merge" and not stamp_has(stamp, "release_exec_validate"):
            if exec_json.get("status") == "allowed":
                record_step(pr_id, "release_exec_validate", {"status": "allowed"})
            else:
                violations.append("missing release-exec.sh validate step before merge")
                next_steps.append(f"./scripts/release-exec.sh validate --pr {pr_id}")

    # Build checklist completion
    steps_def = checklist_steps(effective_class, action)
    completed: dict[str, bool] = {
        "branch_valid": branch_name_valid(branch),
        "release_class_declared": bool(declared),
        "release_class_aligned": not any("contradicts" in v for v in violations),
        "pr_summary": sections["summary"],
        "test_plan": sections["test_plan"],
        "production_impact": sections["production_impact"],
        "rollback": sections["rollback"],
        "decision_engine": stamp_has(load_stamp(pr_id), "decision_engine"),
        "decision_simulate": stamp_has(load_stamp(pr_id), "decision_simulate"),
        "release_exec_validate": stamp_has(load_stamp(pr_id), "release_exec_validate"),
        "deploy_approved": sections["deploy_approved"],
        "no_deploy_intent": not (effective_class == "SAFE" and sections["deploy_approved"]),
    }

    required_ids = [s["id"] for s in steps_def if s["required"]]
    done = sum(1 for i in required_ids if completed.get(i))
    total = len(required_ids)
    missing_required = [i for i in required_ids if not completed.get(i)]

    for sid in missing_required:
        label = next((s["label"] for s in steps_def if s["id"] == sid), sid)
        msg = f"missing SOP step: {label}"
        if msg not in violations and not any(label in v for v in violations):
            violations.append(msg)

    if done == total and not violations:
        stage = "validated"
    elif done > 0:
        stage = "partial"
    else:
        stage = "missing"

    if not next_steps:
        if stage != "validated":
            next_steps.append(f"./scripts/sop-checklist.sh --pr {pr_id}")
            if effective_class in ("DEPLOY", "RISKY"):
                next_steps.append(f"./scripts/decision-engine.sh analyze --pr {pr_id}")
            if action:
                next_steps.append(f"./scripts/release-exec.sh validate --pr {pr_id}")
                next_steps.append("request CEO approval")

    status = "pass" if not violations and stage == "validated" else "fail"

    return emit_result(
        status,
        violations,
        stage,
        list(dict.fromkeys(next_steps))[:8],
        {
            "pr_id": pr_id,
            "mode": "pr",
            "release_class": effective_class,
            "declared_class": declared,
            "inferred_class": inferred,
            "branch": branch,
            "checklist_completion": f"{done}/{total}",
        },
    )


def evaluate_commit(
    branch: str,
    paths: list[str],
    release_class_env: str | None,
) -> dict[str, Any]:
    violations: list[str] = []
    next_steps: list[str] = []

    if branch in ("main", "master"):
        violations.append("direct commit on main forbidden (SOP §1.2)")

    if branch and not branch_name_valid(branch):
        violations.append(f"branch '{branch}' does not match feat/fix/chore/hotfix/* naming (SOP §1.2)")

    inferred = infer_class_from_paths(paths)
    rel = (release_class_env or "").upper().strip()
    if not rel and (repo_root() / ".cursor/.local/release-class").exists():
        rel = (repo_root() / ".cursor/.local/release-class").read_text().strip().upper()

    deployable = inferred in ("DEPLOY", "RISKY")
    critical = any(
        p.startswith(("backend/routes/", "backend/database/"))
        or p in ("AGENTS.md", ".cursorrules")
        or p.startswith(".cursor/")
        for p in paths
    )

    if deployable or critical:
        if not rel:
            violations.append("missing RELEASE_CLASS for deployable/critical path commit")
            next_steps.append("RELEASE_CLASS=SAFE|DEPLOY|RISKY git commit ...")
        elif rel not in ("SAFE", "DEPLOY", "RISKY"):
            violations.append(f"invalid RELEASE_CLASS '{rel}'")
        elif rel == "SAFE" and inferred in ("DEPLOY", "RISKY"):
            violations.append(f"RELEASE_CLASS SAFE contradicts inferred {inferred} diff")

    if os.environ.get("ALLTRUE_AI_COMMIT") == "1":
        handoff = repo_root() / ".cursor/.local/ai-handoff.md"
        if not handoff.exists():
            violations.append("AI commit missing .cursor/.local/ai-handoff.md")
        else:
            ai_v = validate_ai_output(handoff.read_text(encoding="utf-8"))
            violations.extend(ai_v)

    stage = "validated" if not violations else ("partial" if rel else "missing")
    status = "pass" if not violations else "fail"

    if not next_steps and violations:
        next_steps.append("./scripts/sop-checklist.sh --commit")

    return emit_result(
        status,
        violations,
        stage,
        next_steps,
        {"mode": "commit", "branch": branch, "inferred_class": inferred, "release_class": rel or None},
    )


def evaluate_ai(text: str) -> dict[str, Any]:
    violations = validate_ai_output(text)
    stage = "validated" if not violations else "missing"
    next_steps = []
    if violations:
        next_steps = [
            "Add Release-Class, Risk-Tier, Affected modules, Diff summary",
            "Use: Next step: PR ready for CEO — NOT ready to deploy.",
            "./scripts/ai-write-gate.sh --stdin",
        ]
    return emit_result(
        "pass" if not violations else "fail",
        violations,
        stage,
        next_steps,
        {"mode": "ai"},
    )


def evaluate_action(
    pr_id: str,
    action: str,
    exec_json: dict[str, Any] | None = None,
) -> dict[str, Any]:
    if action not in ("merge", "deploy", "validate"):
        return emit_result("fail", [f"unknown action '{action}'"], "missing", [], {"pr_id": pr_id})

    if action == "validate":
        return evaluate_pr(pr_id, action=None, exec_json=exec_json)

    result = evaluate_pr(pr_id, action=action, exec_json=exec_json, auto_run_missing=False)

    if action in ("merge", "deploy") and result["status"] == "pass":
        # Extra guard: SOP must be fully validated
        if result["sop_stage"] != "validated":
            result["status"] = "fail"
            result["violations"].append(f"SOP stage {result['sop_stage']} — full validation required before {action}")

    if action == "deploy" and result["status"] != "fail":
        # Block if simulate was DANGEROUS
        stamp = load_stamp(pr_id)
        sim = stamp.get("decision_simulate", {})
        if sim.get("result") == "DANGEROUS":
            result["status"] = "fail"
            result["violations"].append("illegal direct deploy attempt — simulation DANGEROUS")

    return result


def build_checklist(pr_id: str) -> dict[str, Any]:
    pr = load_pr(pr_id)
    if not pr:
        return {"pr_id": pr_id, "error": "pr_not_found", "steps": []}

    paths = [f.get("path", "") for f in (pr.get("files") or [])]
    declared = parse_release_class(pr.get("body") or "")
    inferred = infer_class_from_paths(paths)
    effective = declared or inferred
    stamp = load_stamp(pr_id)
    sections = pr_body_sections(pr.get("body") or "")
    branch = pr.get("headRefName") or ""

    status_map = {
        "branch_valid": branch_name_valid(branch),
        "release_class_declared": bool(declared),
        "release_class_aligned": not (
            declared and ((inferred == "RISKY" and declared == "SAFE") or (inferred == "DEPLOY" and declared == "SAFE"))
        ),
        "pr_summary": sections["summary"],
        "test_plan": sections["test_plan"],
        "production_impact": sections["production_impact"],
        "rollback": sections["rollback"],
        "decision_engine": stamp_has(stamp, "decision_engine"),
        "decision_simulate": stamp_has(stamp, "decision_simulate"),
        "release_exec_validate": stamp_has(stamp, "release_exec_validate"),
        "deploy_approved": sections["deploy_approved"],
        "no_deploy_intent": not (effective == "SAFE" and sections["deploy_approved"]),
    }

    steps_out = []
    missing = []
    for step in checklist_steps(effective, action=None):
        sid = step["id"]
        done = status_map.get(sid, False)
        steps_out.append(
            {
                "id": sid,
                "label": step["label"],
                "required": step["required"],
                "completed": done,
            }
        )
        if step["required"] and not done:
            missing.append(step["label"])

    required = [s for s in steps_out if s["required"]]
    done_count = sum(1 for s in required if s["completed"])

    return {
        "pr_id": pr_id,
        "release_class": effective,
        "declared_class": declared,
        "inferred_class": inferred,
        "completion": f"{done_count}/{len(required)}",
        "sop_stage": "validated" if done_count == len(required) else ("partial" if done_count else "missing"),
        "steps": steps_out,
        "missing_steps": missing,
    }


def emit_result(
    status: str,
    violations: list[str],
    stage: str,
    next_steps: list[str],
    extra: dict[str, Any],
) -> dict[str, Any]:
    out = {
        "status": status,
        "violations": violations,
        "sop_stage": stage,
        "allowed_next_step": next_steps,
        **extra,
    }
    log_audit(
        extra.get("mode", "unknown"),
        status,
        {"violations": violations, "sop_stage": stage, **{k: extra.get(k) for k in ("pr_id", "branch")}},
    )
    return out


def main() -> None:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "usage"}))
        sys.exit(2)

    cmd = sys.argv[1]

    if cmd == "pr":
        if len(sys.argv) < 3:
            print(json.dumps({"error": "pr id required"}))
            sys.exit(2)
        pr_id = sys.argv[2]
        action: str | None = None
        auto_run = False
        exec_json: dict[str, Any] | None = None
        i = 3
        while i < len(sys.argv):
            if sys.argv[i] == "--action" and i + 1 < len(sys.argv):
                action = sys.argv[i + 1]
                i += 2
            elif sys.argv[i] == "--auto-run":
                auto_run = True
                i += 1
            elif sys.argv[i] == "--exec-json" and i + 1 < len(sys.argv):
                exec_json = json.loads(sys.argv[i + 1])
                i += 2
            else:
                i += 1
        env_exec = os.environ.get("SOP_EXEC_JSON")
        if env_exec and not exec_json:
            try:
                exec_json = json.loads(env_exec)
            except json.JSONDecodeError:
                pass
        if action:
            result = evaluate_action(pr_id, action, exec_json=exec_json)
        else:
            result = evaluate_pr(
                pr_id, action=action, exec_json=exec_json, auto_run_missing=auto_run
            )
        print(json.dumps(result, indent=2, ensure_ascii=False))
        sys.exit(0 if result["status"] == "pass" else 1)

    if cmd == "commit":
        branch = sys.argv[2] if len(sys.argv) > 2 else ""
        paths = sys.argv[3].split("\n") if len(sys.argv) > 3 else []
        rel = os.environ.get("RELEASE_CLASS")
        result = evaluate_commit(branch, paths, rel)
        print(json.dumps(result, indent=2, ensure_ascii=False))
        sys.exit(0 if result["status"] == "pass" else 1)

    if cmd == "ai":
        text = sys.stdin.read()
        result = evaluate_ai(text)
        print(json.dumps(result, indent=2, ensure_ascii=False))
        sys.exit(0 if result["status"] == "pass" else 1)

    if cmd == "checklist":
        pr_id = sys.argv[2] if len(sys.argv) > 2 else ""
        out = build_checklist(pr_id)
        print(json.dumps(out, indent=2, ensure_ascii=False))
        sys.exit(0)

    if cmd == "stamp-run":
        pr_id = sys.argv[2] if len(sys.argv) > 2 else ""
        step = sys.argv[3] if len(sys.argv) > 3 else "decision_engine"
        if step == "decision_engine":
            ok, data = run_decision_engine(pr_id)
            print(json.dumps({"step": step, "ok": ok, "data": data}, indent=2))
            sys.exit(0 if ok else 1)
        if step == "decision_simulate":
            ok, data = run_decision_simulate(pr_id)
            print(json.dumps({"step": step, "ok": ok, "data": data}, indent=2))
            sys.exit(0 if ok else 1)
        if step == "release_exec_validate":
            script = repo_root() / "scripts" / "release-exec.sh"
            code, out = run_cmd([str(script), "validate", "--pr", str(pr_id)])
            m = re.search(r"\{[\s\S]*\}\s*$", out)
            data = json.loads(m.group(0)) if m else {}
            if data.get("status") == "allowed":
                record_step(pr_id, "release_exec_validate", {"status": "allowed"})
            print(json.dumps({"step": step, "ok": code == 0, "data": data}, indent=2))
            sys.exit(0 if code == 0 and data.get("status") == "allowed" else 1)
        sys.exit(2)

    print(json.dumps({"error": f"unknown cmd {cmd}"}))
    sys.exit(2)


if __name__ == "__main__":
    main()
