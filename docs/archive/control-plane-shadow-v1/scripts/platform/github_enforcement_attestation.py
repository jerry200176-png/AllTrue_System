#!/usr/bin/env python3
"""GitHub enforcement attestation — live API truth vs declarative config."""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG = REPO / "config/github/platform-enforcement.json"
DEFAULT_OUT = REPO / "ci-artifacts/enforcement/enforcement_state.json"

CRITICAL_CHECKS = (
    "platform gate",
    "control plane verify",
)


def canonical_json(obj: Any) -> str:
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def gh_json(path: str) -> dict[str, Any]:
    try:
        out = subprocess.check_output(["gh", "api", path], text=True)
        return json.loads(out) if out.strip() else {}
    except subprocess.CalledProcessError:
        return {}


def load_config(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def resolve_repo() -> str:
    out = subprocess.check_output(["gh", "repo", "view", "--json", "nameWithOwner", "-q", ".nameWithOwner"], text=True)
    return out.strip()


def fetch_live_state(repo: str, branch: str) -> dict[str, Any]:
    protection = gh_json(f"/repos/{repo}/branches/{branch}/protection")
    reviews = gh_json(f"/repos/{repo}/branches/{branch}/protection/required_pull_request_reviews")
    environments = gh_json(f"/repos/{repo}/environments")
    rulesets = gh_json(f"/repos/{repo}/rulesets")
    return {
        "branch_protection": protection,
        "required_pull_request_reviews": reviews,
        "environments": environments,
        "rulesets": rulesets,
    }


def normalize_check_names(protection: dict[str, Any]) -> list[str]:
    checks = (protection.get("required_status_checks") or {}).get("checks") or []
    names = [c.get("context", c) if isinstance(c, dict) else str(c) for c in checks]
    if not names:
        names = list((protection.get("required_status_checks") or {}).get("contexts") or [])
    return [str(n).lower() for n in names]


def compare_state(config: dict[str, Any], live: dict[str, Any], repo: str, branch: str) -> dict[str, Any]:
    expected = config.get("branch_protection") or {}
    protection = live.get("branch_protection") or {}
    reviews = live.get("required_pull_request_reviews") or {}
    mismatches: list[str] = []

    if not protection:
        mismatches.append("branch protection not configured on live GitHub")

    live_checks = normalize_check_names(protection)
    expected_checks = [str(c).lower() for c in (expected.get("required_status_checks") or {}).get("contexts") or []]

    for critical in CRITICAL_CHECKS:
        if not any(critical in name for name in live_checks):
            mismatches.append(f"live GitHub missing required ACTIVE check: {critical}")

    for expected_check in expected_checks:
        if not any(expected_check.lower() in name for name in live_checks):
            mismatches.append(f"config requires check not ACTIVE on GitHub: {expected_check}")

    strict = (expected.get("required_status_checks") or {}).get("strict")
    live_strict = (protection.get("required_status_checks") or {}).get("strict")
    if strict is not None and live_strict is not strict:
        mismatches.append(f"required_status_checks.strict expected {strict}, live {live_strict}")

    if (protection.get("allow_force_pushes") or {}).get("enabled", True):
        mismatches.append("direct push bypass: force push allowed on live GitHub")

    review_count = reviews.get("required_approving_review_count", 0)
    expected_reviews = (expected.get("required_pull_request_reviews") or {}).get("required_approving_review_count", 1)
    if review_count < expected_reviews:
        mismatches.append(f"PR reviews live {review_count} < expected {expected_reviews}")

    if expected.get("required_linear_history") and not (protection.get("required_linear_history") or {}).get("enabled"):
        mismatches.append("linear history required by config but not enabled live")

    if expected.get("enforce_admins") and not (protection.get("enforce_admins") or {}).get("enabled"):
        mismatches.append("enforce_admins required by config but not enabled live")

    env_names = {e.get("name") for e in (live.get("environments") or {}).get("environments") or []}
    for env_name in (config.get("environments") or {}).keys():
        if env_name not in env_names:
            mismatches.append(f"GitHub environment missing live: {env_name}")

    active_rulesets = [
        r for r in (live.get("rulesets") or [])
        if str(r.get("enforcement", "")).lower() == "active"
    ]
    disabled_rulesets = [
        r.get("name") for r in (live.get("rulesets") or [])
        if str(r.get("enforcement", "")).lower() == "disabled"
    ]
    if disabled_rulesets:
        mismatches.append(f"ruleset(s) exist but not ACTIVE: {', '.join(disabled_rulesets)}")

    checks = {
        "platform_gate_required_and_active": not any("platform gate" in m for m in mismatches),
        "control_plane_verify_required_and_active": not any("control plane verify" in m for m in mismatches),
        "direct_push_blocked": not any("force push" in m for m in mismatches),
        "pr_reviews_enforced": review_count >= expected_reviews,
        "config_matches_live": not mismatches,
    }

    document: dict[str, Any] = {
        "attested_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "repository": repo,
        "branch": branch,
        "config_schema_version": config.get("schema_version"),
        "config_path": "config/github/platform-enforcement.json",
        "expected_state": expected,
        "live_state": live,
        "checks": checks,
        "status": "PASS" if not mismatches else "FAIL",
        "mismatches": mismatches,
        "active_ruleset_count": len(active_rulesets),
    }
    payload = dict(document)
    document["enforcement_attestation_hash"] = hashlib.sha256(
        canonical_json(payload).encode("utf-8")
    ).hexdigest()
    return document


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default=str(DEFAULT_CONFIG))
    parser.add_argument("--branch", default="main")
    parser.add_argument("--out", default=str(DEFAULT_OUT))
    parser.add_argument("--repo", default="")
    parser.add_argument("--write", action="store_true")
    args = parser.parse_args()

    if not Path(args.config).exists():
        print(json.dumps({"status": "FAIL", "reason": f"missing config {args.config}"}))
        sys.exit(1)

    if not shutil.which("gh"):
        print(json.dumps({"status": "FAIL", "reason": "gh CLI not available"}))
        sys.exit(1)

    repo = args.repo or resolve_repo()
    config = load_config(Path(args.config))
    branch = args.branch or config.get("branch_protection", {}).get("branch", "main")
    live = fetch_live_state(repo, branch)
    document = compare_state(config, live, repo, branch)

    out_path = Path(args.out)
    if args.write:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(document, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(json.dumps(document, indent=2, ensure_ascii=False))
    sys.exit(0 if document.get("status") == "PASS" else 1)


if __name__ == "__main__":
    main()
