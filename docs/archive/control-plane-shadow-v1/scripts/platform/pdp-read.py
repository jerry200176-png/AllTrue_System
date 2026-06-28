#!/usr/bin/env python3
"""Read pdp_v3 fields or verify signature (consumers only — no policy logic)."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pdp_signature import verify_artifact_binding  # noqa: E402


def read_field(artifact: dict, dotted: str):
    v3 = artifact.get("pdp_v3") or {}
    parts = dotted.split(".")
    cur = v3
    for p in parts:
        if not isinstance(cur, dict):
            return None
        cur = cur.get(p)
    return cur


def verify_artifact(path: Path) -> None:
    artifact = json.loads(path.read_text(encoding="utf-8"))
    v3 = artifact.get("pdp_v3")
    if not isinstance(v3, dict):
        raise SystemExit("VERIFY_FAIL: missing pdp_v3")
    ok, msg = verify_artifact_binding(artifact)
    if not ok:
        print(json.dumps({"valid": False, "error": msg}))
        raise SystemExit(1)
    print(
        json.dumps(
            {
                "valid": True,
                "signature": v3.get("_signature"),
                "content_hash": v3.get("_content_hash"),
                "baseline": artifact.get("baseline"),
            }
        )
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("path_positional", nargs="?", help="Artifact path (with --verify)")
    parser.add_argument("--input", help="Artifact path")
    parser.add_argument("--verify", action="store_true")
    parser.add_argument("--field", help="e.g. block_merge, slo.freeze_deploy")
    parser.add_argument("--format", choices=("value", "bool", "json"), default="bool")
    args = parser.parse_args()

    path_str = args.input or args.path_positional
    if args.verify:
        if not path_str:
            print("usage: pdp-read.py --verify ci-artifacts/pdp.json", file=sys.stderr)
            sys.exit(2)
        verify_artifact(Path(path_str))
        sys.exit(0)

    if not path_str or not args.field:
        parser.error("--input and --field required unless --verify")

    artifact = json.loads(Path(path_str).read_text(encoding="utf-8"))
    val = read_field(artifact, args.field)

    if val is None and args.format == "bool":
        if args.field == "block_merge":
            print("true")
        else:
            print("false")
    elif args.format == "json":
        print(json.dumps(val))
    elif args.format == "bool":
        print("true" if val else "false")
    else:
        print("" if val is None else val)

    sys.exit(0)


if __name__ == "__main__":
    main()
