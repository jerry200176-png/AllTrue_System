#!/usr/bin/env python3
"""Request-file trigger for bug-reporter-timeout.yml: parsing, one run per request_id, evidence SHA, tier."""
from __future__ import annotations

import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import bug_reporter_timeout_request as req  # noqa: E402
from scripts.governance.autonomy_gate import classify_scope, validate_declaration  # noqa: E402


def body(request_id: str, mode: str, ids: str = "[]", comment: str = "") -> str:
    return f"# header{comment}\nrequest_id: {request_id}\nmode: {mode}\nreviewed_ids: {ids}\n"


class ParseTest(unittest.TestCase):
    def test_checked_in_request_file_is_valid_and_idle(self):
        parsed = req.parse_request((ROOT / req.REQUEST_PATH).read_text(encoding="utf-8"))
        self.assertEqual(parsed["mode"], "idle", "merging the request file must not start a production run")

    def test_valid_modes(self):
        self.assertEqual(req.parse_request(body("a-1", "auto-dry-run"))["reviewed_ids"], [])
        ids = ", ".join(str(300 + i) for i in range(25))  # longer than the 20-per-run auto cap
        self.assertEqual(len(req.parse_request(body("a-2", "reviewed-apply", f"[{ids}]"))["reviewed_ids"]), 25)
        self.assertEqual(req.parse_request("request_id: x # note\nmode: idle\nreviewed_ids: []")["mode"], "idle")

    def test_rejects(self):
        bad = [
            body("a", "auto"),  # the unattended apply is the schedule's, never a request
            body("a", "apply", "[1]"),
            body("a", "reviewed-apply"),  # empty list
            body("a", "auto-dry-run", "[1]"),
            body("a", "reviewed-apply", "[1, 1]"),
            body("a", "reviewed-apply", "[0]"),
            body("a", "reviewed-apply", "[012]"),
            body("a", "reviewed-apply", "[1;2]"),
            body("a b", "idle"),
            body("", "idle"),
            body("a", "idle") + "extra: 1\n",
            body("a", "idle") + "mode: idle\n",
            "request_id: a\nmode: idle\n",
        ]
        for text in bad:
            with self.subTest(text=text):
                with self.assertRaises(req.RequestError):
                    req.parse_request(text)


class GitResolveTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name)
        self.git("init", "-q")
        self.write("backend/app/Console/Commands/CloseStaleResolvedBugsCommand.php", "<?php // v1\n")
        self.command_sha = self.commit("command")
        self.write("README.md", "unrelated\n")
        self.commit("unrelated")

    def tearDown(self):
        self.tmp.cleanup()

    def git(self, *args: str) -> str:
        return subprocess.run(
            ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid", "-c", "commit.gpgsign=false", "-C", str(self.repo), *args],
            check=True, capture_output=True, text=True,
        ).stdout.strip()

    def write(self, rel: str, text: str) -> None:
        path = self.repo / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")

    def commit(self, message: str) -> str:
        self.git("add", "-A")
        self.git("commit", "-q", "-m", message)
        return self.git("rev-parse", "HEAD")

    def request(self, text: str) -> dict[str, str]:
        self.write(req.REQUEST_PATH, text)
        return req.resolve(self.repo, self.commit("request"))

    def test_same_request_id_runs_once(self):
        self.assertEqual(self.request(body("bootstrap", "idle"))["run"], "false")
        first = self.request(body("2026-10-17-a", "reviewed-apply", "[301, 302]"))
        self.assertEqual((first["run"], first["mode"], first["reviewed_ids"]), ("true", "reviewed-apply", "301,302"))
        again = self.request(body("2026-10-17-a", "reviewed-apply", "[301, 302]", comment=" edited"))
        self.assertEqual((again["run"], again["reason"]), ("false", "request_id_already_used"))
        self.assertEqual(self.request(body("2026-10-17-b", "auto-dry-run"))["run"], "true")
        # Reverting to an earlier request never replays it.
        self.assertEqual(self.request(body("2026-10-17-a", "reviewed-apply", "[301, 302]"))["run"], "false")

    def test_request_is_read_at_head_even_when_head_did_not_touch_it(self):
        self.request(body("2026-10-17-c", "auto-dry-run"))
        self.write("README.md", "later\n")
        head = self.commit("unrelated after request")
        self.assertEqual(req.resolve(self.repo, head)["run"], "true")

    def test_evidence_sha_follows_the_command_code(self):
        self.assertEqual(self.request(body("e-1", "auto-dry-run"))["evidence_sha"], self.command_sha)
        self.write(req.EVIDENCE_PATHS[1], "<?php // service v2\n")
        service_sha = self.commit("service")
        self.assertEqual(self.request(body("e-2", "auto-dry-run"))["evidence_sha"], service_sha)

    def test_cli_writes_ids_only_outputs_and_fails_closed(self):
        self.request(body("cli-1", "reviewed-apply", "[7, 8]"))
        script = ROOT / "scripts" / "bug_reporter_timeout_request.py"
        ok = subprocess.run([sys.executable, str(script), "--repo", str(self.repo)], capture_output=True, text=True)
        self.assertEqual(ok.returncode, 0, ok.stderr)
        keys = {line.split("=", 1)[0] for line in ok.stdout.splitlines()}
        self.assertEqual(keys, {"request_id", "mode", "reviewed_ids", "reviewed_count", "run", "reason", "evidence_sha"})
        self.write(req.REQUEST_PATH, "request_id: cli-2\nmode: auto\nreviewed_ids: []\n")
        self.commit("invalid request")
        bad = subprocess.run([sys.executable, str(script), "--repo", str(self.repo)], capture_output=True, text=True)
        self.assertEqual(bad.returncode, 1)
        self.assertEqual(bad.stdout, "")


class GovernanceTierTest(unittest.TestCase):
    def test_request_file_is_a_t2_production_write(self):
        scope = classify_scope([req.REQUEST_PATH], "")
        self.assertEqual(scope["tier_name"], "T2")
        self.assertIn(f"production-write request file: {req.REQUEST_PATH}", scope["reasons"])
        self.assertFalse(validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [req.REQUEST_PATH])["valid"])
        self.assertEqual(classify_scope(["docs/sop/BUG_INTAKE_TO_PRODUCTION.md", req.REQUEST_PATH], "")["tier_name"], "T2")

    def test_read_only_dump_requests_are_unchanged(self):
        self.assertEqual(classify_scope(["operations/closeout/bug-queue-dump.request.md"], "")["tier_name"], "T0")


if __name__ == "__main__":
    unittest.main()
