"""Render the production-case-dump python heredoc for occurrence_identity_shadow and lint the generated PHP."""
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

WF = Path(__file__).parents[2] / ".github/workflows/production-case-dump.yml"


def render(case):
    wf = yaml.safe_load(WF.read_text(encoding="utf-8"))
    run = next(s for s in wf["jobs"]["dump"]["steps"] if s.get("name", "").startswith("Run bounded"))["run"]
    py = re.search(r"python3 - <<'PY' > /tmp/production_case_probe\.php\n(.*?)\nPY\n", run, re.S).group(1)
    r = subprocess.run(["python3", "-"], input=py, capture_output=True, text=True,
                       env={**os.environ, "CASE_KEY": case, "TARGET_STUDENT_ID": "", "TARGET_STUDENT_CLASS_ID": ""})
    return r, run


class OccurrenceIdentityShadowCase(unittest.TestCase):
    def test_renders_nonempty_php_that_lints(self):
        r, run = render("occurrence_identity_shadow")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertGreater(len(r.stdout.strip()), 1000)  # php -l passes on an empty file
        self.assertIn("$case === 'occurrence_identity_shadow'", r.stdout)
        with tempfile.NamedTemporaryFile("w", suffix=".php", encoding="utf-8") as f:
            f.write(r.stdout)
            f.flush()
            lint = subprocess.run(["php", "-l", f.name], capture_output=True, text=True)
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

    def test_case_is_read_only(self):
        r, _ = render("occurrence_identity_shadow")
        block = r.stdout.split("$case === 'occurrence_identity_shadow'")[1].split("$case === 'paid_status_shadow_log'")[0]
        for bad in ("->insert", "->update(", "->delete(", "->execute(", "DB::statement", "->save(", "->truncate"):
            self.assertNotIn(bad, block)

    def test_listed_as_input_option(self):
        text = WF.read_text(encoding="utf-8")
        self.assertIn("- occurrence_identity_shadow\n", text)


if __name__ == "__main__":
    unittest.main()
