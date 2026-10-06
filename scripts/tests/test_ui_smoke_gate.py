"""Execute the actual UI Smoke preflight steps against bounded negative cases."""

from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


WORKFLOW = Path(__file__).resolve().parents[2] / ".github/workflows/ui-smoke.yml"


def workflow_run_script(step_name: str) -> str:
    lines = WORKFLOW.read_text(encoding="utf-8").splitlines()
    marker = f"      - name: {step_name}"
    start = lines.index(marker)
    run = next(i for i in range(start + 1, len(lines)) if lines[i] == "        run: |")
    body = []
    for line in lines[run + 1 :]:
        if line and not line.startswith("          "):
            break
        body.append(line)
    script = textwrap.dedent("\n".join(body))
    if not script.strip():
        raise AssertionError(f"empty run script for {step_name}")
    return script


class UiSmokeWorkflowGateTest(unittest.TestCase):
    def test_git_diff_error_fails_instead_of_skipping_playwright(self):
        script = workflow_run_script("Detect frontend diff (PR only)")
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            fake_git = root / "git"
            fake_git.write_text("#!/bin/sh\nexit 42\n", encoding="utf-8")
            fake_git.chmod(0o755)
            output = root / "github-output"
            result = subprocess.run(
                ["bash", "-c", script],
                env={
                    "PATH": f"{root}:/usr/bin:/bin",
                    "EVENT": "pull_request",
                    "BASE_SHA": "a" * 40,
                    "HEAD_SHA": "b" * 40,
                    "GITHUB_OUTPUT": str(output),
                },
                capture_output=True,
                text=True,
                timeout=10,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("changed-path detection failed", result.stdout)
            self.assertFalse(output.exists())

    def test_frontend_change_runs_and_docs_only_change_skips(self):
        script = workflow_run_script("Detect frontend diff (PR only)")
        for changed, expected in (
            ("frontend/e2e/dashboard-workbench.spec.js", "true"),
            ("frontend/playwright.config.js", "true"),
            ("frontend/package-lock.json", "true"),
            (".github/workflows/ui-smoke.yml", "true"),
            ("docs/README.md", "false"),
        ):
            with self.subTest(changed=changed), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                fake_git = root / "git"
                fake_git.write_text(f"#!/bin/sh\nprintf '%s\\n' '{changed}'\n", encoding="utf-8")
                fake_git.chmod(0o755)
                output = root / "github-output"
                result = subprocess.run(
                    ["bash", "-c", script],
                    env={
                        "PATH": f"{root}:/usr/bin:/bin",
                        "EVENT": "pull_request",
                        "BASE_SHA": "a" * 40,
                        "HEAD_SHA": "b" * 40,
                        "GITHUB_OUTPUT": str(output),
                    },
                    capture_output=True,
                    text=True,
                    timeout=10,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(output.read_text(encoding="utf-8"), f"run={expected}\n")

    def test_each_missing_required_secret_fails_without_printing_values(self):
        script = workflow_run_script("Require production smoke secrets (TD-070)")
        names = (
            "SMOKE_BASE_URL",
            "SMOKE_DIRECTOR_USER",
            "SMOKE_DIRECTOR_PASS",
            "SMOKE_TEACHER_USER",
            "SMOKE_TEACHER_PASS",
        )
        values = {name: f"fixture-value-{index}" for index, name in enumerate(names)}
        complete = subprocess.run(
            ["bash", "-c", script], env={"PATH": os.environ.get("PATH", ""), **values},
            capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(complete.returncode, 0, complete.stderr)
        for missing in names:
            with self.subTest(missing=missing):
                result = subprocess.run(
                    ["bash", "-c", script],
                    env={"PATH": os.environ.get("PATH", ""), **values, missing: ""},
                    capture_output=True, text=True, timeout=10,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(missing, result.stdout)
                for value in values.values():
                    self.assertNotIn(value, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
