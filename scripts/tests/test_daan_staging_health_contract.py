#!/usr/bin/env python3
"""Regression: staging health verifier matches canonical status=ok contract."""

from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "infra" / "daan-staging" / "lifecycle" / "health_contract.py"
HEALTH_SH = ROOT / "infra" / "daan-staging" / "lifecycle" / "health.sh"


def _load():
    spec = importlib.util.spec_from_file_location("health_contract", CONTRACT)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class StagingHealthContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mod = _load()

    def test_status_ok_passes(self) -> None:
        self.assertTrue(self.mod.health_payload_is_ok('{"status":"ok"}'))
        self.assertTrue(
            self.mod.health_payload_is_ok(
                '{"status":"ok","timestamp":"2026-09-17T06:00:00Z"}'
            )
        )

    def test_legacy_ok_true_is_not_canonical(self) -> None:
        # Application API uses status=ok; verifier must not accept ok:true alone.
        self.assertFalse(self.mod.health_payload_is_ok('{"ok":true}'))
        self.assertFalse(self.mod.health_payload_is_ok('{"ok": true}'))

    def test_malformed_and_unhealthy_fail_closed(self) -> None:
        for body in (
            "",
            "not-json",
            "{}",
            '{"status":"error"}',
            '{"status":"OK"}',
            "[]",
            '{"status":null}',
            None,
        ):
            with self.subTest(body=body):
                self.assertFalse(self.mod.health_payload_is_ok(body))

    def test_health_sh_uses_contract_module_not_ok_true_grep(self) -> None:
        text = HEALTH_SH.read_text(encoding="utf-8")
        self.assertIn("health_contract.py", text)
        self.assertNotIn('grep -q', text)
        self.assertNotIn('"ok":true', text)
        self.assertNotIn('"ok": true', text)


if __name__ == "__main__":
    unittest.main()
