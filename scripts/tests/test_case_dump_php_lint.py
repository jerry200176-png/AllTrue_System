"""Render the production-case-dump python heredoc for every case and lint the generated PHP.

`php -l` passes on an empty file, so also require non-empty output. The generated probe is passed to python
on stdin: it outgrew the 128 KB single-argument limit of `python3 -c`.
"""
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
    run = next(s for s in WORKFLOW["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Run bounded"))["run"]
    py = re.search(r"python3 - <<'PY' > /tmp/production_case_probe\.php\n(.*?)\nPY\n", run, re.S).group(1)
    return subprocess.run(["python3", "-"], input=py, capture_output=True, text=True,
                          env={**os.environ, "CASE_KEY": case, "TARGET_STUDENT_ID": "1", "TARGET_STUDENT_CLASS_ID": ""})


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


if __name__ == "__main__":
    unittest.main()
