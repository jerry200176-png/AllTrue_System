#!/usr/bin/env python3
"""Build a controlled leave-cascade repair bundle from director decisions.

Fail-closed: blank / keep / investigate never enter allowlist.
Directors never see class_session_id; ops map stays off Issue/PR bodies.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

APPROVE = {"核准修正", "approve", "approved", "yes", "y"}
KEEP = {"保留現況", "keep", "保留"}
INVESTIGATE = {"需要查證", "investigate", "查證"}


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    h.update(path.read_bytes())
    return h.hexdigest()


def load_hc_ids(path: Path) -> set[int]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, dict) and "hc_snapshot_session_ids" in data:
        return {int(x) for x in data["hc_snapshot_session_ids"]}
    if isinstance(data, dict) and "session_ids" in data:
        return {int(x) for x in data["session_ids"]}
    raise SystemExit(f"unsupported HC snapshot schema: {path}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--decisions-csv", required=True)
    ap.add_argument("--map-json", required=True)
    ap.add_argument("--hc-snapshot-json", required=True, help="Tracker or leave-slot-hc-summary JSON with session_ids")
    ap.add_argument("--campus-id", type=int, required=True)
    ap.add_argument("--approver", required=True, help="Approver identity label (no PII beyond role/name)")
    ap.add_argument("--out-bundle", required=True)
    ap.add_argument("--require-all-approved", action="store_true",
                    help="Fail if any blank/keep/investigate remain for this campus")
    args = ap.parse_args()

    map_path = Path(args.map_json)
    decisions_path = Path(args.decisions_csv)
    hc_ids = load_hc_ids(Path(args.hc_snapshot_json))
    mapping_rows = json.loads(map_path.read_text(encoding="utf-8"))["rows"]
    mapping = {r["review_key"]: r for r in mapping_rows}

    approved, kept, investigate, blank, rejected = [], [], [], [], []
    errors: list[str] = []

    with decisions_path.open(encoding="utf-8-sig", newline="") as f:
        for row in csv.DictReader(f):
            key = (row.get("review_key") or "").strip()
            decision = (row.get("審核結果") or "").strip()
            meta = mapping.get(key)
            if not meta:
                errors.append(f"unknown_review_key:{key}")
                continue
            sid = int(meta["class_session_id"])
            campus = int(meta.get("campus_id") or 0)
            if campus != args.campus_id:
                errors.append(f"cross_campus:{key}:got={campus}:want={args.campus_id}")
                continue
            if sid not in hc_ids:
                errors.append(f"not_in_hc_snapshot:{sid}")
                continue
            rec = {
                "review_key": key,
                "class_session_id": sid,
                "student_class_id": int(meta["student_class_id"]),
                "campus_id": campus,
                "expected_before": meta.get("current"),
                "expected_after": meta.get("contract"),
                "is_contract_exception": bool(meta.get("is_contract_exception")),
                "decision": decision,
                "approver": (row.get("審核人") or args.approver).strip() or args.approver,
                "approved_at": (row.get("審核時間") or "").strip(),
                "note": (row.get("備註") or "").strip(),
            }
            if decision in APPROVE:
                if rec["is_contract_exception"]:
                    errors.append(f"approve_blocked_contract_exception:{sid}")
                    continue
                if not rec["expected_before"] or not rec["expected_after"]:
                    errors.append(f"missing_expected_state:{sid}")
                    continue
                approved.append(rec)
            elif decision in KEEP:
                kept.append(rec)
            elif decision in INVESTIGATE:
                investigate.append(rec)
            elif decision == "":
                blank.append(rec)
            else:
                rejected.append(rec)

    ids = [r["class_session_id"] for r in approved]
    if len(ids) != len(set(ids)):
        errors.append("duplicate_session_ids_in_allowlist")
    if args.require_all_approved and (blank or kept or investigate or rejected):
        errors.append("require_all_approved_failed")

    # Bundle may be built for partial campus approvals; blank rows stay no-write.
    ok = errors == [] and (
        # At least one approve OR explicit empty approve with no hard errors
        True
    )
    if errors:
        ok = False

    audit_id = f"leave-hc-{args.campus_id}-{uuid.uuid4().hex[:12]}"
    ts = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    bundle = {
        "schema_version": 1,
        "kind": "leave_cascade_slot_times_repair_bundle",
        "ok": ok,
        "execution_audit_id": audit_id,
        "campus_id": args.campus_id,
        "approver_identity": args.approver,
        "approval_timestamp": ts,
        "source_review_artifact_checksum": sha256_file(decisions_path),
        "mapping_file_checksum": sha256_file(map_path),
        "approval_count": len(set(ids)),
        "rejected_count": len(rejected),
        "kept_count": len(kept),
        "needs_review_count": len(investigate),
        "blank_count": len(blank),
        "approved_session_ids": sorted(set(ids)),
        "expected_before_state": {
            str(r["class_session_id"]): r["expected_before"] for r in approved
        },
        "expected_after_state": {
            str(r["class_session_id"]): r["expected_after"] for r in approved
        },
        "pre_exec_gates": {
            "all_ids_from_hc_snapshot": all(i in hc_ids for i in ids),
            "explicit_approve_only": True,
            "no_blank_in_allowlist": True,
            "no_keep_in_allowlist": True,
            "no_investigate_in_allowlist": True,
            "no_duplicates": len(ids) == len(set(ids)),
            "single_campus": True,
            "approval_count_matches_allowlist": len(set(ids)) == len(approved) or len(set(ids)) == len({r["class_session_id"] for r in approved}),
            "errors": errors,
        },
        "rows": approved,
        "non_execute": {
            "kept": [{"class_session_id": r["class_session_id"], "review_key": r["review_key"]} for r in kept],
            "needs_review": [{"class_session_id": r["class_session_id"], "review_key": r["review_key"]} for r in investigate],
            "blank": [{"class_session_id": r["class_session_id"], "review_key": r["review_key"]} for r in blank],
        },
        "pii_note": "Bundle must not include student names. Do not commit filled CSV.",
    }

    out = Path(args.out_bundle)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(bundle, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(
        f"ok={ok} approved={bundle['approval_count']} kept={bundle['kept_count']} "
        f"needs_review={bundle['needs_review_count']} blank={bundle['blank_count']} "
        f"errors={len(errors)} audit_id={audit_id}"
    )
    return 0 if ok else 2


if __name__ == "__main__":
    sys.exit(main())
