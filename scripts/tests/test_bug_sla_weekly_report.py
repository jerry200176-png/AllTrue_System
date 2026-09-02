import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "bug-sla-weekly-report.yml"


class WeeklyBugSlaReportContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_is_scheduled_and_manually_dispatchable(self):
        self.assertIn("schedule:", self.workflow)
        self.assertIn('cron: "0 1 * * 1"', self.workflow)
        self.assertIn("workflow_dispatch:", self.workflow)

    def test_permissions_and_artifact_retention_are_bounded(self):
        self.assertIn("permissions:\n  contents: read", self.workflow)
        self.assertIn("name: bug-sla-weekly", self.workflow)
        self.assertIn("path: out/bug-sla-weekly.json", self.workflow)
        self.assertIn("retention-days: 90", self.workflow)

    def test_snapshot_contract_is_aggregate_and_redacted(self):
        for field in (
            '"schema_version" => "bug-sla-weekly-v1"',
            '"generated_at" =>',
            '"read_only" => true',
            '"pii_redacted" => true',
            '"status_counts" =>',
            '"open_backlog" =>',
            '"missing_triaged_at" =>',
            '"open_breaches" =>',
        ):
            self.assertIn(field, self.workflow)
        for forbidden in ('"title"', '"reporter_user_id"', '"attachment_ids"', '"CampusID"'):
            self.assertNotIn(forbidden, self.workflow)

    def test_remote_command_is_read_only(self):
        self.assertIn("bash <<'REMOTE'", self.workflow)
        self.assertIn("\n          REMOTE\n", self.workflow)
        for forbidden in ("->update(", "->delete(", "->insert(", "close-stale", "reporter-verify", "cache:clear"):
            self.assertNotIn(forbidden, self.workflow)
        self.assertIn('source" => "production:bug_reports+bug_report_status_logs"', self.workflow)


if __name__ == "__main__":
    unittest.main()
