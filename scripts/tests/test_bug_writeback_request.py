"""Governed request-file path for in-app bug write-backs (scripts/bug_writeback_request.py)."""
import os
import sys
import unittest
from pathlib import Path
from unittest import mock

import yaml

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import bug_writeback_request as wb  # noqa: E402

REPO = "jerry200176-png/AllTrue_System"
URL = f"https://github.com/{REPO}/issues/3900"
REPLY = f"已看過您的截圖，換週時月曆沒有重新整理，我們正在修，進度在 {URL}"


def entry(**overrides):
    base = {"request_id": "req-0001", "bug_id": 512, "action": "triage", "github_issue_url": URL, "public_reply": REPLY}
    base.update(overrides)
    return {k: v for k, v in base.items() if v is not None}


def doc(*entries):
    return yaml.safe_dump({"requests": list(entries)}, allow_unicode=True)


class ReplyValidatorTest(unittest.TestCase):
    def test_plain_reply_passes(self):
        self.assertEqual(wb.reply_problems(REPLY, URL), [])

    def test_section_3_8_jargon_is_rejected(self):
        for bad in ("StudentClass", "看StudentClass表", "BugReportService", "SELECT 一下", "Laravel",
                    "跑 migration", "CI 綠了", "deploy.yml", "Phase A", "R51", "P0", "PR #3900", "B1"):
            problems = wb.reply_problems(f"已看過您的截圖，{bad}，進度在 {URL}", URL)
            self.assertTrue(any(p.startswith("jargon") for p in problems), bad)

    def test_personal_data_and_length_and_url_are_rejected(self):
        self.assertIn("personal data: phone number", wb.reply_problems(f"請打 0912-345-678 給我們 {URL}", URL))
        self.assertIn("personal data: email address", wb.reply_problems(f"請寄信到 a.b@example.com 謝謝 {URL}", URL))
        self.assertTrue(wb.reply_problems(URL, URL)[0].startswith("length"))
        self.assertTrue(wb.reply_problems(REPLY + "很" * wb.REPLY_MAX_CHARS, URL)[0].startswith("length"))
        self.assertIn("does not contain github_issue_url", wb.reply_problems("已看過您的截圖，我們正在修，謝謝您的回報。", URL))

    def test_limits_follow_module_constants(self):
        old = wb.REPLY_MAX_CHARS
        try:
            wb.REPLY_MAX_CHARS = len(REPLY) - 1
            self.assertTrue(wb.reply_problems(REPLY, URL)[0].startswith("length"))
        finally:
            wb.REPLY_MAX_CHARS = old


class EntryValidationTest(unittest.TestCase):
    def refuse(self, item, fragment):
        with self.assertRaises(wb.RequestError) as ctx:
            wb.plan("", doc(item), REPO)
        self.assertIn(fragment, str(ctx.exception))
        self.assertNotIn(REPLY, str(ctx.exception))

    def test_triage_maps_to_phase_a_without_close_as_logged(self):
        [item] = wb.plan("", doc(entry()), REPO)
        self.assertEqual(item["mode"], "phase-a")
        self.assertEqual(item["env"]["CLOSE_AS_LOGGED"], "false")
        self.assertEqual(item["env"]["DISPOSITION"], "bug")

    def test_followup_only_for_open_statuses(self):
        [item] = wb.plan("", doc(entry(action="followup", expected_status="in_progress")), REPO)
        self.assertEqual((item["mode"], item["env"]["EXPECTED_STATUS"]), ("followup", "in_progress"))
        for status in ("resolved", "closed", None):
            self.refuse(entry(action="followup", expected_status=status), "expected_status")

    def test_resolve_close_and_unknown_actions_are_refused(self):
        for action in ("resolve", "close", "close_as_logged", "reopen"):
            self.refuse(entry(action=action), "action must be")
        self.refuse(entry(close_as_logged=True), "unknown fields")

    def test_issue_url_must_be_this_repository(self):
        self.refuse(entry(github_issue_url="https://github.com/other/repo/issues/1"), "github_issue_url")
        self.refuse(entry(github_issue_url=f"https://github.com/{REPO}/pull/1"), "github_issue_url")

    def test_bad_ids_and_duplicates_fail_closed(self):
        self.refuse(entry(bug_id="512"), "bug_id")
        self.refuse(entry(bug_id=True), "bug_id")
        self.refuse(entry(request_id="Has Spaces"), "request_id")
        with self.assertRaises(wb.RequestError):  # YAML 1.1 would read 0512 as octal 330
            wb.plan("", doc(entry()).replace("bug_id: 512", "bug_id: 0512"), REPO)
        with self.assertRaises(wb.RequestError):
            wb.plan("", doc(entry(), entry(bug_id=513)), REPO)
        with self.assertRaises(wb.RequestError):
            wb.plan("", "requests: []\nextra: 1\n", REPO)

    def test_one_bad_new_entry_blocks_the_whole_run(self):
        with self.assertRaises(wb.RequestError):
            wb.plan("", doc(entry(), entry(request_id="req-0002", public_reply="StudentClass " + URL)), REPO)


class IdempotencyTest(unittest.TestCase):
    def test_same_request_id_is_processed_once(self):
        calls = []
        runner = lambda mode, env: calls.append((mode, env["BUG_ID"])) or 0  # noqa: E731
        first = doc(entry())
        self.assertEqual(wb.apply(wb.plan("", first, REPO), runner, log=lambda _: None), 1)
        # Same file pushed again (or the entry re-listed alongside a new one): only new IDs run.
        self.assertEqual(wb.apply(wb.plan(first, first, REPO), runner, log=lambda _: None), 0)
        second = doc(entry(), entry(request_id="req-0002", bug_id=513))
        self.assertEqual(wb.apply(wb.plan(first, second, REPO), runner, log=lambda _: None), 1)
        self.assertEqual(calls, [("phase-a", "512"), ("phase-a", "513")])

    def test_failure_stops_and_log_has_ids_only(self):
        lines = []
        items = wb.plan("", doc(entry(), entry(request_id="req-0002", bug_id=513)), REPO)
        with self.assertRaises(wb.RequestError):
            wb.apply(items, lambda mode, env: 1, log=lines.append)
        self.assertEqual(lines, ["request_id=req-0001 bug_id=512 action=triage failed exit=1"])

    def test_main_prints_counts_not_reply(self):
        import contextlib
        import io
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            after = Path(tmp) / "after.yml"
            after.write_text(doc(entry()), encoding="utf-8")
            out = io.StringIO()
            with contextlib.redirect_stdout(out), mock.patch.dict(os.environ, {"GITHUB_OUTPUT": ""}):
                self.assertEqual(wb.main(["--after", str(after), "--repo", REPO]), 0)
        self.assertIn("new requests: 1 (req-0001)", out.getvalue())
        self.assertNotIn(REPLY, out.getvalue())

    def test_checked_in_request_file_parses(self):
        text = (ROOT / "operations/closeout/bug-writeback.request.yml").read_text(encoding="utf-8")
        wb.load_requests(text)


if __name__ == "__main__":
    unittest.main()
