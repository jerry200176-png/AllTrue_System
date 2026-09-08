"""Focused contracts for Phase 1 workflow consolidation."""

from pathlib import Path
import unittest


ROOT = Path(__file__).parents[2]


class GovernanceSimplificationTest(unittest.TestCase):
    def read(self, relative):
        return (ROOT / relative).read_text(encoding="utf-8")

    def test_control_plane_has_unique_canonical_execution(self):
        ci = self.read(".github/workflows/ci.yml")
        self.assertNotIn("control-plane-enforce.yml", ci)
        self.assertIn("node scripts/control-plane-lint.mjs", ci)
        self.assertIn("Test control plane lint catches violations", ci)
        self.assertIn('test "$status" -eq 1', ci)
        self.assertFalse((ROOT / ".github/workflows/control-plane-enforce.yml").exists())

    def test_golden_report_has_one_executable_source(self):
        ci = self.read(".github/workflows/ci.yml")
        presubmit = self.read(".github/workflows/presubmit.yml")
        report = "./.github/scripts/golden-ci-report.sh"
        self.assertEqual(ci.count(report), 1)
        self.assertNotIn(report, presubmit)
        self.assertIn("Golden scenarios report", ci)

    def test_required_status_names_are_not_rewritten(self):
        ci = self.read(".github/workflows/ci.yml")
        presubmit = self.read(".github/workflows/presubmit.yml")
        self.assertIn("name: Control Plane Contract Lint", ci)
        self.assertIn("name: Golden scenarios report", ci)
        self.assertIn("name: Presubmit Checks", presubmit)

    def test_verified_case_specific_workflow_is_retired_with_evidence(self):
        inventory = self.read("docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json")
        self.assertNotIn("234-renewal-overlap-repair.yml\": {\n      \"classification\"", inventory)
        self.assertIn("\"retired_workflows\"", inventory)
        self.assertIn("234-renewal-overlap-repair.yml", inventory)
        self.assertIn("31685594666", inventory)
        self.assertFalse((ROOT / ".github/workflows/234-renewal-overlap-repair.yml").exists())

    def test_completed_bug_evidence_backfill_is_retired_with_closeout_evidence(self):
        inventory = self.read("docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json")
        self.assertNotIn("bug-legacy-evidence-backfill.yml\": {\n      \"classification\"", inventory)
        self.assertIn("bug-legacy-evidence-backfill.yml", inventory)
        self.assertIn("34114112625", inventory)
        self.assertIn("34114244243", inventory)
        self.assertFalse((ROOT / ".github/workflows/bug-legacy-evidence-backfill.yml").exists())


if __name__ == "__main__":
    unittest.main()
