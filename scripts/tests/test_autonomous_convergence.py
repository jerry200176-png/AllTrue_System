"""Regression contracts for the event-driven main convergence reconciler."""

from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "autonomous-convergence.yml"
UI_SMOKE_WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "ui-smoke.yml"
CI_WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "ci.yml"


def decide(ci_runs, deploy_runs):
    """Run the workflow's own embedded decision script against fixture run lists."""
    script = WORKFLOW.read_text(encoding="utf-8").split('DEPLOY_JSON_FILE="$DEPLOY_JSON_FILE" python3 - <<\'PY\'\n', 1)[1].split("\n          PY\n", 1)[0]
    with tempfile.TemporaryDirectory() as tmp:
        paths = {}
        for key, runs in (("CI_JSON_FILE", ci_runs), ("DEPLOY_JSON_FILE", deploy_runs)):
            paths[key] = str(Path(tmp) / key)
            Path(paths[key]).write_text(json.dumps({"workflow_runs": runs}), encoding="utf-8")
        out = subprocess.run([sys.executable, "-c", textwrap.dedent(script)], env={**paths, "MAIN_SHA": "a" * 40},
                             capture_output=True, text=True, check=True)
    return out.stdout.splitlines()[0]


def ago(seconds):
    return (datetime.now(timezone.utc) - timedelta(seconds=seconds)).strftime("%Y-%m-%dT%H:%M:%SZ")


def ci(conclusion, *, event="push", created=3000, updated=1800, status="completed"):
    return {"status": status, "conclusion": conclusion, "event": event, "created_at": ago(created), "updated_at": ago(updated)}


class AutonomousConvergenceTest(unittest.TestCase):
    def test_main_push_path_filter_keeps_read_only_fetch_credentials(self):
        workflow = CI_WORKFLOW.read_text(encoding="utf-8")
        changes_job = workflow.split("\n  changes:\n", 1)[1].split("\n  golden_scenarios:\n", 1)[0]
        checkout_step = changes_job.split("      - name: Detect changed paths", 1)[0]
        permissions = workflow.split("\npermissions:\n", 1)[1].split("\n\njobs:\n", 1)[0]

        self.assertIn("uses: dorny/paths-filter@", changes_job)
        self.assertIn("persist-credentials: true", checkout_step)
        self.assertIn("contents: read", permissions)
        self.assertNotIn("contents: write", permissions)

    def test_merged_pr_event_reconciles_bot_merge_without_running_pr_code(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("pull_request_target:", workflow)
        self.assertIn("types: [closed]", workflow)
        self.assertIn("github.event.pull_request.merged == true", workflow)
        self.assertIn("workflow_run:", workflow)
        self.assertIn('workflows: ["Autonomous safe merge"]', workflow)
        self.assertIn("github.event.workflow_run.conclusion == 'success'", workflow)
        self.assertIn("github.event.workflow_run.pull_requests[0].number", workflow)
        self.assertIn("if .merged_at then \"merged\" else .state end", workflow)
        self.assertIn("pull-requests: read", workflow)
        self.assertNotIn("pull-requests: write", workflow)
        permissions_block = workflow.split("\npermissions:\n", 1)[1].split("\n\nconcurrency:\n", 1)[0]
        declared_permissions = [
            line.strip()
            for line in permissions_block.splitlines()
            if line.startswith("  ")
        ]
        self.assertEqual(
            ["actions: write", "contents: write", "pull-requests: read"],
            declared_permissions,
        )
        self.assertIn('[[ "$PR_STATE" == "merged" ]]', workflow)
        self.assertIn('[[ "$PR_STATE" == "closed" ]]', workflow)
        self.assertIn("contents: write", workflow)
        self.assertIn("client_payload[target_sha]", workflow)
        self.assertIn("autonomous-production-deploy", workflow)
        self.assertIn("Exact-main CI did not pass", workflow)
        self.assertIn("bounded window", workflow)
        self.assertIn("Dispatch CI when current main has no downstream evidence", workflow)
        self.assertNotIn("ssh ", workflow)

    def test_reconciliation_is_event_driven_with_manual_fallback(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("pull_request_target:", workflow)
        self.assertIn("workflow_run:", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertNotIn("  schedule:", workflow)
        self.assertNotIn("github.event_name == 'schedule'", workflow)
        self.assertIn("group: autonomous-main-convergence", workflow)
        self.assertIn("cancel-in-progress: true", workflow)
        self.assertIn("timeout-minutes: 40", workflow)
        self.assertIn("for _ in {1..150}; do", workflow)

    def test_ui_smoke_cancels_only_older_runs_for_the_same_pr(self):
        workflow = UI_SMOKE_WORKFLOW.read_text(encoding="utf-8")
        self.assertIn(
            "group: ui-smoke-${{ github.event.pull_request.number || github.run_id }}",
            workflow,
        )
        self.assertIn(
            "cancel-in-progress: ${{ github.event_name == 'pull_request' }}",
            workflow,
        )

    def test_dispatches_when_merge_left_no_exact_main_evidence(self):
        self.assertEqual(decide([], []), "dispatch")

    def test_does_not_duplicate_active_or_recent_ci(self):
        self.assertEqual(decide([ci(None, status="pending")], []), "hold")
        self.assertEqual(decide([ci("success", event="workflow_dispatch", created=600)], []), "hold")

    def test_holds_when_deploy_followed_green_ci_or_no_ci_is_green(self):
        self.assertEqual(decide([ci("success")], [{"created_at": ago(1790)}]), "hold")
        self.assertEqual(decide([ci("failure")], [{"created_at": ago(1790)}]), "hold")
        self.assertEqual(decide([ci("success", updated=30)], []), "hold")  # deploy.yml may still be starting

    def test_hands_off_when_green_ci_has_only_older_noop_deploy_runs(self):
        # 2026-11-26 (#5659): push run cancelled, dispatched run green later, no-op deploy runs created before it finished.
        runs = [ci("cancelled", created=3100, updated=3080), ci("success", event="workflow_dispatch", created=3080, updated=1800)]
        self.assertEqual(decide(runs, [{"created_at": ago(3050)}, {"created_at": ago(3040)}]), "handoff")
        self.assertIn("handoff$'\\n'*", WORKFLOW.read_text(encoding="utf-8"))

    def test_late_noop_deploy_run_from_an_older_commits_ci_is_not_evidence(self):
        # 2026-11-26: a failed rerun of the previous commit's CI created a deploy run tagged with this SHA 14 minutes later.
        self.assertEqual(decide([ci("success", updated=1800)], [{"created_at": ago(900)}]), "handoff")
        self.assertEqual(decide([ci("success", updated=1800)], [{"created_at": ago(1796)}]), "hold")

    def test_deploy_workflow_counts_only_main_ci_events_as_green_evidence(self):
        deploy = (WORKFLOW.parent / "deploy.yml").read_text(encoding="utf-8")
        trusted = 'run.get("name") == "CI — PHPUnit Tests" and run.get("event") in ("push", "workflow_dispatch") and run.get("conclusion") == "success"'
        self.assertEqual(deploy.count(trusted), 4)
        self.assertNotIn('"CI — PHPUnit Tests" and run.get("conclusion")', deploy)

    def test_only_the_newest_trusted_main_ci_run_is_green_evidence(self):
        self.assertEqual(decide([ci("success", event="pull_request")], []), "dispatch")  # not main's own CI
        older_green = [ci("success", created=5000, updated=4000), ci("failure", created=3000, updated=2000)]
        self.assertEqual(decide(older_green, [{"created_at": ago(3990)}]), "hold")


class PatchlessDataFileTest(unittest.TestCase):
    """deploy.yml tolerates a missing GitHub patch for exactly one generated data file (Founder 2026-11-28)."""

    def test_only_the_exam_reference_data_file_may_ride_without_a_patch(self):
        deploy = (WORKFLOW.parent / "deploy.yml").read_text(encoding="utf-8")
        self.assertEqual(deploy.count("patchless_data_file = ("), 1)
        self.assertIn('path == "frontend/src/lib/schoolExamReferenceData.generated.js"', deploy)
        self.assertIn('and item.get("status") == "modified" and not item.get("previous_filename")', deploy)
        self.assertIn("if item_patch is None and not patchless_data_file:", deploy)
        self.assertEqual(deploy.count("if item_patch is None"), 1, "no second, unguarded patch check")
        # The file stays pure data: one export of a JSON literal, nothing executable.
        data = (WORKFLOW.parents[2] / "frontend/src/lib/schoolExamReferenceData.generated.js").read_text(encoding="utf-8")
        self.assertNotRegex(data, r"\b(import|require|function|=>|eval|fetch|window|document)\b")


if __name__ == "__main__":
    unittest.main()
