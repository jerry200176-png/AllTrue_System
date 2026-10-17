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


if __name__ == "__main__":
    unittest.main()

