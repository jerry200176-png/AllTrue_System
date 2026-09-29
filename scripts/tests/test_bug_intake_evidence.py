import json
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).parents[2]
VALIDATOR = ROOT / "scripts/validate-bug-intake-evidence.py"
WORKFLOW = ROOT / ".github/workflows/bug-detail-dump.yml"


class BugIntakeEvidenceTest(unittest.TestCase):
    def validate(self, *, history=None, total=2, limit=250, complete=True, omit_coverage=False):
        if history is None:
            history = [{"id": 356}, {"id": 357}]
        detail = {
            "bug": {"id": 356}, "attachments": [], "comments": [], "status_logs": [],
            "reporter_history": history, "reporter_history_comments": [],
            "reporter_history_status_logs": [],
        }
        if not omit_coverage:
            detail.update(reporter_history_total=total, reporter_history_limit=limit,
                          reporter_history_complete=complete)
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            files = {
                "queue-meta": {"queue_dump_run_id": "test-run", "dump_generated_at": datetime.now(timezone.utc).isoformat()},
                "queue-open": [{"id": 356, "status": "new"}],
                "detail": detail,
            }
            command = [sys.executable, str(VALIDATOR)]
            for name, payload in files.items():
                path = directory / f"{name}.json"
                path.write_text(json.dumps(payload), encoding="utf-8")
                command.extend((f"--{name}", str(path)))
            return subprocess.run(command + ["--bug-id", "356"], capture_output=True, text=True, check=False)

    def test_complete_history_passes(self):
        result = self.validate()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(json.loads(result.stdout)["reporter_history_complete"])

    def test_truncated_history_fails_closed(self):
        result = self.validate(total=251, complete=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("reporter history is incomplete", result.stderr)

    def test_claimed_complete_but_count_mismatch_fails(self):
        result = self.validate(total=3)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("reporter history is incomplete", result.stderr)

    def test_duplicate_history_fails(self):
        result = self.validate(history=[{"id": 356}, {"id": 356}])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("duplicate rows", result.stderr)

    def test_old_artifact_without_coverage_fails(self):
        result = self.validate(omit_coverage=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("missing SOP fields", result.stderr)

    def test_workflow_declares_bounded_coverage(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("$reporterHistoryLimit = 250;", source)
        self.assertIn("->limit($reporterHistoryLimit + 1)", source)
        self.assertIn('"reporter_history_total" => (int)$reporterHistoryTotal', source)
        self.assertIn('"reporter_history_complete" => $reporterHistoryComplete', source)


if __name__ == "__main__":
    unittest.main()
