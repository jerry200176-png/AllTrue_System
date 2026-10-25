import base64
import json
import os
import re
import subprocess
import tarfile
import tempfile
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

    def rendered_php(self, bug_id=7):
        steps = yaml.safe_load(self.source)["jobs"]["dump"]["steps"]
        run = next(s["run"] for s in steps if s.get("name", "").startswith("Dump bug"))
        template = re.search(r"python3 - <<'PY' > /tmp/bug207_probe\.php\n(.*?)\nPY\n", run, re.S).group(1)
        return subprocess.run(
            ["python3", "-W", "ignore", "-"], input=template, capture_output=True,
            text=True, env={"PATH": "/usr/bin:/bin", "BUG_ID": str(bug_id)}, check=True,
        ).stdout

    def test_encrypted_detail_preserves_stored_browser_diagnostics(self):
        # Exercise the actual emitted PHP projection without running its DB/SSH
        # probe. These are stored strings, as in the normal triage detail API.
        rendered = self.rendered_php()
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
                    "diagnostics": {"decision_grade_required": False, "decision_grade": False,
                                    "probe_results": {"probe_433_leave_tail": {"private_sentinel": sentinel}}}}
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

    def test_failed_evidence_gate_preserves_encrypted_diagnostics(self):
        # Run the shipped shell with errexit, unlike the older helper that runs
        # Python and encryption independently and misses a failure between them.
        steps = yaml.safe_load(self.source)["jobs"]["dump"]["steps"]
        run = next(s["run"] for s in steps if s.get("name", "").startswith("Dump bug"))
        transport = run.split("\nREMOTE\n", 1)[1]
        sentinel = "synthetic-private-failed-probe"
        for label, requested_id, grade, raw in (
            ("success", 433, True, None),
            ("failed_probe", 433, False, None),
            ("wrong_target", 7, True, None),
            ("missing_envelope", 433, True, sentinel),
        ):
            with self.subTest(case=label), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / "raw").mkdir()
                (root / "out").mkdir()
                envelope = {"ok": True, "requested_bug_id": requested_id,
                            "bug": {"id": requested_id, "title": sentinel},
                            "diagnostics": {"decision_grade_required": True,
                                            "decision_grade": grade,
                                            "probe_results": {"private_error": sentinel,
                                                "probe_433_leave_tail": {"decision_grade": False,
                                                    "error": "student_slot_guard_failed" if label == "failed_probe" else sentinel}}}}
                (root / "raw/bug-detail.log").write_text(
                    raw or json.dumps(envelope) + "\n", encoding="utf-8")
                env = {**os.environ, "BUG_ID": "433", "DUMP_ARTIFACT_KEY": "synthetic-test-key"}
                result = subprocess.run(["bash", "-euo", "pipefail", "-c", transport],
                                        cwd=root, env=env, capture_output=True, text=True)
                self.assertEqual(result.returncode == 0, label == "success", result.stderr)
                self.assertNotIn(sentinel, result.stdout + result.stderr)
                encrypted = root / "out/bug-detail.full.tar.gz.enc"
                self.assertTrue(encrypted.is_file(), "a failed probe must keep private evidence")
                for artifact in (root / "out").iterdir():
                    self.assertNotIn(sentinel.encode(), artifact.read_bytes(), artifact.name)
                summary = json.loads((root / "out/summary.json").read_text())
                self.assertEqual(summary["probe_433_failure_stage"],
                                 "student_slot_guard_failed" if label == "failed_probe" else None)
                archive = root / "recovered.tar.gz"
                decrypted = subprocess.run(
                    ["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "600000",
                     "-pass", "env:DUMP_ARTIFACT_KEY", "-in", str(encrypted), "-out", str(archive)],
                    env=env, capture_output=True)
                self.assertEqual(decrypted.returncode, 0, decrypted.stderr)
                with tarfile.open(archive) as contents:
                    evidence = json.load(contents.extractfile("./bug-detail.json"))
                    self.assertEqual(evidence.get("requested_bug_id"), None if raw else requested_id)
                    self.assertIn(sentinel, contents.extractfile("./bug-detail.log").read().decode())

    def run_433_probe(self, fixture, bug_id=433):
        match = re.search(r"// #433 bounded read-only tail diagnosis\n(.*?)// End #433 tail diagnosis", self.rendered_php(bug_id), re.S)
        self.assertIsNotNone(match, "the report needs its own target-bound read-only diagnosis")
        encoded = base64.b64encode(json.dumps(fixture).encode()).decode()
        # Execute the actual rendered probe with synthetic DB/service doubles.
        # The doubles refuse any SQL except session controls and read-only-mode
        # checks. No Laravel bootstrap, DB, SSH or production credentials run.
        setup = r'''<?php
namespace Illuminate\Support\Facades {
  class DB { public static function connection() { $GLOBALS['calls']['connection']++; return new \ProbeConnection; } }
}
namespace App\Models {
  class StudentClass {
    public $ID = 2282, $StudentID = 1, $Stop = 0, $ScheduleMode = 'count', $scheduling_policy = 'auto_recurrence';
    public static function query() { return new \ProbeQuery('course'); }
    public function isUsageSettlementLocked() { return $GLOBALS['fixture']['settlement_locked'] ?? false; }
  }
  class ClassSession {
    public $id = 43722, $StudentClassID = 2282, $SessionDate = '2026-10-24', $Status = 'scheduled';
    public $StartTime = '10:00:00', $EndTime = '12:00:00';
    public static function query() { return new \ProbeQuery('session'); }
  }
  class Student { public static function query() { return new \ProbeQuery('student'); } }
}
namespace App\Services {
  class CourseLeaveCascadeService {
    const POLICY_KEEP_FUTURE_DATES_APPEND_TAIL = 'KEEP_FUTURE_DATES_APPEND_TAIL';
    public static function previewLeaveCascadeForCourse($id, $date, $session) {
      $GLOBALS['calls']['preview']++;
      if ($GLOBALS['fixture']['preview_error'] ?? false) throw new \RuntimeException('synthetic-private-error');
      if ([$id, $date, $session] !== [2282, '2026-10-24', 43722]) throw new \RuntimeException('wrong preview target');
      return ['policy' => self::POLICY_KEEP_FUTURE_DATES_APPEND_TAIL, 'moves' => [], 'vacated' => [],
        'future_dates_unchanged' => true, 'append' => array_key_exists('append', $GLOBALS['fixture']) ? $GLOBALS['fixture']['append'] : '2026-10-31'];
    }
    public static function resolveContractSlotTimes($course, $date) {
      $GLOBALS['calls']['times']++;
      return ['start' => '11:00:00', 'end' => '13:00:00'];
    }
  }
  class ClassSessionMaterializationService {
    public function findStudentSlotConflict($course, $date, $start, $end, $asOf = null, $exclude = [], $lock = false) {
      $GLOBALS['calls']['guard']++;
      $GLOBALS['calls']['guard_args'] = [$course->ID, $date, $start, $end, $asOf, $exclude, $lock];
      if (!($GLOBALS['fixture']['conflict'] ?? true)) return null;
      return (object) ['responseCode' => 'student_slot_conflict', 'conflictCourseId' => 4453,
        'conflictSessionId' => 99999, 'conflictScheduleId' => null, 'message' => 'synthetic-private-error'];
    }
  }
}
namespace {
  class ProbeConnection {
    private $checks = 0;
    public function statement($sql) {
      if (!in_array($sql, ['SET SESSION TRANSACTION READ ONLY', 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'], true))
        throw new \RuntimeException('unexpected SQL');
      $GLOBALS['calls']['statements'][] = $sql;
    }
    public function selectOne($sql, $bindings = [], $read = true) {
      if ($read !== false || $bindings !== [] || !in_array($sql, ['SELECT @@SESSION.transaction_read_only AS ro', 'SELECT @@SESSION.tx_read_only AS ro'], true))
        throw new \RuntimeException('unexpected read-only check');
      if (($GLOBALS['fixture']['mariadb'] ?? false) && $sql === 'SELECT @@SESSION.transaction_read_only AS ro')
        throw new \RuntimeException('synthetic-private-error');
      $this->checks++;
      $ro = ($GLOBALS['fixture']['readonly'] ?? true) && !(($GLOBALS['fixture']['lose_readonly'] ?? false) && $this->checks >= 3);
      return (object) ['ro' => $ro ? 1 : 0];
    }
    public function beginTransaction() { $GLOBALS['calls']['begin']++; }
    public function rollBack() {
      $GLOBALS['calls']['rollback']++;
      if ($GLOBALS['fixture']['rollback_error'] ?? false) throw new \RuntimeException('synthetic-private-error');
    }
  }
  class ProbeQuery {
    private $conditions = [];
    public function __construct(private $kind) {}
    public function where($field, $value) { $this->conditions[$field] = $value; return $this; }
    public function whereKey($id) { return $this->where($this->kind === 'course' ? 'ID' : 'id', $id); }
    public function whereDate($field, $value) { return $this->where($field, $value); }
    public function whereRaw($sql, $bindings) { return $this; }
    public function first() {
      if ($GLOBALS['fixture'][$this->kind . '_missing'] ?? false) return null;
      $obj = $this->kind === 'course' ? new \App\Models\StudentClass : new \App\Models\ClassSession;
      if ($this->kind === 'session' && ($GLOBALS['fixture']['wrong_target'] ?? false)) $obj->StudentClassID = 999;
      $fields = $this->kind === 'course' ? ['Stop', 'ScheduleMode', 'scheduling_policy'] : ['SessionDate', 'StartTime', 'EndTime', 'Status'];
      foreach ($fields as $field) if (array_key_exists($field, $GLOBALS['fixture'])) $obj->$field = $GLOBALS['fixture'][$field];
      foreach ($this->conditions as $k => $v) if (($obj->$k ?? null) != $v) return null;
      return $obj;
    }
    public function value($field) { return $GLOBALS['fixture']['student_campus'] ?? 9; }
    public function count() {
      if ($this->conditions !== ['StudentClassID' => 2282, 'SessionDate' => '2026-10-24']) throw new \RuntimeException('unscoped count');
      return $GLOBALS['fixture']['lessons_on_date'] ?? 1;
    }
  }
  function app($class) { return new $class; }
  $fixture = json_decode(base64_decode('FIXTURE'), true);
  $calls = ['connection' => 0, 'begin' => 0, 'rollback' => 0, 'preview' => 0, 'times' => 0, 'guard' => 0, 'statements' => []];
  $bug = (object) ['id' => 433, 'CampusID' => $fixture['report_campus'] ?? 9];
  $bugId = BUG_ID;
'''.replace("FIXTURE", encoded).replace("BUG_ID", str(bug_id))
        rendered = self.rendered_php(bug_id)
        footer = rendered[rendered.index('$targetProbe = match ($bugId)'):rendered.rindex('echo json_encode([')]
        php = setup + match.group(1) + footer + '\n$calls["envelope_required"] = $decisionGradeRequired; $calls["envelope_grade"] = $decisionGrade; echo json_encode([$probe433, $calls]);\n}\n'
        result = subprocess.run(["php"], input=php, text=True, capture_output=True, check=True)
        self.assertNotIn('synthetic-private-error', result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_433_probe_uses_preview_slot_and_readonly_conflict_guard(self):
        for lessons, start, end in [(1, '11:00:00', '13:00:00'), (2, '10:00:00', '12:00:00')]:
            with self.subTest(lessons=lessons):
                probe, calls = self.run_433_probe({'lessons_on_date': lessons})
                self.assertTrue(probe['decision_grade'])
                self.assertTrue(probe['readonly_session_verified'])
                self.assertTrue(calls['envelope_required'])
                self.assertTrue(calls['envelope_grade'])
                self.assertEqual(probe['tail'], {'date': '2026-10-31', 'start': start, 'end': end})
                self.assertEqual(probe['conflict'], {'code': 'student_slot_conflict', 'course_id': 4453, 'session_id': 99999, 'schedule_id': None})
                self.assertEqual(calls['guard_args'], [2282, '2026-10-31', start, end, None, [], False])
                self.assertEqual(calls['rollback'], 1)

    def test_433_probe_distinguishes_free_slot_from_failed_read(self):
        probe, calls = self.run_433_probe({'conflict': False})
        self.assertTrue(probe['decision_grade'])
        self.assertIsNone(probe['conflict'])
        self.assertEqual(calls['guard'], 1)
        probe, calls = self.run_433_probe({'append': None})
        self.assertTrue(probe['decision_grade'])
        self.assertIsNone(probe['tail'])
        self.assertIsNone(probe['conflict'])
        self.assertEqual(calls['guard'], 0)

    def test_433_probe_discards_failed_or_wrong_target_evidence(self):
        for fixture in ({'readonly': False}, {'wrong_target': True}, {'report_campus': 4}, {'student_campus': 4}, {'preview_error': True}, {'lose_readonly': True}, {'rollback_error': True}):
            with self.subTest(fixture=fixture):
                probe, calls = self.run_433_probe(fixture)
                self.assertFalse(probe['decision_grade'])
                self.assertFalse(probe['readonly_session_verified'])
                self.assertTrue(calls['envelope_required'])
                self.assertFalse(calls['envelope_grade'])
                self.assertNotIn('tail', probe)
                self.assertNotIn('conflict', probe)
                self.assertEqual(calls['rollback'], 0 if fixture.get('readonly') is False else 1)
                if fixture.get('readonly') is False or any(k in fixture for k in ('wrong_target', 'report_campus', 'student_campus')):
                    self.assertEqual(calls['preview'], 0)
                    self.assertEqual(calls['guard'], 0)

    def test_433_probe_verifies_mariadb_readonly_session(self):
        probe, calls = self.run_433_probe({'mariadb': True})
        self.assertTrue(probe['readonly_session_verified'])
        self.assertTrue(calls['envelope_grade'])
        self.assertEqual(calls['rollback'], 1)

    def test_433_binding_failure_identifies_existing_guard_without_preview(self):
        cases = [
            ({'report_campus': 4}, 'report_campus'),
            ({'course_missing': True}, 'course_missing'),
            ({'session_missing': True}, 'session_missing'),
            ({'wrong_target': True}, 'course_link'),
            ({'SessionDate': '2026-10-25'}, 'session_date'),
            ({'StartTime': '11:00:00'}, 'session_start'),
            ({'EndTime': '13:00:00'}, 'session_end'),
            ({'Status': 'cancelled'}, 'session_status'),
            ({'Stop': 1}, 'course_stopped'),
            ({'ScheduleMode': 'date'}, 'course_mode'),
            ({'scheduling_policy': 'manual'}, 'course_policy'),
            ({'settlement_locked': True}, 'settlement_locked'),
            ({'student_campus': 4}, 'student_campus'),
            # Preserve the old guard's order when several facts reject the case.
            ({'report_campus': 4, 'course_missing': True}, 'report_campus'),
        ]
        for fixture, reason in cases:
            with self.subTest(reason=reason, fixture=fixture):
                probe, calls = self.run_433_probe(fixture)
                self.assertFalse(probe['decision_grade'])
                self.assertEqual(probe['error'], 'target_binding_failed')
                self.assertEqual(probe.get('binding_failure'), reason)
                self.assertEqual(calls['preview'], 0)
                self.assertEqual(calls['guard'], 0)
                self.assertEqual(calls['rollback'], 1)
                self.assertNotIn('tail', probe)
                self.assertNotIn('conflict', probe)

    def test_433_probe_is_inert_for_other_reports(self):
        probe, calls = self.run_433_probe({}, bug_id=7)
        self.assertFalse(probe['applicable'])
        self.assertEqual(calls['connection'], 0)
        self.assertFalse(calls['envelope_required'])
        self.assertIn('433 => $probe433,', self.source)
        self.assertIn('"probe_433_leave_tail" => $probe433,', self.source)

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
