"""Founder 2026-10-28 A: POP dry-runs run without a click; approve still waits for production-activation.

Each case workflow splits the Founder click into a secret-free `founder-approval` job on
`production-activation` (approve only) and runs the SSH job on the main-only `production-ssh`.
These tests pin that a dry-run can never reach the approval request and that every approve
attempt needs its own Founder approval.
"""
import itertools
import re
import os
import json
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
# workflow -> id of its SSH job
SPLIT = {
    "pop-contract-charge-correction-3868.yml": "repair",
    "pop-duplicate-attended-cancel.yml": "cancel",
    "pop-forward-extra-cancel.yml": "cancel",
    "pop-duplicate-history-pin-repair.yml": "cancel",
    "pop-taught-lesson-signin-repair.yml": "repair",
    "pop-forward-overdeduct-reassign.yml": "reassign",
    "pop-invoice-header-item-repair.yml": "repair",
    "pop-invoice1997-stale-values.yml": "repair",
    "pop-package-ledger-net-repair-4455.yml": "repair",
    "pop-past-scheduled-cancel.yml": "repair",
    "pop-shared-pool-trim.yml": "trim",
    "pop-unbilled-backlog-catchup.yml": "catchup",
    "pop-billing-period-rekey.yml": "rekey",
    "pop-unpaid-hidden-closures.yml": "repair",
    "pop-unused-lessons-closeout-5617.yml": "repair",
}
GATE_IF = "if: ${{ needs.guard.result == 'success' && inputs.mode == 'approve' }}"
REPAIR_IF = (
    "if: ${{ !cancelled() && needs.guard.result == 'success' && ((inputs.mode == 'dry-run' && "
    "needs.founder-approval.result == 'skipped') || (inputs.mode == 'approve' && "
    "needs.founder-approval.result == 'success' && needs.founder-approval.outputs.attempt == github.run_attempt)) }}"
)
CANCEL_GATE_IF = GATE_IF.replace("inputs.mode == 'approve'", "(inputs.mode == 'approve' || inputs.mode == 'rollback')")
CANCEL_REPAIR_IF = REPAIR_IF.replace("inputs.mode == 'approve'", "(inputs.mode == 'approve' || inputs.mode == 'rollback')")
SECRET = re.compile(r"secrets\.PI_", re.I)


def jobs(text):
    body = text.split("\njobs:\n", 1)[1]
    parts = re.split(r"(?m)^  ([A-Za-z0-9_-]+):\n", body)
    return dict(zip(parts[1::2], parts[2::2]))


def evaluate(expr, **values):
    """Evaluate a GitHub `if:` with the given contexts (GitHub compares strings case-insensitively)."""
    inner = expr.removeprefix("if: ${{").removesuffix("}}")
    names = {
        "needs.founder-approval.outputs.attempt": "fa_attempt", "needs.founder-approval.result": "fa",
        "needs.guard.result": "guard", "inputs.mode": "mode", "github.run_attempt": "attempt",
    }
    for name, key in names.items():
        inner = inner.replace(name, repr(str(values[key]).lower()))
    inner = inner.replace("!cancelled()", "True").replace("&&", " and ").replace("||", " or ")
    inner = re.sub(r"'([^']*)' == '([^']*)'", lambda m: str(m.group(1) == m.group(2)), inner)
    # Only booleans, and/or and parentheses are left, so eval cannot reach a name or a call.
    assert re.fullmatch(r"(?:\s|\(|\)|True|False|and|or)*", inner), inner
    return eval(inner, {"__builtins__": {}}, {})  # noqa: S307


class PopDryRunGate(unittest.TestCase):
    def test_dry_run_job_is_on_production_ssh_and_exits_before_any_approval(self):
        for name, ssh_job in SPLIT.items():
            text = (WORKFLOWS / name).read_text()
            job = jobs(text)
            gate, repair = job["founder-approval"], job[ssh_job]
            self.assertIn("    environment:\n      name: production-activation\n", gate, name)
            extended = name == 'pop-past-scheduled-cancel.yml'
            self.assertIn(CANCEL_GATE_IF if extended else GATE_IF, gate, name)
            self.assertNotRegex(gate, SECRET, name)
            self.assertIn("    environment:\n      name: production-ssh\n", repair, name)
            self.assertIn(CANCEL_REPAIR_IF if extended else REPAIR_IF, repair, name)
            # Inside the SSH job only an exact "approve" passes the exit; the approval request comes after it.
            self.assertNotIn('"$MODE" == dry-run ]]; then', repair, name)
            exit_at = repair.index('if [[ "$MODE" != approve && "$MODE" != rollback ]]; then' if extended else 'if [[ "$MODE" != approve ]]; then')
            self.assertLess(exit_at, repair.index("/approvals"), name)
            self.assertIn("exit 0", repair[exit_at:repair.index("/approvals")], name)
            guard = job["guard"]
            self.assertIn('[[ "$MODE" == dry-run || "$MODE" == approve || "$MODE" == rollback ]] || exit 1' if extended else '[[ "$MODE" == dry-run || "$MODE" == approve ]] || exit 1', guard, name)
            self.assertRegex(guard, r"DRY_RUN_\w+", name)
            self.assertRegex(guard, r"APPROVE_\w+", name)

    def test_approve_runs_only_after_this_attempts_founder_approval(self):
        results = ("success", "skipped", "failure", "cancelled")
        for mode, guard, fa, fa_attempt, attempt in itertools.product(
            ("dry-run", "approve", "Approve", "DRY-RUN", "other"), results, results, ("1", "2", ""), ("1", "2"),
        ):
            gate_runs = evaluate(GATE_IF, guard=guard, mode=mode, fa=fa, fa_attempt=fa_attempt, attempt=attempt)
            runs = evaluate(REPAIR_IF, guard=guard, mode=mode, fa=fa, fa_attempt=fa_attempt, attempt=attempt)
            # Reachable states only: the gate job is skipped exactly when its own condition is false.
            if (fa == "skipped") == gate_runs:
                continue
            if runs and mode.lower() == "approve":
                self.assertEqual((fa, fa_attempt), ("success", attempt), (mode, guard, fa, fa_attempt, attempt))
            if runs:
                self.assertEqual(guard, "success")

    def test_every_pop_workflow_splits_its_dry_run_and_clicks_every_other_ssh_job(self):
        """Founder 2026-10-29「怎麼什麼都要我按核准呀」: a new or unsplit dry-run mode fails here; so does an unclicked SSH job."""
        for path in sorted(WORKFLOWS.glob("pop-*.yml")):
            text = path.read_text()
            auto = "pop_auto_approve.py" in text  # append-only auto approve: pinned by test_pop_auto_approve.py
            if re.search(r"(?m)^ +options: \[[^\]]*\bdry-run\b", text):
                self.assertTrue(path.name in SPLIT or auto, f"{path.name}: dry-run must not wait for production-activation")
            for job_id, body in jobs(text).items():
                if SECRET.search(body) and not auto and SPLIT.get(path.name) != job_id:
                    self.assertRegex(body, r"environment:(?:\n      name:)? production-activation\n", f"{path.name}:{job_id}")

    def test_credential_workflows_keep_the_click(self):
        for name in ("pop-telegram-webhook-secret.yml", "pop-mount-reviewed-financial-once.yml"):
            for job_id, body in jobs((WORKFLOWS / name).read_text()).items():
                if SECRET.search(body):
                    self.assertRegex(body, r"environment:(?:\n      name:)? production-activation\n", f"{name}:{job_id}")

    def test_new_cancel_rollback_needs_this_attempts_founder_and_only_accepts_the_exact_case(self):
        job = jobs((WORKFLOWS / 'pop-past-scheduled-cancel.yml').read_text())
        gate = re.search(r'(?m)^    if: (.+)$', job['founder-approval']).group(0).strip()
        repair = re.search(r'(?m)^    if: (.+)$', job['repair']).group(0).strip()
        for mode, guard, fa, fa_attempt, attempt in itertools.product(
            ('dry-run', 'approve', 'rollback', 'other'), ('success', 'failure'),
            ('success', 'skipped', 'failure', 'cancelled'), ('1', '2', ''), ('1', '2'),
        ):
            values = dict(mode=mode, guard=guard, fa=fa, fa_attempt=fa_attempt, attempt=attempt)
            self.assertEqual(evaluate(gate, **values), guard == 'success' and mode in {'approve', 'rollback'})
            if evaluate(repair, **values) and mode in {'approve', 'rollback'}:
                self.assertEqual((guard, fa, fa_attempt), ('success', 'success', attempt))
        script = textwrap.dedent(job['guard'].split('        run: |\n', 1)[1])
        for case, mode, confirm, sha, ok in [
            ('3380', 'rollback', 'ROLLBACK_PAST_SCHEDULED_CANCEL_20261118', 'a'*40, True),
            ('2942', 'rollback', 'ROLLBACK_PAST_SCHEDULED_CANCEL_20261008', 'a'*40, False),
            ('3380', 'rollback', 'APPROVE_PAST_SCHEDULED_CANCEL_20261118', 'a'*40, False),
            ('3380', 'rollback', 'ROLLBACK_PAST_SCHEDULED_CANCEL_20261118', 'a'*39, False),
            ('3380; false', 'rollback', 'ROLLBACK_PAST_SCHEDULED_CANCEL_20261118', 'a'*40, False),
            ('2942', 'approve', 'APPROVE_PAST_SCHEDULED_CANCEL_20261008', 'a'*40, True),
            ('3380', 'dry-run', 'DRY_RUN_PAST_SCHEDULED_CANCEL_20261118', 'a'*40, True),
        ]:
            result = subprocess.run(['bash', '-c', script], env={'PATH': os.defpath, 'CASE_ID': case, 'MODE': mode, 'CONFIRM': confirm, 'INPUT_SHA': sha}, capture_output=True)
            self.assertEqual(result.returncode == 0, ok, (case, mode, result.stderr))

    def test_cancel_rollback_poll_requires_its_own_native_receipt(self):
        text = (WORKFLOWS / 'pop-past-scheduled-cancel.yml').read_text()
        script = textwrap.dedent(re.search(r"if poll_summary=.*?<<'PY'\n(.*?)\n          PY", text, re.S).group(1))
        sha, ref = 'a'*40, 'founder-go-past-scheduled-cancel-rollback-20261118'
        record = dict(phase='rollback', result='rolled_back', actor='pop-pi-local', commit_sha=sha, approval_reference=ref)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'receipt.json'
            for change, ok in [({}, True), ({'actor': 'other'}, False), ({'commit_sha': 'b'*40}, False),
                               ({'approval_reference': 'founder-go-past-scheduled-cancel-20261118'}, False),
                               ({'phase': 'verify', 'result': 'succeeded'}, False)]:
                path.write_text(json.dumps({'request_status': 'rolled_back', 'records':
                    [{'phase': 'execute', 'snapshot_present': True}, record | change]}))
                result = subprocess.run([sys.executable, '-c', script, str(path)],
                    env={'MODE': 'rollback', 'TARGET_SHA': sha, 'APPROVAL_REF': ref}, capture_output=True)
                self.assertEqual(result.returncode == 0, ok, result.stderr)


if __name__ == "__main__":
    unittest.main()
