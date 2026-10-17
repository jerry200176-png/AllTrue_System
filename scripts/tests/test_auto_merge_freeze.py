"""Executes auto-merge-safe.yml's real step scripts against a fake `gh` (merge freeze, edited, disarm)."""

import json
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).parents[2]
WF = yaml.safe_load((ROOT / ".github/workflows/auto-merge-safe.yml").read_text(encoding="utf-8"))
JOBS = WF["jobs"]
FROZEN = 'frozen: true\nreason: "test freeze"\nsince: 2026-10-15\n'
OPEN = 'frozen: false\nreason: ""\nsince: 2026-10-15\n'
T0_BODY = "Risk-Class: R0\nAutonomy-Tier: T0\nRollback: revert\n"
T3_BODY = "Risk-Class: R3\nAutonomy-Tier: T3\nRollback: revert\n"
FAKE_GH = r"""#!/usr/bin/env bash
echo "$*" >> "$FAKE_DIR/log"
case "$*" in
  *contents/operations/merge-freeze.yaml*) [ -f "$FAKE_DIR/freeze" ] || exit 1; cat "$FAKE_DIR/freeze" ;;
  *"pulls/7/files"*) cat "$FAKE_DIR/files.json" ;;
  *".head.sha"*) echo "$HEAD" ;;
  *".auto_merge != null"*) cat "$FAKE_DIR/armed" ;;
  *"pulls/7"*) cat "$FAKE_DIR/pr.json" ;;
  *"--disable-auto"*) echo false > "$FAKE_DIR/armed" ;;
  *"--auto --squash"*) echo true > "$FAKE_DIR/armed" ;;
  *) echo "unexpected gh $*" >&2; exit 2 ;;
esac
"""


def expr(text, ctx):
    """Evaluate the small GitHub-expression subset these jobs use (==, !=, &&, ||, !, quoted strings)."""
    body = text.strip().removeprefix("${{").removesuffix("}}")
    body = re.sub(r"[a-z_]+(?:\(\)|(?:\.[a-z_]+)+)", lambda m: repr(ctx[m.group(0)]), body)
    body = body.replace("!=", " <> ").replace("!", " not ").replace(" <> ", " != ")
    return eval(body.replace("&&", " and ").replace("||", " or "), {})  # noqa: S307 - test-only, fixed input


class AutoMergeFreezeTest(unittest.TestCase):
    def setUp(self):
        self.dir = Path(tempfile.mkdtemp())
        (self.dir / "gh").write_text(FAKE_GH)
        (self.dir / "gh").chmod(0o755)
        (self.dir / "files.json").write_text(json.dumps([[{"filename": "docs/x.md", "patch": "+x"}]]))
        self.out = self.dir / "out"

    def world(self, freeze, body, armed):
        if freeze is not None:
            (self.dir / "freeze").write_text(freeze)
        (self.dir / "pr.json").write_text(json.dumps({"head": {"sha": "abc"}, "body": body}))
        (self.dir / "armed").write_text("true" if armed else "false")

    def run_step(self, job, name):
        step = next(s for s in JOBS[job]["steps"] if s.get("name") == name)
        self.out.write_text("")
        env = {**os.environ, "PATH": f"{self.dir}:{os.environ['PATH']}", "FAKE_DIR": str(self.dir),
               "GITHUB_OUTPUT": str(self.out), "REPO": "o/r", "PR_NUMBER": "7", "HEAD": "abc",
               "EVENT_HEAD_SHA": "abc", "BASE_REF": "main", "GH_TOKEN": "x"}
        return subprocess.run(["bash", "-c", step["run"]], cwd=ROOT, env=env, capture_output=True, text=True)

    def pipeline(self):
        """classify job steps -> job outputs -> which of enable/disable runs -> run it. Returns final armed state."""
        ok = self.run_step("classify", "Classify pull request without executing its code").returncode == 0
        steps = {"classify": dict(re.findall(r"^(\w+)=(.*)$", self.out.read_text(), re.M))}
        if ok:
            ok = self.run_step("classify", "Read merge freeze from the base branch (fail closed)").returncode == 0
            steps["freeze"] = dict(re.findall(r"^(\w+)=(.*)$", self.out.read_text(), re.M))
        ctx = {f"steps.{s}.outputs.{k}": v for s in ("classify", "freeze") for k, v in steps.get(s, {}).items()}
        ctx.setdefault("steps.freeze.outputs.frozen", "")
        ctx.setdefault("steps.classify.outputs.decision", "")
        out = {"needs.classify.outputs.decision": expr(JOBS["classify"]["outputs"]["decision"], ctx) or "",
               "needs.classify.result": "success" if ok else "failure", "cancelled()": False}
        self.decision = out["needs.classify.outputs.decision"]
        if expr(JOBS["enable-auto-merge"]["if"], out):
            self.assertEqual(self.run_step("enable-auto-merge", "Enable server-side squash auto-merge").returncode, 0)
        if expr(JOBS["disable-auto-merge"]["if"], out):
            self.assertEqual(self.run_step("disable-auto-merge", "Disable server-side auto-merge if armed").returncode, 0)
        return (self.dir / "armed").read_text().strip() == "true"

    def test_open_t0_arms(self):
        self.world(OPEN, T0_BODY, armed=False)
        self.assertTrue(self.pipeline())
        self.assertEqual(self.decision, "auto")

    def test_frozen_does_not_arm_and_disarms_existing(self):
        self.world(FROZEN, T0_BODY, armed=True)
        self.assertFalse(self.pipeline())
        self.assertEqual(self.decision, "hold")
        self.assertNotIn("--auto --squash", (self.dir / "log").read_text())

    def test_edited_to_higher_tier_disarms(self):
        self.assertIn("edited", WF[True]["pull_request_target"]["types"])
        self.world(OPEN, T3_BODY, armed=True)
        self.assertFalse(self.pipeline())
        self.assertEqual(self.decision, "hold")

    def test_malformed_or_unreadable_freeze_is_frozen(self):
        for freeze in ("frozen: maybe\n", "frozen: false\nfrozen: false\n", "", None):
            with self.subTest(freeze=freeze):
                (self.dir / "freeze").unlink(missing_ok=True)
                self.world(freeze, T0_BODY, armed=True)
                self.assertFalse(self.pipeline())
                self.assertEqual(self.decision, "hold")

    def test_failed_classification_disarms(self):
        self.world(OPEN, T0_BODY, armed=True)
        (self.dir / "pr.json").write_text(json.dumps({"head": {"sha": "moved"}, "body": T0_BODY}))
        self.assertFalse(self.pipeline())

    def test_freeze_rechecked_right_before_arming(self):
        self.world(FROZEN, T0_BODY, armed=True)
        self.assertEqual(self.run_step("enable-auto-merge", "Enable server-side squash auto-merge").returncode, 0)
        self.assertNotIn("--auto --squash", (self.dir / "log").read_text())
        self.assertEqual((self.dir / "armed").read_text().strip(), "false")


if __name__ == "__main__":
    unittest.main()
