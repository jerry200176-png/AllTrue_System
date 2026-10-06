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
        r = subprocess.run(["python3", "-W", "ignore", "-c", py], env={**base, "CASE_KEY": "parent_lou"}, capture_output=True, text=True)
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("requires student_id", r.stderr)
        r = subprocess.run(["python3", "-W", "ignore", "-c", py], env={**base, "CASE_KEY": "parent_lou", "TARGET_STUDENT_ID": "12"}, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("$targetStudentId = 12;", r.stdout)


if __name__ == "__main__":
    unittest.main()
