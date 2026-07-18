#!/usr/bin/env python3
"""Validate docs/governance/agent_capabilities.json freshness rules."""
from __future__ import annotations

import argparse
import json
import sys
from datetime import date, datetime
from pathlib import Path

REQUIRED = [
    "id", "name", "status", "risk", "last_verified_at", "review_after",
    "verification_method", "environment", "verifier", "evidence",
]
HIGH_RISK = {"high"}
PROVEN = "Proven"


def parse_day(s: str) -> date:
    return datetime.strptime(str(s)[:10], "%Y-%m-%d").date()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", default="docs/governance/agent_capabilities.json")
    ap.add_argument("--today", default=None)
    args = ap.parse_args()
    path = Path(args.file)
    if not path.is_file():
        print(f"FAIL: missing {path}", file=sys.stderr)
        return 1
    data = json.loads(path.read_text(encoding="utf-8"))
    caps = data.get("capabilities") or []
    today = parse_day(args.today) if args.today else date.today()
    errors = warnings = 0
    for cap in caps:
        cid = cap.get("id", "?")
        for field in REQUIRED:
            if not cap.get(field):
                print(f"FAIL: {cid} missing field {field}")
                errors += 1
        if str(cap.get("status", "")) != PROVEN:
            continue
        try:
            review_after = parse_day(cap["review_after"])
        except Exception:
            print(f"FAIL: {cid} invalid review_after")
            errors += 1
            continue
        if review_after < today:
            msg = f"stale Proven capability {cid} review_after={review_after}"
            if str(cap.get("risk", "")).lower() in HIGH_RISK:
                print(f"FAIL: {msg} (high-risk fail-closed)")
                errors += 1
            else:
                print(f"WARN: {msg}")
                warnings += 1
    if errors:
        print(f"capability-registry: FAIL errors={errors} warnings={warnings}")
        return 1
    print(f"capability-registry: OK warnings={warnings}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
