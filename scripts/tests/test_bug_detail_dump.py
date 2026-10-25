import base64
import json
import re
import subprocess
import unittest
from pathlib import Path

import yaml

if __package__:
    from .test_dump_ids_only_contract import post_process
else:
    from test_dump_ids_only_contract import post_process


WORKFLOW = Path(__file__).parents[2] / ".github/workflows/bug-detail-dump.yml"


class BugDetailDumpContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")

    def test_encrypted_detail_preserves_stored_browser_diagnostics(self):
        # Exercise the actual emitted PHP projection without running its DB/SSH
        # probe. These are stored strings, as in the normal triage detail API.
        steps = yaml.safe_load(self.source)["jobs"]["dump"]["steps"]
        run = next(s["run"] for s in steps if s.get("name", "").startswith("Dump bug"))
        template = re.search(r"python3 - <<'PY' > /tmp/bug207_probe\.php\n(.*?)\nPY\n", run, re.S).group(1)
        rendered = subprocess.run(
            ["python3", "-W", "ignore", "-"], input=template, capture_output=True,
            text=True, env={"PATH": "/usr/bin:/bin", "BUG_ID": "7"}, check=True,
        ).stdout
        projection = rendered[rendered.rindex("echo json_encode(["):]
        variables = sorted(set(re.findall(r"\$([A-Za-z_][A-Za-z0-9_]*)", projection)))
        context = json.dumps({
            "buildSha": "a" * 40,
            "recentApiFailures": [{"method": "PATCH", "path": "/api/v1/example", "status": 422,
                                   "requestId": "synthetic-request-reference"}],
            "recentClicks": [{"label": "取消本堂"}],
            "recentMessages": [{"kind": "alert", "text": "synthetic-private-diagnostic"}],
        }, ensure_ascii=False)
        for stored in (context, None, "", "legacy non-JSON diagnostic"):
            with self.subTest(stored=stored):
                fixture = base64.b64encode(json.dumps({
                    "id": 7, "status": "new", "severity": "medium", "CampusID": 1,
                    "page_key": "calendar", "reporter_user_id": 2, "created_at": "2026-01-01",
                    "updated_at": "2026-01-01", "client_info": stored,
                }).encode()).decode()
                setup = "\n".join(f"${name} = null;" for name in variables)
                setup += f'\n$bug = json_decode(base64_decode("{fixture}"));\n'
                result = subprocess.run(["php"], input="<?php\n" + setup + projection,
                                        capture_output=True, text=True, check=True)
                detail = json.loads(result.stdout)
                self.assertIn("client_info", detail["bug"])
                self.assertEqual(detail["bug"]["client_info"], stored)

    def test_browser_diagnostics_remain_in_encrypted_artifact_only(self):
        sentinel = "synthetic-private-browser-diagnostic"
        envelope = {"ok": True, "requested_bug_id": 7,
                    "bug": {"id": 7, "status": "new", "client_info": sentinel},
                    "attachments": [], "comments": [], "status_logs": [], "reporter_history": [],
                    "diagnostics": {"decision_grade_required": False, "decision_grade": False}}
        result, encrypted, public, recovered = post_process(
            "bug-detail-dump.yml", "Dump bug", "bug-detail.log",
            json.dumps(envelope) + "\n", {"BUG_ID": "7"},
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(encrypted.returncode, 0, encrypted.stderr)
        self.assertNotIn(sentinel, result.stdout + result.stderr + encrypted.stdout + encrypted.stderr)
        for name, content in public.items():
            self.assertNotIn(sentinel.encode(), content, name)
        self.assertIn(sentinel, recovered)

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
            '"probe_359_cross_campus_source" => $probe359',
        ):
            self.assertIn(marker, source)
        self.assertIn('$probeRefKey359 = random_bytes(32);', block)
        self.assertEqual(2, block.count('->map(function ($row) use ($probeRefKey359) {{'))
        self.assertEqual(4, block.count('hash_hmac("sha256",'))
        self.assertNotIn('hash("sha256",', block)
        self.assertNotIn('"probe_ref_key"', block)
        for field in ('"teacher_name" =>', '"student_name" =>', '"teacher_id" =>', '"student_id" =>'):
            self.assertNotIn(field, block)
        for write in ('->insert(', '->update(', '->delete(', '->save('):
            self.assertNotIn(write, block)
        self.assertIn('359], true)', source)

    def test_bug_359_discards_partial_evidence_on_source_overflow(self):
        source = self.source
        gate = source.index('if ($bugId === 359) {{')
        end = source.index('// #338-specific bounded capacity probe', gate)
        block = source[gate:end]
        busy_map = block.index('$busySlots359 = collect($busy359)')
        busy_limit = block.index('if (count($busySlots359) > 100)')
        busy_output = block.index('$probe359["busy_slots"] = $busySlots359;')
        self.assertLess(busy_map, busy_limit)
        self.assertLess(busy_limit, busy_output)
        catch = block[block.index('catch (\\Throwable $e) {{'):]
        for field in ('busy_slots', 'class_sessions', 'schedules'):
            self.assertIn(f'$probe359["{field}"] = [];', catch)

    def test_parser_rejects_ambiguous_or_mismatched_output(self):
        source = self.source
        self.assertIn('expected exactly one JSON evidence envelope', source)
        self.assertIn('requested_bug_id does not match dispatched bug_id', source)
        self.assertIn('if: always()', source)


    def test_probe_376_is_bug_scoped_read_only_and_name_free(self):
        source = self.source
        gate = source.index('if ($bugId === 376) {{')
        end = source.index('// #359 read-only source check', gate)
        block = source[gate:end]
        self.assertIn('if ((int)$bug->CampusID !== 3)', block)
        self.assertIn('target not uniquely resolved', block)
        self.assertIn('$refKey376 = random_bytes(32);', block)
        self.assertIn('"probe_376_teacher_reassign" => $probe376', source)
        self.assertIn('376 => $probe376,', source)
        # Names are matched server-side against the report text and never emitted.
        # Output statements only (guard *inputs* may carry IDs; they are never returned).
        output = "\n".join(l for l in block.splitlines() if '$probe376[' in l or 'return [' in l or l.strip().startswith('"'))
        for field in ('"teacher_name" =>', '"student_name" =>', '"teacher_id" =>', '"student_id" =>', '"name" =>', '"Name" =>'):
            self.assertNotIn(field, output)
        self.assertIn('session row limit exceeded', block)
        self.assertIn('identity candidate cap reached', block)
        self.assertNotIn('$e->getMessage();', block.split('$known376')[1] if '$known376' in block else block)
        self.assertIn('read_error:', block)
        self.assertIn('OccurrenceAssignmentService::onLeave', block)
        self.assertIn('"restore_original"', block)
        self.assertIn('"past_bookkeeping"', block)
        for marker in ('"incomplete_times"', '"campus_binding_rejected"', '"on_leave_rejected"', 'enabledFor(3)'):
            self.assertIn(marker, block)
        self.assertIn('teacher_branches', block)
        self.assertIn('validateScheduleOccurrence', block)
        self.assertIn('teacherForOccurrence', block)
        for write in ('->insert(', '->update(', '->delete(', '->save(', '->create('):
            self.assertNotIn(write, block)

if __name__ == "__main__":
    unittest.main()
