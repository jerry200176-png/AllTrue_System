"""CHECK 2 (presubmit.yml): 700 logic lines; tests and docs count only toward a 1500-line total.

Runs the step's own shell in a throwaway git repo (Founder 2026-10-28 governance smoothing).
"""
from pathlib import Path
import os
import json
import shutil
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
STEP = next(step for step in yaml.safe_load((ROOT / ".github/workflows/presubmit.yml").read_text(encoding="utf-8"))
            ["jobs"]["gate"]["steps"] if str(step.get("name", "")).startswith("[CHECK 2]"))


def run_gate(files: dict[str, int | None], *, local=False, branch="fix/x", base_files=None) -> subprocess.CompletedProcess:
    """files: path -> number of added lines on the PR branch."""
    with tempfile.TemporaryDirectory() as tmp:
        git = lambda *args: subprocess.run(["git", *args], cwd=tmp, check=True, capture_output=True)
        git("init", "-q", "-b", "main")
        # Exercise the real local CLI without exporting/reimplementing its size rule.
        # Unrelated generated-file checks are inspected separately below.
        for source in ["scripts/ci-preflight.mjs", "scripts/ci/gov-codes.mjs", "scripts/ci/branch-policy.mjs"]:
            target = Path(tmp, source)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / source, target)
        for name, lines in (base_files or {}).items():
            target = Path(tmp, name)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("".join(f"old {i}\n" for i in range(lines)))
        git("add", "-A")
        git("-c", "user.email=t@t", "-c", "user.name=t", "commit", "-q", "--allow-empty", "-m", "base")
        git("update-ref", "refs/remotes/origin/main", "HEAD")
        git("checkout", "-q", "-b", branch)
        for path, lines in files.items():
            target = Path(tmp, path)
            target.parent.mkdir(parents=True, exist_ok=True)
            if lines is None:
                target.unlink()
                continue
            text = "".join(f"line {i}\n" for i in range(lines))
            if path.startswith(".github/workflows/"):
                header = "on:\n  workflow_dispatch:\njobs:\n  fixture:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo synthetic\n"
                text = header + "# synthetic size fixture\n" * (lines - len(header.splitlines()))
            target.write_text(text, encoding="utf-8")
        git("add", "-A")
        git("-c", "user.email=t@t", "-c", "user.name=t", "commit", "-q", "-m", "pr")
        env = {**os.environ, "GITHUB_HEAD_REF": branch, "GITHUB_ENV": os.devnull}
        # Keep host CI/base settings out of the synthetic repository.
        for key in ["PR_BASE_SHA", "GITHUB_BASE_SHA", "GITHUB_BASE_REF"]:
            env.pop(key, None)
        command = ["node", "scripts/ci-preflight.mjs", "--json"] if local else ["bash", "-e", "-c", STEP["run"]]
        return subprocess.run(command, cwd=tmp, env=env, capture_output=True, text=True)


class SizeGateTest(unittest.TestCase):
    def test_actual_local_cli_matches_native_policy(self):
        cases = [
            ({"backend/app/X.php": 280, "backend/tests/Feature/XTest.php": 500, "docs/x.md": 10}, True),
            ({"backend/app/X.php": 701}, False),
            ({"backend/app/X.php": 100, "backend/tests/Feature/XTest.php": 1401}, False),
            ({"backend/app/X.php": 700, "backend/tests/Feature/XTest.php": 800}, True),
            ({"scripts/tool.mjs": 1300}, True),
            ({"scripts/tool.mjs": 1301}, False),
            ({"backend/app/X.php": 1, "scripts/tool.mjs": 400, ".github/workflows/x.yml": 300}, False),
            ({"backend/app/X.php": 1, ".claude/skills/example/SKILL.md": 1600, "package-lock.json": 1600}, True),
            ({"backend/app/X.php": 200, "frontend/e2e/x.js": 600, "frontend/src/x.spec.js": 600}, True),
        ]
        cases.extend([
            ({"backend/app/Old.php": None}, True, {"base_files": {"backend/app/Old.php": 3000}}),
            ({"backend/app/Old.php": None}, False, {"base_files": {"backend/app/Old.php": 3001}}),
            ({"backend/app/X.php": 31, "backend/app/Old.php": None}, False,
             {"base_files": {"backend/app/Old.php": 1000}}),
            ({"docs/archive/x.md": 1600}, True, {"branch": "chore/docs-size-fixture"}),
        ])
        for files, allowed, *options in cases:
            with self.subTest(files=files, options=options):
                native = run_gate(files, **(options[0] if options else {}))
                self.assertEqual(allowed, native.returncode == 0, native.stdout + native.stderr)
                local = run_gate(files, local=True, **(options[0] if options else {}))
                result = json.loads(local.stdout)
                # The minimal fixture deliberately omits sync-generated.mjs;
                # no other unrelated failure may make a size test appear green.
                self.assertEqual(["GOV-GENERATED-001"], [e["code"] for e in result["errors"] if e["code"] != "GOV-SIZE-001"])
                size_errors = [e for e in result["errors"] if e["code"] == "GOV-SIZE-001"]
                self.assertEqual(allowed, not size_errors, size_errors)

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
