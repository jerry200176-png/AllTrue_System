#!/usr/bin/env python3
"""PDP replay attack prevention — reject reused signatures and stale artifacts."""
from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
REGISTRY_PATH = REPO / "ci-artifacts" / "runtime" / "pdp-signature-registry.json"
MAX_REGISTRY_ENTRIES = 500

sys.path.insert(0, str(REPO / "scripts" / "platform"))
from pdp_signature import verify_artifact_binding  # noqa: E402


def load_registry(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"entries": []}
    try:
        doc = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(doc.get("entries"), list):
            return doc
    except (json.JSONDecodeError, OSError):
        pass
    return {"entries": []}


def save_registry(path: Path, registry: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(registry, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pdp", default="ci-artifacts/pdp.json")
    parser.add_argument("--staging-artifact", default="")
    parser.add_argument("--strict-run", action="store_true", help="Require artifact run_id == GITHUB_RUN_ID")
    parser.add_argument("--register", action="store_true")
    parser.add_argument("--registry", default=str(REGISTRY_PATH))
    args = parser.parse_args()

    path = Path(args.pdp)
    if not path.exists():
        print(json.dumps({"status": "FAIL", "error": "missing pdp artifact"}))
        raise SystemExit("REPLAY_ATTACK: missing pdp artifact")

    artifact = json.loads(path.read_text(encoding="utf-8"))
    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        print(json.dumps({"status": "FAIL", "error": msg}))
        raise SystemExit(f"REPLAY_ATTACK: {msg}")

    v3 = artifact.get("pdp_v3") or {}
    baseline = artifact.get("baseline") or {}
    binding = v3.get("_binding") or {}
    signature = v3.get("_signature", "")
    commit_sha = baseline.get("commit_sha") or binding.get("commit_sha", "")
    run_id = baseline.get("generated_at") or binding.get("run_id", "")

    current_run = os.environ.get("GITHUB_RUN_ID", "")
    if args.strict_run and current_run and run_id and current_run != run_id:
        print(
            json.dumps(
                {
                    "status": "FAIL",
                    "error": "stale pdp.json from previous CI run",
                    "expected_run_id": current_run,
                    "artifact_run_id": run_id,
                },
                indent=2,
            )
        )
        raise SystemExit("REPLAY_ATTACK: stale pdp.json reused in new CI run")

    registry = load_registry(Path(args.registry))
    entries = registry.get("entries") or []
    for entry in entries:
        if entry.get("signature") != signature:
            continue
        if entry.get("commit_sha") and entry.get("commit_sha") != commit_sha:
            print(
                json.dumps(
                    {
                        "status": "FAIL",
                        "error": "signature reused across commits",
                        "signature": signature[:16] + "…",
                        "prior_commit": entry.get("commit_sha"),
                        "current_commit": commit_sha,
                    },
                    indent=2,
                )
            )
            raise SystemExit("REPLAY_ATTACK: signature reused across commits")

    if args.staging_artifact:
        staging_path = Path(args.staging_artifact)
        if staging_path.exists():
            staging = json.loads(staging_path.read_text(encoding="utf-8"))
            staging_sig = staging.get("pdp_signature") or (staging.get("pdp_v3") or {}).get("_signature")
            staging_commit = staging.get("commit", "")
            if not staging_sig:
                raise SystemExit("REPLAY_ATTACK: unsigned staging artifact for production")
            if staging_sig != signature and staging_commit.startswith(commit_sha[:8]):
                raise SystemExit("REPLAY_ATTACK: staging artifact reused with mismatched CI signature")
            if staging_commit and commit_sha and staging_commit[:8] != commit_sha[:8]:
                raise SystemExit("REPLAY_ATTACK: staging artifact commit mismatch")

    report = {
        "status": "PASS",
        "signature": signature,
        "commit_sha": commit_sha,
        "run_id": run_id,
        "registry_entries_checked": len(entries),
    }

    if args.register:
        entries.append(
            {
                "signature": signature,
                "commit_sha": commit_sha,
                "run_id": run_id,
                "content_hash": v3.get("_content_hash"),
                "recorded_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            }
        )
        registry["entries"] = entries[-MAX_REGISTRY_ENTRIES:]
        save_registry(Path(args.registry), registry)
        report["registered"] = True

    print(json.dumps(report, indent=2))
    raise SystemExit(0)


if __name__ == "__main__":
    main()
