import unittest
from pathlib import Path


WORKFLOW = Path(__file__).parents[2] / ".github/workflows/bug-detail-dump.yml"


class BugDetailDumpContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")

    def test_fixed_probes_are_guarded_by_their_bug_ids(self):
        source = self.source
        package_gate = source.index('if ($bugId === 208) {{')
        projection_gate = source.index('if ($bugId === 241) {{')
        self.assertGreater(source.index('course_packages', package_gate), package_gate)
        self.assertLess(source.index('course_packages', package_gate), projection_gate)
        self.assertNotIn('course_packages', source[:package_gate])
        self.assertNotIn('package_124', source[projection_gate:])

    def test_envelope_declares_target_and_decision_grade(self):
        source = self.source
        for marker in (
            '"requested_bug_id" => (int)$bugId',
            '"probe_results" => [',
            '"evidence_generated_at" => gmdate("c")',
            '"read_only" => true',
            '"pii_redacted" => true',
            '"decision_grade_required" => $decisionGradeRequired',
            '"decision_grade" => $decisionGrade',
        ):
            self.assertIn(marker, source)

    def test_known_unmapped_reports_are_explicitly_not_applicable(self):
        source = self.source
        self.assertIn('"probe_247_calendar_capacity"', source)
        self.assertIn('"probe_249_shared_subject_payment"', source)
        self.assertIn('"decision_grade_required" => $decisionGradeRequired', source)
        self.assertIn('target-correct probe is required; evidence is not decision-grade', source)

    def test_parser_rejects_ambiguous_or_mismatched_output(self):
        source = self.source
        self.assertIn('expected exactly one JSON evidence envelope', source)
        self.assertIn('requested_bug_id does not match dispatched bug_id', source)
        self.assertIn('if: always()', source)


if __name__ == "__main__":
    unittest.main()
