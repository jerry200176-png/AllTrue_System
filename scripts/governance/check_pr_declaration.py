#!/usr/bin/env python3
"""Fail a PR check when its risk declaration is absent or understated."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.governance.autonomy_gate import validate_declaration


def _flatten_files(value: object) -> list[dict[str, object]]:
    if not isinstance(value, list):
        return []
    files: list[dict[str, object]] = []
    for page in value:
        if isinstance(page, list):
            files.extend(item for item in page if isinstance(item, dict))
        elif isinstance(page, dict):
            files.append(page)
    return files


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr-json", required=True, type=Path)
    parser.add_argument("--files-json", required=True, type=Path)
    args = parser.parse_args()

    pr = json.loads(args.pr_json.read_text(encoding="utf-8"))
    files = _flatten_files(json.loads(args.files_json.read_text(encoding="utf-8")))
    paths = [str(item.get("filename") or "") for item in files]
    patch = "\n".join(
        f"diff --git a/{path} b/{path}\n{item.get('patch') or ''}"
        for path, item in zip(paths, files)
    )
    result = validate_declaration(str(pr.get("body") or ""), paths, patch)
    generated = result["generated"]
    print(
        "Machine declaration: "
        f"Risk-Class: {generated['risk_class']} / "
        f"Autonomy-Tier: {generated['autonomy_tier']}"
    )
    print("Machine reasons: " + "; ".join(str(x) for x in generated["reasons"]))
    if not result["valid"]:
        print(
            "PR declaration invalid: "
            f"{result['error']}; expected at least "
            f"{generated['risk_class']}/{generated['autonomy_tier']}",
            file=sys.stderr,
        )
        return 1
    print(
        "PR declaration valid: "
        f"{result['declared_risk']}/{result['declared_tier']} "
        f"(effective {result['effective_tier']})"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
