"""Regression contract for the merge-to-production activation boundary."""

from pathlib import Path
import re
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "deploy.yml"


class DeployActivationStateContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_existing_ci_completion_trigger_is_preserved(self):
        self.assertIn('workflows: ["CI — PHPUnit Tests"]', self.workflow)
        self.assertIn("types: [completed]", self.workflow)
        self.assertIn("branches: [main]", self.workflow)

    def test_manual_activation_is_an_existing_workflow_dispatch_phase(self):
        self.assertIn("- application-deploy", self.workflow)
        self.assertIn("target_sha:", self.workflow)
        self.assertIn("ACTIVATE_PRODUCTION:<target_sha>", self.workflow)
        self.assertIn("environment:", self.workflow)
        self.assertIn("name: production-activation", self.workflow)

    def test_state_machine_has_fail_closed_modes(self):
        for mode in ("no-op", "manual"):
            self.assertIn(f"mode={mode}", self.workflow)
        for mode in ("auto", "awaiting-activation"):
            self.assertIn(f'mode = "{mode}"', self.workflow)
        self.assertIn("no merged PR provenance or valid tier declaration; fail closed", self.workflow)
        self.assertIn("No production SSH, migration, frontend build, or data mutation was executed", self.workflow)

    def test_auto_deploy_requires_policy_permitted_tier_and_no_workflow_change(self):
        self.assertRegex(
            self.workflow,
            re.compile(
                r'tier in \{"T0", "T1"\}.*risk == \("R0" if tier == "T0" else "R1"\).*not workflow_change',
                re.S,
            ),
        )
        self.assertIn("mode == 'auto'", self.workflow)
        self.assertIn('changes a workflow; production activation is held', self.workflow)
        self.assertIn('"gh", "api", "--paginate", "--slurp"', self.workflow)

    def test_deploy_job_cannot_run_without_auto_or_approved_manual_gate(self):
        self.assertIn("needs: [resolve-target, detect-deployable, classify-activation, production-activation]", self.workflow)
        self.assertIn("needs.classify-activation.outputs.mode == 'auto'", self.workflow)
        self.assertIn("needs.production-activation.result == 'success'", self.workflow)
        self.assertIn('TARGET_SHA="${{ needs.resolve-target.outputs.target_sha }}"', self.workflow)

    def test_manual_target_is_current_main_and_has_successful_ci(self):
        self.assertIn("Manual activation must target the current main SHA", self.workflow)
        self.assertIn("actions/workflows/ci.yml/runs?branch=main&head_sha=", self.workflow)
        self.assertIn('run.get("name") == "CI — PHPUnit Tests"', self.workflow)
        self.assertIn('run.get("conclusion") == "success"', self.workflow)

    def test_contract_test_runs_before_target_resolution(self):
        self.assertIn("contract-test:", self.workflow)
        self.assertIn("needs: contract-test", self.workflow)
        self.assertIn("python3 scripts/tests/test_deploy_activation_state.py", self.workflow)
        self.assertEqual(self.workflow.count("  detect-deployable:"), 1)


if __name__ == "__main__":
    unittest.main()
