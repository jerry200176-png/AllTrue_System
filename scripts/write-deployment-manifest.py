#!/usr/bin/env python3
"""Write the public, non-secret runtime deployment identity manifest."""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path


def _run_number(value: str | None) -> int | None:
    """A GitHub run id or attempt: digits only, else unknown (the manifest is public and must stay well-formed)."""
    return int(value) if value and value.isascii() and value.isdigit() and len(value) <= 20 else None


def build_manifest(target_sha: str, version: dict, deployed_at: str | None = None,
                   run_id: str | None = None, run_attempt: str | None = None) -> dict:
    frontend_sha = version.get("build_sha") or version.get("hash") or None
    return {
        "schema": 1,
        "backend_sha": target_sha,
        "frontend_sha": frontend_sha,
        "frontend_build_sha": frontend_sha,
        "frontend_built_at": version.get("built_at") or version.get("t") or None,
        "deployed_at": deployed_at or datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "source": "github-actions:deploy.yml",
        # #3503: which workflow run activated this SHA. The run's own head_sha is the workflow revision, not the target.
        "run_id": _run_number(run_id),
        "run_attempt": _run_number(run_attempt),
    }


def read_version(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--target-sha", required=True)
    parser.add_argument("--version-file", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--deployed-at")
    parser.add_argument("--run-id")
    parser.add_argument("--run-attempt")
    args = parser.parse_args()

    manifest = build_manifest(args.target_sha, read_version(args.version_file), args.deployed_at,
                              args.run_id, args.run_attempt)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(manifest, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    print(f"deployment manifest written: backend_sha={args.target_sha}")


if __name__ == "__main__":
    main()
