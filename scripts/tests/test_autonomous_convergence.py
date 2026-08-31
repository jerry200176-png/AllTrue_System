"""Regression contracts for the main-event convergence scheduler."""

from pathlib import Path
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "autonomous-convergence.yml"


def should_dispatch(*, active_ci, recent_dispatch, deploy_present):
    """Only request CI when no exact-main run is active or already downstream."""

    return not (active_ci or recent_dispatch or deploy_present)


class AutonomousConvergenceTest(unittest.TestCase):
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
        self.assertIn("bounded window", workflow)
        self.assertIn("Dispatch CI when current main has no downstream evidence", workflow)
        self.assertNotIn("ssh ", workflow)

    def test_dispatches_when_merge_left_no_exact_main_evidence(self):
        self.assertTrue(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=False))

    def test_does_not_duplicate_active_or_recent_ci(self):
        self.assertFalse(should_dispatch(active_ci=True, recent_dispatch=False, deploy_present=False))
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=True, deploy_present=False))

    def test_does_not_dispatch_when_deploy_run_exists(self):
        self.assertFalse(should_dispatch(active_ci=False, recent_dispatch=False, deploy_present=True))


if __name__ == "__main__":
    unittest.main()
