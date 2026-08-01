#!/usr/bin/env python3
"""Fail-closed preflight for in-app bug triage evidence."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path


OPEN_STATUSES = {"new", "triaged", "in_progress"}
REQUIRED_DETAIL_KEYS = {
    "attachments", "comments", "status_logs", "reporter_history",
    "reporter_history_comments", "reporter_history_status_logs",
}


def read_json(path: Path) -> object:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"evidence unreadable: {path}: {exc}") from exc


def parse_utc(value: str) -> datetime:
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise SystemExit(f"invalid dump_generated_at: {value}") from exc
    return parsed.astimezone(timezone.utc)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--queue-meta", type=Path, required=True)
    parser.add_argument("--queue-open", type=Path, required=True)
    parser.add_argument("--detail", type=Path, required=True)
    parser.add_argument("--bug-id", type=int, required=True)
    parser.add_argument("--max-age-minutes", type=int, default=15)
    args = parser.parse_args()

    meta = read_json(args.queue_meta)
    open_rows = read_json(args.queue_open)
    detail = read_json(args.detail)
    if not isinstance(meta, dict) or not meta.get("queue_dump_run_id"):
        raise SystemExit("queue evidence is missing queue_dump_run_id")
    generated_at = meta.get("dump_generated_at")
    if not isinstance(generated_at, str):
        raise SystemExit("queue evidence is missing dump_generated_at")
    age_minutes = (datetime.now(timezone.utc) - parse_utc(generated_at)).total_seconds() / 60
    if age_minutes < -1 or age_minutes > args.max_age_minutes:
        raise SystemExit(f"queue evidence is stale: age_minutes={age_minutes:.1f}")
    matching_rows = [row for row in open_rows if isinstance(row, dict) and int(row.get("id", -1)) == args.bug_id]
    if len(matching_rows) != 1:
        raise SystemExit(f"bug #{args.bug_id} is not uniquely present in the fresh open queue")
    if matching_rows[0].get("status") not in OPEN_STATUSES:
        raise SystemExit(f"bug #{args.bug_id} is not open: {matching_rows[0].get('status')}")
    if not isinstance(detail, dict) or not isinstance(detail.get("bug"), dict):
        raise SystemExit("detail evidence is missing bug")
    if int(detail["bug"].get("id", -1)) != args.bug_id:
        raise SystemExit("queue bug id and detail bug id do not match")
    missing = sorted(REQUIRED_DETAIL_KEYS - detail.keys())
    if missing:
        raise SystemExit(f"detail evidence is missing SOP fields: {', '.join(missing)}")
    if not any(isinstance(row, dict) and int(row.get("id", -1)) == args.bug_id for row in detail.get("reporter_history") or []):
        raise SystemExit("reporter history does not include the target bug")
    print(json.dumps({"ok": True, "bug_id": args.bug_id, "queue_dump_run_id": meta["queue_dump_run_id"], "queue_age_minutes": round(age_minutes, 1), "status": matching_rows[0].get("status"), "attachment_count": len(detail.get("attachments", [])), "reporter_history_count": len(detail.get("reporter_history", []))}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
