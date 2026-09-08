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


if __name__ == "__main__":
    unittest.main()
