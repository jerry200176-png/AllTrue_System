#!/usr/bin/env python3
"""Director CSV (審核結果) → --session-ids via ops-review-key-map.json."""
from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path


APPROVE = {"核准修正", "approve", "approved", "yes", "y"}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--decisions-csv", required=True, help="Director-filled CSV")
    ap.add_argument("--map-json", required=True, help="ops-review-key-map.json")
    ap.add_argument("--out-allowlist", required=True, help="Write comma-separated session ids")
    ap.add_argument("--out-audit", required=True, help="Write audit JSON")
    args = ap.parse_args()

    mapping = {r["review_key"]: r for r in json.loads(Path(args.map_json).read_text())["rows"]}
    approved = []
    kept = []
    investigate = []
    unknown = []
    with Path(args.decisions_csv).open(encoding="utf-8-sig", newline="") as f:
        for row in csv.DictReader(f):
            key = (row.get("review_key") or "").strip()
            decision = (row.get("審核結果") or "").strip()
            meta = mapping.get(key)
            if not meta:
                unknown.append({"review_key": key, "decision": decision})
                continue
            rec = {
                **meta,
                "decision": decision,
                "approver": (row.get("審核人") or "").strip(),
                "approved_at": (row.get("審核時間") or "").strip(),
                "note": (row.get("備註") or "").strip(),
            }
            if decision in APPROVE:
                approved.append(rec)
            elif decision in {"保留現況", "keep", "保留"}:
                kept.append(rec)
            elif decision in {"需要查證", "investigate", "查證"}:
                investigate.append(rec)
            elif decision == "":
                unknown.append(rec)  # unread — must stay read-only
            else:
                unknown.append(rec)

    ids = sorted({int(r["class_session_id"]) for r in approved})
    Path(args.out_allowlist).write_text(",".join(str(i) for i in ids) + ("\n" if ids else ""))
    Path(args.out_audit).write_text(
        json.dumps(
            {
                "approved_count": len(ids),
                "approved_session_ids": ids,
                "kept": kept,
                "investigate": investigate,
                "blank_or_unknown": unknown,
                "execute_hint": (
                    "Use scripts/leave-cascade-build-repair-bundle.py + "
                    "workflow ops-leave-cascade-repair.yml (do not paste artisan session-ids)"
                    if ids
                    else "no approved rows — do not execute"
                ),
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    print(f"approved={len(ids)} kept={len(kept)} investigate={len(investigate)} blank_or_unknown={len(unknown)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
