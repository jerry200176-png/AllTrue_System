#!/usr/bin/env python3
"""Post-deploy watch (workflow gap #3): decision logic and deploy.yml wiring.

Argo Rollouts AnalysisRun / Flagger: a release is watched for a window after it goes live;
N consecutive failed checks, or an error-rate spike against the pre-release baseline, rolls it
back through the same path a failed verification uses.
"""

from __future__ import annotations

import argparse
import importlib.util
import io
import json
import re
import os
import subprocess
import tempfile
import textwrap
from unittest.mock import patch
import sys
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"


def _load():
    spec = importlib.util.spec_from_file_location("post_deploy_verify_watch", ROOT / "scripts" / "post-deploy-verify.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


PDV = _load()


class FakeClock:
    def __init__(self):
        self.mono = 0.0
        self.wall_now = 1_800_000_000.0

    def clock(self):
        return self.mono

    def wall(self):
        return self.wall_now

    def sleep(self, seconds):
        self.mono += seconds
        self.wall_now += seconds


def watch_args(**overrides):
    values = dict(
        base_url="https://daan.test", health_path=None, expect_backend_sha="a" * 40, expect_frontend_sha=None,
        timeout=1, insecure=True, watch_seconds=600, watch_interval=30, watch_failures=4, sentry_every=300,
        spike=PDV.SpikePolicy(5.0, 0.2, 20), sentry_baseline_minutes=60, sentry_baseline_gap_minutes=15,
    )
    values.update(overrides)
    return argparse.Namespace(**values)


def run_watch(args, results, sentry=None, counts=None):
    """Drive watch() with a scripted sequence of check results (last one repeats)."""
    clock = FakeClock()
    calls = {"checks": 0, "counts": []}

    def check():
        index = min(calls["checks"], len(results) - 1)
        calls["checks"] += 1
        return results[index]

    def count(config, start, end):
        calls["counts"].append((start, end))
        if counts is None:
            return None
        return counts(start, end, clock)

    out = io.StringIO()
    with redirect_stdout(out):
        breach = PDV.watch(args, sentry=sentry, check=check, count=count, sleep=clock.sleep,
                           clock=clock.clock, wall=clock.wall)
    return breach, calls, out.getvalue(), clock


class SpikeDecisionTest(unittest.TestCase):
    def test_spike_follows_the_configured_factor(self):
        # baseline 60 errors / 60 min = 1/min; 30 errors in 5 min = 6/min.
        for factor, expect_spike in ((5.0, True), (6.0, False), (10.0, False)):
            reason = PDV.sentry_spike(60, 60, 30, 5, PDV.SpikePolicy(factor, 0.2, 20))
            self.assertEqual(expect_spike, reason is not None, factor)
            if reason:
                self.assertIn(f"{factor:g}x baseline", reason)

    def test_spike_needs_the_configured_minimum_count(self):
        for min_count, expect_spike in ((10, True), (25, True), (26, False)):
            reason = PDV.sentry_spike(0, 60, 25, 5, PDV.SpikePolicy(5.0, 0.2, min_count))
            self.assertEqual(expect_spike, reason is not None, min_count)

    def test_quiet_baseline_uses_the_floor(self):
        # Baseline 0: threshold = factor x floor. 20 errors in 20 min = 1/min.
        self.assertIsNone(PDV.sentry_spike(0, 60, 20, 20, PDV.SpikePolicy(5.0, 0.2, 20)))  # 1.0 is not > 1.0
        self.assertIsNotNone(PDV.sentry_spike(0, 60, 20, 20, PDV.SpikePolicy(5.0, 0.1, 20)))

    def test_unknown_counts_never_trigger(self):
        policy = PDV.SpikePolicy(1.0, 0.0, 0)
        self.assertIsNone(PDV.sentry_spike(None, 60, 999, 1, policy))
        self.assertIsNone(PDV.sentry_spike(0, 60, None, 1, policy))
        self.assertIsNone(PDV.sentry_spike(0, 0, 999, 1, policy))

    def test_failure_streak_resets_on_a_passing_check(self):
        streak = 0
        for problems, expected in ((["x"], 1), (["x"], 2), ([], 0), (["x"], 1)):
            streak = PDV.next_failure_streak(streak, problems)
            self.assertEqual(expected, streak)


class WatchLoopTest(unittest.TestCase):
    def test_breach_after_the_configured_consecutive_failures(self):
        for failures in (2, 4, 6):
            breach, calls, _, _ = run_watch(watch_args(watch_failures=failures), [[], ["health down"]])
            self.assertIsNotNone(breach)
            self.assertIn(f"{failures} consecutive failed", breach)
            self.assertEqual(1 + failures, calls["checks"])

    def test_intermittent_failures_pass_the_window(self):
        results = [["blip"], [], ["blip"], ["blip"], [], ["blip"]] * 10
        breach, calls, out, clock = run_watch(watch_args(watch_failures=3), results)
        self.assertIsNone(breach)
        self.assertIn("post-deploy watch: OK", out)
        # 600 s at 30 s intervals: checks at 0, 30, ..., 600 (both ends of the window), then it ends.
        self.assertEqual(21, calls["checks"])
        self.assertLessEqual(clock.mono, 600)

    def test_window_length_follows_the_arguments(self):
        for seconds, interval, expected in ((120, 30, 5), (300, 60, 6), (1200, 30, 41)):
            _, calls, _, _ = run_watch(watch_args(watch_seconds=seconds, watch_interval=interval), [[]])
            self.assertEqual(expected, calls["checks"], (seconds, interval))

    def test_sentry_absent_is_skipped_and_said(self):
        breach, calls, out, _ = run_watch(watch_args(), [[]], sentry=None)
        self.assertIsNone(breach)
        self.assertEqual([], calls["counts"])
        self.assertIn("Sentry check skipped", out)

    def test_sentry_spike_breaches(self):
        def counts(start, end, clock):
            # Baseline query ends before the watch began; afterwards 50 errors since start.
            return 6 if end < clock.wall_now - 60 else 50

        breach, calls, _, _ = run_watch(watch_args(), [[]], sentry={"token": "t"}, counts=counts)
        self.assertIsNotNone(breach)
        self.assertIn("Sentry errors 50", breach)
        # Baseline: the configured 60 minutes, ending the configured 15 minutes before the watch.
        start, end = calls["counts"][0]
        self.assertEqual(60 * 60, end - start)
        self.assertEqual(1_800_000_000.0 - 15 * 60, end)

    def test_baseline_window_follows_the_arguments(self):
        _, calls, _, _ = run_watch(watch_args(sentry_baseline_minutes=30, sentry_baseline_gap_minutes=5), [[]],
                                   sentry={"token": "t"}, counts=lambda s, e, c: 0)
        start, end = calls["counts"][0]
        self.assertEqual(30 * 60, end - start)
        self.assertEqual(1_800_000_000.0 - 5 * 60, end)

    def test_sentry_steady_rate_passes(self):
        breach, calls, out, _ = run_watch(watch_args(), [[]], sentry={"token": "t"}, counts=lambda s, e, c: 3)
        self.assertIsNone(breach)
        self.assertIn("no spike", out)
        # Baseline + every 300 s (at 300 s) + the final check at the end of the window.
        self.assertEqual(3, len(calls["counts"]))

    def test_sentry_outage_never_rolls_back(self):
        breach, calls, out, _ = run_watch(watch_args(), [[]], sentry={"token": "t"}, counts=lambda s, e, c: None)
        self.assertIsNone(breach)
        self.assertIn("Sentry baseline unavailable", out)
        self.assertEqual(1, len(calls["counts"]))  # no further queries once the baseline is unknown


class GateWatchTest(unittest.TestCase):
    TARGET, PREV = "a" * 40, "b" * 40

    def gate_args(self, **overrides):
        values = dict(
            base_url="https://daan.test", attempts=1, interval=0, timeout=1, insecure=True, deadline=10,
            target_sha=self.TARGET, previous_sha=self.PREV, frontend_changed="0", migration_ran="0",
            smoke="smoke.sh", watch_seconds=1200, watch_interval=30, watch_failures=4, sentry_every=300,
            sentry_factor=5.0, sentry_floor_per_min=0.2, sentry_min_count=20, sentry_baseline_minutes=60,
            sentry_baseline_gap_minutes=15,
        )
        values.update(overrides)
        return argparse.Namespace(**values)

    def run_gate(self, args, breach, migrations="0", additive="0"):
        seen = {}

        def watch_fn(wargs, sentry=None):
            seen["args"] = wargs
            seen["sentry"] = sentry
            return breach

        def run(cmd, env=None):
            seen["smoke_env"] = env
            return SimpleNamespace(returncode=0)

        with redirect_stdout(io.StringIO()):
            rc = PDV.gate(args, verify_fn=lambda a: 0, run=run, migrations_fn=lambda p, t: migrations,
                          additive_fn=lambda p, t: additive, watch_fn=watch_fn)
        return rc, seen

    def test_breach_takes_the_rollback_path_without_a_migration(self):
        rc, seen = self.run_gate(self.gate_args(), "4 consecutive failed health/identity checks")
        self.assertEqual(PDV.RC_GATE_ROLLBACK, rc)
        self.assertEqual(1200, seen["args"].watch_seconds)
        self.assertEqual(self.TARGET, seen["args"].expect_backend_sha)

    def test_breach_holds_when_a_non_additive_migration_is_in_range(self):
        rc, _ = self.run_gate(self.gate_args(migration_ran="1"), "spike", migrations="1", additive="0")
        self.assertEqual(PDV.RC_HELD_MIGRATION, rc)
        rc, _ = self.run_gate(self.gate_args(migration_ran="1"), "spike", migrations="1", additive="1")
        self.assertEqual(PDV.RC_GATE_ROLLBACK, rc)

    def test_clean_watch_passes(self):
        rc, _ = self.run_gate(self.gate_args(), None)
        self.assertEqual(0, rc)

    def test_watch_settings_flow_from_the_arguments(self):
        _, seen = self.run_gate(self.gate_args(watch_interval=45, watch_failures=7, sentry_factor=3.5,
                                               sentry_min_count=11), None)
        self.assertEqual(45, seen["args"].watch_interval)
        self.assertEqual(7, seen["args"].watch_failures)
        self.assertEqual(3.5, seen["args"].spike.factor)
        self.assertEqual(11, seen["args"].spike.min_count)

    def test_no_watch_when_seconds_is_zero(self):
        rc, seen = self.run_gate(self.gate_args(watch_seconds=0), "would breach")
        self.assertEqual(0, rc)
        self.assertNotIn("args", seen)

    def test_smoke_never_receives_the_sentry_secret(self):
        import os

        os.environ[PDV.SENTRY_TOKEN_ENV] = "acme/backend sntrys_x"
        try:
            _, seen = self.run_gate(self.gate_args(), None)
        finally:
            del os.environ[PDV.SENTRY_TOKEN_ENV]
        self.assertNotIn(PDV.SENTRY_TOKEN_ENV, seen["smoke_env"])
        self.assertEqual({"token": "sntrys_x", "org": "acme", "project": "backend", "base": "https://sentry.io"},
                         seen["sentry"])

    def test_cli_rejects_a_zero_failure_threshold(self):
        with redirect_stdout(io.StringIO()), self.assertRaises(SystemExit):
            sys_stderr, sys.stderr = sys.stderr, io.StringIO()
            try:
                PDV.main(["gate", "--target-sha", "x", "--previous-sha", "y", "--migration-ran", "0",
                          "--watch-seconds", "60", "--watch-failures", "0"])
            finally:
                sys.stderr = sys_stderr


class SentryClientTest(unittest.TestCase):
    def test_config_parsing(self):
        parse = PDV.sentry_config_from_env
        with redirect_stdout(io.StringIO()):
            self.assertIsNone(parse({}))
            self.assertIsNone(parse({"SENTRY_WATCH": "   "}))
            self.assertIsNone(parse({"SENTRY_WATCH": "only-token"}))
            self.assertIsNone(parse({"SENTRY_WATCH": "acme/../x tok"}))  # slug check
            self.assertIsNone(parse({"SENTRY_WATCH": "http://evil acme/p tok"}))  # https only
            self.assertEqual("https://de.sentry.io",
                             parse({"SENTRY_WATCH": "https://de.sentry.io/ acme/p tok"})["base"])
        self.assertEqual({"token": "tok", "org": "acme", "project": "p", "base": "https://sentry.io"},
                         parse({"SENTRY_WATCH": "acme/p tok"}))

    def test_query_shape_and_failure(self):
        captured = {}

        class Response(io.BytesIO):
            def __enter__(self):
                return self

            def __exit__(self, *exc):
                return False

        def opener(request, timeout):
            captured["url"] = request.full_url
            captured["auth"] = request.get_header("Authorization")
            return Response(json.dumps({"data": [{"count()": 17}]}).encode())

        config = {"token": "tok", "org": "acme", "project": "backend", "base": "https://sentry.io"}
        self.assertEqual(17, PDV.sentry_error_count(config, 0, 60, opener=opener))
        self.assertTrue(captured["url"].startswith("https://sentry.io/api/0/organizations/acme/events/?"))
        self.assertIn("project%3Abackend", captured["url"])
        self.assertIn("event.type%3Aerror", captured["url"])
        self.assertEqual("Bearer tok", captured["auth"])

        def broken(request, timeout):
            raise OSError("down")

        with redirect_stdout(io.StringIO()):
            self.assertIsNone(PDV.sentry_error_count(config, 0, 60, opener=broken))


class WorkflowWiringTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        start = cls.workflow.index("  deploy:\n")
        cls.deploy = cls.workflow[start:cls.workflow.index("\n  deploy-incident:\n")]
        cls.incident = cls.workflow[cls.workflow.index("\n  deploy-incident:\n"):cls.workflow.index("\n  parent-portal-smoke:\n")]

    def test_executor_gate_runs_the_watch_with_the_sentry_secret(self):
        line = next(l for l in self.deploy.splitlines() if 'python3 -I "$PDV" gate' in l)
        self.assertIn('SENTRY_WATCH="${{ secrets.SENTRY_WATCH }}" python3 -I "$PDV" gate', line)
        self.assertRegex(line, r"--watch-seconds \d+")

    def test_runner_timeout_covers_the_watch_window(self):
        line = next(l for l in self.deploy.splitlines() if 'python3 -I "$PDV" gate' in l)
        watch_seconds = float(re.search(r"--watch-seconds (\d+)", line).group(1))
        timeout = int(re.search(r"(?m)^    timeout-minutes: (\d+)$", self.deploy).group(1))
        # Deploy + verify/smoke + rollback need at least 25 minutes on top of the watch.
        self.assertGreaterEqual(timeout, watch_seconds / 60 + 25)

    def test_executor_gate_maps_a_breach_to_the_existing_rollback(self):
        stage = self.deploy.split('echo "=== [6/7]', 1)[1].split("\n          ENDSSH", 1)[0]
        self.assertIn("case $? in 0) exit 0 ;; 20) ;; *) exit 12 ;; esac", stage)
        self.assertTrue(stage.rstrip().endswith('abort_deploy "health check 或 smoke test 失敗"'))

    def test_incident_job_is_least_privilege_and_only_after_an_executor_failure(self):
        self.assertIn("needs: [resolve-target, detect-deployable, deploy]", self.incident)
        # Cancellation/missing outputs directly replace the source's rc-required failure contract.
        self.assertIn("if: ${{ always() && (needs.deploy.result == 'failure' || needs.deploy.result == 'cancelled') }}",
                      self.incident)
        self.assertIn("permissions:\n      contents: read\n      issues: write\n", self.incident)
        self.assertNotIn("secrets.", self.incident)  # no SSH key or production secret in this job
        self.assertIn("rc: ${{ steps.deploy.outputs.rc }}", self.deploy)
        self.assertNotIn("issues: write", self.deploy)
        self.assertIn('summarize --rc "$RC"', self.incident)

    def test_contract_test_runs_this_file(self):
        self.assertIn("python3 scripts/tests/test_post_deploy_watch.py", self.workflow)



class OutcomeIntegrityTest(unittest.TestCase):
    def test_unknown_and_actual_runner_outcomes_are_truthful(self):
        for rc in (None, "", "unknown", "not-an-int"):
            text = PDV.summarize(rc, "target", "previous", "cancelled")
            self.assertIn("UNKNOWN", text)
            self.assertNotIn("code `1`", text)
            self.assertNotIn("rollback was re-verified", text)
        for outcome in ("cancelled", "skipped", ""):
            text = PDV.summarize(0, "target", "previous", outcome)
            self.assertIn("UNKNOWN", text)
            self.assertNotIn("runner check failed", text)
        self.assertIn("runner check failed", PDV.summarize(0, "target", "previous", "failure"))

    def test_real_watch_terminal_outcomes_survive_the_marker(self):
        cases = ((None, None, "skipped_unconfigured"),
                 ({"token": "synthetic"}, [None], "skipped_baseline_unavailable"),
                 ({"token": "synthetic"}, [3, None, 3], "degraded_query_unavailable"),
                 ({"token": "synthetic"}, [3, 3, 3], "passed"),
                 ({"token": "synthetic"}, [0, 50], "breached"))
        for sentry, values, expected in cases:
            clock, args = FakeClock(), watch_args()
            args.outcome = {"watch": "unknown", "sentry": "unknown"}
            counts = iter(values or [])
            with redirect_stdout(io.StringIO()):
                breach = PDV.watch(args, sentry=sentry, check=lambda: [], count=lambda *a: next(counts),
                                   sleep=clock.sleep, clock=clock.clock, wall=clock.wall)
            self.assertEqual(expected, args.outcome["sentry"])
            self.assertEqual("breached" if breach else "passed", args.outcome["watch"])
            payload = io.StringIO()
            PDV.emit_watch_result(args.outcome, payload)
            output = io.StringIO()
            PDV.capture_watch(io.StringIO(payload.getvalue()), output)
            self.assertIn(f"sentry={expected}\n", output.getvalue())

    def test_gate_emits_malformed_config_and_interruption_without_token(self):
        args = GateWatchTest().gate_args()
        for config, interrupted in (("synthetic-secret", False), ("acme/p synthetic-secret", True)):
            def watcher(wargs, sentry=None):
                if interrupted:
                    raise KeyboardInterrupt()
                return PDV.watch(wargs, sentry=sentry, check=lambda: [], sleep=lambda _: None,
                                 clock=iter([0, 1201, 1201]).__next__)
            out = io.StringIO()
            with patch.dict(os.environ, {"SENTRY_WATCH": config}), redirect_stdout(out):
                try:
                    PDV.gate(args, verify_fn=lambda _: 0, run=lambda *a, **k: SimpleNamespace(returncode=0),
                             watch_fn=watcher)
                except KeyboardInterrupt:
                    self.assertTrue(interrupted)
            self.assertNotIn("synthetic-secret", out.getvalue())
            captured = io.StringIO()
            PDV.capture_watch(io.StringIO(out.getvalue()), captured)
            expected = "unknown" if interrupted else "skipped_malformed"
            self.assertIn(f"sentry={expected}\n", captured.getvalue())
            self.assertIn("watch=unknown\n" if interrupted else "watch=passed\n", captured.getvalue())

    def test_marker_is_strict_and_never_echoes_untrusted_values(self):
        valid = 'ALLTRUE_WATCH_RESULT={"version":1,"watch":"passed","sentry":"passed"}\n'
        bad = ("", valid * 2, valid.replace('"passed"', '"invented"', 1),
               valid.rstrip() + 'synthetic-secret\n', valid.replace('"version":1', '"version":true'),
               valid.replace('"watch":"passed"', '"watch":"passed","watch":"passed"'),
               valid.replace('"sentry":"passed"', '"sentry":"breached"'),
               valid.replace('"passed"', '"passed\\nsynthetic-secret"', 1))
        for payload in bad:
            result = io.StringIO()
            PDV.capture_watch(io.StringIO(payload), result)
            self.assertEqual("watch=unknown\nsentry=unknown\n", result.getvalue())
        result = io.StringIO()
        PDV.capture_watch(io.StringIO(valid), result)
        self.assertEqual("watch=passed\nsentry=passed\n", result.getvalue())

    def test_sentry_exception_never_echoes_token(self):
        def broken(*a, **k):
            raise OSError("synthetic-secret")
        out = io.StringIO()
        config = {"token": "synthetic-secret", "org": "acme", "project": "p", "base": "https://sentry.io"}
        with redirect_stdout(out):
            self.assertIsNone(PDV.sentry_error_count(config, 0, 60, opener=broken))
        self.assertNotIn("synthetic-secret", out.getvalue())

    def test_actual_ssh_payload_keeps_exit_code_and_strict_outputs(self):
        workflow = WORKFLOW.read_text()
        # Execute the committed runner wrapper, replacing only its SSH transport.
        script = workflow.split("      - name: Deploy\n", 1)[1].split("        run: |\n", 1)[1]
        script = script.split("\n      # Post-deploy safety net", 1)[0]
        script = textwrap.dedent(script)
        script = re.sub(r"\$\{\{.*?\}\}", "unused", script)
        script = re.sub(r"(?s)(<< 'ENDSSH'.*?\n).*?\nENDSSH", r"\1ENDSSH", script)
        script = script.replace("post-deploy-verify/scripts/post-deploy-verify.py", str(ROOT / "scripts/post-deploy-verify.py"))
        marker = 'ALLTRUE_WATCH_RESULT={"version":1,"watch":"passed","sentry":"passed"}\n'
        for rc, payload in ((0, marker), (10, marker), (12, ""), (255, marker), (0, marker * 2)):
            with tempfile.TemporaryDirectory() as directory:
                output = Path(directory) / "outputs"
                env = {"PATH": os.environ["PATH"], "GITHUB_OUTPUT": str(output), "SSH_RC": str(rc), "PAYLOAD": payload,
                       "WATCH_READER": str(ROOT / "scripts/post-deploy-verify.py")}
                harness = 'ssh() { cat >/dev/null; printf "%s" "$PAYLOAD"; return "$SSH_RC"; }\n'
                result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", harness + script],
                                        env=env, text=True, capture_output=True)
                self.assertEqual(rc, result.returncode, result.stderr)
                outputs = dict(line.split("=", 1) for line in output.read_text().splitlines())
                self.assertEqual(str(rc), outputs["rc"])
                self.assertEqual("passed" if payload == marker else "unknown", outputs["watch"])
                self.assertNotIn("ALLTRUE_WATCH_RESULT", result.stdout)

    def test_actual_failure_and_incident_payloads_keep_unknown_results(self):
        workflow = WORKFLOW.read_text()
        def step_script(name):
            block = workflow.split(f"      - name: {name}\n", 1)[1]
            block = re.split(r"\n(?:      - name: |  [a-z].*:\n)", block, maxsplit=1)[0]
            return textwrap.dedent(block.split("        run: |\n", 1)[1])
        incident = step_script("Open or append the deploy incident issue")
        failure = step_script("Summarize production verification failure")
        helper = ROOT / "scripts/post-deploy-verify.py"
        for checkout in (True, False):
            for rc, runner, expected in (("", "cancelled", "UNKNOWN"), ("", "skipped", "UNKNOWN"),
                                         ("255", "skipped", "unknown"), ("0", "cancelled", "UNKNOWN"),
                                         ("0", "failure", "runner check failed"),
                                         ("10", "skipped", "rollback was re-verified"),
                                         ("11", "skipped", "did not verify"),
                                         ("12", "skipped", "no automatic rollback"),
                                         ("13", "skipped", "Runtime state is UNKNOWN")):
                with tempfile.TemporaryDirectory() as directory:
                    root = Path(directory)
                    if checkout:
                        for parent in (root / "scripts", root / "post-deploy-verify/scripts"):
                            parent.mkdir(parents=True)
                            (parent / "post-deploy-verify.py").symlink_to(helper)
                    env = {"PATH": os.environ["PATH"], "RC": rc, "RUNNER_VERIFY": runner,
                           "WATCH": "unknown", "SENTRY": "unknown", "TARGET_SHA": "target", "PREVIOUS_SHA": "previous",
                           "RUN_URL": "https://example.test/run/1", "REPO": "o/r", "GITHUB_STEP_SUMMARY": str(root / "summary"),
                           "INCIDENT_BODY": str(root / "body")}
                    gh = 'gh() { for arg in "$@"; do case "$arg" in body=*) printf "%s" "${arg#body=}" > "$INCIDENT_BODY";; esac; done; }\n'
                    result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", gh + incident],
                                            cwd=root, env=env, text=True, capture_output=True)
                    self.assertEqual(0, result.returncode, result.stderr)
                    body = (root / "body").read_text()
                    self.assertIn(expected if checkout else "UNKNOWN", body)
                    self.assertNotIn("auto-activation is frozen", body)
                    self.assertNotIn("code `1`", body)
                    result = subprocess.run(["bash", "-e", "-c", failure], cwd=root, env=env,
                                            text=True, capture_output=True)
                    self.assertEqual(0 if checkout else 1, result.returncode, result.stderr)
                    summary = (root / "summary").read_text()
                    self.assertIn(expected if checkout else "UNKNOWN", summary)
        # Evaluate the committed job condition for failure/cancellation without rc outputs.
        expression = workflow.split("  deploy-incident:\n", 1)[1].split("    if: ${{ ", 1)[1].split(" }}", 1)[0]
        for state in ("failure", "cancelled", "success", "skipped"):
            condition = expression.replace("always()", "True").replace("needs.deploy.result", repr(state))
            condition = condition.replace("&&", "and").replace("||", "or")
            self.assertEqual(state in ("failure", "cancelled"), eval(condition, {"__builtins__": {}}))

    def test_success_summary_cannot_pass_missing_or_malformed_markers(self):
        workflow = WORKFLOW.read_text()
        block = workflow.split("      - name: Record deployed and production-verified state\n", 1)[1]
        script = textwrap.dedent(block.split("        run: |\n", 1)[1].split("\n      - name:", 1)[0])
        script = script.replace("post-deploy-verify/scripts/post-deploy-verify.py", str(ROOT / "scripts/post-deploy-verify.py"))
        for watch, sentry in (("passed", "skipped_malformed"), ("passed", "degraded_query_unavailable"),
                              ("unknown", "unknown"), ("", "")):
            with tempfile.TemporaryDirectory() as directory:
                summary = Path(directory) / "summary"
                env = {"PATH": os.environ["PATH"], "WATCH": watch, "SENTRY": sentry, "GITHUB_STEP_SUMMARY": str(summary)}
                result = subprocess.run(["bash", "-e", "-c", script], env=env, text=True, capture_output=True)
                self.assertEqual(0 if watch == "passed" else 1, result.returncode, result.stderr)
                text = summary.read_text()
                self.assertIn(f"Sentry error-count check: {sentry or 'unknown'}", text)
                self.assertIn(f"Health/identity watch: {watch or 'unknown'}", text)
                if watch != "passed":
                    self.assertNotIn("## state=production-verified", text)
                    self.assertNotIn("## state=deployed", text)
                    self.assertIn("## state=production-verification-failed", text)
                    self.assertIn("UNKNOWN", text)

    def test_helper_failures_use_fixed_unknown_and_still_attempt_incident(self):
        workflow = WORKFLOW.read_text()
        def step_script(name):
            block = workflow.split(f"      - name: {name}\n", 1)[1]
            block = re.split(r"\n(?:      - name: |  [a-z].*:\n)", block, maxsplit=1)[0]
            return textwrap.dedent(block.split("        run: |\n", 1)[1])
        incident = step_script("Open or append the deploy incident issue")
        failure = step_script("Summarize production verification failure")
        success = step_script("Record deployed and production-verified state")
        for broken_command in ("summarize", "watch-summary", "all"):
            with self.subTest(broken_command=broken_command), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                # A present helper can crash or emit partial output before failing.
                source = ("import sys\n"
                          "if sys.argv[1] in (" + repr(broken_command) + ", 'all') or " + repr(broken_command) + " == 'all':\n"
                          "    print('synthetic-secret')\n"
                          "    raise RuntimeError('synthetic-secret')\n"
                          "print('Automatic rollback was re-verified' if sys.argv[1] == 'summarize' else 'watch passed')\n")
                for parent in (root / "scripts", root / "post-deploy-verify/scripts"):
                    parent.mkdir(parents=True)
                    (parent / "post-deploy-verify.py").write_text(source)
                env = {"PATH": os.environ["PATH"], "RC": "", "RUNNER_VERIFY": "cancelled",
                       "WATCH": "unknown", "SENTRY": "unknown", "TARGET_SHA": "target", "PREVIOUS_SHA": "previous",
                       "RUN_URL": "https://example.test/run/1", "REPO": "o/r", "GITHUB_STEP_SUMMARY": str(root / "summary"),
                       "INCIDENT_BODY": str(root / "body"), "GH_CALLED": str(root / "gh-called")}
                gh = ('gh() { touch "$GH_CALLED"; for arg in "$@"; do case "$arg" in '
                      'body=*) printf "%s" "${arg#body=}" > "$INCIDENT_BODY";; esac; done; }\n')
                result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", gh + incident],
                                        cwd=root, env=env, text=True, capture_output=True)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertTrue((root / "gh-called").exists())
                body = (root / "body").read_text()
                self.assertIn("UNKNOWN", body)
                for text in (body, result.stdout, result.stderr):
                    self.assertNotIn("synthetic-secret", text)
                    self.assertNotIn("rollback was re-verified", text)
                # The always failure-summary path must also record unknown on a helper crash.
                if broken_command != "watch-summary":
                    result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", failure],
                                            cwd=root, env=env, text=True, capture_output=True)
                    text = (root / "summary").read_text()
                    self.assertIn("## state=production-verification-failed", text)
                    self.assertIn("UNKNOWN", text)
                    self.assertNotIn("synthetic-secret", text + result.stdout + result.stderr)
                    self.assertEqual(1, result.returncode)
                if broken_command != "summarize":
                    env.update(WATCH="passed", SENTRY="passed")
                    result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", success],
                                            cwd=root, env=env, text=True, capture_output=True)
                    text = (root / "summary").read_text()
                    self.assertEqual(1, result.returncode)
                    self.assertIn("UNKNOWN", text)
                    self.assertNotIn("## state=production-verified", text)
                    self.assertNotIn("## state=deployed", text)
                    self.assertNotIn("synthetic-secret", text + result.stdout + result.stderr)

    def test_incident_and_failure_summary_run_with_missing_outputs(self):
        workflow = WORKFLOW.read_text()
        incident = workflow.split("\n  deploy-incident:\n", 1)[1].split("\n  parent-portal-smoke:\n", 1)[0]
        self.assertIn("needs.deploy.result == 'cancelled'", incident)
        self.assertNotIn("needs.deploy.outputs.rc != ''", incident)
        self.assertIn("if: ${{ always() }}", incident)
        self.assertIn('runner-verify "$RUNNER_VERIFY"', incident)
        self.assertNotIn("auto-activation is frozen", incident)
        self.assertNotIn('${RC:-1}', workflow)
        self.assertNotIn("SENTRY_WATCH_CONFIGURED", workflow)


if __name__ == "__main__":
    unittest.main()
