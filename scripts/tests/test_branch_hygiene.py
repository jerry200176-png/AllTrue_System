#!/usr/bin/env python3
"""Regression test for the read-only branch hygiene report."""

from __future__ import annotations

import subprocess
import os
import re
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "branch-hygiene.sh"


def run_git(cwd: Path, *args: str) -> None:
    subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True)


class BranchHygieneTest(unittest.TestCase):
    def test_all_hygiene_writers_share_one_non_cancelling_lock(self):
        workflow = (ROOT / ".github/workflows/branch-hygiene.yml").read_text()
        concurrency = re.search(r"^concurrency:\n((?:  [^\n]*\n)+)", workflow, re.MULTILINE)
        self.assertIsNotNone(concurrency, "manual and scheduled writers need a shared workflow lock")
        group = re.search(r"^  group:\s*(\S[^\n]*)$", concurrency.group(1), re.MULTILINE)
        self.assertIsNotNone(group)
        # All refs and trigger modes update the same report/alarm trackers.
        # Per-run, per-schedule or per-ref keys permit read-before-create races.
        for varying_context in ("github.run_id", "github.run_attempt", "github.event", "github.ref", "github.sha"):
            self.assertNotIn(varying_context, group.group(1))
        self.assertRegex(concurrency.group(1), r"(?m)^  cancel-in-progress:\s*false\s*$")
        self.assertRegex(concurrency.group(1), r"(?m)^  queue:\s*max\s*$")

    def test_checkout_report_needs_no_credentials_and_failures_stop(self):
        with tempfile.TemporaryDirectory(prefix="branch-hygiene-stub-") as raw_dir:
            root = Path(raw_dir)
            log = root / "calls"
            stub = root / "git"
            stub.write_text(f"#!{sys.executable}\n" + """
import os, sys
with open(os.environ['GIT_STUB_LOG'], 'a') as log:
    log.write(' '.join(sys.argv[1:]) + '\\n')
if sys.argv[1] == 'fetch':
    sys.exit(1)  # private origin has no checkout credential
if sys.argv[1:3] == ['rev-parse', '--verify'] and os.environ.get('FAIL_BASE'):
    sys.exit(1)
if sys.argv[1] == 'rev-parse':
    print('main')
""", encoding="utf-8")
            stub.chmod(0o755)
            env = {**os.environ, "PATH": f"{root}{os.pathsep}{os.environ['PATH']}", "GIT_STUB_LOG": str(log)}

            def report(*args, extra=None):
                return subprocess.run(["bash", str(SCRIPT), *args], cwd=root,
                                      env={**env, **(extra or {})}, capture_output=True, text=True)

            result = report()
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(log.read_text().splitlines(), ["fetch --prune"])
            log.unlink()
            result = report("--no-fetch")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("Dry-run complete. No branch deleted.", result.stdout)
            self.assertNotIn("fetch", log.read_text())
            self.assertNotIn("push", log.read_text())
            log.unlink()
            result = report("--no-fetch", "--apply")
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(log.exists())
            result = report("--no-fetch", extra={"FAIL_BASE": "1"})
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn("Dry-run complete", result.stdout)

    def test_workflow_reuses_complete_checkout_without_persisting_credentials(self):
        workflow = (ROOT / ".github/workflows/branch-hygiene.yml").read_text()
        self.assertIn("fetch-depth: 0", workflow)
        self.assertEqual(workflow.count("persist-credentials: false"), 2)
        self.assertNotIn("persist-credentials: true", workflow)
        self.assertIn("OUTPUT=$(./scripts/branch-hygiene.sh --no-fetch 2>&1)", workflow)
        self.assertEqual(workflow.count("pull-requests: read"), 2)
        self.assertNotIn("pull-requests: write", workflow)
        self.assertIn("workflow_dispatch: {}", workflow)
        self.assertNotIn("--close", workflow)

    def test_symbolic_remote_head_is_not_a_deletion_candidate(self):
        with tempfile.TemporaryDirectory(prefix="branch-hygiene-") as raw_dir:
            root = Path(raw_dir)
            remote = root / "remote.git"
            seed = root / "seed"
            clone = root / "clone"
            run_git(root, "init", "--bare", str(remote))
            run_git(root, "init", str(seed))
            run_git(seed, "config", "user.email", "test@example.invalid")
            run_git(seed, "config", "user.name", "branch-hygiene-test")
            (seed / "README").write_text("test\n", encoding="utf-8")
            run_git(seed, "add", "README")
            run_git(seed, "commit", "-m", "test")
            run_git(seed, "branch", "-M", "main")
            run_git(seed, "remote", "add", "origin", str(remote))
            run_git(seed, "push", "origin", "main")
            run_git(remote, "symbolic-ref", "HEAD", "refs/heads/main")
            run_git(root, "clone", str(remote), str(clone))
            run_git(clone, "remote", "set-head", "origin", "main")

            result = subprocess.run(
                ["bash", str(SCRIPT)],
                cwd=clone,
                check=True,
                capture_output=True,
                text=True,
            )

            self.assertIn("Mode: DRY-RUN", result.stdout)
            self.assertIn("No remote merged branches eligible for deletion.", result.stdout)
            self.assertNotIn("HEAD -> origin/main", result.stdout)
            self.assertIn("Dry-run complete. No branch deleted.", result.stdout)


if __name__ == "__main__":
    unittest.main()
