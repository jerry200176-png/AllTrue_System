"""post-merge-smoke.sh compares abbreviated commit ids by prefix, not by exact string.

A real release on 2026-11-13 was rolled back because version.json held a 9-character id and
the check expected 8 characters of the same commit (`git rev-parse --short` grows with the repo).
"""

from __future__ import annotations

from pathlib import Path
import os
import subprocess
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "post-merge-smoke.sh"


def same_commit(a: str, b: str) -> bool:
    env = {**os.environ, "SMOKE_SOURCE_ONLY": "1", "SMOKE_PI_BACKEND": "/nonexistent"}
    proc = subprocess.run(
        ["bash", "-c", f'source "{SCRIPT}" && same_commit "$1" "$2"', "_", a, b],
        env=env, capture_output=True, text=True, timeout=30,
    )
    return proc.returncode == 0


class SameCommitTest(unittest.TestCase):
    def test_longer_abbreviation_of_the_same_commit_matches(self):
        self.assertTrue(same_commit("514049762", "51404976"))
        self.assertTrue(same_commit("51404976", "5140497621006bbb5aca0a3dc7ea8b683fa0a74a"))
        self.assertTrue(same_commit("ABCDEF12", "abcdef1234"))

    def test_different_commits_do_not_match(self):
        self.assertFalse(same_commit("514049762", "51404977"))
        self.assertFalse(same_commit("f714326c", "51404976"))

    def test_too_short_or_not_hex_never_matches(self):
        self.assertFalse(same_commit("514049", "51404976"))
        self.assertFalse(same_commit("", "51404976"))
        self.assertFalse(same_commit("51404976", ""))
        self.assertFalse(same_commit("main", "main"))

    def test_every_commit_comparison_in_the_script_uses_the_helper(self):
        src = SCRIPT.read_text(encoding="utf-8")
        self.assertNotIn('"$ver_hash" != ', src)
        self.assertNotIn('!= "${pi_commit', src)
        self.assertGreaterEqual(src.count("same_commit "), 3)


if __name__ == "__main__":
    unittest.main()
