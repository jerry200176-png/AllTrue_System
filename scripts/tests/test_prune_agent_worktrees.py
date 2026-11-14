#!/usr/bin/env python3
"""Regression tests for scripts/dev/prune-agent-worktrees.sh (throwaway repo in a temp dir; never touches real worktrees).

PRUNE_SCRIPT overrides the script under test (used to prove the tests fail on the previous version).
"""
from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(os.environ.get("PRUNE_SCRIPT") or ROOT / "scripts/dev/prune-agent-worktrees.sh")


class PruneAgentWorktrees(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.tmp = Path(tempfile.mkdtemp())
        cls.addClassCleanup(shutil.rmtree, cls.tmp, ignore_errors=True)  # registered first: runs even if setup fails
        home = cls.tmp / "home"
        home.mkdir()
        cls.env = {**os.environ, "HOME": str(home), "GIT_CONFIG_GLOBAL": "/dev/null",
                   "GIT_CONFIG_SYSTEM": "/dev/null", "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@example.com",
                   "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@example.com"}
        for var in ("GH_TOKEN", "GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_COMMON_DIR"):
            cls.env.pop(var, None)  # an exported value must not point git at a real repo
        cls.repo = cls.tmp / "repo"
        cls.git(cls.tmp, "init", "-q", "--bare", "origin.git")
        cls.git(cls.tmp, "init", "-q", "-b", "main", "repo")
        cls.git(cls.repo, "remote", "add", "origin", str(cls.tmp / "origin.git"))
        (cls.repo / ".gitignore").write_text("node_modules/\nfrontend/node_modules/\nbackend/storage/\n")
        (cls.repo / "a.txt").write_text("x\n")
        (cls.repo / "scripts").mkdir()  # the script under test calls the repo's own index audit
        shutil.copy(ROOT / "scripts/git-index-audit.sh", cls.repo / "scripts/git-index-audit.sh")
        cls.git(cls.repo, "add", "-A")
        cls.git(cls.repo, "commit", "-qm", "init")
        cls.git(cls.repo, "push", "-q", "origin", "main")
        cases = {
            "cache-only": ["node_modules/x/i.js", "backend/storage/framework/cache/c"],
            "untracked-hidden": ["new.txt"],
            "repair-snapshot": ["backend/storage/app/repair-snapshots/snap.json"],
            "notes-file": ["frontend/node_modules-notes.txt"],
            "storage-public": ["backend/storage/app/public/f.png"],
            "assume-unchanged": [],
            "skip-worktree": [],
        }
        for name, files in cases.items():
            cls.git(cls.repo, "worktree", "add", "-q", "--detach", f".claude/worktrees/{name}", "main")
            w = cls.repo / ".claude/worktrees" / name
            for rel in files:
                (w / rel).parent.mkdir(parents=True, exist_ok=True)
                (w / rel).write_text("y\n")
            gd = Path(cls.git(w, "rev-parse", "--absolute-git-dir").strip())
            for f in ("logs/HEAD", "index"):  # old enough to pass the 2-hour recency guard
                os.utime(gd / f, (1, 1))
        # Edits to tracked files that the index flags hide from `git status`.
        for name, flag in (("assume-unchanged", "--assume-unchanged"), ("skip-worktree", "--skip-worktree")):
            w = cls.repo / ".claude/worktrees" / name
            cls.git(w, "update-index", flag, "a.txt")
            (w / "a.txt").write_text("edited\n")
            gd = Path(cls.git(w, "rev-parse", "--absolute-git-dir").strip())
            for f in ("logs/HEAD", "index"):
                os.utime(gd / f, (1, 1))
        # A user config that hides untracked files from `git status`, as a developer might have globally.
        cls.git(cls.repo, "config", "status.showUntrackedFiles", "no")
        out = subprocess.run(["bash", str(SCRIPT), "--dry-run"], cwd=cls.repo, env=cls.env,
                             capture_output=True, text=True, check=True).stdout
        cls.removable = {l.split()[-1] for l in out.splitlines() if l.startswith("would remove ")}

    @classmethod
    def git(cls, cwd: Path, *args: str) -> str:
        return subprocess.run(["git", *args], cwd=cwd, env=cls.env, capture_output=True, text=True, check=True).stdout

    def test_worktree_with_only_regenerable_caches_is_removed(self) -> None:
        self.assertIn("cache-only", self.removable)

    def test_untracked_file_is_kept_even_with_show_untracked_files_no(self) -> None:
        self.assertNotIn("untracked-hidden", self.removable)

    def test_ignored_repair_snapshot_is_kept(self) -> None:
        self.assertNotIn("repair-snapshot", self.removable)

    def test_ignored_storage_public_upload_is_kept(self) -> None:
        self.assertNotIn("storage-public", self.removable)

    def test_node_modules_lookalike_file_is_kept(self) -> None:
        self.assertNotIn("notes-file", self.removable)

    def test_assume_unchanged_edit_is_kept(self) -> None:
        self.assertNotIn("assume-unchanged", self.removable)

    def test_skip_worktree_edit_is_kept(self) -> None:
        self.assertNotIn("skip-worktree", self.removable)


if __name__ == "__main__":
    unittest.main()
