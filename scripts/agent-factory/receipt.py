#!/usr/bin/env python3
"""Small, append-only task evidence model for the Phase 1 pilot."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

VERIFICATION = {"PASSED", "FAILED", "SKIPPED", "NOT_APPLICABLE"}
TERMINAL = {"VERIFIED", "FOUNDER_REQUIRED", "FAILED_FINAL"}
ESCALATIONS = {
    "RETRYABLE", "NEEDS_NEW_WORKER", "EVIDENCE_REPAIR", "FOUNDER_REQUIRED",
    "INFRASTRUCTURE_FAILURE", "FINAL_FAILURE",
}
RETRYABLE = {"RETRYABLE", "NEEDS_NEW_WORKER", "EVIDENCE_REPAIR", "INFRASTRUCTURE_FAILURE"}
MAX_ATTEMPTS = 3
BACKOFF_SECONDS = (15, 60, 180)


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def idempotency_key(task_id: str, target_sha: str) -> str:
    return hashlib.sha256(f"{task_id}:{target_sha}".encode()).hexdigest()


def safe_identifier(value: str, default="unknown") -> str:
    cleaned = re.sub(r"[^A-Za-z0-9_.:-]", "_", str(value or ""))[:128]
    return cleaned or default


def task_from_branch(branch: str) -> str:
    match = re.search(r"(?:task[-/])([^/]+)$", branch or "")
    return safe_identifier(match.group(1) if match else (branch.rsplit("/", 1)[-1] or "unknown"))


def new_receipt(task_id: str, session_id: str, target_sha: str, *, issue=None, pr=None,
                acceptance=None, attempt=1, run_id=None) -> dict:
    if not re.fullmatch(r"[0-9a-f]{7,40}", target_sha or "", re.I):
        raise ValueError("target_sha must be a git SHA")
    task_id = safe_identifier(task_id)
    session_id = safe_identifier(session_id, default="") if session_id else ""
    stamp = now()
    return {
        "schema_version": "1.0",
        "receipt_id": f"{idempotency_key(task_id, target_sha)[:16]}-{run_id or stamp}",
        "idempotency_key": idempotency_key(task_id, target_sha),
        "task_id": task_id,
        "session_id": session_id or None,
        "issue": issue,
        "pr": pr,
        "head_sha": target_sha,
        "target_sha": target_sha,
        "workflow_run_ids": [str(run_id)] if run_id else [],
        "attempt_count": attempt,
        "actor": {"type": "agent", "provider": None, "model": None},
        "acceptance_criteria": [safe_text(x) for x in (acceptance or [])],
        "required_checks": [],
        "deployment_required": False,
        "checks": [],
        "merge": {"status": "UNKNOWN", "sha": None},
        "deployment": {"status": "NOT_APPLICABLE", "sha": None},
        "runtime_verification": {"status": "NOT_APPLICABLE", "reason": "not collected"},
        "shadow_classification": None,
        "unresolved_warnings": [],
        "terminal_state": None,
        "escalation_class": None,
        "timestamps": {"created": stamp, "updated": stamp},
    }


def add_check(receipt: dict, name: str, status: str, *, reason="", acceptance_relevant=True) -> dict:
    status = status.upper()
    if status not in VERIFICATION:
        raise ValueError(f"invalid verification status: {status}")
    checks = [c for c in receipt["checks"] if c["name"] != name]
    checks.append({"name": name, "status": status, "reason": safe_text(reason),
                   "acceptance_relevant": bool(acceptance_relevant)})
    receipt["checks"] = checks
    receipt["timestamps"]["updated"] = now()
    return receipt


def classify_failure(reason: str, attempt: int) -> str:
    text = (reason or "").lower()
    if any(x in text for x in ("founder", "billing", "identity", "permission", "migration", "production data")):
        return "FOUNDER_REQUIRED"
    if any(x in text for x in ("manifest", "provenance", "declaration", "receipt")):
        return "EVIDENCE_REPAIR" if attempt < MAX_ATTEMPTS else "FINAL_FAILURE"
    if any(x in text for x in ("timeout", "network", "runner", "rate limit", "ci", "convergence")):
        return "INFRASTRUCTURE_FAILURE" if attempt < MAX_ATTEMPTS else "FINAL_FAILURE"
    return "FINAL_FAILURE"


def retry_decision(receipt: dict, escalation: str, *, reason="") -> dict:
    escalation = escalation.upper()
    if escalation not in ESCALATIONS:
        raise ValueError(f"invalid escalation class: {escalation}")
    attempt = int(receipt.get("attempt_count") or 1)
    retry = escalation in RETRYABLE and attempt < MAX_ATTEMPTS
    return {
        "idempotency_key": receipt["idempotency_key"],
        "action": "RETRY" if retry else "STOP",
        "escalation_class": escalation if retry else ("FINAL_FAILURE" if escalation in RETRYABLE else escalation),
        "attempt": attempt + 1 if retry else attempt,
        "backoff_seconds": BACKOFF_SECONDS[min(attempt - 1, len(BACKOFF_SECONDS) - 1)] if retry else 0,
        "reason": safe_text(reason),
    }


def terminal_state(receipt: dict, *, escalation=None) -> str | None:
    if escalation:
        escalation = escalation.upper()
        if escalation == "FOUNDER_REQUIRED":
            return "FOUNDER_REQUIRED"
        if escalation == "FINAL_FAILURE":
            return "FAILED_FINAL"
    relevant = [c for c in receipt.get("checks", []) if c.get("acceptance_relevant")]
    if not receipt.get("acceptance_criteria"):
        return "FOUNDER_REQUIRED"
    required = set(receipt.get("required_checks") or [])
    observed = {c.get("name") for c in receipt.get("checks", [])}
    if required - observed:
        return None
    if any(c["status"] == "FAILED" for c in relevant):
        return "FAILED_FINAL"
    if any(c["status"] == "SKIPPED" for c in relevant):
        return "FOUNDER_REQUIRED"
    if receipt.get("deployment_required"):
        deployment_status = receipt.get("deployment", {}).get("status")
        runtime_status = receipt.get("runtime_verification", {}).get("status")
        if deployment_status == "FAILED" or runtime_status == "FAILED":
            return "FAILED_FINAL"
        if deployment_status != "PASSED" or runtime_status != "PASSED":
            return None
    if not relevant or any(c["status"] not in {"PASSED", "NOT_APPLICABLE"} for c in relevant):
        return None
    return "VERIFIED"


def set_terminal(receipt: dict, state: str, *, escalation=None) -> dict:
    if state not in TERMINAL:
        raise ValueError(f"invalid terminal state: {state}")
    current = receipt.get("terminal_state")
    if current and current != state:
        raise ValueError(f"terminal state is immutable: {current}")
    receipt["terminal_state"] = state
    if escalation: receipt["escalation_class"] = escalation
    receipt["timestamps"]["updated"] = now()
    return receipt


def append_event(path: str | Path, event: dict) -> None:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    safe_event = {key: safe_text(value) if isinstance(value, str) else value
                  for key, value in event.items()}
    event_id = hashlib.sha256(json.dumps(safe_event, sort_keys=True).encode()).hexdigest()[:16]
    if path.exists():
        for line in path.read_text(encoding="utf-8").splitlines():
            try:
                if json.loads(line).get("event_id") == event_id: return
            except ValueError: continue
    safe = {"schema_version": "1.0", "event_id": event_id, **safe_event}
    with path.open("a", encoding="utf-8") as stream:
        stream.write(json.dumps(safe, sort_keys=True, separators=(",", ":")) + "\n")


def write_once(path: str | Path, value: dict) -> None:
    path = Path(path)
    if path.exists():
        raise FileExistsError(f"refusing to overwrite historical receipt: {path}")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def shadow_effects(paths: list[str], patch: str = "", existing="UNKNOWN") -> dict:
    text = " ".join(paths + [patch]).lower()
    effects = []
    rules = {
        "PRODUCTION_DATA_WRITE": r"migrat|repair|backfill|insert|update|delete|save|upsert",
        "EXTERNAL_COMMUNICATION": r"mail|email|sms|line|webhook|notification",
        "PRIVILEGE_CHANGE": r"auth|permission|role|credential|secret|token",
        "FINANCIAL_EFFECT": r"billing|payment|invoice|ledger|refund|charge",
        "SCHEMA_CHANGE": r"migration|schema|alter table|drop table",
        "PRODUCTION_ACTIVATION": r"deploy|production|activation|release",
    }
    for effect, pattern in rules.items():
        if re.search(pattern, text):
            effects.append(effect)
    shadow = "T3" if any(e in effects for e in ("PRIVILEGE_CHANGE", "FINANCIAL_EFFECT", "SCHEMA_CHANGE")) else ("T2" if effects else "T1")
    return {"existing_classification": existing, "shadow_classification": shadow,
            "detected_effects": sorted(effects), "actual_outcome": "UNKNOWN",
            "authoritative": False}


def safe_text(value: str) -> str:
    value = re.sub(r"[\w.+-]+@[\w.-]+", "[redacted-email]", str(value or ""))
    value = re.sub(r"(?i)(password|secret|token|api[_-]?key)\s*[:=]\s*\S+", r"\1=[redacted]", value)
    return value[:500]


def api(repo: str, path: str) -> object:
    return json.loads(subprocess.check_output(["gh", "api", f"repos/{repo}/{path}"], text=True))


def collect(event_path: str, repo: str, run_id: str, output: str, events: str | None = None) -> dict:
    event = json.loads(Path(event_path).read_text(encoding="utf-8"))
    pr_data = event.get("pull_request") or {}
    workflow = event.get("workflow_run") or {}
    payload = event.get("client_payload") or {}
    pr_number = (pr_data.get("number") or ((workflow.get("pull_requests") or [{}])[0].get("number")))
    target_sha = pr_data.get("head", {}).get("sha") or workflow.get("head_sha") or ""
    if not pr_number and target_sha:
        prs = api(repo, f"commits/{target_sha}/pulls")
        pr_number = prs[0].get("number") if prs else None
    if pr_number:
        pr_data = api(repo, f"pulls/{pr_number}")
        target_sha = pr_data.get("head", {}).get("sha") or target_sha
    branch = pr_data.get("head", {}).get("ref") or workflow.get("head_branch") or "unknown"
    task_id = payload.get("task_id") or task_from_branch(branch)
    session_id = ""
    provider = None
    model = None
    if pr_number and target_sha:
        try:
            manifest = api(repo, f"contents/.agent-session/manifest.json?ref={target_sha}")
            manifest_data = json.loads(__import__("base64").b64decode(manifest["content"]).decode())
            session_id = manifest_data.get("session_id", "")
            provider = "codex" if manifest_data.get("agent_cli") else None
        except Exception:
            session_id = ""
    body = pr_data.get("body") or ""
    acceptance = [safe_text(x.strip()[5:]) for x in re.findall(r"(?ms)^## (?:Acceptance Criteria|Test Plan|Verification)\s*(.*?)(?=^## |\Z)", body) for x in re.findall(r"^[-*] \[[ xX]\] (.+)$", x)]
    issue_refs = [int(value) for value in re.findall(r"(?<![\w/])#(\d+)\b", body)]
    issue = next((value for value in issue_refs if value != pr_number), None)
    receipt = new_receipt(task_id, session_id, target_sha, issue=issue, pr=pr_number,
                          acceptance=acceptance, attempt=int(workflow.get("run_attempt") or 1),
                          run_id=run_id)
    receipt["actor"] = {"type": "workflow" if workflow else "agent",
                        "provider": provider, "model": model}
    receipt["head_sha"] = target_sha
    files = api(repo, f"pulls/{pr_number}/files?per_page=100") if pr_number else []
    paths = [x.get("filename", "") for x in files]
    receipt["required_checks"] = ["Presubmit Checks", "PHPUnit Feature & Unit Tests", "Vite Frontend Build"]
    if any(p.startswith(("frontend/src/", "frontend/e2e/")) for p in paths):
        receipt["required_checks"].append("UI Smoke (Playwright)")
    receipt["deployment_required"] = any(p.startswith(("backend/", "frontend/src/")) for p in paths)
    existing = "UNKNOWN"
    match = re.search(r"(?:Risk-Class|Autonomy-Tier)\s*:\s*([RT][0-3])", body, re.I)
    if match: existing = match.group(1).upper()
    receipt["shadow_classification"] = shadow_effects(paths, existing=existing)
    runs = api(repo, f"commits/{target_sha}/check-runs?per_page=100").get("check_runs", []) if target_sha else []
    for check_run in runs:
        conclusion = check_run.get("conclusion")
        status = {"success": "PASSED", "failure": "FAILED", "cancelled": "FAILED", "skipped": "SKIPPED", "neutral": "NOT_APPLICABLE"}.get(conclusion)
        if not status: continue
        name = check_run.get("name", "unknown")
        relevant = name in {"Presubmit Checks", "PHPUnit Feature & Unit Tests", "Vite Frontend Build"}
        if name == "UI Smoke (Playwright)": relevant = any(p.startswith(("frontend/src/", "frontend/e2e/")) for p in paths)
        add_check(receipt, name, status, reason=safe_text(check_run.get("output", {}).get("title", "")), acceptance_relevant=relevant)
    if workflow:
        conclusion = workflow.get("conclusion")
        status = {"success": "PASSED", "failure": "FAILED", "cancelled": "FAILED", "skipped": "SKIPPED", "neutral": "NOT_APPLICABLE"}.get(conclusion)
        if status: add_check(receipt, workflow.get("name", "workflow"), status, reason="workflow_run", acceptance_relevant=False)
        if workflow.get("name") == "UI Smoke (Playwright)":
            with tempfile.TemporaryDirectory(prefix="ui-smoke-receipt-") as directory:
                artifact = f"ui-smoke-verification-{workflow.get('id')}-{workflow.get('run_attempt') or 1}"
                result = subprocess.run(["gh", "run", "download", str(workflow.get("id")), "--repo", repo, "--name", artifact, "--dir", directory], capture_output=True, text=True)
                if result.returncode == 0:
                    for path in Path(directory).rglob("*.json"):
                        try:
                            outcome = json.loads(path.read_text(encoding="utf-8"))["checks"][0]
                            add_check(receipt, "UI Smoke (Playwright)", outcome["status"], reason=outcome.get("reason", ""), acceptance_relevant=bool(outcome.get("acceptance_relevant")))
                        except (KeyError, TypeError, ValueError):
                            receipt["unresolved_warnings"].append("ui_smoke_artifact_invalid")
                else:
                    receipt["unresolved_warnings"].append("ui_smoke_artifact_missing")
    merge_sha = pr_data.get("merge_commit_sha") or ""
    receipt["merge"] = {"status": "PASSED" if pr_data.get("merged_at") else "NOT_APPLICABLE", "sha": merge_sha or None}
    deploy_sha = merge_sha if pr_data.get("merged_at") else target_sha
    if deploy_sha:
        deploy_data = api(repo, f"actions/workflows/deploy.yml/runs?branch=main&head_sha={deploy_sha}&per_page=20")
        deploy_runs = deploy_data.get("workflow_runs", []) if isinstance(deploy_data, dict) else []
        matching = next((r for r in deploy_runs if r.get("head_sha") == deploy_sha), None)
        if matching:
            receipt["workflow_run_ids"].append(str(matching.get("id")))
            receipt["deployment"] = {"status": "PASSED" if matching.get("conclusion") == "success" else "FAILED", "sha": deploy_sha, "workflow_run_id": matching.get("id")}
            if matching.get("conclusion") == "success":
                try:
                    with urllib.request.urlopen("https://daan.lifenet.com.tw/deployment.json", timeout=10) as response:
                        runtime = json.loads(response.read().decode())
                    runtime_sha = runtime.get("backend_sha") or runtime.get("frontend_sha")
                    receipt["runtime_verification"] = {"status": "PASSED" if runtime_sha == deploy_sha else "FAILED", "sha": runtime_sha, "reason": "public deployment manifest"}
                except Exception as error:
                    receipt["runtime_verification"] = {"status": "FAILED", "reason": safe_text(str(error))}
        elif any(p.startswith(("backend/", "frontend/", "scripts/")) for p in paths):
            receipt["unresolved_warnings"].append("runtime_deployment_evidence_missing")
    if pr_data.get("merged_at") and receipt["deployment_required"] and receipt["deployment"]["status"] == "NOT_APPLICABLE":
        receipt["deployment"] = {"status": "UNKNOWN", "sha": merge_sha or None}
        receipt["runtime_verification"] = {"status": "UNKNOWN", "reason": "deployment evidence not yet collected"}
    if pr_data.get("state") == "closed" and not pr_data.get("merged_at"):
        set_terminal(receipt, "FAILED_FINAL", escalation="FINAL_FAILURE")
    else:
        state = terminal_state(receipt)
        if (state is None and workflow.get("name") in {"UI Smoke (Playwright)", "Deploy to Pi"}
                and workflow.get("conclusion") in {"success", "failure", "cancelled"}):
            state = "FOUNDER_REQUIRED"
            receipt["unresolved_warnings"].append("acceptance_evidence_incomplete")
        if state: set_terminal(receipt, state, escalation="FOUNDER_REQUIRED" if state == "FOUNDER_REQUIRED" else None)
    if receipt["shadow_classification"] is not None:
        receipt["shadow_classification"]["actual_outcome"] = receipt.get("terminal_state") or "UNKNOWN"
    if events:
        base_event = {"task_id": task_id, "session_id": session_id or None,
                      "target_sha": target_sha, "actor_type": receipt["actor"]["type"],
                      "provider": provider, "model": model, "workflow_run_id": run_id,
                      "skipped_verifications": [
                          {"name": check["name"], "reason": check.get("reason", "")}
                          for check in receipt["checks"] if check.get("status") == "SKIPPED"
                      ],
                      "founder_intervention_reason": (
                          "acceptance verification requires Founder review"
                          if receipt.get("terminal_state") == "FOUNDER_REQUIRED" else None
                      )}
        lifecycle = ["receipt_collected"]
        action = event.get("action")
        if pr_data:
            if action == "opened": lifecycle.extend(("accepted", "started", "pr_opened"))
            if action == "synchronize": lifecycle.append("first_commit")
            if action == "closed": lifecycle.append("closed")
            if pr_data.get("merged_at"): lifecycle.append("merged")
        if workflow and workflow.get("conclusion") == "success":
            lifecycle.append("first_green")
        if receipt["deployment"]["status"] == "PASSED": lifecycle.append("deployed")
        if receipt["runtime_verification"]["status"] == "PASSED": lifecycle.append("runtime_verified")
        if receipt.get("terminal_state") == "FAILED_FINAL": lifecycle.append("failed_final")
        for event_type in dict.fromkeys(lifecycle):
            append_event(events, {"event_type": event_type, **base_event})
    write_once(output, receipt)
    return receipt


def cli() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    init = sub.add_parser("init"); init.add_argument("--task-id", required=True); init.add_argument("--session-id", default=""); init.add_argument("--sha", required=True); init.add_argument("--output", required=True); init.add_argument("--run-id"); init.add_argument("--acceptance", action="append", default=[])
    check = sub.add_parser("check"); check.add_argument("--output", required=True); check.add_argument("--name", required=True); check.add_argument("--status", required=True); check.add_argument("--reason", default=""); check.add_argument("--acceptance-relevant", action="store_true")
    event = sub.add_parser("event"); event.add_argument("--output", required=True); event.add_argument("--task-id", required=True); event.add_argument("--session-id", default=""); event.add_argument("--sha", required=True); event.add_argument("--type", required=True); event.add_argument("--actor", default="agent"); event.add_argument("--provider"); event.add_argument("--model"); event.add_argument("--reason", default=""); event.add_argument("--duration-minutes", type=float); event.add_argument("--run-id")
    shadow = sub.add_parser("shadow"); shadow.add_argument("--paths", nargs="*", default=[]); shadow.add_argument("--patch", default=""); shadow.add_argument("--existing", default="UNKNOWN")
    collect_cmd = sub.add_parser("collect"); collect_cmd.add_argument("--event", required=True); collect_cmd.add_argument("--repo", required=True); collect_cmd.add_argument("--run-id", required=True); collect_cmd.add_argument("--output", required=True); collect_cmd.add_argument("--events")
    args = parser.parse_args()
    if args.command == "init":
        write_once(args.output, new_receipt(args.task_id, args.session_id, args.sha, acceptance=args.acceptance, run_id=args.run_id)); return 0
    if args.command == "check":
        data = json.loads(Path(args.output).read_text(encoding="utf-8")); add_check(data, args.name, args.status, reason=args.reason, acceptance_relevant=args.acceptance_relevant); Path(args.output).write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8"); return 0
    if args.command == "event":
        append_event(args.output, {"event_type": args.type, "task_id": args.task_id, "session_id": args.session_id or None, "target_sha": args.sha, "actor_type": args.actor, "provider": args.provider, "model": args.model, "reason": args.reason, "founder_attention_minutes": args.duration_minutes, "workflow_run_id": args.run_id}); return 0
    if args.command == "collect":
        collect(args.event, args.repo, args.run_id, args.output, args.events); return 0
    print(json.dumps(shadow_effects(args.paths, args.patch, args.existing), sort_keys=True)); return 0


if __name__ == "__main__":
    sys.exit(cli())
