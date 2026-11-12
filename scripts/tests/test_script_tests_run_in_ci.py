#!/usr/bin/env python3
"""Every script test under scripts/tests is run by CI, or is listed below with the reason it cannot be.

CI used to run script tests from a hand-kept list in ci.yml. A test file nobody added to the list was never
run, and went stale while the workflows and services it pins kept changing (#5201). Python tests are now run
by discovery; this guard fails when a file is neither collected by that discovery, nor invoked by a workflow
command, nor excluded here with a reason.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TESTS = ROOT / "scripts/tests"
WORKFLOWS = ROOT / ".github/workflows"
KINDS = ("test_*.py", "*.test.mjs", "*.test.sh")
DISCOVER = re.compile(r"python3 -m unittest discover -s scripts/tests -p '([^']+)'")
RUNNER = re.compile(r'(?:python3|node|bash|"[^"\s]*/python")\s')

# Files CI cannot run. Each needs a reason; an entry for a file that is run, or that is gone, fails the guard.
NOT_RUN_IN_CI = {
    "scripts/tests/test_dbhub_readonly.py":
        "manual proof that takes a DBHub binary as its argument (infra/agent-readonly/README.md); "
        "the binary is not on the runner and the file defines no unittest case",
    "scripts/tests/test_dbhub_mysql_readonly.py":
        "manual proof that needs the DBHub binary and a disposable MySQL container "
        "(infra/agent-readonly/README.md)",
}


def commands(text: str) -> list[str]:
    """Workflow lines that start a test runner; comments, path filters and step names are not commands."""
    found = []
    for line in text.splitlines():
        command = re.sub(r"^(?:- )?(?:run: )?", "", line.strip())
        if RUNNER.match(command):
            found.append(command)
    return found


def invoked(path: str, lines: list[str]) -> bool:
    """True when a command runs the whole file: by path, or as a whole module (not one class of it)."""
    by_path = re.compile(r"(?<![\w./-])" + re.escape(path) + r"(?![\w./-])")
    module = "scripts.tests." + Path(path).stem if path.endswith(".py") else None
    by_module = re.compile(r"-m unittest (?:\S+ )*" + re.escape(module) + r"(?![\w.])") if module else None
    return any(by_path.search(line) or (by_module and by_module.search(line)) for line in lines)


def not_run(files: list[str], lines: list[str], collected: set[str], excluded: dict[str, str]) -> list[str]:
    return sorted(path for path in files
                  if path not in collected and path not in excluded and not invoked(path, lines))


def job_block(workflow: str, job: str) -> str:
    start = workflow.index(f"\n  {job}:\n")
    following = re.search(r"\n  [\w-]+:\n", workflow[start + 1:])
    return workflow[start:start + 1 + following.start()] if following else workflow[start:]


def discovery_patterns() -> list[str]:
    """Patterns of the discovery commands in the required Control Plane job of ci.yml."""
    job = job_block((WORKFLOWS / "ci.yml").read_text(encoding="utf-8"), "control_plane")
    return [match.group(1) for line in commands(job) for match in [DISCOVER.search(line)] if match]


def collected_by_discovery(patterns: list[str]) -> set[str]:
    """Files from which unittest discovery really collects at least one test."""
    modules: set[str] = set()

    def walk(suite):
        for item in suite:
            if isinstance(item, unittest.TestSuite):
                walk(item)
            else:
                modules.add(type(item).__module__.rsplit(".", 1)[-1])

    for pattern in patterns:
        walk(unittest.TestLoader().discover(str(TESTS), pattern=pattern))
    return {f"scripts/tests/{path.name}" for path in TESTS.glob("*.py") if path.stem in modules}


def script_tests() -> list[str]:
    return sorted(f"scripts/tests/{path.name}" for kind in KINDS for path in TESTS.glob(kind))


def workflow_commands() -> list[str]:
    return [line for path in sorted(WORKFLOWS.glob("*.yml")) for line in commands(path.read_text(encoding="utf-8"))]


class ScriptTestsRunInCiTest(unittest.TestCase):
    def test_discovery_runs_in_the_required_control_plane_job(self):
        self.assertEqual(discovery_patterns(), ["test_*.py"])

    def test_every_script_test_is_run_or_excluded_with_a_reason(self):
        missing = not_run(script_tests(), workflow_commands(),
                          collected_by_discovery(discovery_patterns()), NOT_RUN_IN_CI)
        self.assertEqual(missing, [], "no workflow runs these script tests. Python: give the file a "
                         "unittest.TestCase so discovery collects it. .mjs/.sh: add it to the Control Plane "
                         "step in .github/workflows/ci.yml. Only if CI cannot run it: NOT_RUN_IN_CI with a reason.")

    def test_exclusions_have_a_reason_and_are_still_needed(self):
        files = script_tests()
        lines = workflow_commands()
        collected = collected_by_discovery(discovery_patterns())
        for path, reason in NOT_RUN_IN_CI.items():
            with self.subTest(path=path):
                self.assertIn(path, files, "excluded file no longer exists")
                self.assertGreaterEqual(len(reason.strip()), 20, "exclusion needs a real reason")
                self.assertEqual(not_run([path], lines, collected, {}), [path], "file is run; drop the exclusion")


class GuardLogicTest(unittest.TestCase):
    """Synthetic inputs: the guard must fail for the cases that let tests rot."""

    LINES = commands("""
      paths:
        - 'scripts/tests/test_only_a_path_filter.py'
      - name: python3 scripts/tests/test_only_a_step_name.py
        run: |
          # python3 scripts/tests/test_commented_out.py
          python3 scripts/tests/test_named.py
          python3 -m unittest scripts.tests.test_whole_module
          python3 -m unittest scripts.tests.test_one_class.OnlyThisClass
          "$RUNNER_TEMP/venv/bin/python" -m unittest scripts.tests.test_in_venv
          node --test scripts/tests/a.test.mjs scripts/tests/b.test.mjs
          echo scripts/tests/c.test.mjs
      - run: bash scripts/tests/d.test.sh
    """)

    def check(self, path, collected=()):
        return not_run([path], self.LINES, set(collected), {}) == []

    def test_invoked_files_pass(self):
        for name in ("test_named.py", "test_whole_module.py", "test_in_venv.py", "a.test.mjs", "b.test.mjs", "d.test.sh"):
            with self.subTest(name=name):
                self.assertTrue(self.check(f"scripts/tests/{name}"))

    def test_a_new_unreferenced_file_fails(self):
        for name in ("test_new.py", "new.test.mjs", "new.test.sh"):
            with self.subTest(name=name):
                self.assertFalse(self.check(f"scripts/tests/{name}"))

    def test_mentions_that_run_nothing_do_not_count(self):
        for name in ("test_only_a_path_filter.py", "test_only_a_step_name.py", "test_commented_out.py",
                     "test_one_class.py", "c.test.mjs", "test_name.py", "est_named.py"):
            with self.subTest(name=name):
                self.assertFalse(self.check(f"scripts/tests/{name}"))

    def test_collection_by_discovery_counts_and_exclusion_needs_listing(self):
        path = "scripts/tests/test_new.py"
        self.assertTrue(self.check(path, collected=[path]))
        self.assertEqual(not_run([path], self.LINES, set(), {path: "reason"}), [])

    def test_a_python_file_without_a_collectable_case_is_not_collected(self):
        collected = collected_by_discovery(["test_*.py"])
        self.assertIn("scripts/tests/test_script_tests_run_in_ci.py", collected)
        # Plain test functions and a __main__ block: discovery collects nothing from these files.
        self.assertNotIn("scripts/tests/test_inapp_issue_reconcile.py", collected)
        self.assertNotIn("scripts/tests/test_dbhub_readonly.py", collected)

    def test_job_block_stops_at_the_next_job(self):
        workflow = "jobs:\n  control_plane:\n    steps:\n      - run: a\n  changes:\n    steps:\n      - run: b\n"
        self.assertIn("run: a", job_block(workflow, "control_plane"))
        self.assertNotIn("run: b", job_block(workflow, "control_plane"))


if __name__ == "__main__":
    unittest.main()
