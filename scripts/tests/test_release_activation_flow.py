"""Regression contracts for the single-run post-merge activation flow."""

from pathlib import Path
import sys
import unittest


ROOT = Path(__file__).parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.governance.release_activation import (  # noqa: E402
    environment_protection_is_valid_for_release,
)


class ReleaseActivationPolicyTest(unittest.TestCase):
    def test_post_merge_requires_one_environment_reviewer_gate(self):
        for event_name in ("workflow_run", "repository_dispatch"):
            with self.subTest(event_name=event_name):
                self.assertTrue(environment_protection_is_valid_for_release(
                    event_name=event_name,
                    phase="application-deploy",
                    required_reviewers_configured=True,
                    prevent_self_review=True,
                ))

    def test_post_merge_without_reviewer_is_blocked_not_auto_deployed(self):
        self.assertFalse(environment_protection_is_valid_for_release(
            event_name="workflow_run",
            phase="application-deploy",
            required_reviewers_configured=False,
            prevent_self_review=False,
        ))

    def test_manual_exception_keeps_typed_gate_without_second_reviewer_queue(self):
        self.assertTrue(environment_protection_is_valid_for_release(
            event_name="workflow_dispatch",
            phase="application-deploy",
            required_reviewers_configured=False,
            prevent_self_review=False,
        ))
        self.assertFalse(environment_protection_is_valid_for_release(
            event_name="workflow_dispatch",
            phase="application-deploy",
            required_reviewers_configured=True,
            prevent_self_review=True,
        ))


class ReleaseActivationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_post_merge_uses_same_run_environment_gate(self):
        self.assertIn("github.event_name == 'workflow_run'", self.workflow)
        self.assertIn("github.event_name == 'repository_dispatch'", self.workflow)
        self.assertIn("outputs.mode == 'awaiting-activation'", self.workflow)
        self.assertIn("outputs.approval_eligible == 'true'", self.workflow)
        self.assertIn("name: production-activation", self.workflow)
        self.assertIn("event_name in {\"workflow_run\", \"repository_dispatch\"}", self.workflow)
        self.assertNotIn("Founder exact-SHA dispatch", self.workflow)
        self.assertNotIn("merged-awaiting-activation:", self.workflow)

    def test_invalid_evidence_is_blocked_and_cannot_reach_deploy(self):
        self.assertIn("name: Release state", self.workflow)
        self.assertIn('state=blocked', self.workflow)
        self.assertIn('exit 1', self.workflow)
        self.assertIn("approval_eligible", self.workflow)

    def test_states_do_not_call_workflow_success_deployed(self):
        self.assertIn("state=ready", self.workflow)
        self.assertIn("state=awaiting-approval", self.workflow)
        self.assertIn("state=deployed", self.workflow)
        self.assertIn("state=production-verified", self.workflow)
        self.assertIn("This state is not deployed.", self.workflow)

    def test_environment_review_is_required_before_side_effect_job(self):
        gate = self.workflow[self.workflow.index("  production-activation:"):]
        deploy = self.workflow[self.workflow.index("  deploy:"):]
        self.assertLess(gate.index("environment:"), gate.index("Verify production environment protection"))
        self.assertIn("needs.production-activation.result == 'success'", deploy)
        self.assertIn("Final exact-main gate before production executor", deploy)


if __name__ == "__main__":
    unittest.main()
