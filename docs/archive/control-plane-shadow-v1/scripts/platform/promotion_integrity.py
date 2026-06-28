#!/usr/bin/env python3
"""Promotion integrity — hash chain for CI → staging → production artifact binding."""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / "scripts" / "platform"))
from pdp_signature import compute_content_hash, verify_artifact_binding  # noqa: E402

INTEGRITY_KEYS = (
    "commit_sha",
    "ci_test_hash",
    "pdp_decision_hash",
    "staging_deployment_hash",
)


def canonical_json(obj: Any) -> str:
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def compute_ci_test_hash(sources: dict[str, Any]) -> str:
    return hashlib.sha256(canonical_json(sources).encode("utf-8")).hexdigest()


def compute_staging_body_hash(staging_doc: dict[str, Any]) -> str:
    body = {
        k: v
        for k, v in staging_doc.items()
        if k not in ("artifact_hash", "integrity_signature", "staging_deployment_hash")
    }
    return hashlib.sha256(canonical_json(body).encode("utf-8")).hexdigest()


def build_integrity(
    *,
    commit_sha: str,
    ci_test_hash: str,
    pdp_decision_hash: str,
    staging_deployment_hash: str | None = None,
) -> dict[str, Any]:
    chain_material = ":".join(
        [
            commit_sha,
            ci_test_hash,
            pdp_decision_hash,
            staging_deployment_hash or "",
        ]
    )
    return {
        "commit_sha": commit_sha,
        "ci_test_hash": ci_test_hash,
        "pdp_decision_hash": pdp_decision_hash,
        "staging_deployment_hash": staging_deployment_hash,
        "integrity_signature": hashlib.sha256(chain_material.encode("utf-8")).hexdigest(),
    }


def integrity_from_pdp_artifact(artifact: dict[str, Any]) -> dict[str, Any]:
    baseline = artifact.get("baseline") or {}
    v3 = artifact.get("pdp_v3") or {}
    commit = baseline.get("commit_sha") or (v3.get("_binding") or {}).get("commit_sha") or ""
    sources = artifact.get("sources") or {}
    decision_hash = v3.get("_content_hash") or compute_content_hash(v3)
    existing = artifact.get("promotion_integrity") or {}
    staging_hash = existing.get("staging_deployment_hash")
    return build_integrity(
        commit_sha=commit,
        ci_test_hash=compute_ci_test_hash(sources),
        pdp_decision_hash=decision_hash,
        staging_deployment_hash=staging_hash,
    )


def attach_integrity_to_artifact(artifact: dict[str, Any]) -> dict[str, Any]:
    updated = dict(artifact)
    updated["promotion_integrity"] = integrity_from_pdp_artifact(artifact)
    return updated


def verify_integrity_record(record: dict[str, Any]) -> tuple[bool, str]:
    missing = [k for k in INTEGRITY_KEYS if k not in record]
    if missing:
        return False, f"promotion_integrity missing keys: {', '.join(missing)}"
    expected = build_integrity(
        commit_sha=str(record["commit_sha"]),
        ci_test_hash=str(record["ci_test_hash"]),
        pdp_decision_hash=str(record["pdp_decision_hash"]),
        staging_deployment_hash=record.get("staging_deployment_hash"),
    )
    if record.get("integrity_signature") != expected["integrity_signature"]:
        return False, "integrity_signature mismatch"
    return True, "ok"


def verify_pdp_artifact(artifact: dict[str, Any], *, require_staging_hash: bool = False) -> tuple[bool, str]:
    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        return False, f"pdp binding: {msg}"

    integrity = artifact.get("promotion_integrity")
    if not isinstance(integrity, dict):
        return False, "missing promotion_integrity block"

    ok, msg = verify_integrity_record(integrity)
    if not ok:
        return False, msg

    baseline = artifact.get("baseline") or {}
    if baseline.get("commit_sha") and integrity.get("commit_sha") != baseline.get("commit_sha"):
        return False, "promotion_integrity.commit_sha != baseline.commit_sha"

    v3 = artifact.get("pdp_v3") or {}
    if v3.get("_content_hash") and integrity.get("pdp_decision_hash") != v3.get("_content_hash"):
        return False, "promotion_integrity.pdp_decision_hash != pdp_v3._content_hash"

    sources = artifact.get("sources") or {}
    expected_ci = compute_ci_test_hash(sources)
    if integrity.get("ci_test_hash") != expected_ci:
        return False, "promotion_integrity.ci_test_hash != computed ci_test_hash"

    if require_staging_hash and not integrity.get("staging_deployment_hash"):
        return False, "staging_deployment_hash required for production promotion"

    return True, "ok"


def verify_staging_against_pdp(pdp: dict[str, Any], staging: dict[str, Any]) -> tuple[bool, str]:
    ok, msg = verify_pdp_artifact(pdp, require_staging_hash=False)
    if not ok:
        return False, msg

    pdp_integrity = pdp.get("promotion_integrity") or {}
    staging_integrity = staging.get("promotion_integrity") or {}

    for key in ("commit_sha", "ci_test_hash", "pdp_decision_hash"):
        if staging_integrity.get(key) != pdp_integrity.get(key):
            return False, f"staging promotion_integrity.{key} mismatch"

    staging_hash = staging.get("staging_deployment_hash") or staging_integrity.get("staging_deployment_hash")
    if not staging_hash:
        return False, "staging artifact missing staging_deployment_hash"

    if staging_integrity.get("staging_deployment_hash") != staging_hash:
        return False, "staging promotion_integrity.staging_deployment_hash mismatch"

    if pdp_integrity.get("staging_deployment_hash") and pdp_integrity.get("staging_deployment_hash") != staging_hash:
        return False, "pdp promotion_integrity.staging_deployment_hash mismatch"

    expected_body_hash = compute_staging_body_hash(staging)
    if staging.get("artifact_hash") and staging.get("artifact_hash") != expected_body_hash:
        return False, "staging artifact_hash mismatch"

    if staging.get("pdp_signature") != (pdp.get("pdp_v3") or {}).get("_signature"):
        return False, "staging pdp_signature != pdp_v3._signature"

    return True, "ok"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pdp", default="ci-artifacts/pdp.json")
    parser.add_argument("--staging-dir", default="ci-artifacts/promotion")
    parser.add_argument("--commit", default="")
    parser.add_argument("--require-staging", action="store_true")
    parser.add_argument("--attach", action="store_true", help="Print artifact with promotion_integrity attached")
    args = parser.parse_args()

    pdp_path = Path(args.pdp)
    artifact = json.loads(pdp_path.read_text(encoding="utf-8"))

    if args.attach:
        print(json.dumps(attach_integrity_to_artifact(artifact), indent=2, ensure_ascii=False))
        sys.exit(0)

    ok, msg = verify_pdp_artifact(artifact, require_staging_hash=args.require_staging)
    if not ok:
        print(json.dumps({"valid": False, "reason": msg}))
        sys.exit(1)

    commit = args.commit or (artifact.get("baseline") or {}).get("commit_sha", "")
    staging_path = None
    if commit:
        for path in sorted(Path(args.staging_dir).glob("staging-*.json"), reverse=True):
            try:
                doc = json.loads(path.read_text(encoding="utf-8"))
            except (json.JSONDecodeError, OSError):
                continue
            art_commit = doc.get("commit", "")
            if art_commit == commit or art_commit.startswith(commit[:8]) or commit.startswith(art_commit[:8]):
                staging_path = path
                break

    if args.require_staging:
        if not staging_path:
            print(json.dumps({"valid": False, "reason": "no staging artifact for commit"}))
            sys.exit(1)
        staging = json.loads(staging_path.read_text(encoding="utf-8"))
        ok, msg = verify_staging_against_pdp(artifact, staging)
        if not ok:
            print(json.dumps({"valid": False, "reason": msg, "staging": str(staging_path)}))
            sys.exit(1)

    print(json.dumps({"valid": True, "staging": str(staging_path) if staging_path else None}))
    sys.exit(0)


if __name__ == "__main__":
    main()
