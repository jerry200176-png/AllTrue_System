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
        self.assertIn('if ($bugId === 251) {{', source)
        self.assertIn('"probe_251_contract_session_count_payment"', source)
        self.assertIn('if ($bugId === 253) {{', source)
        self.assertIn('"probe_253_teacher_capacity_orphan_exception"', source)
        self.assertIn('if ($bugId === 272) {{', source)
        self.assertIn('"teacher_same_slot_three_course_report_272"', source)
        self.assertIn('"three_course_materialized_slots"', source)
        self.assertIn('collectTeacherBusySlotsWithCapacity', source)
        self.assertIn('"capacity_slot_checks"', source)
        self.assertIn('"session_records"', source)
        self.assertIn('"session_count_summaries"', source)
        self.assertIn('"decision_grade_required" => $decisionGradeRequired', source)
        self.assertIn('target-correct probe is required; evidence is not decision-grade', source)

    def test_bug_338_probe_is_targeted_and_redacted(self):
        source = self.source
        gate = source.index('if ($bugId === 338) {{')
        target_end = source.index('$targetProbe = match ($bugId)', gate)
        block = source[gate:target_end]
        for marker in (
            'current_bug_description_runtime_match',
            'collectTeacherBusySlotsWithCapacity',
            'with_reported_student_excluded',
            'without_student_exclusion',
            'matched_teacher_target_slot_count',
            'TRIM(name) LIKE ?',
            'target_slot_has_remaining_capacity_after_excluding_student',
            'target_slot_full_after_excluding_student',
            'target_one_on_three_slot_not_found',
            '"probe_338_target_capacity" => $probe338',
        ):
            self.assertIn(marker, source)
        self.assertNotIn('"teacher_name" =>', block)
        self.assertNotIn('"student_name" =>', block)
        self.assertNotIn('teacher_id" =>', block)
        self.assertNotIn('student_id" =>', block)
        self.assertRegex(source, r'338(?:,\s*\d+)*\], true\)')

    def test_bug_359_source_probe_is_bounded_and_redacted(self):
        source = self.source
        gate = source.index('if ($bugId === 359) {{')
        end = source.index('// #338-specific bounded capacity probe', gate)
        block = source[gate:end]
        for marker in (
            '"date" => "2026-09-26"',
            '"viewing_campus_id" => (int)$bug->CampusID',
            'where("id", 29)->where("type", "T")',
            'collectTeacherBusySlotsWithCapacity',
            '->limit(101)',
            'source row limit exceeded',
            '"session_ref" => substr(hash("sha256"',
            '"schedule_ref" => substr(hash("sha256"',
            '"probe_359_cross_campus_source" => $probe359',
        ):
            self.assertIn(marker, source)
        for field in ('"teacher_name" =>', '"student_name" =>', '"teacher_id" =>', '"student_id" =>'):
            self.assertNotIn(field, block)
        for write in ('->insert(', '->update(', '->delete(', '->save('):
            self.assertNotIn(write, block)
        self.assertIn('359], true)', source)

    def test_parser_rejects_ambiguous_or_mismatched_output(self):
        source = self.source
        self.assertIn('expected exactly one JSON evidence envelope', source)
        self.assertIn('requested_bug_id does not match dispatched bug_id', source)
        self.assertIn('if: always()', source)


if __name__ == "__main__":
    unittest.main()
