#!/usr/bin/env python3
"""Write runtime metrics only — does NOT create or modify ci-artifacts/pdp.json."""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
RUNTIME_LATEST = REPO / "ci-artifacts" / "runtime" / "latest.json"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--metrics", required=True)
    args = parser.parse_args()

    metrics = json.loads(Path(args.metrics).read_text(encoding="utf-8"))
    doc = {
        "timestamp": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "runtime_metrics": metrics,
        "note": "metrics-only — PDP authority remains ci-artifacts/pdp.json",
    }
    RUNTIME_LATEST.parent.mkdir(parents=True, exist_ok=True)
    RUNTIME_LATEST.write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"written": str(RUNTIME_LATEST), "pdp_authority": "ci-artifacts/pdp.json"}, indent=2))


if __name__ == "__main__":
    main()
