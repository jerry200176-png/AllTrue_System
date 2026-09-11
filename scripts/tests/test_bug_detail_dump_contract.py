import unittest
from pathlib import Path


WORKFLOW = Path(__file__).parents[2] / ".github/workflows/bug-detail-dump.yml"


class BugDetailDump278281ContractTest(unittest.TestCase):
    def test_candidate_probe_is_bounded_and_redacted(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        gate = source.index('if (in_array($bugId, [278, 281], true)) {{')
        for marker in (
            '"campus_ids" => [9, 13]',
            '"2026-07-22", "2026-08-12"',
            '"class_type" => "one_on_three"',
            '"subject_name_contains" => "物理"',
            '"candidate_resolution"',
            '"candidate_ref" => substr(hash("sha256"',
            '"probe_278_281_cancelled_makeup_candidates" => $probe278281',
        ):
            self.assertIn(marker, source[gate:])
        candidate_block = source[gate:source.index('$probe272 = [', gate)]
        self.assertNotIn('student_name', candidate_block)
        self.assertNotIn('teacher_name', candidate_block)
        self.assertNotIn('"class_id" =>', candidate_block)
        self.assertNotIn('"session_id" =>', candidate_block)


if __name__ == "__main__":
    unittest.main()
