#!/usr/bin/env python3
"""Unit tests for outbound-readiness-gate.py."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

GATE = Path(__file__).resolve().parents[2] / "scripts" / "outbound-readiness-gate.py"


def run_gate(tracker: dict, mode: str = "schema", delivery: dict | None = None):
    with tempfile.TemporaryDirectory() as td:
        tpath = Path(td) / "t.json"
        tpath.write_text(json.dumps(tracker), encoding="utf-8")
        cmd = [sys.executable, str(GATE), "--tracker", str(tpath), "--mode", mode]
        if delivery is not None:
            dpath = Path(td) / "d.json"
            dpath.write_text(json.dumps(delivery), encoding="utf-8")
            cmd += ["--delivery-artifact", str(dpath)]
        p = subprocess.run(cmd, capture_output=True, text=True)
        return p.returncode, json.loads(p.stdout)


def base(**o):
    t = {
        "channel": {
            "not_done_when": "artifact generated only",
            "forbidden": ["treat artifact generated as delivery complete"],
        },
        "campuses": [{
            "campus_id": 3, "status": "awaiting_delivery",
            "channel": "dm", "csv_sha256": "a", "artifact_run": "1",
            "delivered_at": None, "acknowledged_at": None,
        }],
        "platform_ops_delivery_task": {"owner": "platform-ops", "single_step": "x", "status": "open"},
        "outbound_readiness": {
            "recipient_configured": False, "channel_health": False, "test_delivery": False,
            "acknowledgment_path": True, "pii_policy": True, "activation_allowed": False,
        },
    }
    t.update(o)
    return t


class T(unittest.TestCase):
    def test_schema_ok(self):
        self.assertEqual(run_gate(base())[0], 0)

    def test_activation_blocked(self):
        self.assertEqual(run_gate(base(), mode="activation")[0], 2)

    def test_activation_pass(self):
        t = base(outbound_readiness={k: True for k in (
            "recipient_configured", "channel_health", "test_delivery",
            "acknowledgment_path", "pii_policy", "activation_allowed")})
        self.assertEqual(run_gate(t, mode="activation")[0], 0)

    def test_skip_no_line(self):
        code, r = run_gate(base(), delivery={"delivered": [{"campus_id": 3, "status": "skipped_no_line", "has_group": False}]})
        self.assertEqual(code, 2)
        self.assertIn("campus_3_channel_not_configured", r["errors"])

    def test_delivery_complete_blocked(self):
        self.assertEqual(run_gate(base(), mode="delivery_complete")[0], 2)


if __name__ == "__main__":
    unittest.main()
