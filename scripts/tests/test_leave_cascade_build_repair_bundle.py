#!/usr/bin/env python3
"""Unit tests for leave-cascade-build-repair-bundle.py (no network / no DB)."""
from __future__ import annotations

import csv
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "leave-cascade-build-repair-bundle.py"


class BuildRepairBundleTest(unittest.TestCase):
    def _write_fixtures(self, td: Path, decision: str, campus: int = 3, sid: int = 4790):
        map_path = td / "map.json"
        map_path.write_text(
            json.dumps(
                {
                    "count": 1,
                    "rows": [
                        {
                            "review_key": "abc123",
                            "class_session_id": sid,
                            "student_class_id": 591,
                            "campus_id": campus,
                            "current": "17:00-19:00",
                            "contract": "13:00-15:00",
                            "is_contract_exception": False,
                        }
                    ],
                },
                ensure_ascii=False,
            ),
            encoding="utf-8",
        )
        csv_path = td / "decisions.csv"
        with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
            w = csv.DictWriter(
                f,
                fieldnames=["審核結果", "審核人", "審核時間", "備註", "review_key"],
            )
            w.writeheader()
            w.writerow(
                {
                    "審核結果": decision,
                    "審核人": "主任A",
                    "審核時間": "2026-07-20",
                    "備註": "",
                    "review_key": "abc123",
                }
            )
        snap = td / "hc.json"
        snap.write_text(json.dumps({"session_ids": [sid, 6392]}), encoding="utf-8")
        return csv_path, map_path, snap

    def test_approve_builds_ok_bundle(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            csv_path, map_path, snap = self._write_fixtures(td_path, "核准修正")
            out = td_path / "bundle.json"
            ec = subprocess.call(
                [
                    sys.executable,
                    str(SCRIPT),
                    f"--decisions-csv={csv_path}",
                    f"--map-json={map_path}",
                    f"--hc-snapshot-json={snap}",
                    "--campus-id=3",
                    "--approver=director:campus-3",
                    f"--out-bundle={out}",
                ]
            )
            self.assertEqual(ec, 0)
            b = json.loads(out.read_text(encoding="utf-8"))
            self.assertTrue(b["ok"])
            self.assertEqual(b["approved_session_ids"], [4790])
            self.assertEqual(b["approval_count"], 1)
            self.assertNotIn("學生姓名", json.dumps(b, ensure_ascii=False))

    def test_blank_not_in_allowlist_and_ok(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            csv_path, map_path, snap = self._write_fixtures(td_path, "")
            out = td_path / "bundle.json"
            ec = subprocess.call(
                [
                    sys.executable,
                    str(SCRIPT),
                    f"--decisions-csv={csv_path}",
                    f"--map-json={map_path}",
                    f"--hc-snapshot-json={snap}",
                    "--campus-id=3",
                    "--approver=ops",
                    f"--out-bundle={out}",
                ]
            )
            self.assertEqual(ec, 0)
            b = json.loads(out.read_text(encoding="utf-8"))
            self.assertEqual(b["approved_session_ids"], [])
            self.assertEqual(b["blank_count"], 1)

    def test_cross_campus_fail_closed(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            csv_path, map_path, snap = self._write_fixtures(td_path, "核准修正", campus=3)
            out = td_path / "bundle.json"
            ec = subprocess.call(
                [
                    sys.executable,
                    str(SCRIPT),
                    f"--decisions-csv={csv_path}",
                    f"--map-json={map_path}",
                    f"--hc-snapshot-json={snap}",
                    "--campus-id=11",
                    "--approver=ops",
                    f"--out-bundle={out}",
                ]
            )
            self.assertEqual(ec, 2)
            b = json.loads(out.read_text(encoding="utf-8"))
            self.assertFalse(b["ok"])
            self.assertTrue(any("cross_campus" in e for e in b["pre_exec_gates"]["errors"]))


    def test_keep_and_investigate_excluded(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            for decision, field in [("保留現況", "kept_count"), ("需要查證", "needs_review_count")]:
                csv_path, map_path, snap = self._write_fixtures(td_path, decision)
                out = td_path / f"b-{field}.json"
                ec = subprocess.call([
                    sys.executable, str(SCRIPT),
                    f"--decisions-csv={csv_path}", f"--map-json={map_path}",
                    f"--hc-snapshot-json={snap}", "--campus-id=3", "--approver=ops",
                    f"--out-bundle={out}",
                ])
                self.assertEqual(ec, 0)
                b = json.loads(out.read_text(encoding="utf-8"))
                self.assertEqual(b["approved_session_ids"], [])
                self.assertEqual(b[field], 1)

    def test_unknown_review_key_fail_closed(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            csv_path, map_path, snap = self._write_fixtures(td_path, "核准修正")
            # corrupt key
            rows = list(csv.DictReader(csv_path.open(encoding="utf-8-sig")))
            rows[0]["review_key"] = "does-not-exist"
            with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
                w = csv.DictWriter(f, fieldnames=rows[0].keys()); w.writeheader(); w.writerows(rows)
            out = td_path / "bundle.json"
            ec = subprocess.call([
                sys.executable, str(SCRIPT),
                f"--decisions-csv={csv_path}", f"--map-json={map_path}",
                f"--hc-snapshot-json={snap}", "--campus-id=3", "--approver=ops",
                f"--out-bundle={out}",
            ])
            self.assertEqual(ec, 2)
            b = json.loads(out.read_text(encoding="utf-8"))
            self.assertFalse(b["ok"])
            self.assertTrue(any("unknown_review_key" in e for e in b["pre_exec_gates"]["errors"]))

    def test_duplicate_session_fail_closed(self):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            map_path = td_path / "map.json"
            map_path.write_text(json.dumps({"rows": [
                {"review_key": "a", "class_session_id": 4790, "student_class_id": 1, "campus_id": 3,
                 "current": "17:00-19:00", "contract": "13:00-15:00", "is_contract_exception": False},
                {"review_key": "b", "class_session_id": 4790, "student_class_id": 1, "campus_id": 3,
                 "current": "17:00-19:00", "contract": "13:00-15:00", "is_contract_exception": False},
            ]}), encoding="utf-8")
            csv_path = td_path / "d.csv"
            with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
                w = csv.DictWriter(f, fieldnames=["審核結果","審核人","審核時間","備註","review_key"])
                w.writeheader()
                w.writerow({"審核結果":"核准修正","審核人":"a","審核時間":"t","備註":"","review_key":"a"})
                w.writerow({"審核結果":"核准修正","審核人":"a","審核時間":"t","備註":"","review_key":"b"})
            snap = td_path / "hc.json"; snap.write_text(json.dumps({"session_ids":[4790]}))
            out = td_path / "bundle.json"
            ec = subprocess.call([
                sys.executable, str(SCRIPT),
                f"--decisions-csv={csv_path}", f"--map-json={map_path}",
                f"--hc-snapshot-json={snap}", "--campus-id=3", "--approver=ops",
                f"--out-bundle={out}",
            ])
            self.assertEqual(ec, 2)
            b = json.loads(out.read_text(encoding="utf-8"))
            self.assertTrue(any("duplicate" in e for e in b["pre_exec_gates"]["errors"]))


if __name__ == "__main__":
    unittest.main()
