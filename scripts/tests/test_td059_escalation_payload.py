#!/usr/bin/env python3
"""Fixture test: TD-059 escalate payload when signal>0 (no GitHub Issue side effects)."""
from __future__ import annotations

import json
import unittest


def build_escalation(metrics: dict) -> dict | None:
    hit = int(metrics.get("partial_minutes_signal") or 0) > 0
    if not hit:
        return None
    return {
        "action": "escalate_p1",
        "issue": 1343,
        "auto_schema_migration": False,
        "evidence": {
            "partial_minutes_signal": metrics.get("partial_minutes_signal"),
            "partial_minutes_reverse": metrics.get("partial_minutes_reverse"),
            "personal_partial_member_courses": metrics.get("personal_partial_member_courses"),
            "multi_member_packages": metrics.get("multi_member_packages"),
            "null_minutes_deduct_blind_spot": metrics.get("null_minutes_deduct_blind_spot"),
            "blind_spot": metrics.get("blind_spot"),
        },
    }


class Td059EscalationPayloadTest(unittest.TestCase):
    def test_clean_no_payload(self):
        self.assertIsNone(build_escalation({
            "partial_minutes_signal": 0,
            "personal_partial_member_courses": 0,
            "multi_member_packages": 46,
        }))

    def test_signal_builds_p1_payload_without_schema(self):
        p = build_escalation({
            "partial_minutes_signal": 3,
            "partial_minutes_reverse": 1,
            "personal_partial_member_courses": 2,
            "multi_member_packages": 46,
            "null_minutes_deduct_blind_spot": 390,
            "blind_spot": "null minutes historical",
        })
        self.assertIsNotNone(p)
        assert p is not None
        self.assertEqual(p["action"], "escalate_p1")
        self.assertEqual(p["issue"], 1343)
        self.assertFalse(p["auto_schema_migration"])
        self.assertEqual(p["evidence"]["partial_minutes_signal"], 3)
        # Must stay de-identified
        blob = json.dumps(p, ensure_ascii=False)
        self.assertNotIn("student", blob.lower())
        self.assertNotIn("姓名", blob)


if __name__ == "__main__":
    unittest.main()
