#!/usr/bin/env python3
"""Policy fork detection — canonical pdp.json vs promotion artifacts only."""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / "scripts" / "platform"))
from pdp_signature import compute_content_hash, verify_artifact_binding  # noqa: E402

FORBIDDEN_DERIVED = (
    "services/feature-flags/pdp-runtime-snapshot.json",
    "ci-artifacts/pdp-baseline.json",
)


def load_json(path: Path) -> dict[str, Any] | None:
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return None


def load_main_pdp(repo: Path) -> dict[str, Any] | None:
    try:
        raw = subprocess.check_output(
            ["git", "show", "origin/main:ci-artifacts/pdp.json"],
            cwd=repo,
            text=True,
            stderr=subprocess.DEVNULL,
        )
        return json.loads(raw)
    except (subprocess.CalledProcessError, json.JSONDecodeError, FileNotFoundError):
        return None


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--ci", default="ci-artifacts/pdp.json")
    parser.add_argument("--promotion-dir", default="ci-artifacts/promotion")
    parser.add_argument("--repo-root", default=str(REPO))
    args = parser.parse_args()

    repo = Path(args.repo_root)
    ci_artifact = load_json(Path(args.ci))
    if not ci_artifact:
        raise SystemExit("POLICY_FORK_DETECTED: missing canonical pdp.json")

    ok, msg = verify_artifact_binding(ci_artifact)
    if not ok:
        raise SystemExit(f"POLICY_FORK_DETECTED: {msg}")

    ci_v3 = ci_artifact.get("pdp_v3") or {}
    ci_sig = ci_v3.get("_signature")
    ci_content = ci_v3.get("_content_hash")
    ci_commit = (ci_artifact.get("baseline") or {}).get("commit_sha", "")

    findings: list[str] = []
    report: dict[str, Any] = {
        "status": "PASS",
        "ci_signature": ci_sig,
        "ci_content_hash": ci_content,
        "checks": {"forbidden_derived": [], "promotion": []},
    }

    for rel in FORBIDDEN_DERIVED:
        path = repo / rel
        if path.exists():
            findings.append("POLICY_FORK_DETECTED")
            report["checks"]["forbidden_derived"].append({"path": rel, "error": "derived PDP artifact present"})

    promo_dir = Path(args.promotion_dir)
    if promo_dir.exists():
        for path in sorted(promo_dir.glob("*.json")):
            doc = load_json(path)
            if not doc:
                continue
            promo_sig = doc.get("pdp_signature")
            promo_commit = doc.get("commit", "")
            entry = {"path": str(path), "commit": promo_commit, "pdp_signature": promo_sig}
            if not promo_sig:
                findings.append("UNAUTHORIZED_PROMOTION_PATH")
                entry["error"] = "unsigned promotion artifact"
            elif promo_sig != ci_sig:
                same_commit = (
                    promo_commit == ci_commit
                    or (promo_commit and ci_commit and promo_commit[:8] == ci_commit[:8])
                )
                if same_commit:
                    findings.append("UNAUTHORIZED_PROMOTION_PATH")
                    entry["error"] = "promotion signature mismatch for same commit"
            report["checks"]["promotion"].append(entry)

    main_pdp = load_main_pdp(repo)
    if main_pdp:
        main_v3 = main_pdp.get("pdp_v3") or {}
        main_content = main_v3.get("_content_hash") or compute_content_hash(main_v3)
        report["checks"]["main_content_hash"] = main_content
        main_sig = main_v3.get("_signature")
        if main_sig and main_sig == ci_sig and main_content != ci_content:
            findings.append("POLICY_FORK_DETECTED")
            report["checks"]["signature_reuse_across_policy_bodies"] = True

    if findings:
        report["status"] = "FAIL"
        report["findings"] = sorted(set(findings))
        print(json.dumps(report, indent=2))
        raise SystemExit(findings[0])

    print(json.dumps(report, indent=2))
    raise SystemExit(0)


if __name__ == "__main__":
    main()
