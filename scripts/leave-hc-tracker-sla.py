#!/usr/bin/env python3
"""Evaluate #1342 tracker SLA (deadline_at_risk). No PII. Exit 0 always; prints JSON."""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def main() -> int:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "operations/closeout/leave-hc-campus-review-tracker.json")
    tracker = json.loads(path.read_text(encoding="utf-8"))
    now = datetime.now(timezone.utc)
    sla = tracker["sla"]

    def parse(ts: str) -> datetime:
        return datetime.fromisoformat(ts.replace("Z", "+00:00"))

    ack_by = parse(sla.get("ack_by_today") or sla["reply_by"])
    rows = []
    at_risk = 0
    for c in tracker.get("campuses") or []:
        status = c.get("status")
        flag = None
        if (
            not c.get("acknowledged_at")
            and now >= ack_by
            and status in ("awaiting_delivery", "delivered", "deadline_at_risk")
        ):
            status = "deadline_at_risk"
            flag = "deadline_at_risk"
            at_risk += 1
        rows.append({
            "campus_id": c.get("campus_id"),
            "status_effective": status,
            "delivered": bool(c.get("delivered_at")),
            "acknowledged": bool(c.get("acknowledged_at")),
            "deadline_flag": flag,
        })
    print(json.dumps({
        "now": now.isoformat().replace("+00:00", "Z"),
        "ack_by": sla.get("ack_by_today") or sla["reply_by"],
        "deadline_at_risk_campuses": at_risk,
        "campuses": rows,
        "note": "deadline_at_risk = ack risk, not content non-review",
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
