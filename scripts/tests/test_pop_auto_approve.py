"""Founder 2026-10-28 A (b): internal append-only POP approve without a click only on a cited, fresh, matching dry-run."""
import itertools
import json
import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
sys.path.insert(0, str(ROOT))
from scripts.governance import pop_auto_approve as auto  # noqa: E402

LEDGER, TD076 = "pop-package-ledger-net-repair-4455.yml", "pop-td076-occurrence-repairs.yml"
SCOPES = {TD076: "r2:c11"}
SHA, DIGEST, NOW = "a" * 40, "b" * 64, datetime(2026, 10, 28, 12, 0, tzinfo=timezone.utc)
HEAD, MAIN = "e" * 40, "f" * 40
FA_IF = "if: ${{ needs.guard.result == 'success' && inputs.mode == 'approve' && needs.guard.outputs.auto_approve != 'true' }}"
REPAIR_IF = (
    "if: ${{ !cancelled() && needs.guard.result == 'success' && ((inputs.mode == 'dry-run' && needs.founder-approval.result == 'skipped') "
    "|| (inputs.mode == 'approve' && needs.founder-approval.result == 'success' && needs.founder-approval.outputs.attempt == github.run_attempt) "
    "|| (inputs.mode == 'approve' && needs.founder-approval.result == 'skipped' && needs.guard.outputs.auto_approve == 'true' "
    "&& needs.guard.outputs.attempt == github.run_attempt && github.ref == 'refs/heads/main')) }}"
)


def evidence(wf=TD076, **over):
    return {"workflow": wf, "mode": "dry-run", "backend_sha": SHA, "scope": SCOPES[wf], "digest": DIGEST,
            "ok": True, "blocked": 0, "run_id": "7", **over}


def run(wf=TD076, ev=None, **over):
    return {"id": 7, "created_at": "2026-10-28T02:00:00Z", "conclusion": "success", "head_branch": "main", "on_main": True,
            "event": "workflow_dispatch", "path": f".github/workflows/{wf}",
            "evidence": [evidence(wf)] if ev is None else ev, **over}


def decide(cited, wf=TD076, **over):
    args = {"workflow": wf, "mode": "approve", "ref": "refs/heads/main", "backend_sha": SHA, "scope": SCOPES[wf],
            "digest": DIGEST, "dry_run_id": "7", "now": NOW}
    return auto.decide(**{"run": cited, **args, **over})[0]


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
    def test_cited_matching_fresh_dry_run_approves(self):
        for wf in auto.APPEND_ONLY:
            self.assertTrue(decide(run(wf), wf), wf)
        self.assertTrue(decide(run(created_at="2026-10-27T12:00:00Z")))  # exactly 24 h

    def test_each_missing_input_refuses(self):
        for label, over in {
            "billing workflow": dict(workflow="pop-unbilled-backlog-catchup.yml"),
            "closed package-ledger case": dict(workflow=LEDGER),
            "dry-run mode": dict(mode="dry-run"),
            "not main": dict(ref="refs/heads/feature"),
            "short sha": dict(backend_sha="a" * 39),
            "empty digest": dict(digest=""),
            "other scope": dict(scope="package-ledger-net-repair-4455"),
            "no cited run id": dict(dry_run_id=""),
            "cited id is not a number": dict(dry_run_id="7 "),
            "other cited id": dict(dry_run_id="8"),
            "other backend sha": dict(backend_sha="c" * 40),
            "other digest": dict(digest="d" * 64),
        }.items():
            self.assertFalse(decide(run(), **over), label)
        self.assertFalse(decide(None), "cited run missing")
        self.assertFalse(decide(run(TD076, [evidence(TD076, scope="r1:c11")]), TD076, scope="r1:c11"), "td076 r1")
        self.assertFalse(decide(run(TD076, [evidence(TD076, scope="r2:c0")]), TD076, scope="r2:c0"), "campus 0")
        self.assertFalse(decide(run(TD076, [evidence(TD076, scope="r2:c12")]), TD076), "other campus")

    def test_cited_run_must_be_a_fresh_successful_main_dispatch_of_this_workflow(self):
        for label, over in {
            "older than 24 h": dict(created_at="2026-10-27T11:59:59Z"),
            "future": dict(created_at="2026-10-28T12:00:01Z"),
            "no timezone": dict(created_at="2026-10-28T02:00:00"),
            "failed": dict(conclusion="failure"),
            "other branch": dict(head_branch="feature"),
            "not in main's history (e.g. a tag named main)": dict(on_main=False),
            "history unknown": dict(on_main=None),
            "push": dict(event="push"),
            "other workflow": dict(path=f".github/workflows/{LEDGER}"),
            "other run": dict(id=8),
        }.items():
            self.assertFalse(decide(run(**over)), label)

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
            "other workflow": [evidence(workflow=LEDGER)],
            "evidence of another run": [evidence(run_id="8")],
            "missing digest": [evidence(digest=None)],
            "malformed": [{}],
        }.items():
            self.assertFalse(decide(run(ev=ev)), label)

    def test_parse_reads_github_log_lines_only(self):
        line = "POP_DRY_RUN_EVIDENCE " + json.dumps(evidence(), sort_keys=True)
        log = "﻿2026-10-28T02:00:01.1Z Current runner\n" \
              "2026-10-28T02:00:02.1Z \x1b[36;1mprint('POP_DRY_RUN_EVIDENCE ' + json.dumps({}))\x1b[0m\n" \
              f"2026-10-28T02:00:03.1Z {line}\r\n2026-10-28T02:00:04.1Z package=5 remaining 3->2\n"
        self.assertEqual(auto.parse_evidence(log), [evidence()])
        self.assertEqual(auto.parse_evidence("2026-10-28T02:00:03.1Z POP_DRY_RUN_EVIDENCE {not json}\n"), [{}])
        self.assertEqual(auto.parse_evidence("x POP_DRY_RUN_EVIDENCE " + json.dumps(evidence())), [])

    def test_main_fails_closed_and_reads_only_the_cited_run(self):
        env = {"WORKFLOW": TD076, "MODE": "approve", "REPO": "o/r", "REF": "refs/heads/main", "MAIN_SHA": MAIN,
               "BACKEND_SHA": SHA, "SCOPE": SCOPES[TD076], "DIGEST": DIGEST, "DRY_RUN_ID": "7"}
        with tempfile.NamedTemporaryFile("r+") as out, \
                mock.patch.object(auto, "collect_run", side_effect=subprocess.CalledProcessError(1, "gh")):
            auto.main({**env, "GITHUB_OUTPUT": out.name})
            self.assertEqual(out.read(), "auto_approve=false\n")
        for over in ({"MODE": "dry-run"}, {"DRY_RUN_ID": ""}, {"WORKFLOW": "pop-unbilled-backlog-catchup.yml"}):
            with tempfile.NamedTemporaryFile("r+") as out, mock.patch.object(auto, "collect_run") as collect:
                auto.main({**env, **over, "GITHUB_OUTPUT": out.name})
                collect.assert_not_called()
                self.assertEqual(out.read(), "auto_approve=false\n", over)
        api_run = {k: v for k, v in run().items() if k not in ("on_main", "evidence")} | {"head_sha": HEAD}
        for status, want in (("ahead", "true"), ("identical", "true"), ("diverged", "false"), ("behind", "false")):
            responses = {  # a dry-run dispatched on a tag named `main` off main's history compares as diverged/behind
                "/repos/o/r/actions/runs/7": json.dumps(api_run),
                f"/repos/o/r/compare/{HEAD}...{MAIN}": json.dumps({"status": status}),
                "/repos/o/r/actions/runs/7/jobs?per_page=50": json.dumps(
                    {"jobs": [{"id": 9, "name": auto.APPEND_ONLY[TD076][0], "conclusion": "success"}]}),
                "/repos/o/r/actions/jobs/9/logs": "2026-10-28T02:00:03.1Z POP_DRY_RUN_EVIDENCE " + json.dumps(evidence()),
            }
            with tempfile.NamedTemporaryFile("r+") as out, mock.patch.object(auto, "_gh", side_effect=responses.__getitem__), \
                    mock.patch.object(auto, "datetime", wraps=datetime) as clock:
                clock.now.return_value = NOW
                auto.main({**env, "GITHUB_OUTPUT": out.name})
                self.assertEqual(out.read(), f"auto_approve={want}\n", status)


class Workflows(unittest.TestCase):
    def test_only_the_allowlist_uses_the_auto_path(self):
        users = sorted(p.name for p in WORKFLOWS.glob("*.yml") if "pop_auto_approve.py" in p.read_text())
        self.assertEqual(users, sorted(auto.APPEND_ONLY))
        self.assertEqual(set(auto.APPEND_ONLY), {TD076})  # widening this list is a reviewed control-plane change

    def test_gate_and_ssh_job_environments(self):
        for wf, (job_name, _) in auto.APPEND_ONLY.items():
            j = jobs((WORKFLOWS / wf).read_text())
            guard, gate, ssh = j["guard"], j["founder-approval"], j["repair"]
            self.assertIn(f"    name: {job_name}\n", ssh, wf)
            self.assertIn(f"WORKFLOW: {wf}\n", guard, wf)
            self.assertIn("    environment:\n      name: production-activation\n", gate, wf)
            self.assertIn(FA_IF, gate, wf)
            self.assertNotIn("secrets.", gate + guard, wf)
            self.assertIn("    environment:\n      name: production-ssh\n", ssh, wf)
            self.assertIn(REPAIR_IF, ssh, wf)
            self.assertIn("    permissions:\n      actions: read\n      contents: read\n", guard, wf)
            self.assertIn("DRY_RUN_ID: ${{ inputs.dry_run_run_id }}\n        run: python3 scripts/governance/pop_auto_approve.py", guard, wf)
            self.assertIn("MAIN_SHA: ${{ github.sha }}\n", guard, wf)

    def test_approve_never_runs_without_a_click_or_this_attempts_evidence(self):
        res = ("success", "skipped", "failure")
        for mode, guard, fa, fa_a, a, auto_ok, g_a, ref in itertools.product(
            ("dry-run", "approve", "Approve"), res, res, ("1", "2"), ("1", "2"), ("true", "false", ""), ("1", "2"),
            ("refs/heads/main", "refs/heads/x"),
        ):
            v = dict(mode=mode, guard=guard, fa=fa, fa_attempt=fa_a, attempt=a, auto=auto_ok, guard_attempt=g_a, ref=ref)
            if (fa == "skipped") == evaluate(FA_IF, **v):
                continue  # unreachable: the gate job is skipped exactly when its condition is false
            if evaluate(REPAIR_IF, **v) and mode.lower() == "approve":
                clicked = fa == "success" and fa_a == a
                evidenced = fa == "skipped" and auto_ok == "true" and g_a == a and ref == "refs/heads/main"
                self.assertTrue(clicked or evidenced, v)

    def test_remote_auto_path_rechecks_then_exits_before_approval_otherwise(self):
        refs = {TD076: '[[ "$REPAIR" == r2 ]] || exit 1; APPROVAL_REF="standing-a-auto-pop-td076-occurrence-repairs-${DIGEST:0:12}"'}
        for wf, ref in refs.items():
            ssh = jobs((WORKFLOWS / wf).read_text())["repair"]
            recheck, exit_at = ssh.index('if [[ "$AUTO" == true ]]; then\n            python3'), ssh.index('if [[ "$MODE" != approve ]]; then')
            self.assertLess(recheck, exit_at, wf)
            self.assertLess(exit_at, ssh.index("/approvals"), wf)
            self.assertIn(f'if [[ "$AUTO" == true ]]; then {ref}; fi', ssh, wf)
            self.assertEqual(ssh.count("standing-a-auto-"), 1, wf)
            self.assertIn('[[ "$AUTO" == true || "$AUTO" == false ]]', ssh, wf)
            self.assertIn("AUTO='$AUTO' bash -s", ssh, wf)
            self.assertIn("AUTO: ${{ needs.founder-approval.result == 'skipped' && needs.guard.outputs.auto_approve == 'true' && 'true' || 'false' }}", ssh, wf)
            self.assertIn("::error::Pi-local execute/verify failed (the POP rollback snapshot is kept)", ssh, wf)

    def test_dry_run_evidence_printed_by_each_workflow_is_what_decide_accepts(self):
        plans = {
            TD076: lambda blocked: {"ok": True, "errors": [], "state": "unpinned", "digest": DIGEST, "campus_id": 11,
                                    "counts": {"actions": {"pin": 2}, "quarantine": {"no_teacher": blocked} if blocked else []},
                                    "manifest": {"actions": [{"type": "pin", "class_session_id": 4}], "quarantine": []}},
        }
        for wf, plan in plans.items():
            ssh = jobs((WORKFLOWS / wf).read_text())["repair"]
            start = ssh.index("python3 - \"$dry_run\" <<'PY'")
            script = textwrap.dedent(ssh[ssh.index("\n", start) + 1:ssh.index("\n          PY\n", start)])
            for mode, blocked, accepted in (("dry-run", 0, True), ("dry-run", 1, False), ("approve", 0, False)):
                with tempfile.NamedTemporaryFile("w", suffix=".json") as f:
                    json.dump({"data": {"plan": plan(blocked)}}, f)
                    f.flush()
                    env = {**os.environ, "MODE": mode, "TARGET_SHA": SHA, "CAMPUS": "11", "REPAIR": "r2", "RUN_ID": "7"}
                    out = subprocess.run([sys.executable, "-", f.name], input=script, text=True, capture_output=True,
                                         env=env, check=True).stdout
                log = "".join(f"2026-10-28T02:00:0{i}.1Z {line}\n" for i, line in enumerate(out.splitlines()))
                self.assertEqual(decide(run(wf, auto.parse_evidence(log)), wf), accepted, (wf, mode, blocked))


if __name__ == "__main__":
    unittest.main()
