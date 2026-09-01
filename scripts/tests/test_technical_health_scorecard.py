#!/usr/bin/env python3
import importlib.util
import unittest
from pathlib import Path


SCRIPT = Path(__file__).parents[1] / "radars/technical-health-scorecard.py"
SPEC = importlib.util.spec_from_file_location("technical_health_scorecard", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class TechnicalHealthScorecardTest(unittest.TestCase):
    def test_flatten_pages(self):
        self.assertEqual(MODULE.flatten_pages([[{"id": 1}], [{"id": 2}]]), [{"id": 1}, {"id": 2}])

    def test_ci_failure_rate_ignores_skipped_and_incomplete(self):
        runs = [
            {"status": "completed", "conclusion": "success"},
            {"status": "completed", "conclusion": "failure"},
            {"status": "completed", "conclusion": "skipped"},
            {"status": "in_progress", "conclusion": None},
        ]
        self.assertEqual(MODULE.ci_failure_rate(runs), {"sample_size": 2, "failures": 1, "failure_rate_pct": 50.0, "status": "ok"})

    def test_coverage_runs_use_distinct_revisions(self):
        runs = [
            {"id": 3, "head_sha": "same", "status": "completed", "conclusion": "success"},
            {"id": 2, "head_sha": "same", "status": "completed", "conclusion": "success"},
            {"id": 1, "head_sha": "older", "status": "completed", "conclusion": "success"},
        ]
        self.assertEqual([run["id"] for run in MODULE.distinct_successful_runs(runs)], [3, 1])

    def test_baseline_count_and_recurrence_families(self):
        self.assertEqual(MODULE.baseline_count("parameters:\n\tignoreErrors:\n\t\t-\n\t\t-\n"), 2)
        text = "# 🔁 復發家族\n| **F1 狀態** | x |\n| **F7 帳務** | y |\n"
        self.assertEqual(MODULE.recurrence_families(text), {"count": 2, "families": ["F1", "F7"]})

    def test_hot_files_counts_bug_fix_commits_not_lines(self):
        log = "\n".join(
            [
                "commit\0fix: first",
                "frontend/src/A.vue",
                "frontend/src/A.vue",
                "commit\0docs: no",
                "frontend/src/B.vue",
                "commit\0repair: second",
                "backend/app/X.php",
            ]
        )
        self.assertEqual(MODULE.hot_files(log)["files"], [{"path": "frontend/src/A.vue", "bug_fix_commits": 1}, {"path": "backend/app/X.php", "bug_fix_commits": 1}])

    def test_open_tech_debt_produces_two_candidates(self):
        issues = [
            {"number": 1, "title": "p2", "created_at": "2026-01-01", "labels": [{"name": "type:tech-debt"}, {"name": "priority:p2"}]},
            {"number": 2, "title": "p1", "created_at": "2026-02-01", "labels": [{"name": "type:tech-debt"}, {"name": "priority:p1"}]},
            {"number": 3, "title": "bug", "created_at": "2026-01-01", "labels": [{"name": "priority:p1"}]},
        ]
        result = MODULE.open_tech_debt(issues)
        self.assertEqual(result["count"], 2)
        self.assertEqual([item["number"] for item in result["roadmap_candidates"]], [2, 1])


if __name__ == "__main__":
    unittest.main()
