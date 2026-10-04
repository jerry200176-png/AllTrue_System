"""Regression contracts for the event-driven main convergence reconciler."""

from pathlib import Path
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "autonomous-convergence.yml"
UI_SMOKE_WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "ui-smoke.yml"
CI_WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "ci.yml"


def should_dispatch(*, active_ci, recent_dispatch, deploy_present):
    """Only request CI when no exact-main run is active or already downstream."""

    return not (active_ci or recent_dispatch or deploy_present)


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
        self.assertTrue(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=False))

    def test_does_not_duplicate_active_or_recent_ci(self):
        self.assertFalse(should_dispatch(active_ci=True, recent_dispatch=False, deploy_present=False))
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=True, deploy_present=False))

    def test_does_not_dispatch_when_deploy_run_exists(self):
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=True))


if __name__ == "__main__":
    unittest.main()
