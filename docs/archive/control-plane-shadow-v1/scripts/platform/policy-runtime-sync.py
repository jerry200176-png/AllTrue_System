#!/usr/bin/env python3
"""Metrics timestamp writer — no policy inference."""
from __future__ import annotations

import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
RUNTIME_DIR = REPO / "ci-artifacts" / "runtime"


def main() -> None:
    latest = RUNTIME_DIR / "latest.json"
    metrics = {}
    if latest.exists():
        try:
            metrics = json.loads(latest.read_text(encoding="utf-8")).get("runtime_metrics") or {}
        except (json.JSONDecodeError, OSError):
            metrics = {}

    doc = {
        "synced_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "read_only": True,
        "pdp_authority": "ci-artifacts/pdp.json",
        "runtime_metrics_summary": {
            "error_rate": metrics.get("error_rate"),
            "latency_p95": metrics.get("latency_p95"),
            "slo_breach": metrics.get("slo_breach"),
        },
    }

    out = os.environ.get("SYNC_OUT", str(RUNTIME_DIR / "sync-status.json"))
    Path(out).parent.mkdir(parents=True, exist_ok=True)
    Path(out).write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(doc, indent=2))
    raise SystemExit(0)


if __name__ == "__main__":
    main()
