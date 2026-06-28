#!/usr/bin/env python3
"""Validate Platform Gate artifact against PDP v3 contract."""
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

REQUIRED_PDP_V3 = {
    "block_merge": bool,
    "allow_staging": bool,
    "allow_production": bool,
    "runtime_flags": dict,
    "slo": dict,
    "promotion": dict,
    "reason_codes": list,
}
REQUIRED_SLO = {"freeze_deploy": bool, "error_budget_burn": (int, float)}
REQUIRED_PROMOTION = {
    "staging_required": bool,
    "production_requires_staging_artifact": bool,
    "artifact_max_age_minutes": (int, float),
}
FORBIDDEN_DECISION_PATHS = [
    ("pdp", "block_merge"),
    ("pdp", "promotion", "allow_staging"),
    ("pdp", "promotion", "allow_production"),
]


def _get(obj: dict, path: tuple[str, ...]) -> Any:
    cur: Any = obj
    for p in path:
        if not isinstance(cur, dict) or p not in cur:
            return None
        cur = cur[p]
    return cur


def validate(artifact: dict[str, Any]) -> list[str]:
    errors: list[str] = []

    meta = artifact.get("meta") or {}
    if meta.get("schema_version") != "0.3":
        errors.append(f"meta.schema_version must be 0.3 (got {meta.get('schema_version')!r})")

    baseline = artifact.get("baseline")
    if not isinstance(baseline, dict):
        errors.append("missing baseline object")
    else:
        for key in ("commit_sha", "branch", "generated_at"):
            if not baseline.get(key):
                errors.append(f"baseline missing key: {key}")

    integrity = artifact.get("promotion_integrity")
    if not isinstance(integrity, dict):
        errors.append("missing promotion_integrity object")
    else:
        for key in ("commit_sha", "ci_test_hash", "pdp_decision_hash", "integrity_signature"):
            if not integrity.get(key):
                errors.append(f"promotion_integrity missing key: {key}")

    v3 = artifact.get("pdp_v3")
    if not isinstance(v3, dict):
        errors.append("missing pdp_v3 object")
        return errors

    for key, typ in REQUIRED_PDP_V3.items():
        if key not in v3:
            errors.append(f"pdp_v3 missing key: {key}")
            continue
        if not isinstance(v3[key], typ):
            errors.append(f"pdp_v3.{key} wrong type (expected {typ.__name__})")

    slo = v3.get("slo") or {}
    for key, typ in REQUIRED_SLO.items():
        if key not in slo:
            errors.append(f"pdp_v3.slo missing key: {key}")
        elif not isinstance(slo[key], typ):
            errors.append(f"pdp_v3.slo.{key} wrong type")

    promo = v3.get("promotion") or {}
    for key, typ in REQUIRED_PROMOTION.items():
        if key not in promo:
            errors.append(f"pdp_v3.promotion missing key: {key}")
        elif not isinstance(promo[key], typ):
            errors.append(f"pdp_v3.promotion.{key} wrong type")

    # No duplicate decision fields outside pdp_v3
    if "block_merge" in artifact and artifact.get("block_merge") != v3.get("block_merge"):
        errors.append("duplicate block_merge at artifact root conflicts with pdp_v3")
    legacy_pdp = artifact.get("pdp")
    if isinstance(legacy_pdp, dict) and legacy_pdp.get("block_merge") is not None:
        if legacy_pdp.get("block_merge") != v3.get("block_merge"):
            errors.append("legacy pdp.block_merge conflicts with pdp_v3")

    for path in FORBIDDEN_DECISION_PATHS:
        if _get(artifact, path) is not None:
            errors.append(f"forbidden duplicate decision field at {'.'.join(path)}")

    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from pdp_signature import verify_artifact_binding  # noqa: E402

    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        errors.append(f"pdp_v3 signature: {msg}")
    meta_sig = meta.get("pdp_v3_bound_signature") or meta.get("pdp_signature")
    if meta_sig and meta_sig != v3.get("_signature"):
        errors.append("meta.pdp_v3_bound_signature does not match pdp_v3._signature")

    sig_block = artifact.get("pdp_signature")
    if not isinstance(sig_block, dict):
        errors.append("missing pdp_signature root-of-trust block")
    else:
        for key in ("signer", "public_key_fingerprint", "signature", "signed_at", "payload_sha256"):
            if not sig_block.get(key):
                errors.append(f"pdp_signature missing {key}")

    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from pdp_signing_authority import verify_artifact as verify_root_signature  # noqa: E402

    ok_root, root_msg = verify_root_signature(artifact, require_staging_hash=False)
    if not ok_root:
        errors.append(f"pdp_signature root-of-trust: {root_msg}")

    return errors


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    args = parser.parse_args()
    path = Path(args.input)
    artifact = json.loads(path.read_text(encoding="utf-8"))
    errors = validate(artifact)
    result = {"valid": not errors, "errors": errors}
    print(json.dumps(result, indent=2))
    sys.exit(0 if not errors else 1)


if __name__ == "__main__":
    main()
