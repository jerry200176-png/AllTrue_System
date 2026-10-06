"""Public repo (#3605 part 2): diagnose/ops workflows print ids, statuses, dates and counts only.

Synthetic names only. Each test feeds a fake person/free-text value through the real script or the real
workflow step and asserts it never reaches stdout or a plaintext artifact.
"""
import json
import os
import re
import shutil
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
WF = ROOT / ".github/workflows"
NAME = "測試學生甲"  # synthetic; must never reach stdout or a plaintext artifact
NOTE = "備註含測試家長乙"
KEY = "unit-test-passphrase-123"
# A SELECT list may mention these only inside CHAR_LENGTH(...) (a length, not the text).
PERSON_COLUMN = re.compile(r"\b(name|memo|note|description|reason|source_reference|rejection_note|reported_by_name)\b", re.I)
# ...or in a predicate that is never selected: LOWER(IFNULL(cs.Note,'')) REGEXP 'leave|makeup'.
LENGTH_ONLY = re.compile(r"CHAR_LENGTH\((?:[^()]|\([^()]*\))*\)|LOWER\(IFNULL\([^()]*\)\)\s+REGEXP\s+'[^']*'", re.I)


def steps(workflow, job=None):
    jobs = yaml.safe_load((WF / workflow).read_text(encoding="utf-8"))["jobs"]
    return next(iter(jobs.values()))["steps"] if job is None else jobs[job]["steps"]


def step_run(workflow, prefix):
    return next(s for s in steps(workflow) if s.get("name", "").startswith(prefix))["run"]


def dispatch_inputs(workflow):
    doc = yaml.safe_load((WF / workflow).read_text(encoding="utf-8"))
    return ((doc.get(True) or doc.get("on") or {}).get("workflow_dispatch") or {}).get("inputs") or {}


class StudentSessionScriptTest(unittest.TestCase):
    def run_script(self, script, env_extra):
        """Run a diagnose script with a fake mysql that leaks NAME for any query selecting a person column."""
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            (p / "env").write_text("DB_USERNAME=f\nDB_PASSWORD=f\nDB_DATABASE=f\n")
            mysql = p / "mysql"
            mysql.write_text(textwrap.dedent(f"""\
                #!/usr/bin/env python3
                import json, os, re, sys
                q = sys.argv[sys.argv.index("-e") + 1]
                open(os.environ["CAPTURE"], "a").write(json.dumps(q) + "\\n")
                q = re.sub({LENGTH_ONLY.pattern!r}, "", q, flags=re.I)
                print("{NAME}|{NOTE}" if re.search({PERSON_COLUMN.pattern!r}, q, re.I) else "1|ok")
                """))
            mysql.chmod(0o755)
            env = dict(os.environ, PATH=f"{p}:{os.environ['PATH']}", ENV_FILE=str(p / "env"),
                       CAPTURE=str(p / "queries"), **env_extra)
            r = subprocess.run(["bash", str(ROOT / "scripts" / script)], env=env, text=True, capture_output=True)
            cap = p / "queries"
            return r, [json.loads(x) for x in cap.read_text().splitlines()] if cap.exists() else []

    def test_student_session_script_is_id_scoped_and_prints_no_person_text(self):
        r, queries = self.run_script("diagnose-student-session.sh",
                                     {"STUDENT_ID": "12", "CAMPUS_ID": "16", "DATE": "2026-09-23",
                                      "STUDENT_NAME": NAME, "TEACHER_NAME": NAME})
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertTrue(queries)
        for q in queries:
            if re.search(r"\bStudent\b", q):
                self.assertRegex(q, r"(\bs\.id|\bid)=12\b")
            self.assertNotRegex(LENGTH_ONLY.sub("", q), PERSON_COLUMN, q)
        self.assertNotIn(NAME, r.stdout + r.stderr)
        self.assertNotIn(NOTE, r.stdout + r.stderr)
        self.assertIn("STUDENT_ID=12", r.stdout)

    def test_student_session_script_rejects_names_and_missing_id(self):
        for extra in ({"STUDENT_NAME": NAME}, {"STUDENT_ID": NAME}, {"STUDENT_ID": "12 OR 1=1"}):
            r, queries = self.run_script("diagnose-student-session.sh", dict(CAMPUS_ID="16", DATE="2026-09-23", **extra))
            self.assertNotEqual(0, r.returncode)
            self.assertEqual([], queries)
            self.assertNotIn(NAME, r.stdout)

    def test_entitlement_capacity_script_prints_no_person_text(self):
        r, queries = self.run_script("diagnose-entitlement-capacity.sh", {})
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertNotIn(NAME, r.stdout)
        self.assertNotIn(NOTE, r.stdout)
        self.assertTrue(queries)


@unittest.skipUnless(shutil.which("php"), "php not installed")
class AttendanceCaseScriptTest(unittest.TestCase):
    STUB = r"""<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static array $selected = [];
        public static function table($t) { return new \FakeQuery($t); }
        public static function raw($e) { return $e; }
    }
}
namespace {
    class FakeCollection {
        public function __construct(public array $items = []) {}
        public function pluck($k) { return new self(array_map(fn ($r) => ((array) $r)[$k] ?? null, $this->items)); }
        public function map($f) { return new self(array_map($f, $this->items)); }
        public function values() { return new self(array_values($this->items)); }
        public function all() { return $this->items; }
    }
    function collect($a = []) { return new FakeCollection($a); }
    class FakeQuery {
        public function __construct(private string $t) {}
        public function __call($m, $a) {
            if ($m === 'get') {
                foreach ($a[0] as $c) { $GLOBALS['selected'][] = (string) $c; }
                return new FakeCollection(array_map(fn ($r) => (object) $r, FakeData::rows(explode(' as ', $this->t)[0])));
            }
            return $this;
        }
    }
    class FakeData {
        // Rows carry person data the real query must not select; the script must not print it either.
        public static function rows($t) {
            $n = getenv('SYN_NAME'); $note = getenv('SYN_NOTE');
            return match ($t) {
                'Student' => [['id' => 7, 'CampusID' => 9, 'name' => $n]],
                'StudentClass' => [['class_id' => 70, 'student_id' => 7, 'teacher_id' => 3, 'teacher_name' => $n, 'subject_name' => $n]],
                'ClassSession' => [['session_id' => 700, 'class_id' => 70, 'student_id' => 7, 'status' => 'attended', 'note' => $note, 'note_length' => 9]],
                'schedule_audit_logs' => [['audit_id' => 1, 'session_id' => 700, 'action_type' => 'edit', 'description' => $note,
                    'operator_id' => 3, 'operator_name' => $n,
                    'old_data' => json_encode(['Status' => 'scheduled', 'Note' => $n]), 'new_data' => json_encode(['Status' => 'attended', 'Note' => $n . 'x'])]],
                'LearningRecord' => [['learning_record_id' => 5, 'session_id' => 700, 'created_by_name' => $n, 'void_reason' => $note, 'void_reason_length' => 4]],
                'StudentSingIn' => [['sign_in_id' => 6, 'session_id' => 700, 'recorded_by_name' => $n, 'void_reason' => $note]],
                default => [],
            };
        }
    }
}
"""

    def test_probe_selects_and_prints_ids_only(self):
        with tempfile.TemporaryDirectory() as d:
            stub = Path(d, "stub.php")
            stub.write_text(self.STUB)
            tail = Path(d, "tail.php")
            tail.write_text("<?php register_shutdown_function(fn () => file_put_contents(__DIR__ . '/selected.json', json_encode($GLOBALS['selected'] ?? [])));")
            env = dict(os.environ, CAMPUS_ID="9", DATE_FROM="2026-08-01", DATE_TO="2026-08-10", STUDENT_ID="7",
                       SYN_NAME=NAME, SYN_NOTE=NOTE)
            script = ROOT / "scripts/diagnose-attendance-case.php"
            r = subprocess.run(["php", "-d", f"auto_prepend_file={stub}", "-d", f"auto_append_file={tail}", str(script)],
                               env=env, text=True, capture_output=True)
            self.assertEqual(0, r.returncode, r.stderr + r.stdout)
            self.assertNotIn(NAME, r.stdout)
            self.assertNotIn(NOTE, r.stdout)
            out = json.loads(r.stdout.strip().splitlines()[-1])
            self.assertEqual(7, out["student_id_filter"])
            self.assertEqual(["Note", "Status"], out["schedule_audit_logs"][0]["changed_fields"])
            self.assertNotIn("student_name_filter", out)
            selected = json.loads(Path(d, "selected.json").read_text())
            self.assertTrue(selected)
            for col in selected:
                col = LENGTH_ONLY.sub("", col)
                self.assertNotRegex(col, r"\bu\.name\b|\bteacher\.name\b|Subject_Name|\bname\b", col)
                self.assertNotRegex(col, r"\.(Note|description|VoidReason)\b", col)


class WorkflowContractTest(unittest.TestCase):
    def test_diagnose_workflows_take_ids_not_names(self):
        for wf in ("attendance-case-diagnose.yml", "student-session-diagnose.yml", "teacher-signin-diagnose.yml",
                   "classsession-duplicate-diagnose-push.yml"):
            for key in dispatch_inputs(wf):
                self.assertNotRegex(key, r"name|login|phone|email|line", f"{wf}: input {key}")
        self.assertIn("student_id", dispatch_inputs("student-session-diagnose.yml"))
        self.assertIn("student_id", dispatch_inputs("attendance-case-diagnose.yml"))
        self.assertIn("teacher_id", dispatch_inputs("teacher-signin-diagnose.yml"))

    def test_attendance_workflow_never_tees_raw_output_and_redacts_artifact(self):
        src = (WF / "attendance-case-diagnose.yml").read_text(encoding="utf-8")
        self.assertNotIn("tee ", src)
        run = step_run("attendance-case-diagnose.yml", "Run bounded")
        py = textwrap.dedent(re.search(r"python3 - <<'PY'\n(.*?)\n\s*PY\n", run, re.S).group(1))
        leak = {"ok": True, "read_only": True, "students": [{"id": 7, "name": NAME}],
                "student_classes": [{"class_id": 70, "teacher_name": NAME, "note_length": 3}],
                "schedule_audit_logs": [{"audit_id": 1, "operator_name": NAME, "description": NOTE,
                                         "old_data": NOTE, "description_length": 4, "changed_fields": ["Status"]}],
                "learning_records": [{"learning_record_id": 5, "void_reason": NOTE, "void_reason_length": 4}]}
        with tempfile.TemporaryDirectory() as d:
            Path(d, "raw").mkdir(), Path(d, "out").mkdir()
            Path(d, "raw/attendance-case.log").write_text("banner\n" + json.dumps(leak, ensure_ascii=False) + "\n")
            r = subprocess.run(["python3", "-c", py], cwd=d, capture_output=True, text=True)
            self.assertEqual(0, r.returncode, r.stderr)
            artifact = Path(d, "out/attendance-case.json").read_text(encoding="utf-8")
            for text in (r.stdout, r.stderr, artifact):
                self.assertNotIn(NAME, text)
                self.assertNotIn(NOTE, text)
            kept = json.loads(artifact)
            self.assertEqual(3, kept["student_classes"][0]["note_length"])
            self.assertEqual(["Status"], kept["schedule_audit_logs"][0]["changed_fields"])
            self.assertEqual([p.name for p in Path(d, "out").iterdir()], ["attendance-case.json"])

    def test_director_pack_is_encrypted_and_plaintext_has_no_csv(self):
        steps_ = steps("ops-director-leave-hc-pack.yml")
        self.assertIn("DUMP_ARTIFACT_KEY", json.dumps(steps_[0]))
        self.assertIn("exit 1", steps_[0]["run"])
        run = step_run("ops-director-leave-hc-pack.yml", "Encrypt the named director pack")
        with tempfile.TemporaryDirectory() as d:
            Path(d, "out").mkdir()
            Path(d, "raw/director-leave-hc").mkdir(parents=True)
            Path(d, "raw/director-leave-hc/director-review-9.csv").write_text(f"學生姓名\n{NAME}\n", encoding="utf-8")
            env = {**os.environ, "DUMP_ARTIFACT_KEY": KEY}
            r = subprocess.run(["bash", "-c", "set -euo pipefail\n" + run], cwd=d, env=env, capture_output=True, text=True)
            self.assertEqual(0, r.returncode, r.stderr)
            files = [p.name for p in Path(d, "out").iterdir()]
            self.assertEqual(["director-leave-hc.full.tar.gz.enc"], files)
            self.assertNotIn(NAME.encode(), Path(d, "out", files[0]).read_bytes())
            dec = subprocess.run("openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:DUMP_ARTIFACT_KEY "
                                 "-in out/*.enc | tar xzO", shell=True, cwd=d, env=env, capture_output=True)
            self.assertIn(NAME, dec.stdout.decode("utf-8"))
        up = [s for s in steps("ops-director-leave-hc-pack.yml") if "upload-artifact" in str(s.get("uses"))][0]
        self.assertEqual("out/", up["with"]["path"])
        self.assertIn("raw/", step_run("ops-director-leave-hc-pack.yml", "Export packs"))

    def test_bodies_notes_and_logins_are_not_echoed(self):
        slow = (WF / "slow-query-report.yml").read_text(encoding="utf-8")
        self.assertNotIn("INFO", slow)
        dur = (WF / "actual-duration-acceptance.yml").read_text(encoding="utf-8")
        self.assertNotRegex(dur, r"cat /tmp/(lock|pause)-body\.json\n")
        self.assertNotIn('echo "create-response-body', dur)
        self.assertNotIn("VoidReason", (WF / "lr-missing-diagnose.yml").read_text(encoding="utf-8").split("PHP")[1])
        director = (ROOT / "scripts/diagnose-director-285.sh").read_text(encoding="utf-8")
        self.assertNotIn("observed=${IDENTITY}", director)
        for wf in ("bug-phase-a-triage.yml", "bug-followup-comment.yml"):
            self.assertNotIn('"comment" => $comment', (WF / wf).read_text(encoding="utf-8"))
        lu = (WF / "lu-yue-1513-unpaid-rollback.yml").read_text(encoding="utf-8")
        self.assertNotRegex(lu, r'first\(\[[^\]]*"name"')
        self.assertNotRegex(lu, r'"Note"\]\);')

    def run_filter(self, source, text):
        return subprocess.run(["python3", "-c", textwrap.dedent(source)], input=text, text=True, capture_output=True)

    def test_teacher_signin_diagnose_output_is_allowlisted(self):
        doc = yaml.safe_load((WF / "teacher-signin-diagnose.yml").read_text(encoding="utf-8"))
        src = next(s for s in doc["jobs"]["diagnose"]["steps"] if "PII_FILTER" in (s.get("env") or {}))["env"]["PII_FILTER"]
        raw = (f"Read-only teacher sign-in diagnostic for 2026-04-28\nTeachers matched: 1\n+----+------+----+\n"
               f"| teacher_id | teacher_name | approved |\n+----+------+----+\n| 5 | {NAME} | 1 |\n+----+------+----+\n"
               f"Teacher 5 / {NAME}\nRFID fingerprint: abcdef012345\n+--+--+\n| student_id | student_name | memo | status |\n+--+--+\n"
               f"| 9 | {NAME} | {NOTE} | present |\n+--+--+\nunexpected debug line {NAME}\n")
        out = self.run_filter(src, raw)
        self.assertEqual(0, out.returncode, out.stderr)
        self.assertNotIn(NAME, out.stdout + out.stderr)
        self.assertNotIn(NOTE, out.stdout)
        self.assertIn("Teacher 5\n", out.stdout)
        self.assertIn("5 | 1", out.stdout)
        self.assertIn("9 | present", out.stdout)
        text = (WF / "teacher-signin-diagnose.yml").read_text(encoding="utf-8")
        self.assertIn("2>/dev/null <<'ENDSSH' | python3 -c \"$PII_FILTER\"", text)

    def test_guardian_activation_output_is_allowlisted_and_stderr_dropped(self):
        doc = yaml.safe_load((WF / "multi-guardian-activation.yml").read_text(encoding="utf-8"))
        raw = (f"head={'a' * 40}\n[DRY-RUN] guardians:sync-from-legacy\n  student_id=12 campus=9 phone=0918000111 name={NAME}\n"
               f"{{\"mode\":\"dry-run\",\"scanned\":3,\"would_write\":1,\"phone_mismatch_sample\":[12],\"note\":\"{NAME}\","
               f"\"slb_orphan_sample\":[{{\"line_user_id_suffix\":\"{NAME}\",\"student_id\":4}}]}}\n"
               f"Traceback: {NAME}\nVERIFY_OK\nenable-result=SUCCESS\nstaff-acceptance-result=SUCCESS {NAME}\n")
        out = self.run_filter(doc["env"]["PII_FILTER"], raw)
        self.assertEqual(0, out.returncode, out.stderr)
        self.assertNotIn(NAME, out.stdout)
        self.assertNotIn("0918000111", out.stdout)
        self.assertIn("student_id=12 campus=9", out.stdout)
        self.assertIn('"mode":"dry-run"', out.stdout)
        self.assertIn("VERIFY_OK", out.stdout)
        self.assertIn("enable-result=SUCCESS\n", out.stdout)
        text = (WF / "multi-guardian-activation.yml").read_text(encoding="utf-8")
        self.assertEqual(6, text.count("2>/dev/null <<'ENDSSH' | python3 -c \"$PII_FILTER\" | tee"))
        self.assertNotIn("| tee /tmp/mg-verify.txt\n          grep", text.replace("python3 -c \"$PII_FILTER\" | tee", ""))

    def test_filters_never_pass_mixed_noise_or_injected_rows(self):
        tdoc = yaml.safe_load((WF / "teacher-signin-diagnose.yml").read_text(encoding="utf-8"))
        tsrc = next(s for s in tdoc["jobs"]["diagnose"]["steps"] if "PII_FILTER" in (s.get("env") or {}))["env"]["PII_FILTER"]
        noise = (f"PHP Warning: {NAME}\n+--+--+\n| teacher_id | status |\n+--+--+\n| 5 | present |\n"
                 f"| 6 | {NAME} |\n| {NAME} | leave |\n|{NAME}\n+--+--+\n{{\"x\": \"{NAME}\"}}\n"
                 f"{NAME}\n| {NAME} | {NAME} |\n")
        out = self.run_filter(tsrc, noise)
        self.assertNotIn(NAME, out.stdout + out.stderr)
        self.assertIn("5 | present", out.stdout)
        gdoc = yaml.safe_load((WF / "multi-guardian-activation.yml").read_text(encoding="utf-8"))
        gnoise = (f"noise {NAME}\n{{\"ok\":true,\"{NAME}\":1,\"mode\":\"{NAME}\"}}\n{{\"mode\":\"verify\",\"scanned\":2}}\n"
                  f"enable-result={NAME}\nbackup-file={NAME}\n{{broken {NAME}\n")
        out = self.run_filter(gdoc["env"]["PII_FILTER"], gnoise)
        self.assertNotEqual(0, out.returncode)  # unparseable JSON fails the step
        self.assertNotIn(NAME, out.stdout + out.stderr)
        self.assertIn('{"scanned":2,"mode":"verify"}', out.stdout)
        self.assertIn("filter_error=unparseable_json_line", out.stdout)

    def test_teacher_signin_recovery_parses_one_json_object_and_never_echoes_input(self):
        doc = yaml.safe_load((WF / "teacher-signin-recovery.yml").read_text(encoding="utf-8"))
        step = next(x for x in doc["jobs"]["recover"]["steps"] if "PII_FILTER" in (x.get("env") or {}))
        src = step["env"]["PII_FILTER"]
        row = {"student_signin_id": 7, "teacher_id": 5, "campus_id": 2, "student_id": 9,
               "sign_in_dt": "2026-04-28 19:30:00", "sign_out_dt": "", "student_name": NAME, "rfid": NAME}
        good = (f"noise {NAME}\nDRYRUN_JSON " + json.dumps({"mode": "dry-run", "date": "2026-04-28", "candidates": 1,
                "rows": [row], "teacher_name": NAME}, ensure_ascii=False) + "\nBACKUP_OK\n"
                + "APPLY_JSON " + json.dumps({"mode": "apply", "inserted": 1, "note": NAME}, ensure_ascii=False) + "\n")
        out = self.run_filter(src, good)
        self.assertEqual(0, out.returncode, out.stderr)
        self.assertNotIn(NAME, out.stdout + out.stderr)
        self.assertIn('"student_signin_id":7', out.stdout)
        self.assertIn('"inserted":1', out.stdout)
        self.assertIn("BACKUP_OK", out.stdout)
        for bad in (f"DRYRUN_JSON {{broken {NAME}\n", f"DRYRUN_JSON [\"{NAME}\"]\n",
                    f"DRYRUN_JSON {{\"mode\":\"dry-run\"}}\nDRYRUN_JSON {{\"mode\":\"{NAME}\"}}\n"):
            out = self.run_filter(src, bad)
            self.assertNotEqual(0, out.returncode)
            self.assertNotIn(NAME, out.stdout + out.stderr)
            self.assertIn("recovery output could not be parsed; withheld", out.stdout)
        text = (WF / "teacher-signin-recovery.yml").read_text(encoding="utf-8")
        self.assertIn("2>/dev/null <<'ENDSSH' | python3 -c \"$PII_FILTER\"", text)
        self.assertIn("--json", text)

    def test_workflows_never_pass_with_names_to_artisan(self):
        for path in WF.glob("*.yml"):
            self.assertNotIn("--with-names", path.read_text(encoding="utf-8"), path.name)

    def test_calendar_acceptance_report_is_encrypted_not_uploaded_plaintext(self):
        text = (WF / "calendar-course-acceptance.yml").read_text(encoding="utf-8")
        uploads = [x for x in steps("calendar-course-acceptance.yml") if "upload-artifact" in str(x.get("uses", ""))]
        self.assertTrue(uploads)
        for u in uploads:
            self.assertNotIn("playwright-report", str(u["with"]["path"]))
            self.assertNotIn("test-results", str(u["with"]["path"]))
        self.assertIn("DUMP_ARTIFACT_KEY", text)
        self.assertIn("openssl enc -aes-256-cbc", text)

    def test_allowlist_replies_and_probes_carry_no_names(self):
        text = (WF / "bug-phase-c-allowlist.yml").read_text(encoding="utf-8")
        probes = (WF / "bug-legacy-production-probes.yml").read_text(encoding="utf-8")
        for name in ("呂承澔", "楊璦瑄", "張進鴻", "陳姝彣", "張珉恩", "洪家溱", "周芮湘", "樓兆瑄", "陳品承",
                     "何昀佳", "邱崴", "簡湧耆", "沈柏宇", "王俞方", "芝琳", "鄭老師"):
            self.assertNotIn(name, text)
        self.assertNotIn("urlencode({\"name\"", probes)
        self.assertIn("student_id_114", dispatch_inputs("bug-legacy-production-probes.yml"))

    def test_classsession_duplicate_diagnose_selects_no_person_columns(self):
        run = step_run("classsession-duplicate-diagnose-push.yml", "Run diagnose on Pi")
        sql = "\n".join(re.findall(r'-e "(SELECT.*?;)"', run, re.S))
        self.assertTrue(sql)
        self.assertNotRegex(LENGTH_ONLY.sub("", sql), r"\b(s|st)\.name\b|\bName\b|LoginName|\bNote\b")


if __name__ == "__main__":
    unittest.main()
