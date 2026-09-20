#!/usr/bin/env python3
"""Synthetic-only tests for the bounded capacity log collector."""

from __future__ import annotations

import gzip
import io
import json
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.infra.capacity_log_aggregate import (
    ReadBudget,
    Window,
    enumerate_sources,
    parse_access,
    parse_perf,
    safe_route_template,
)


class CapacityLogAggregateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.window = Window(
            start=__import__("datetime").datetime.fromisoformat(
                "2026-09-13T00:00:00+08:00"
            ),
            end=__import__("datetime").datetime.fromisoformat(
                "2026-09-20T00:00:00+08:00"
            ),
        )

    def budget(self, **overrides: object) -> ReadBudget:
        values = {
            "max_bytes": 128 * 1024 * 1024,
            "max_line_bytes": 4096,
            "max_records": 1000,
            "deadline": time.monotonic() + 10,
        }
        values.update(overrides)
        return ReadBudget(**values)

    def test_perf_filters_by_record_time_and_deduplicates(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = [
                '[2026-09-12T16:00:00Z] production.INFO: perf_metric '
                '{"trace_id":"day-13","method":"GET","path":"api/v1/class-sessions/10",'
                '"status":200,"duration_ms":100}',
                '[2026-09-19T15:59:59Z] production.INFO: perf_metric '
                '{"trace_id":"day-19","method":"GET","path":"api/v1/students/10",'
                '"status":500,"duration_ms":300}',
                '[2026-09-20T00:00:00Z] production.INFO: perf_metric '
                '{"trace_id":"outside","method":"GET","path":"api/v1/students/10",'
                '"status":200,"duration_ms":1}',
                '[2026-09-19T15:59:59Z] production.WARNING: [slo-breach] '
                '{"trace_id":"day-19"}',
            ]
            plain = ("\n".join(lines) + "\n").encode()
            (root / "perf-2026-09-13.log").write_bytes(plain)
            gzip_record = (
                '[2026-09-14T00:00:00+08:00] production.INFO: perf_metric '
                '{"trace_id":"day-14","method":"GET","path":"api/v1/class-sessions/10",'
                '"status":200,"duration_ms":200}\n'
                '[2026-09-13T00:00:00+08:00] production.INFO: perf_metric '
                '{"trace_id":"day-13","method":"GET","path":"api/v1/class-sessions/10",'
                '"status":200,"duration_ms":100}\n'
            ).encode()
            (root / "perf-2026-09-14.log.gz").write_bytes(gzip.compress(gzip_record))
            selected, _ = enumerate_sources(root, "perf", self.window)
            result = parse_perf(selected, self.window, self.budget())

            self.assertEqual(result["request_count"], 3)
            self.assertEqual(result["duplicate_events_removed"], 1)
            self.assertEqual(result["status"], {"200": 2, "500": 1})
            self.assertEqual(len(result["routes"]), 2)

    def test_invalid_values_and_unknown_sensitive_routes_are_safe(self) -> None:
        self.assertEqual(safe_route_template("/api/v1/students/123?email=secret@example.test"), "/api/v1/students")
        self.assertEqual(safe_route_template("/unknown/secret/123"), "OTHER")

    def test_apache_filters_record_time_and_gzip(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            access = (
                '127.0.0.1 - - [13/Sep/2026:16:00:00 +0000] '
                '"GET /api/v1/students/9?email=secret@example.test HTTP/1.1" 200 10 "-" "-"\n'
                '127.0.0.1 - - [20/Sep/2026:00:00:00 +0000] '
                '"GET /api/v1/students/9 HTTP/1.1" 200 10 "-" "-"\n'
            ).encode()
            (root / "alltrue_access.log.gz").write_bytes(gzip.compress(access))
            selected, _ = enumerate_sources(root, "alltrue_access", self.window)
            result = parse_access(selected, self.window, self.budget())
            self.assertEqual(result["request_count"], 1)
            self.assertEqual(result["observed_record_days"], ["2026-09-14"])
            self.assertNotIn("secret", json.dumps(result))

    def test_missing_permission_budget_and_timeout_are_explicit(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "perf-2026-09-13.log").write_bytes(
                b"[2026-09-13T00:00:00+08:00] production.INFO: perf_metric "
                b'{"trace_id":"x","method":"GET","path":"/api/v1/students/1",'
                b'"status":200,"duration_ms":10}\n'
            )
            selected, inventory = enumerate_sources(root, "perf", self.window)
            result = parse_perf(
                selected,
                self.window,
                self.budget(max_bytes=8),
            )
            self.assertTrue(result["request_count"] == 0)
            self.assertTrue(result["unreadable_files"] == [] or result["parse_errors"] >= 0)
            self.assertIn("inventory_count", inventory)

    def test_permission_denied_invalid_gzip_and_timeout_are_not_silent(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            valid = root / "perf-2026-09-13.log"
            invalid = root / "perf-2026-09-14.log.gz"
            valid.write_bytes(b"not a perf record\n")
            invalid.write_bytes(b"not gzip")
            selected, _ = enumerate_sources(root, "perf", self.window)

            with patch(
                "scripts.infra.capacity_log_aggregate.open_source",
                side_effect=PermissionError("synthetic permission denial"),
            ):
                denied_budget = self.budget()
                denied = parse_perf(selected, self.window, denied_budget)
            self.assertEqual(denied["permission_denied_files"], [path.name for path in selected])

            invalid_result = parse_perf([invalid], self.window, self.budget())
            self.assertEqual(invalid_result["unreadable_files"][0]["file"], invalid.name)

            timeout_budget = self.budget(deadline=time.monotonic() - 1)
            parse_perf([valid], self.window, timeout_budget)
            self.assertTrue(timeout_budget.timeout)


if __name__ == "__main__":
    unittest.main()
