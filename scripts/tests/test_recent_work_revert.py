#!/usr/bin/env python3
"""Temp-repo tests for scripts/check-recent-work-revert.py (run: python3 scripts/tests/test_recent_work_revert.py)."""
import importlib.util
import os
import pathlib
import subprocess
import tempfile
import unittest

SRC = pathlib.Path(__file__).resolve().parents[1] / "check-recent-work-revert.py"
spec = importlib.util.spec_from_file_location("guard", SRC)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

F = "backend/app/Foo.php"
OLD = "keep_this_line_alpha();\n"
NEW = "recently_merged_feature();\n"


class T(unittest.TestCase):
    def setUp(self):
        self.d = tempfile.TemporaryDirectory()
        self.old_cwd = os.getcwd()
        os.chdir(self.d.name)
        self.addCleanup(self.d.cleanup)
        self.addCleanup(os.chdir, self.old_cwd)
        self.g("init", "-q", "-b", "main")

    def g(self, *a, date=None):
        env = dict(os.environ, GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t", GIT_COMMITTER_NAME="t",
                   GIT_COMMITTER_EMAIL="t@t")
        if date:
            env["GIT_COMMITTER_DATE"] = env["GIT_AUTHOR_DATE"] = date
        subprocess.run(["git", *a], check=True, capture_output=True, env=env)

    def commit(self, files, msg, date=None):
        for p, c in files.items():
            pathlib.Path(p).parent.mkdir(parents=True, exist_ok=True)
            pathlib.Path(p).write_text(c)
        self.g("add", "-A")
        self.g("commit", "-q", "-m", msg, date=date)

    def hits(self, days=7):
        self.g("update-ref", "refs/remotes/origin/main", "main")  # base = main tip
        return guard.find_hits("main", "pr", days)

    def pr(self):
        self.g("checkout", "-q", "-b", "pr")

    def test_deleting_recent_main_line_fails(self):
        self.commit({F: OLD + NEW}, "feat: add (#3615)")
        self.pr()
        self.commit({F: OLD}, "stale copy")
        h = self.hits()
        self.assertEqual([(x[0], x[1], x[3]) for x in h], [(F, 2, "#3615")])

    def test_old_line_passes(self):
        self.commit({F: OLD + NEW}, "feat: add (#1)", date="2020-01-01T00:00:00")
        self.pr()
        self.commit({F: OLD}, "cleanup")
        self.assertEqual(self.hits(), [])

    def test_line_added_then_removed_by_pr_passes(self):
        self.commit({F: OLD}, "base")
        self.pr()
        self.commit({F: OLD + NEW}, "add")
        self.commit({F: OLD}, "remove")
        self.assertEqual(self.hits(), [])

    def test_moved_line_passes(self):
        self.commit({F: OLD + NEW}, "feat (#9)")
        self.pr()
        self.commit({F: OLD, "backend/app/Bar.php": NEW}, "move")
        self.assertEqual(self.hits(), [])

    def test_branch_behind_main_passes(self):
        self.commit({F: OLD}, "base")
        self.pr()
        self.commit({"scripts/x.sh": "unrelated_change_here\n"}, "pr work")
        self.g("checkout", "-q", "main")
        self.commit({F: OLD + NEW}, "feat (#9) lands after the branch point")
        self.assertEqual(self.hits(), [])

    def test_edited_line_passes(self):
        self.commit({F: OLD + "assert.equal(split(x).length - 1, 3);\n"}, "feat (#9)")
        self.pr()
        self.commit({F: OLD + "assert.equal(split(x).length - 1, 4);\n"}, "tweak")
        self.assertEqual(self.hits(), [])

    def test_out_of_scope_path_ignored(self):
        self.commit({"docs/a.md": NEW}, "docs (#9)")
        self.pr()
        self.commit({"docs/a.md": ""}, "rm")
        self.assertEqual(self.hits(), [])


if __name__ == "__main__":
    unittest.main()
