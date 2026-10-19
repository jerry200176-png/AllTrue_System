"""Render the production-case-dump python heredoc for every case and lint the generated PHP.

`php -l` passes on an empty file, so also require non-empty output. The generated probe is passed to python
on stdin: it outgrew the 128 KB single-argument limit of `python3 -c`.
"""
import json
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

WF = Path(__file__).parents[2] / ".github/workflows/production-case-dump.yml"
WORKFLOW = yaml.safe_load(WF.read_text(encoding="utf-8"))
OPTIONS = WORKFLOW[True]["workflow_dispatch"]["inputs"]["case_key"]["options"]  # PyYAML parses `on` as True
SEPARATE_PATH = {"laravel_2833_preflight"}  # not rendered by the python heredoc
WRITE_MARKERS = ("->insert", "->update(", "->delete(", "->execute(", "DB::statement", "->save(", "->truncate")


def render(case):
    if case == 'xinzhuang_fee_cases':
        return subprocess.run(['python3', '-'], input=PROBE_PY, capture_output=True, text=True,
                              env={**os.environ, 'CASE_KEY': case, 'TARGET_STUDENT_ID': '',
                                   'TARGET_STUDENT_CLASS_ID': '4064', 'EXPECTED_TEACHER_IDS': ''})
    if case in SECRET_ID_CASES:
        return render_secret_case(case)
    run = next(s for s in WORKFLOW["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Run bounded"))["run"]
    py = re.search(r"python3 - <<'PY' > /tmp/production_case_probe\.php\n(.*?)\nPY\n", run, re.S).group(1)
    return subprocess.run(["python3", "-"], input=py, capture_output=True, text=True,
                          env={**os.environ, "CASE_KEY": case, "TARGET_STUDENT_ID": "1", "TARGET_STUDENT_CLASS_ID": "",
                               "EXPECTED_TEACHER_IDS": "7,42" if case == "contract_muzha_student" else ""})


RUN = next(s for s in WORKFLOW["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Run bounded"))["run"]
PROBE_PY = re.search(r"python3 - <<'PY' > /tmp/production_case_probe\.php\n(.*?)\nPY\n", RUN, re.S).group(1)
# Cases keyed by a StudentClass ID from the masked CASE_INPUT_STUDENT_CLASS_ID secret (no dispatch ID input accepted).
SECRET_ID_CASES = {"inapp_414_amount_source"}


def render_secret_case(case, student_id="", student_class_id="", secret_class_id="1"):
    return subprocess.run(["python3", "-"], input=PROBE_PY, capture_output=True, text=True,
                          env={**os.environ, "CASE_KEY": case, "TARGET_STUDENT_ID": student_id,
                               "TARGET_STUDENT_CLASS_ID": student_class_id, "CASE_INPUT_STUDENT_CLASS_ID": secret_class_id,
                               "EXPECTED_TEACHER_IDS": ""})


def case_block(php, case):
    return php.split(f"$case === '{case}'")[1].split("} elseif ($case ===")[0]


class CaseDumpPhpLint(unittest.TestCase):
    def test_every_case_renders_nonempty_php_that_lints(self):
        for case in OPTIONS:
            if case in SEPARATE_PATH:
                continue
            with self.subTest(case=case):
                r = render(case)
                self.assertEqual(r.returncode, 0, r.stderr)
                self.assertGreater(len(r.stdout.strip()), 1000)
                with tempfile.NamedTemporaryFile("w", suffix=".php", encoding="utf-8") as f:
                    f.write(r.stdout)
                    f.flush()
                    lint = subprocess.run(["php", "-l", f.name], capture_output=True, text=True)
                self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

    @unittest.skipUnless("occurrence_identity_shadow" in OPTIONS, "case not present")
    def test_occurrence_identity_shadow_is_read_only(self):
        out = render("occurrence_identity_shadow").stdout
        block = out.split("$case === 'occurrence_identity_shadow'")[1].split("} elseif ($case ===")[0]
        for bad in WRITE_MARKERS:
            self.assertNotIn(bad, block)


class InApp414AmountSourceCase(unittest.TestCase):
    """#4155 / in-app #414: one campus-11 StudentClass, read-only, IDs/amounts/dates/statuses/enums only."""

    CASE = "inapp_414_amount_source"

    def block(self):
        r = render_secret_case(self.CASE, secret_class_id="4567")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("$targetStudentClassId = 4567;", r.stdout)
        return case_block(r.stdout, self.CASE)

    def test_case_is_offered_and_allowlisted(self):
        self.assertIn(self.CASE, OPTIONS)
        allow = re.search(r"if case not in \{(.*?)\}:", PROBE_PY).group(1)
        self.assertIn(f"'{self.CASE}'", allow)

    def test_requires_a_positive_numeric_secret_class_id(self):
        for scid in ("", "abc", "0", "00", "-1", "1;x", "1 2", "\u0663", "1" * 13):
            with self.subTest(secret_class_id=scid):
                r = render_secret_case(self.CASE, secret_class_id=scid)
                self.assertNotEqual(r.returncode, 0)
                self.assertIn("requires the CASE_INPUT_STUDENT_CLASS_ID secret", r.stderr)

    def test_refuses_any_dispatch_id_input(self):
        # Dispatch inputs are printed in the public log's env block, so this case refuses them even when valid.
        for sid, scid in (("12", ""), ("", "4567"), ("12", "4567")):
            with self.subTest(student_id=sid, student_class_id=scid):
                r = render_secret_case(self.CASE, student_id=sid, student_class_id=scid, secret_class_id="4567")
                self.assertNotEqual(r.returncode, 0)
                self.assertIn("takes no student_id/student_class_id input", r.stderr)

    def test_reads_the_id_only_from_the_environment_secret_never_from_inputs(self):
        step = next(s for s in WORKFLOW["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Run bounded"))
        self.assertEqual(step["env"]["CASE_INPUT_STUDENT_CLASS_ID"], "${{ secrets.CASE_INPUT_STUDENT_CLASS_ID }}")
        self.assertEqual(WORKFLOW["jobs"]["dump"]["environment"], "production-ssh")
        inputs = WORKFLOW[True]["workflow_dispatch"]["inputs"]
        self.assertNotIn("case_input_student_class_id", {k.lower() for k in inputs})
        src = WF.read_text(encoding="utf-8")
        self.assertNotRegex(src, r"(?i)inputs\.case_input")
        self.assertNotRegex(src, r"CASE_INPUT_STUDENT_CLASS_ID\s*:\s*\$\{\{\s*inputs")
        self.assertEqual(src.count("secrets.CASE_INPUT_STUDENT_CLASS_ID"), 1)
        # The case branch takes its ID from the secret and nowhere else.
        case_py = PROBE_PY.split("if case == 'inapp_414_amount_source':", 1)[1].split("\n          if ", 1)[0]
        self.assertIn("os.environ.get('CASE_INPUT_STUDENT_CLASS_ID')", case_py)
        self.assertIn("target_student_class_id = secret_class_id", case_py)
        # Never echoed: only the env mapping and the validation read it; no run-name, step summary or artifact name.
        self.assertEqual(re.findall(r"(?:echo|printf|print\(|tee|>>)[^\n]*(?:CASE_INPUT|secret_class_id)", src), [])
        self.assertNotIn("run-name", WORKFLOW)
        self.assertNotIn("GITHUB_STEP_SUMMARY", src)
        upload = next(s for s in WORKFLOW["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Upload"))
        self.assertNotIn("CASE_INPUT", json.dumps(upload))
        self.assertNotIn("secrets.", json.dumps(upload))

    def test_artifact_never_carries_the_target_or_student_id(self):
        block = self.block()
        self.assertIn("$out['contract'] = ['class_id' => $idOut($c->class_id),", block)
        out_keys = re.findall(r"\$out\['contract'\] = \[(.*?)\];", block, re.S)[0]
        self.assertNotIn("'student_id'", out_keys)
        for raw in ("(int) $c->class_id", "(int) $l->student_class_id,", "(int) $i->student_class_id,", "(int) $r->student_class_id,", "(int) $c->student_id,"):
            self.assertNotIn(f"=> {raw}", block)

    def test_runs_inside_one_read_only_transaction_that_is_rolled_back(self):
        block = self.block()
        ro, begin = block.index("SET TRANSACTION READ ONLY"), block.index("->beginTransaction()")
        self.assertLess(ro, begin)
        self.assertRegex(block, r"\} finally \{\s*\$conn->rollBack\(\);")
        self.assertEqual(block.count("->beginTransaction()"), 1)
        for bad in WRITE_MARKERS + ("->commit", "DB::"):
            self.assertNotIn(bad, block)
        self.assertNotRegex(block, r"(?i)\b(insert\s+into|update\s+\w+\s+set|delete\s+from|replace\s+into|alter\s+table|drop\s+table|truncate|create\s+table|for\s+update|lock\s+in\s+share)\b")
        # Every query of the case runs inside the try block.
        body = block.split("$conn->beginTransaction();")[1].split("} finally {")[0]
        self.assertIn("$conn->selectOne($sqlContract", body)
        self.assertEqual(body.count("$readComplete("), 7)
        self.assertNotIn("$conn->select($sql", body)

    def test_is_bound_to_campus_11_through_student_campus(self):
        block = self.block()
        self.assertIn("$requiredCampusId = 11;", block)
        self.assertIn("JOIN Student s ON s.id = sc.StudentID", block)
        self.assertIn("s.CampusID AS campus_id", block)
        self.assertRegex(block, r"if \(\(int\) \$c->campus_id !== \$requiredCampusId\) \{ throw ")
        self.assertRegex(block, r"if \(\$c === null\) \{ throw ")
        # The campus check comes before any bill is read.
        self.assertLess(block.index("$requiredCampusId) {"), block.index("$readComplete($sqlInvoices"))
        self.assertIn("obj.get('campus_bound') != 11", RUN)

    def test_selects_and_outputs_no_free_text_columns(self):
        block = self.block()
        sql = " ".join(re.findall(r"<<<'SQL'\n(.*?)\nSQL;", block, re.S) + re.findall(r"\$sql\w+ = '(SELECT .*?)';", block))
        self.assertIn("FROM Invoice i", sql)
        for col in ("Memo", "Note", "Description", "reported_by_name", "account_last5", "rejection_note", "void_reason",
                    "source_reference", "receipt_url", "billing_snapshot", "pricing_snapshot", "settlement_snapshot",
                    "report_token_hash", "Name", "Phone", "parent_phone", "correlation_id", "binding_ref"):
            self.assertNotRegex(sql, rf"\b{col}\b", col)
        self.assertNotRegex(sql, r"\breason\b(?!_code)")
        self.assertNotRegex(sql, r"\*")
        self.assertEqual(sorted(set(re.findall(r"'\$\.(\w+)'", sql))), sorted(
            ["reason_code", "old_charge", "new_charge", "old_session_count", "new_session_count", "outstanding_amount"]))
        self.assertNotRegex(sql, r"e\.metadata(?!, ')")
        keys = set(re.findall(r"'([a-z_]+)' =>", block))
        for bad in ("name", "phone", "note", "memo", "description", "title", "body", "comment"):
            self.assertFalse([k for k in keys if bad in k], bad)

    def test_reports_snapshot_complete_with_reasons(self):
        block = self.block()
        self.assertIn("$out['snapshot_complete'] = $reasons === [];", block)
        self.assertIn('count($unlinked), count($amendments)) > $cap', block)
        self.assertIn("$out['reason'] = $reasons === [] ? null : implode(',', $reasons);", block)
        self.assertIn("$out['read_only_transaction'] = true;", block)
        post = re.findall(r"python3 - <<'PY'\n(.*?)\nPY\n", RUN, re.S)[-1]
        self.assertIn("if obj.get('case') == 'inapp_414_amount_source':", post)
        self.assertIn("'snapshot_complete' not in obj", post)




class XinzhuangFeeScope(unittest.TestCase):
    def test_only_exact_user_supplied_references_are_accepted(self):
        for value in ('4064', '3753', '3693', '', '1', '04064', '４０６４', '4064;exit'):
            r = subprocess.run(['python3', '-'], input=PROBE_PY, capture_output=True, text=True,
                env={**os.environ, 'CASE_KEY': 'xinzhuang_fee_cases', 'TARGET_STUDENT_ID': '',
                     'TARGET_STUDENT_CLASS_ID': value, 'EXPECTED_TEACHER_IDS': '', 'TARGET_SUBJECT_NAME': '數學'})
            self.assertEqual(r.returncode == 0, value in {'4064', '3753', '3693'}, r.stderr)
        block = case_block(render('xinzhuang_fee_cases').stdout, 'xinzhuang_fee_cases')
        self.assertIn(", 11, '新莊分校'", block)
        self.assertIn("$targetStudentClassId === 3753)", block)
        for marker in WRITE_MARKERS:
            self.assertNotIn(marker, block)


class Contract4131StaleTeacherCase(unittest.TestCase):
    """in-app #401/#402 / #4245: fixed contract 4131 campus-16 leftover teacher occupancy provenance."""

    CASE = "contract_4131_stale_teacher"

    def execute_case(self, head="a" * 40, ancestor=0, foreign_row=False, invalid_date=False, transaction_diagnostics=False):
        rows = []
        for ident, status, day, teacher, kind in [
            (1, "scheduled", "01", 7, "regular"), (2, "scheduled", "01", 9, "regular"),
            (3, "leave", "01", 9, "regular"), (4, "rescheduled", "01", 9, "regular"),
            (5, "scheduled", "02", 9, "regular"), (6, "scheduled", "03", 9, "regular"),
            (7, "scheduled", "01", 9, "extra"),
        ]:
            rows.append(dict(id=ident, status=status, schedule_date="2026-12-" + day,
                             student_course_id=4131, branch_id=16, teacher_id=teacher, type=kind,
                             start_time="13:00", end_time="15:00", original_schedule_id=None))
        if foreign_row:
            rows.append({**rows[0], "id": 8, "branch_id": 99})
        if invalid_date is not False:
            rows.append({**rows[0], "id": 8, "schedule_date": invalid_date})
        service = WF.parents[2] / "backend/app/Services/StaleScheduleExceptionFilter.php"
        # Run the rendered production branch and the actual canonical filter. Only
        # database rows and git identity are fixtures; no production access occurs.
        setup = r'''<?php
namespace Illuminate\Support\Facades {
class DB {
    public static function raw($s) { return $s; }
    public static function table($s) { return new class {
        public function whereIn($a, $b) { return $this; }
        public function select(...$a) { return $this; }
        public function get() { $GLOBALS['read_tx_flags'][]=$GLOBALS['tx_open']; return $GLOBALS['sessions']; }
    }; }
}}
namespace ProbeFixture {
function shell_exec($s) { return HEAD; }
function exec($s, &$output, &$exit) { $exit = ANCESTOR; return ''; }
function app($s) {
    if ($s !== 'db') return new \App\Services\StaleScheduleExceptionFilter();
    $GLOBALS['db_touched']++;
    return new class {
        public function connection() { return $this; }
        public function transactionLevel() { return $GLOBALS['tx_open'] ? 1 : 0; }
        public function useWriteConnectionWhenReading() { return $this; }
        public function beginTransaction() {
            if ($GLOBALS['tx_settings'] !== ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'SET TRANSACTION READ ONLY']) throw new \RuntimeException('snapshot_configuration_invalid');
            $GLOBALS['tx_open']=true;
        }
        public function rollBack() { $GLOBALS['tx_open']=false; }
        public function selectOne(...$a) { $GLOBALS['read_tx_flags'][]=$GLOBALS['tx_open']; return (object) [
            'class_id'=>4131, 'campus_id'=>16, 'teacher_id'=>7,
            'schedule_mode'=>'count', 'class_type'=>'one_on_one',
            'session_count'=>8, 'remaining_sessions'=>8, 'stop'=>0]; }
        public function select(...$a) {
            if (str_starts_with($a[0], 'SET ')) { $GLOBALS['tx_settings'][]=$a[0]; return []; }
            $GLOBALS['read_tx_flags'][]=$GLOBALS['tx_open']; return $GLOBALS['rows'];
        }
    };
}
'''
        setup += "const HEAD = " + json.dumps(head) + "; const ANCESTOR = " + str(ancestor) + ";\n"
        setup += "require " + json.dumps(str(service)) + ";\n"
        setup += "$GLOBALS['tx_open']=false; $GLOBALS['tx_settings']=[]; $GLOBALS['read_tx_flags']=[]; $GLOBALS['db_touched']=0; $GLOBALS['rows']=json_decode(" + json.dumps(json.dumps(rows)) + ");\n"
        setup += "$GLOBALS['sessions']=[(object)['StudentClassID'=>4131,'ymd'=>'2026-12-02','StartTime'=>'13:00','Status'=>'cancelled'],(object)['StudentClassID'=>4131,'ymd'=>'2026-12-03','StartTime'=>'15:00','Status'=>'scheduled']];\n$out=[];\ntry {\n"
        body = self.block().split("{", 1)[1]
        finish = "\n} catch (\\Throwable $e) { $out=['error'=>$e->getMessage()]; }\n$out['db_touched']=$GLOBALS['db_touched'];\n"
        if transaction_diagnostics:
            finish += "$out['read_tx_flags']=$GLOBALS['read_tx_flags']; $out['tx_open']=$GLOBALS['tx_open'];\n"
        source = setup + body + finish + "echo json_encode($out);\n}\n"
        result = subprocess.run(["php"], input=source, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_foreign_campus_schedule_refuses_evidence(self):
        out = self.execute_case(foreign_row=True)
        self.assertEqual(out.get("error"), "schedule_campus_mismatch")
        self.assertNotIn("schedule_rows", out)

    def test_missing_and_invalid_schedule_dates_refuse_evidence(self):
        for value in (None, "", "0000-00-00", "2026-02-30"):
            out = self.execute_case(invalid_date=value)
            self.assertEqual(out.get("error"), "invalid_schedule_date")
            self.assertNotIn("schedule_rows", out)

    def test_all_data_reads_share_a_closed_snapshot(self):
        for extra in ({}, {"foreign_row": True}, {"invalid_date": None}):
            out = self.execute_case(transaction_diagnostics=True, **extra)
            self.assertGreaterEqual(len(out["read_tx_flags"]), 2)
            self.assertTrue(all(out["read_tx_flags"]))
            self.assertFalse(out["tx_open"])

    def test_production_prerequisite_refuses_before_database_reads(self):
        for head, ancestor, error in [("", 0, "production_identity_unavailable"),
                                      ("a" * 40, 1, "required_fix_not_deployed")]:
            out = self.execute_case(head, ancestor)
            self.assertEqual(out, dict(error=error, db_touched=0))

    def test_classification_excludes_inactive_and_moved_occurrences(self):
        out = self.execute_case()
        self.assertNotIn("error", out)
        self.assertEqual([row["schedule_id"] for row in out["schedule_rows"]], [1, 2, 7])
        self.assertEqual(out["stale_skip_teacher_ids"], [9])
        self.assertEqual(out["counts"]["stale_skip_rows"], 1)
        self.assertEqual(out["counts"]["keep_rows"], 2)

    def block(self):
        r = render(self.CASE)
        self.assertEqual(r.returncode, 0, r.stderr)
        return case_block(r.stdout, self.CASE)

    def test_case_is_offered_and_allowlisted(self):
        self.assertIn(self.CASE, OPTIONS)
        allow = re.search(r"if case not in \{(.*?)\}:", PROBE_PY).group(1)
        self.assertIn(f"'{self.CASE}'", allow)

    def test_fixed_scope_campus_16_course_4131(self):
        block = self.block()
        self.assertIn("$courseId = 4131;", block)
        self.assertIn("$requiredCampusId = 16;", block)
        self.assertIn("JOIN Student s ON s.id = sc.StudentID", block)
        self.assertRegex(block, r"if \(\(int\) \$course->campus_id !== \$requiredCampusId\) \{ throw ")

    def test_mirrors_h1_keep_skip_and_is_read_only(self):
        block = self.block()
        self.assertIn("extra", block)
        self.assertIn("makeup", block)
        self.assertIn("original_schedule_id", block)
        self.assertIn("stale_skip_vs_contract", block)
        self.assertIn("'fix_sha_hint' => 'eb39beb5f'", block)
        for bad in WRITE_MARKERS + ("->commit", "->save(", "DB::statement"):
            self.assertNotIn(bad, block)
        self.assertNotRegex(
            block,
            r"(?i)\b(insert\s+into|update\s+\w+\s+set|delete\s+from|replace\s+into|alter\s+table|drop\s+table|truncate)\b",
        )

    def test_outputs_ids_enums_dates_only(self):
        block = self.block()
        keys = set(re.findall(r"'([a-z_]+)' =>", block))
        for bad in ("name", "phone", "note", "memo", "description", "title", "body", "comment", "amount", "charge"):
            self.assertFalse([k for k in keys if bad in k], bad)
        self.assertIn("$out['stale_skip_teacher_ids']", block)
        self.assertIn("$out['schedule_rows']", block)


if __name__ == "__main__":
    unittest.main()
