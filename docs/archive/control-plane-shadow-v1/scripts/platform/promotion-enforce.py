#!/usr/bin/env python3
"""Promotion enforcement — read-only execution from ci-artifacts/pdp.json."""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]

sys.path.insert(0, str(REPO / "scripts" / "platform"))
from pdp_signature import verify_artifact_binding  # noqa: E402
from promotion_integrity import (  # noqa: E402
    build_integrity,
    compute_staging_body_hash,
    integrity_from_pdp_artifact,
)
from pdp_signing_authority import sign_artifact  # noqa: E402


def find_staging_artifact(staging_dir: Path, commit: str) -> Path | None:
    for path in sorted(staging_dir.glob("staging-*.json"), reverse=True):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, OSError):
            continue
        art_commit = data.get("commit", "")
        if art_commit == commit or art_commit.startswith(commit[:8]) or commit.startswith(art_commit[:8]):
            return path
    return None


def staging_is_fresh(staging_path: Path, max_age_minutes: float, commit: str) -> tuple[bool, str]:
    if not staging_path.exists():
        return False, "staging artifact missing"
    data = json.loads(staging_path.read_text(encoding="utf-8"))
    art_commit = data.get("commit", "")
    if not (art_commit == commit or art_commit.startswith(commit[:8]) or commit.startswith(art_commit[:8])):
        return False, "staging artifact commit mismatch"
    recorded = data.get("recorded_at", "")
    if not recorded:
        return False, "staging artifact missing recorded_at"
    ts = datetime.fromisoformat(recorded.replace("Z", "+00:00"))
    age_min = (datetime.now(timezone.utc) - ts).total_seconds() / 60.0
    if age_min > max_age_minutes:
        return False, f"staging artifact age {age_min:.0f}m > {max_age_minutes}m"
    promo_sig = data.get("pdp_v3_bound_signature") or data.get("pdp_signature")
    if not isinstance(promo_sig, str) or not promo_sig:
        return False, "staging artifact missing pdp_v3_bound_signature"
    return True, "ok"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--target", choices=("staging", "production"), required=True)
    parser.add_argument("--pdp", default="ci-artifacts/pdp.json")
    parser.add_argument("--commit", required=True)
    parser.add_argument("--staging-dir", default="ci-artifacts/promotion")
    parser.add_argument("--out-dir", default="ci-artifacts/promotion")
    args = parser.parse_args()

    pdp_path = Path(args.pdp)
    artifact = json.loads(pdp_path.read_text(encoding="utf-8"))
    v3 = artifact.get("pdp_v3") or {}

    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        print(json.dumps({"allowed": False, "reason": f"invalid_signature: {msg}"}))
        sys.exit(1)

    if not v3.get("_signature"):
        print(json.dumps({"allowed": False, "reason": "missing pdp_v3._signature"}))
        sys.exit(1)

    if args.target == "staging":
        if not v3.get("allow_staging"):
            print(json.dumps({"allowed": False, "reason": "pdp_v3.allow_staging=false"}))
            sys.exit(1)
        base_integrity = integrity_from_pdp_artifact(artifact)
        doc = {
            "target": "staging",
            "commit": args.commit,
            "pr": artifact.get("pr"),
            "pdp_v3_bound_signature": v3.get("_signature"),
            "recorded_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            "promotion_integrity": dict(base_integrity),
        }
        doc["staging_deployment_hash"] = compute_staging_body_hash(doc)
        doc["promotion_integrity"] = build_integrity(
            commit_sha=base_integrity["commit_sha"],
            ci_test_hash=base_integrity["ci_test_hash"],
            pdp_decision_hash=base_integrity["pdp_decision_hash"],
            staging_deployment_hash=doc["staging_deployment_hash"],
        )
        doc["artifact_hash"] = doc["staging_deployment_hash"]
        out = Path(args.out_dir) / f"staging-{args.commit}.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")

        pdp_integrity = dict(base_integrity)
        pdp_integrity["staging_deployment_hash"] = doc["staging_deployment_hash"]
        pdp_integrity = build_integrity(
            commit_sha=pdp_integrity["commit_sha"],
            ci_test_hash=pdp_integrity["ci_test_hash"],
            pdp_decision_hash=pdp_integrity["pdp_decision_hash"],
            staging_deployment_hash=doc["staging_deployment_hash"],
        )
        artifact["promotion_integrity"] = pdp_integrity
        artifact = sign_artifact(artifact)
        pdp_path.write_text(json.dumps(artifact, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

        print(json.dumps(doc, indent=2))
        sys.exit(0)

    if not v3.get("allow_production"):
        print(json.dumps({"allowed": False, "reason": "pdp_v3.allow_production=false"}))
        sys.exit(1)

    promo = v3.get("promotion") or {}
    max_age = promo.get("artifact_max_age_minutes")
    if max_age is None:
        print(json.dumps({"allowed": False, "reason": "missing pdp_v3.promotion.artifact_max_age_minutes"}))
        sys.exit(1)

    staging_path = find_staging_artifact(Path(args.staging_dir), args.commit)
    if not staging_path:
        print(json.dumps({"allowed": False, "reason": "no_staging_artifact"}))
        sys.exit(1)

    fresh, reason = staging_is_fresh(staging_path, float(max_age), args.commit)
    if not fresh:
        print(json.dumps({"allowed": False, "reason": reason}))
        sys.exit(1)

    staging = json.loads(staging_path.read_text(encoding="utf-8"))
    staging_sig = staging.get("pdp_v3_bound_signature") or staging.get("pdp_signature")
    if not isinstance(staging_sig, str) or staging_sig != v3.get("_signature"):
        print(json.dumps({"allowed": False, "reason": "staging pdp_v3_bound_signature mismatch"}))
        sys.exit(1)

    staging_hash = staging.get("staging_deployment_hash") or (staging.get("promotion_integrity") or {}).get(
        "staging_deployment_hash"
    )
    pdp_integrity = artifact.get("promotion_integrity") or integrity_from_pdp_artifact(artifact)
    if staging_hash and pdp_integrity.get("staging_deployment_hash") != staging_hash:
        print(json.dumps({"allowed": False, "reason": "pdp promotion_integrity staging hash mismatch"}))
        sys.exit(1)

    doc = {
        "target": "production",
        "commit": args.commit,
        "pr": artifact.get("pr"),
        "pdp_v3_bound_signature": v3.get("_signature"),
        "staging_deployment_hash": staging_hash,
        "promotion_integrity": pdp_integrity,
        "checked_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }
    out = Path(args.out_dir) / f"production-{args.commit}.json"
    out.write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(doc, indent=2))
    sys.exit(0)


if __name__ == "__main__":
    main()
