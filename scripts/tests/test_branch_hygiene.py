#!/usr/bin/env python3
"""Regression test for the read-only branch hygiene report."""

from __future__ import annotations

import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "branch-hygiene.sh"


def run_git(cwd: Path, *args: str) -> None:
    subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True)


class BranchHygieneTest(unittest.TestCase):
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
