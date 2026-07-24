#!/usr/bin/env python3
"""Outbound readiness gate for human-recipient workflows.

Modes: schema | activation | delivery_complete
Exit 0 = ready; 2 = blocked. Never prints PII.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REQUIRED = ("campus_id", "status", "channel", "csv_sha256", "artifact_run")
ACTIVATION = (
    "recipient_configured",
    "channel_health",
    "test_delivery",
    "acknowledgment_path",
    "pii_policy",
)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--tracker", required=True)
    ap.add_argument("--delivery-artifact", default="")
    ap.add_argument("--mode", choices=("schema", "activation", "delivery_complete"), default="schema")
    args = ap.parse_args()
    tracker = json.loads(Path(args.tracker).read_text(encoding="utf-8"))
    errors: list[str] = []
    channel = tracker.get("channel") or {}
    forbidden = " ".join(channel.get("forbidden") or []).lower()
    not_done = str(channel.get("not_done_when", "")).lower()
    if "treat artifact generated as delivery complete" not in forbidden and "artifact generated only" not in not_done:
        errors.append("tracker_missing_artifact_neq_delivery_rule")
    for c in tracker.get("campuses") or []:
        cid = c.get("campus_id")
        for f in REQUIRED:
            if not c.get(f):
                errors.append(f"campus_{cid}_missing_{f}")
        if "acknowledged_at" not in c:
            errors.append(f"campus_{cid}_missing_ack_field")
        if "delivered_at" not in c:
            errors.append(f"campus_{cid}_missing_delivered_at_field")
        if c.get("status") == "awaiting_delivery" and c.get("delivered_at"):
            errors.append(f"campus_{cid}_status_delivered_at_inconsistent")
        if c.get("status") == "awaiting_review" and not c.get("acknowledged_at"):
            errors.append(f"campus_{cid}_awaiting_review_without_ack")
    task = tracker.get("platform_ops_delivery_task") or {}
    if not task.get("owner") or not task.get("single_step"):
        errors.append("missing_platform_ops_task")
    if args.delivery_artifact:
        art = json.loads(Path(args.delivery_artifact).read_text(encoding="utf-8"))
        rows = art.get("delivered") or []
        if not rows:
            errors.append("delivery_artifact_empty")
        for r in rows:
            if r.get("status") == "skipped_no_line" or r.get("has_group") is False:
                errors.append(f"campus_{r.get('campus_id')}_channel_not_configured")
    awaiting = [c["campus_id"] for c in tracker.get("campuses") or [] if c.get("status") == "awaiting_delivery"]
    if task.get("status") == "completed" and awaiting:
        errors.append("claimed_complete_while_awaiting_delivery")
    if args.mode == "activation":
        ready = tracker.get("outbound_readiness") or {}
        for k in ACTIVATION:
            if ready.get(k) is not True:
                errors.append(f"activation_missing_or_false:{k}")
        if ready.get("activation_allowed") is not True:
            errors.append("activation_allowed_false")
    if args.mode == "delivery_complete":
        if awaiting:
            errors.append("still_awaiting_delivery")
        for c in tracker.get("campuses") or []:
            if not c.get("delivered_at"):
                errors.append(f"campus_{c.get('campus_id')}_missing_delivered_at")
    report = {
        "ok": errors == [],
        "mode": args.mode,
        "errors": errors,
        "awaiting_delivery_campus_ids": awaiting,
        "pii_policy": "no names/csv in gate output",
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if errors == [] else 2


if __name__ == "__main__":
    sys.exit(main())
