"""Public repo: dump workflows must log IDs/counts only; report text only in an encrypted artifact."""
import json
import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

import yaml

WF = Path(__file__).parents[2] / ".github/workflows"
NAME = "測試學生甲"  # synthetic sample name; must never reach stdout or plaintext artifacts
KEY = "unit-test-passphrase-123"


def run_step(workflow, step_prefix):
    steps = yaml.safe_load((WF / workflow).read_text(encoding="utf-8"))["jobs"]["dump"]["steps"]
    return next(s for s in steps if s.get("name", "").startswith(step_prefix))["run"]


def post_process(workflow, step_prefix, raw_name, raw_text, env):
    """Run the step's python + encrypt tail (after the ssh pipeline) in a temp dir."""
    run = run_step(workflow, step_prefix)
    py = re.findall(r"python3 (?:- )?<<'PY'\n(.*?)\n\s*PY\n", run, re.S)[-1]
    tail = run[run.rindex("\nPY\n") + len("\nPY\n"):]
    with tempfile.TemporaryDirectory() as d:
        Path(d, "raw").mkdir(), Path(d, "out").mkdir()
        Path(d, "raw", raw_name).write_text(raw_text, encoding="utf-8")
        e = {**os.environ, **env, "DUMP_ARTIFACT_KEY": KEY}
        r = subprocess.run(["python3", "-c", textwrap.dedent(py)], cwd=d, env=e, capture_output=True, text=True)
        enc = subprocess.run(["bash", "-c", "set -euo pipefail\n" + textwrap.dedent(tail)], cwd=d, env=e, capture_output=True, text=True)
        out = {p.name: p.read_bytes() for p in Path(d, "out").iterdir()}
        # local-agent decrypt recipe from the SOP
        dec = subprocess.run(
            "openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:DUMP_ARTIFACT_KEY -in out/*.enc | tar xzO",
            shell=True, cwd=d, env=e, capture_output=True)
        return r, enc, out, dec.stdout.decode("utf-8", "replace")


class DumpIdsOnlyTest(unittest.TestCase):
    def test_queue_dump_log_and_artifact_have_no_name(self):
        row = {"id": 7, "status": "new", "severity": "low", "campus_id": 1, "page_key": "x", "title": f"{NAME} 無法登入",
               "created_at": "t", "updated_at": "t", "reporter_user_id": 3, "url_path": "/a"}
        meta = {"counts": {"new": 1, "triaged": 0, "in_progress": 0, "resolved": 0, "closed": 0}, "max_id": 7,
                "probes": [{"id": 1, "status": "new", "title": NAME}], "newest_any": {"id": 7, "title": NAME}, "prod_head": "abc"}
        raw = "---META---\n" + json.dumps(row, ensure_ascii=False) + "\n" + json.dumps(meta, ensure_ascii=False) + "\n"
        r, enc, out, dec = post_process("bug-queue-dump.yml", "Query open bugs", "bug-queue-dump.log", raw, {"TARGET_BUG_ID": ""})
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(enc.returncode, 0, enc.stderr)
        self.assertNotIn(NAME, r.stdout + r.stderr + enc.stdout + enc.stderr)
        self.assertIn("open_rows=1", r.stdout)
        for name, data in out.items():
            self.assertNotIn(NAME.encode(), data, name)
        self.assertIn(NAME, dec)  # agents can still read the report after decrypting

    def test_detail_dump_log_and_artifact_have_no_name(self):
        env = {"ok": True, "requested_bug_id": 7, "bug": {"id": 7, "status": "new", "title": NAME, "description": NAME},
               "comments": [{"id": 1, "body": NAME}], "attachments": [], "status_logs": [], "reporter_history": [],
               "diagnostics": {"decision_grade_required": False, "decision_grade": False}}
        r, enc, out, dec = post_process("bug-detail-dump.yml", "Dump bug", "bug-detail.log", json.dumps(env, ensure_ascii=False) + "\n", {"BUG_ID": "7"})
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(enc.returncode, 0, enc.stderr)
        self.assertNotIn(NAME, r.stdout + r.stderr + enc.stdout + enc.stderr)
        for name, data in out.items():
            self.assertNotIn(NAME.encode(), data, name)
        self.assertIn(NAME, dec)

    def test_bug_dumps_fail_closed_without_key_and_never_tee_to_log(self):
        for wf in ("bug-queue-dump.yml", "bug-detail-dump.yml"):
            src = (WF / wf).read_text(encoding="utf-8")
            first = yaml.safe_load(src)["jobs"]["dump"]["steps"][0]
            self.assertIn("DUMP_ARTIFACT_KEY", json.dumps(first))
            self.assertIn("exit 1", first["run"])
            self.assertNotIn("tee out/", src)

    def test_production_case_dump_takes_ids_not_names(self):
        src = (WF / "production-case-dump.yml").read_text(encoding="utf-8")
        for bad in ("student_name", "targetStudentName", "TARGET_STUDENT_NAME", "approvedNames", "parentStudentName", "raw_tail"):
            self.assertNotIn(bad, src)
        run = run_step("production-case-dump.yml", "Run bounded")
        py = textwrap.dedent(re.search(r"python3 - <<'PY' > /tmp/production_case_probe.php\n(.*?)\n\s*PY\n", run, re.S).group(1))
        base = {**os.environ, "TARGET_STUDENT_CLASS_ID": "", "TARGET_STUDENT_ID": ""}
        r = subprocess.run(["python3", "-W", "ignore", "-"], input=py, env={**base, "CASE_KEY": "parent_student_probe"}, capture_output=True, text=True)
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("requires student_id", r.stderr)
        r = subprocess.run(["python3", "-W", "ignore", "-"], input=py, env={**base, "CASE_KEY": "parent_student_probe", "TARGET_STUDENT_ID": "12"}, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("$targetStudentId = 12;", r.stdout)


    def test_contract_probe_requires_an_independent_teacher_id_oracle(self):
        run = run_step("production-case-dump.yml", "Run bounded")
        py = textwrap.dedent(re.search(r"python3 - <<'PY' > /tmp/production_case_probe.php\n(.*?)\n\s*PY\n", run, re.S).group(1))
        env = {**os.environ, "CASE_KEY": "contract_muzha_student", "TARGET_STUDENT_ID": "12", "TARGET_STUDENT_CLASS_ID": ""}
        for value in ("", "name", "0", "1;echo x", "1," , ",".join(map(str, range(1, 22)))):
            r = subprocess.run(["python3", "-W", "ignore", "-"], input=py, env={**env, "EXPECTED_TEACHER_IDS": value}, capture_output=True, text=True)
            self.assertNotEqual(r.returncode, 0, value)
        r = subprocess.run(["python3", "-W", "ignore", "-"], input=py, env={**env, "EXPECTED_TEACHER_IDS": "42,7,42"}, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("$expectedTeacherIds = [7, 42];", r.stdout)
        self.assertIn("$out['teachers'] = $expectedTeacherIds;", r.stdout)
        self.assertNotIn("$out['teachers'] = collect($classes)", r.stdout)


CASE_WF = "production-case-dump.yml"
ENCRYPT_STEP = "Encrypt case output (log shows status only)"


def case_steps():
    return yaml.safe_load((WF / CASE_WF).read_text(encoding="utf-8"))["jobs"]["dump"]["steps"]


def case_step(prefix):
    return next(s for s in case_steps() if s.get("name", "").startswith(prefix))


class ProductionCaseDumpEncryptedOutputTest(unittest.TestCase):
    """Case output only reaches an encrypted artifact; the public log carries status only (in-app #414 prep)."""

    def run_bash(self, script, cwd, env):
        return subprocess.run(["bash", "-c", textwrap.dedent(script)], cwd=cwd, env=env, capture_output=True, text=True)

    def seed_raw(self, d):
        raw = Path(d, "raw")
        raw.mkdir()
        payload = {"ok": True, "case": "schedule_241", "student": NAME, "snapshot_complete": False,
                   "completeness": "inconclusive", "reason": "truncated,no_linked_invoice"}
        Path(raw, "production-case.log").write_text(f"noise {NAME}\n" + json.dumps(payload, ensure_ascii=False) + "\n", encoding="utf-8")
        Path(raw, "production-case.stderr.log").write_text(f"warning about {NAME}\n", encoding="utf-8")
        Path(raw, "production-case.json").write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")

    def test_key_check_is_first_and_fails_closed_before_any_production_read(self):
        steps = case_steps()
        first = steps[0]
        self.assertIn("secrets.DUMP_ARTIFACT_KEY", json.dumps(first))
        self.assertNotIn("if", first)
        self.assertNotIn("continue-on-error", first)
        with tempfile.TemporaryDirectory() as d:
            for key in ("", "short"):
                r = self.run_bash(first["run"], d, {**os.environ, "DUMP_ARTIFACT_KEY": key})
                self.assertNotEqual(r.returncode, 0, key)
            self.assertEqual(self.run_bash(first["run"], d, {**os.environ, "DUMP_ARTIFACT_KEY": KEY}).returncode, 0)

    def test_probe_output_goes_to_raw_files_never_the_log(self):
        src = (WF / CASE_WF).read_text(encoding="utf-8")
        run = case_step("Run bounded")["run"]
        for gone in ("tee out/", "out/production-case.json", "out/production-case.log", "GITHUB_STEP_SUMMARY"):
            self.assertNotIn(gone, src)
        ssh_lines = [line for line in run.splitlines() if "<<'PREFLIGHT'" in line or "'bash -s' <" in line]
        self.assertEqual(len(ssh_lines), 2)
        for line in ssh_lines:
            self.assertNotIn("tee", line)
            self.assertTrue(line.rstrip().endswith("> raw/production-case.log 2> raw/production-case.stderr.log"), line)
        for step in case_steps():
            for line in step.get("run", "").splitlines():
                if re.search(r"\b(?:cat|tee|echo|printf|head|tail|less|jq)\b", line):
                    self.assertNotRegex(line, r"raw/|production-case\.(?:json|log)|production_case_probe\.php", line)

    def test_probe_parser_prints_case_key_only(self):
        run = case_step("Run bounded")["run"]
        py = re.findall(r"python3 - <<'PY'\n(.*?)\n\s*PY\n", run, re.S)[-1]
        with tempfile.TemporaryDirectory() as d:
            self.seed_raw(d)
            r = subprocess.run(["python3", "-c", textwrap.dedent(py)], cwd=d, capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertNotIn(NAME, r.stdout + r.stderr)
            self.assertIn(NAME, Path(d, "raw", "production-case.json").read_text(encoding="utf-8"))

    def test_encrypt_step_logs_status_only_and_uploads_only_ciphertext(self):
        step = case_step(ENCRYPT_STEP)
        self.assertEqual(step.get("if"), "always()")
        self.assertIn("secrets.DUMP_ARTIFACT_KEY", json.dumps(step))
        with tempfile.TemporaryDirectory() as d:
            self.seed_raw(d)
            summary = Path(d, "summary.md")
            env = {**os.environ, "DUMP_ARTIFACT_KEY": KEY, "CASE_KEY": "schedule_241", "PROBE_OUTCOME": "success",
                   "GITHUB_STEP_SUMMARY": str(summary)}
            r = self.run_bash(step["run"], d, env)
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertFalse(summary.exists())  # nothing goes to the step summary
            published = r.stdout + r.stderr
            for absent in (NAME, "noise", "warning about", "student"):
                self.assertNotIn(absent, published)
            self.assertIn('"case": "schedule_241"', published)
            self.assertIn('"snapshot_complete": false', published)
            self.assertIn('"reason": "truncated,no_linked_invoice"', published)
            self.assertRegex(published, r"sha256=[0-9a-f]{64}")
            out = sorted(p.name for p in Path(d, "out").iterdir())
            self.assertEqual(out, ["production-case.full.tar.gz.enc", "production-case.full.tar.gz.enc.sha256"])
            self.assertNotIn(NAME.encode(), Path(d, "out", out[0]).read_bytes())
            check = subprocess.run(["sha256sum", "-c", out[1]], cwd=Path(d, "out"), capture_output=True, text=True)
            self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
            # Key holder's local recipe (docs/sop/BUG_INTAKE_TO_PRODUCTION.md step 5) recovers every raw file.
            dec = subprocess.run(
                "openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:DUMP_ARTIFACT_KEY "
                "-in out/production-case.full.tar.gz.enc | tar xzO",
                shell=True, cwd=d, env=env, capture_output=True)
            self.assertEqual(dec.returncode, 0, dec.stderr)
            self.assertEqual(dec.stdout.decode("utf-8").count(NAME), 4)  # log x2, stderr, json

    def test_encrypt_step_withholds_non_token_status_text(self):
        step = case_step(ENCRYPT_STEP)
        with tempfile.TemporaryDirectory() as d:
            raw = Path(d, "raw")
            raw.mkdir()
            Path(raw, "production-case.json").write_text(json.dumps(
                {"ok": NAME, "snapshot_complete": NAME, "completeness": NAME, "reason": f"{NAME} x"}, ensure_ascii=False), encoding="utf-8")
            summary = Path(d, "summary.md")
            env = {**os.environ, "DUMP_ARTIFACT_KEY": KEY, "CASE_KEY": "schedule_241", "PROBE_OUTCOME": "failure",
                   "GITHUB_STEP_SUMMARY": str(summary)}
            r = self.run_bash(step["run"], d, env)
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertFalse(summary.exists())
            self.assertNotIn(NAME, r.stdout + r.stderr)
            self.assertIn("withheld", r.stdout)

    def test_encrypt_step_without_key_uploads_nothing(self):
        step = case_step(ENCRYPT_STEP)
        with tempfile.TemporaryDirectory() as d:
            self.seed_raw(d)
            env = {**os.environ, "DUMP_ARTIFACT_KEY": "", "CASE_KEY": "schedule_241", "PROBE_OUTCOME": "success",
                   "GITHUB_STEP_SUMMARY": str(Path(d, "summary.md"))}
            r = self.run_bash(step["run"], d, env)
            self.assertNotEqual(r.returncode, 0)
            self.assertNotIn(NAME, r.stdout + r.stderr)
            self.assertEqual(list(Path(d, "out").iterdir()), [])

    def test_upload_is_ciphertext_and_hash_only(self):
        steps = case_steps()
        uploads = [s for s in steps if "upload-artifact" in s.get("uses", "")]
        self.assertEqual(len(uploads), 1)
        paths = [p.strip() for p in uploads[0]["with"]["path"].splitlines() if p.strip()]
        self.assertEqual(paths, ["out/production-case.full.tar.gz.enc", "out/production-case.full.tar.gz.enc.sha256"])
        names = [s.get("name", "") for s in steps]
        self.assertLess(names.index(ENCRYPT_STEP), names.index(uploads[0]["name"]))

    def test_plaintext_is_shredded_in_always_cleanup(self):
        step = case_step("Shred plaintext")
        self.assertEqual(step.get("if"), "always()")
        self.assertIn("shred -u", step["run"])
        names = [s.get("name", "") for s in case_steps()]
        self.assertLess(names.index(ENCRYPT_STEP), names.index(step["name"]))
        with tempfile.TemporaryDirectory() as d:
            self.seed_raw(d)
            probe = Path(d, "probe.php")
            probe.write_text("<?php // probe", encoding="utf-8")
            runner_temp = Path(d, "runner")
            runner_temp.mkdir()
            Path(runner_temp, "production-case-remote.sh").write_text("x", encoding="utf-8")
            script = step["run"].replace("/tmp/production_case_probe.php", str(probe))
            r = self.run_bash(script, d, {**os.environ, "RUNNER_TEMP": str(runner_temp)})
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
            self.assertFalse(Path(d, "raw").exists())
            self.assertFalse(probe.exists())
            self.assertFalse(Path(runner_temp, "production-case-remote.sh").exists())

    def test_workflow_stays_on_the_pii_log_purge_list(self):
        listed = (WF.parent / "pii-log-workflows.txt").read_text(encoding="utf-8").split()
        self.assertIn(CASE_WF, listed)


if __name__ == "__main__":
    unittest.main()
