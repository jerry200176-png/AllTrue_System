#!/usr/bin/env python3
"""PDP schema validation — structural check only, no policy decisions."""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
VALIDATOR = REPO / "scripts" / "platform" / "policy-contract-validator.py"

FORBIDDEN_AUTHORITY_PATHS = (
    "services/feature-flags/pdp-runtime-snapshot.json",
    "ci-artifacts/pdp-baseline.json",
)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pdp", default="ci-artifacts/pdp.json")
    parser.add_argument("--repo-root", default=str(REPO))
    args = parser.parse_args()

    repo = Path(args.repo_root)
    pdp_path = Path(args.pdp)
    errors: list[str] = []

    if not pdp_path.exists():
        errors.append(f"missing canonical PDP: {pdp_path}")
    else:
        proc = subprocess.run(
            [sys.executable, str(VALIDATOR), "--input", str(pdp_path)],
            capture_output=True,
            text=True,
        )
        if proc.returncode != 0:
            try:
                result = json.loads(proc.stdout or proc.stderr)
                errors.extend(result.get("errors") or ["schema validation failed"])
            except json.JSONDecodeError:
                errors.append("schema validation failed")

    for rel in FORBIDDEN_AUTHORITY_PATHS:
        if (repo / rel).exists():
            errors.append(f"forbidden derived authority file exists: {rel}")

    report = {
        "schema_valid": not errors,
        "errors": errors,
        "risk_level": "blocked" if errors else "clean",
        "files_flagged": [e for e in errors if "forbidden" in e],
    }
    print(json.dumps(report, indent=2))
    raise SystemExit(1 if errors else 0)


if __name__ == "__main__":
    main()
