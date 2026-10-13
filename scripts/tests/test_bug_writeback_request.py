"""Governed request-file path for in-app bug write-backs (scripts/bug_writeback_request.py)."""
import os
import re
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
    base = {"request_id": "req-0001", "bug_id": 512, "action": "triage", "github_issue_url": URL, "public_reply": REPLY, "captured_at": "2026-10-13T10:00:00.000000Z", "writeback_proof": "a" * 64}
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
        self.assertIn("personal data: email address or handle", wb.reply_problems(f"請寄信到 a.b@example.com 謝謝 {URL}", URL))
        self.assertIn("personal data: long number (ID, phone, account)", wb.reply_problems(f"學號 1234567 的課已經修好了 {URL}", URL))
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
    def test_logged_close_dispositions_are_refused(self):
        for kind in wb.logged_close_kinds():
            self.refuse(entry(disposition=kind), "close as logged")
        self.assertEqual(wb.plan("", doc(entry(disposition="bug")), REPO)[0]["env"]["DISPOSITION"], "bug")
    def test_logged_close_kinds_are_read_from_the_service(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            php = Path(tmp) / "Svc.php"
            php.write_text("public const LOGGED_CLOSE_KINDS = ['wont_do', 'dupe'];", encoding="utf-8")
            self.assertEqual(wb.logged_close_kinds(php), {"wont_do", "dupe"})
            php.write_text("no constant", encoding="utf-8")
            with self.assertRaises(wb.RequestError):
                wb.logged_close_kinds(php)
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
    def test_duplicate_mapping_keys_fail_closed(self):
        dup = doc(entry()).replace("bug_id: 512", "bug_id: 512\n  bug_id: 999", 1)
        with self.assertRaises(wb.RequestError) as ctx:
            wb.plan("", dup, REPO)
        self.assertIn("duplicate mapping key bug_id", str(ctx.exception))
        with self.assertRaises(wb.RequestError):
            wb.plan("", "requests: []\nrequests: []\n", REPO)
    def test_entries_are_append_only(self):
        first = doc(entry())
        for later in (doc(), doc(entry(bug_id=513)), doc(entry(request_id="req-0002"))):
            with self.assertRaises(wb.RequestError) as ctx:
                wb.plan(first, later, REPO)
            self.assertIn("append-only", str(ctx.exception))
    def test_new_requests_per_push_are_bounded_by_the_job_timeout(self):
        self.assertLess(wb.MAX_NEW_PER_PUSH * wb.REMOTE_TIMEOUT_S, 20 * 60)
        many = [entry(request_id=f"req-{i:04d}", bug_id=500 + i) for i in range(wb.MAX_NEW_PER_PUSH + 1)]
        with self.assertRaises(wb.RequestError) as ctx:
            wb.plan("", doc(*many), REPO)
        self.assertIn(f"at most {wb.MAX_NEW_PER_PUSH}", str(ctx.exception))
        self.assertEqual(len(wb.plan("", doc(*many[:-1]), REPO)), wb.MAX_NEW_PER_PUSH)
    def test_hung_remote_call_kills_its_whole_process_group(self):
        import tempfile
        import time
        with tempfile.TemporaryDirectory() as tmp:
            marker = Path(tmp) / "late-write"
            script = Path(tmp) / "remote.sh"
            # Stands in for run-remote.sh: a child (like ssh) that would write after the timeout.
            script.write_text(f"(sleep 2; touch {marker}) &\nwait\n", encoding="utf-8")
            with mock.patch.object(wb, "REMOTE_SCRIPT", script):
                self.assertEqual(wb.run_remote("phase-a", {}, timeout=0.5), 124)
            time.sleep(2.5)
            self.assertFalse(marker.exists(), "the timed-out child must not finish its write")
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
    def test_failure_does_not_drop_later_requests_and_log_has_ids_only(self):
        lines = []
        items = wb.plan("", doc(entry(), entry(request_id="req-0002", bug_id=513)), REPO)
        with self.assertRaises(wb.RequestError) as ctx:
            wb.apply(items, lambda mode, env: 1 if env["BUG_ID"] == "512" else 0, log=lines.append)
        self.assertEqual(lines, ["request_id=req-0001 bug_id=512 action=triage failed exit=1",
                                 "request_id=req-0002 bug_id=513 action=triage ok"])
        self.assertIn("1 failed (req-0001); 1 succeeded", str(ctx.exception))
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
    def test_checked_in_request_file_is_fully_valid(self):
        # Runs in required CI, so an invalid entry fails before it can merge, not after.
        text = (ROOT / "operations/closeout/bug-writeback.request.yml").read_text(encoding="utf-8")
        wb.validate_all(text, REPO)  # every entry, without the per-push batch limit
    def test_history_longer_than_one_batch_stays_valid(self):
        many = [entry(request_id=f"req-{i:04d}", bug_id=500 + i) for i in range(wb.MAX_NEW_PER_PUSH + 3)]
        self.assertEqual(len(wb.validate_all(doc(*many), REPO)), len(many))
        self.assertEqual(len(wb.plan(doc(*many[:-1]), doc(*many), REPO)), 1)
        with mock.patch.object(wb, "logged_close_kinds", return_value={"bug"}):
            self.assertEqual(len(wb.validate_all(doc(*many), REPO)), len(many))
            with self.assertRaises(wb.RequestError):
                wb.plan(doc(*many[:-1]), doc(*many), REPO)
    def test_cursor_uses_permanent_state_and_frozen_continuation(self):
        workflow = yaml.safe_load((ROOT / ".github/workflows/bug-writeback-request.yml").read_text())
        steps = workflow["jobs"]["writeback"]["steps"]
        plan = next(s for s in steps if s.get("id") == "plan")["run"]
        self.assertNotIn("/actions/runs", plan)
        self.assertIn('--snapshot-sha "$REQUEST_SHA"', plan)
        self.assertIn("refs/remotes/writeback/main", plan)
        continuation = next(s for s in steps if s["name"] == "Continue remaining frozen requests")
        self.assertIn("steps.apply.outputs.checkpoint_count != ''", continuation["if"])
        self.assertIn("--ref main", continuation["run"])
        self.assertIn('request_sha=$REQUEST_SHA', continuation["run"])
        self.assertIn('expected_prefix=$EXPECTED_PREFIX', continuation["run"])
        self.assertIn("timeout 15", continuation["run"])
    def test_out_of_order_pushes_cap_only_the_new_push(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            first = [entry(request_id=f"req-{i:04d}", bug_id=500 + i) for i in range(3)]
            all_entries = first + [entry(request_id=f"req-{i:04d}", bug_id=500 + i) for i in range(3, 6)]
            before, push, after = [root / name for name in ("before", "push", "after")]
            before.write_text(doc()); push.write_text(doc(*first)); after.write_text(doc(*all_entries))
            with mock.patch.dict(os.environ, {"GITHUB_OUTPUT": ""}):
                self.assertEqual(wb.main(["--before", str(before), "--push-before", str(push),
                                          "--after", str(after), "--repo", REPO]), 0)
                push.write_text(doc())
                self.assertEqual(wb.main(["--before", str(before), "--push-before", str(push),
                                          "--after", str(after), "--repo", REPO]), 1)
    def test_durable_drain_and_uncertain_partial_restart(self):
        import tempfile
        sys.path.insert(0, str(ROOT / "scripts/bug-writeback"))
        import writeback_cursor as cursor
        entries = [entry(request_id=f"req-{i:04d}", bug_id=500+i) for i in range(6)]
        state = dict(schema=cursor.SCHEMA, prefix=0, prefix_hash=cursor.digest([]), snapshot_sha=None,
                     last_request_id=None, unknown=[], recent_outcomes=[])
        class Store:
            def write(self, value):
                state.clear(); state.update(value)
        def planning(after, sha, minimum):
            target = wb.load_requests(after)
            return Store(), dict(state), target, [wb.validate_entry(e, REPO) for e in target[state['prefix']:]]
        calls = []
        def runner(mode, env):
            # Reservation MUST be durable before a remote call.
            self.assertEqual(state['prefix'] - 1, len(calls))
            calls.append(env['BUG_ID'])
            return 124
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); push=root/'push'; after=root/'after'; output=root/'output'
            push.write_text(doc(*entries[:3])); after.write_text(doc(*entries))
            args=['--snapshot-sha', 'a'*40, '--push-before', str(push), '--after', str(after), '--repo', REPO, '--apply']
            with mock.patch.object(wb, 'durable_plan', planning), mock.patch.object(wb, 'run_remote', runner), mock.patch.dict(os.environ, {'GITHUB_OUTPUT':str(output)}):
                self.assertEqual(1, wb.main(args))
                self.assertEqual(5, state['prefix'])
                self.assertIn('remaining=1', output.read_text())
                self.assertEqual(1, wb.main(args))
                self.assertEqual(6, state['prefix'])
                self.assertEqual(6, len(state['unknown']))
                self.assertEqual(0, wb.main(args))
                self.assertEqual(6, len(calls))
                state.update(prefix=0,prefix_hash=cursor.digest([]),snapshot_sha=None,last_request_id=None,unknown=[],recent_outcomes=[]); calls.clear()
                def interrupted(mode, env):
                    calls.append(env['BUG_ID'])
                    if len(calls)==3: raise RuntimeError('job interrupted after durable reservation')
                    return 0
                with mock.patch.object(wb,'run_remote',interrupted):
                    with self.assertRaises(RuntimeError): wb.main(args)
                self.assertEqual(3,state['prefix'])
                self.assertEqual(['req-0002'],[r['id'] for r in state['unknown']])
                with mock.patch.object(wb,'run_remote',lambda mode,env:calls.append(env['BUG_ID']) or 0):
                    self.assertEqual(0,wb.main(args))
                self.assertEqual([str(i) for i in range(500,506)],calls)
            self.assertLess(wb.MAX_NEW_PER_RUN * wb.REMOTE_TIMEOUT_S + 12 * 15 + 70 + 15, 20*60)
    def test_persistence_failure_prevents_remote_write(self):
        import tempfile
        sys.path.insert(0, str(ROOT / "scripts/bug-writeback"))
        import writeback_cursor as cursor
        state=dict(schema=cursor.SCHEMA, prefix=0, prefix_hash=cursor.digest([]), snapshot_sha=None,
                   last_request_id=None, unknown=[], recent_outcomes=[])
        store=mock.Mock(); store.write.side_effect=ValueError('persistence unconfirmed')
        with tempfile.TemporaryDirectory() as tmp:
            after=Path(tmp)/'after'; after.write_text(doc(entry()))
            with mock.patch.object(wb, 'durable_plan', return_value=(store,state,[entry()],[wb.validate_entry(entry(),REPO)])), mock.patch.object(wb,'run_remote') as remote:
                self.assertEqual(1,wb.main(['--snapshot-sha','a'*40,'--after',str(after),'--repo',REPO,'--apply']))
                remote.assert_not_called()
    def test_new_request_requires_timestamp_bound_proof(self):
        for overrides in ({'writeback_proof':None}, {'captured_at':None}, {'captured_at':"2026-10-13"}, {'writeback_proof':'bad'}):
            with self.assertRaises(wb.RequestError):
                wb.validate_entry(entry(**overrides), REPO)
    def test_checked_in_request_file_is_append_only_vs_base(self):
        # Required CI has full history: removing or editing an entry already on the base branch fails here.
        import subprocess
        rel = "operations/closeout/bug-writeback.request.yml"
        ref = f"origin/{os.environ.get('GITHUB_BASE_REF') or 'main'}"
        got = subprocess.run(["git", "show", f"{ref}:{rel}"], cwd=ROOT, capture_output=True, text=True)
        if got.returncode != 0:
            if re.search(r"does not exist|exists on disk, but not in", got.stderr):
                wb.plan("", (ROOT / rel).read_text(encoding="utf-8"), REPO)
                return
            if os.environ.get("GITHUB_ACTIONS") and not re.search(r"does not exist|exists on disk, but not in", got.stderr):
                self.fail(f"cannot read {ref}:{rel}")
            self.skipTest(f"{rel} is not on {ref} yet")
        wb.plan(got.stdout, (ROOT / rel).read_text(encoding="utf-8"), REPO)


if __name__ == "__main__":
    unittest.main()

