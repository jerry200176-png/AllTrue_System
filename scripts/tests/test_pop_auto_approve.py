"""Founder 2026-10-28 A (b): append-only POP approve without a click only on fresh, matching dry-run evidence."""
import itertools
import json
import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.governance import pop_auto_approve as auto  # noqa: E402

WF = "pop-unbilled-backlog-catchup.yml"
WORKFLOW = ROOT / ".github" / "workflows" / WF
SHA, DIGEST, NOW = "a" * 40, "b" * 64, datetime(2026, 10, 28, 12, 0, tzinfo=timezone.utc)
FA_IF = "if: ${{ needs.guard.result == 'success' && inputs.mode == 'approve' && needs.guard.outputs.auto_approve != 'true' }}"
CATCHUP_IF = (
    "if: ${{ !cancelled() && needs.guard.result == 'success' && ((inputs.mode == 'dry-run' && needs.founder-approval.result == 'skipped') "
    "|| (inputs.mode == 'approve' && needs.founder-approval.result == 'success' && needs.founder-approval.outputs.attempt == github.run_attempt) "
    "|| (inputs.mode == 'approve' && needs.founder-approval.result == 'skipped' && needs.guard.outputs.auto_approve == 'true' "
    "&& needs.guard.outputs.attempt == github.run_attempt && github.ref == 'refs/heads/main')) }}"
)


def evidence(**over):
    ev = {"workflow": WF, "mode": "dry-run", "backend_sha": SHA, "scope": "15", "digest": DIGEST,
          "ok": True, "blocked": 0, "run_id": "7"}
    return {**ev, **over}


def run(ev=None, **over):
    base = {"id": 7, "created_at": "2026-10-28T02:00:00Z", "conclusion": "success", "head_branch": "main",
            "event": "workflow_dispatch", "path": f".github/workflows/{WF}", "evidence": [evidence()] if ev is None else ev}
    return {**base, **over}


def decide(runs, **over):
    args = {"workflow": WF, "mode": "approve", "ref": "refs/heads/main", "backend_sha": SHA, "scope": "15",
            "digest": DIGEST, "now": NOW}
    return auto.decide(**{"runs": runs, **args, **over})[0]


def jobs(text):
    parts = re.split(r"(?m)^  ([A-Za-z0-9_-]+):\n", text.split("\njobs:\n", 1)[1])
    return dict(zip(parts[1::2], parts[2::2]))


def evaluate(expr, **values):
    """A GitHub `if:` under the given contexts (string compares are case-insensitive in GitHub)."""
    inner = expr.removeprefix("if: ${{").removesuffix("}}")
    names = {"needs.founder-approval.outputs.attempt": "fa_attempt", "needs.founder-approval.result": "fa",
             "needs.guard.outputs.auto_approve": "auto", "needs.guard.outputs.attempt": "guard_attempt",
             "needs.guard.result": "guard", "inputs.mode": "mode", "github.run_attempt": "attempt", "github.ref": "ref"}
    for name, key in names.items():
        inner = inner.replace(name, repr(str(values[key]).lower()))
    inner = inner.replace("!cancelled()", "True").replace("&&", " and ").replace("||", " or ")
    inner = re.sub(r"'([^']*)' == '([^']*)'", lambda m: str(m.group(1) == m.group(2)), inner)
    inner = re.sub(r"'([^']*)' != '([^']*)'", lambda m: str(m.group(1) != m.group(2)), inner)
    # Only booleans, and/or and parentheses are left, so eval cannot reach a name or a call.
    assert re.fullmatch(r"(?:\s|\(|\)|True|False|and|or)*", inner), inner
    return eval(inner, {"__builtins__": {}}, {})  # noqa: S307


class Decide(unittest.TestCase):
    def test_matching_fresh_dry_run_approves(self):
        self.assertTrue(decide([run()]))
        self.assertTrue(decide([run(created_at="2026-10-27T12:00:00Z")]))  # exactly 24 h

    def test_each_missing_condition_refuses(self):
        refusals = {
            "not allowlisted": dict(workflow="pop-past-scheduled-cancel.yml"),
            "dry-run mode": dict(mode="dry-run"),
            "not main": dict(ref="refs/heads/feature"),
            "short sha": dict(backend_sha="a" * 39),
            "empty digest": dict(digest=""),
            "bad scope": dict(scope="0"),
            "other backend sha": dict(backend_sha="c" * 40),
            "other digest": dict(digest="d" * 64),
            "other campus": dict(scope="16"),
        }
        for label, over in refusals.items():
            self.assertFalse(decide([run()], **over), label)
        self.assertFalse(decide([]), "no runs")

    def test_run_metadata_must_be_a_fresh_successful_main_dispatch(self):
        for label, over in {
            "older than 24 h": dict(created_at="2026-10-27T11:59:59Z"),
            "future": dict(created_at="2026-10-28T12:00:01Z"),
            "no timezone": dict(created_at="2026-10-28T02:00:00"),
            "failed": dict(conclusion="failure"),
            "other branch": dict(head_branch="feature"),
            "push": dict(event="push"),
            "other workflow": dict(path=".github/workflows/pop-past-scheduled-cancel.yml"),
        }.items():
            self.assertFalse(decide([run(**over)]), label)

    def test_evidence_must_be_exactly_one_clean_dry_run_line(self):
        for label, ev in {
            "none": [],
            "two": [evidence(), evidence()],
            "blocked rows": [evidence(blocked=1)],
            "blocked unknown": [evidence(blocked=None)],
            "blocked as bool": [evidence(blocked=False)],
            "blocked as text": [evidence(blocked="0")],
            "plan not ok": [evidence(ok=False)],
            "ok as text": [evidence(ok="true")],
            "approve log": [evidence(mode="approve")],
            "other workflow": [evidence(workflow="pop-td076-occurrence-repairs.yml")],
            "other run": [evidence(run_id="8")],
            "missing digest": [evidence(digest=None)],
            "malformed": [{}],
        }.items():
            self.assertFalse(decide([run(ev)]), label)

    def test_parse_reads_github_log_lines_only(self):
        line = "POP_DRY_RUN_EVIDENCE " + json.dumps(evidence(), sort_keys=True)
        log = "﻿2026-10-28T02:00:01.1Z Current runner\n" \
              "2026-10-28T02:00:02.1Z \x1b[36;1mprint('POP_DRY_RUN_EVIDENCE ' + json.dumps({}))\x1b[0m\n" \
              f"2026-10-28T02:00:03.1Z {line}\r\n2026-10-28T02:00:04.1Z row contract=1 status=ready\n"
        self.assertEqual(auto.parse_evidence(log), [evidence()])
        self.assertEqual(auto.parse_evidence("2026-10-28T02:00:03.1Z POP_DRY_RUN_EVIDENCE {not json}\n"), [{}])
        self.assertEqual(auto.parse_evidence("x POP_DRY_RUN_EVIDENCE " + json.dumps(evidence())), [])

    def test_main_fails_closed_on_any_error(self):
        with tempfile.NamedTemporaryFile("r+") as out, \
                mock.patch.object(auto, "collect_runs", side_effect=subprocess.CalledProcessError(1, "gh")):
            auto.main({"WORKFLOW": WF, "MODE": "approve", "REPO": "o/r", "REF": "refs/heads/main",
                       "BACKEND_SHA": SHA, "SCOPE": "15", "DIGEST": DIGEST, "GITHUB_OUTPUT": out.name})
            self.assertEqual(out.read(), "auto_approve=false\n")
        with tempfile.NamedTemporaryFile("r+") as out, mock.patch.object(auto, "collect_runs") as collect:
            auto.main({"WORKFLOW": WF, "MODE": "dry-run", "GITHUB_OUTPUT": out.name})
            collect.assert_not_called()
            self.assertEqual(out.read(), "auto_approve=false\n")


class Workflow(unittest.TestCase):
    def setUp(self):
        self.text = WORKFLOW.read_text()
        self.jobs = jobs(self.text)

    def test_only_the_allowlist_uses_the_auto_path(self):
        users = sorted(p.name for p in (ROOT / ".github" / "workflows").glob("*.yml") if "pop_auto_approve.py" in p.read_text())
        self.assertEqual(users, sorted(auto.APPEND_ONLY))
        for name, job_name in auto.APPEND_ONLY.items():
            text = (ROOT / ".github" / "workflows" / name).read_text()
            self.assertIn(f"    name: {job_name}\n", text, name)
            self.assertIn(f"WORKFLOW: {name}\n", text, name)

    def test_gate_and_ssh_job_environments(self):
        guard, gate, ssh = self.jobs["guard"], self.jobs["founder-approval"], self.jobs["catchup"]
        self.assertIn("    environment:\n      name: production-activation\n", gate)
        self.assertIn(FA_IF, gate)
        self.assertNotIn("secrets.", gate)
        self.assertIn("    environment:\n      name: production-ssh\n", ssh)
        self.assertIn(CATCHUP_IF, ssh)
        self.assertIn("    permissions:\n      actions: read\n      contents: read\n", guard)
        self.assertNotIn("secrets.", guard)
        self.assertEqual(guard.count("if: ${{ inputs.mode == 'approve' }}"), 2)

    def test_approve_never_runs_without_a_click_or_this_attempts_evidence(self):
        res = ("success", "skipped", "failure")
        for mode, guard, fa, fa_a, a, auto_ok, g_a, ref in itertools.product(
            ("dry-run", "approve", "Approve"), res, res, ("1", "2"), ("1", "2"), ("true", "false", ""), ("1", "2"),
            ("refs/heads/main", "refs/heads/x"),
        ):
            v = dict(mode=mode, guard=guard, fa=fa, fa_attempt=fa_a, attempt=a, auto=auto_ok, guard_attempt=g_a, ref=ref)
            if (fa == "skipped") == evaluate(FA_IF, **v):
                continue  # unreachable: the gate job is skipped exactly when its condition is false
            if evaluate(CATCHUP_IF, **v) and mode.lower() == "approve":
                clicked = fa == "success" and fa_a == a
                evidenced = fa == "skipped" and auto_ok == "true" and g_a == a and ref == "refs/heads/main"
                self.assertTrue(clicked or evidenced, v)

    def test_remote_auto_path_rechecks_and_exits_before_approval_otherwise(self):
        ssh = self.jobs["catchup"]
        approvals = ssh.index("/approvals")
        self.assertLess(ssh.index('if [[ "$AUTO" == true ]]; then\n            python3'), ssh.index('if [[ "$MODE" != approve ]]; then'))
        self.assertLess(ssh.index('if [[ "$MODE" != approve ]]; then'), approvals)
        self.assertIn('[[ "$AUTO" == true || "$AUTO" == false ]]', ssh)
        self.assertIn('APPROVAL_REF="founder-go-standing-a-backlog-c', ssh)  # POP requires a founder-go- prefix
        self.assertIn("AUTO: ${{ needs.founder-approval.result == 'skipped' && needs.guard.outputs.auto_approve == 'true' && 'true' || 'false' }}", ssh)
        self.assertIn("::error::Pi-local execute/verify failed (the POP rollback snapshot is kept)", ssh)

    def test_dry_run_evidence_printed_by_the_workflow_is_accepted(self):
        ssh = self.jobs["catchup"]
        start = ssh.index('python3 - "$dry_run" <<\'PY\'')
        script = textwrap.dedent(ssh[ssh.index("\n", start) + 1:ssh.index("\n          PY\n", start)])
        rows = [{"contract_id": 1, "student_id": 2, "campus_id": 15, "month": "2026-09", "lessons": 3, "amount": 900,
                 "status": "ready", "reason": None}, {"status": "skipped", "reason": "already_billed"}]
        cases = {
            "clean": ("dry-run", rows, True),
            "needs director amount": ("dry-run", rows + [{"status": "needs_director_amount"}], False),
            "approve run prints no evidence": ("approve", rows, False),
        }
        for label, (mode, manifest, accepted) in cases.items():
            with tempfile.NamedTemporaryFile("w", suffix=".json") as f:
                json.dump({"data": {"plan": {"ok": True, "errors": [], "state": "unpinned", "digest": DIGEST,
                                             "totals": {}, "manifest": manifest}}}, f)
                f.flush()
                env = {**os.environ, "MODE": mode, "TARGET_SHA": SHA, "CAMPUS": "15", "RUN_ID": "7"}
                out = subprocess.run([sys.executable, "-", f.name], input=script, text=True, capture_output=True,
                                     env=env, check=True).stdout
            log = "".join(f"2026-10-28T02:00:0{i}.1Z {line}\n" for i, line in enumerate(out.splitlines()))
            self.assertEqual(decide([run(auto.parse_evidence(log))]), accepted, label)


if __name__ == "__main__":
    unittest.main()
