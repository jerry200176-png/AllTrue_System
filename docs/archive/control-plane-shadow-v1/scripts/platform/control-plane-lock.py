#!/usr/bin/env python3
"""Control plane lock — hard validation gate for all pipelines."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pdp_signature import verify_artifact_binding

REQUIRED_TOP = ("block_merge", "allow_staging", "allow_production", "runtime_flags", "slo", "promotion", "reason_codes")
REQUIRED_BASELINE = ("commit_sha", "branch", "generated_at")


def validate_control_plane(artifact: dict) -> None:
    pdp = artifact.get("pdp_v3")
    if not isinstance(pdp, dict):
        raise ValueError("INVALID_STATE: missing pdp_v3")

    baseline = artifact.get("baseline")
    if not isinstance(baseline, dict):
        raise ValueError("INVALID_STATE: missing baseline block")
    for key in REQUIRED_BASELINE:
        if not baseline.get(key):
            raise ValueError(f"INVALID_STATE: baseline missing {key}")

    for key in REQUIRED_TOP:
        if key not in pdp:
            raise ValueError(f"INVALID_STATE: pdp_v3 missing {key}")

    slo = pdp.get("slo") or {}
    if slo.get("freeze_deploy") and pdp.get("allow_production"):
        raise ValueError("INVALID_STATE: freeze + allow_production contradiction")

    if slo.get("freeze_deploy") and not pdp.get("block_merge"):
        raise ValueError("INVALID_STATE: freeze_deploy requires block_merge")

    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        raise ValueError(f"INVALID_STATE: {msg}")

    env_commit = os.environ.get("GITHUB_SHA", "")
    if env_commit and baseline.get("commit_sha"):
        a = baseline["commit_sha"]
        b = env_commit
        if not (a == b or a.startswith(b[:8]) or b.startswith(a[:8])):
            raise ValueError("INVALID_STATE: baseline commit_sha does not match GITHUB_SHA")


def main() -> None:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "")
    if not path.exists():
        print(json.dumps({"valid": False, "error": f"artifact not found: {path}"}))
        sys.exit(1)
    artifact = json.loads(path.read_text(encoding="utf-8"))
    try:
        validate_control_plane(artifact)
        print(json.dumps({"valid": True, "control_plane": "locked"}))
        sys.exit(0)
    except ValueError as e:
        print(json.dumps({"valid": False, "error": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
