"""CHECK 2 (presubmit.yml): 700 logic lines; tests and docs count only toward a 1500-line total.

Runs the step's own shell in a throwaway git repo (Founder 2026-10-28 governance smoothing).
"""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
STEP = next(step for step in yaml.safe_load((ROOT / ".github/workflows/presubmit.yml").read_text(encoding="utf-8"))
            ["jobs"]["gate"]["steps"] if str(step.get("name", "")).startswith("[CHECK 2]"))


def run_gate(files: dict[str, int]) -> subprocess.CompletedProcess:
    """files: path -> number of added lines on the PR branch."""
    with tempfile.TemporaryDirectory() as tmp:
        git = lambda *args: subprocess.run(["git", *args], cwd=tmp, check=True, capture_output=True)
        git("init", "-q", "-b", "main")
        git("-c", "user.email=t@t", "-c", "user.name=t", "commit", "-q", "--allow-empty", "-m", "base")
        git("update-ref", "refs/remotes/origin/main", "HEAD")
        git("checkout", "-q", "-b", "fix/x")
        for path, lines in files.items():
            target = Path(tmp, path)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("".join(f"line {i}\n" for i in range(lines)), encoding="utf-8")
        git("add", "-A")
        git("-c", "user.email=t@t", "-c", "user.name=t", "commit", "-q", "-m", "pr")
        env = {**os.environ, "GITHUB_HEAD_REF": "fix/x", "GITHUB_ENV": os.devnull}
        return subprocess.run(["bash", "-e", "-c", STEP["run"]], cwd=tmp, env=env, capture_output=True, text=True)


class SizeGateTest(unittest.TestCase):
    def test_tests_and_docs_do_not_force_a_split(self):
        # The #4200 shape: ~280 logic lines plus ~500 lines of tests.
        out = run_gate({"backend/app/X.php": 280, "backend/tests/Feature/XTest.php": 400,
                        "frontend/src/x/__tests__/x.test.js": 100, "docs/changes/x.md": 10})
        self.assertEqual(0, out.returncode, out.stdout + out.stderr)
        self.assertIn("280 logic lines", out.stdout)

    def test_logic_over_700_still_fails(self):
        out = run_gate({"backend/app/X.php": 701})
        self.assertEqual(1, out.returncode, out.stdout)
        self.assertIn("701 logic lines (limit: 700)", out.stdout)

    def test_total_over_1500_still_fails(self):
        out = run_gate({"backend/app/X.php": 100, "backend/tests/Feature/XTest.php": 1401})
        self.assertEqual(1, out.returncode, out.stdout)

    def test_scripts_and_workflows_are_logic(self):
        out = run_gate({"backend/app/X.php": 1, "scripts/governance/x.py": 400, ".github/workflows/x.yml": 300})
        self.assertEqual(1, out.returncode, out.stdout)


if __name__ == "__main__":
    unittest.main()
