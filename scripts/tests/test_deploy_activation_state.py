"""Executable policy decisions and regression contracts for Deploy to Pi.

The actual changed-scope classifier is owned by #2180.  This module only
decides what the activation state machine may do with already-validated
classifier evidence; it deliberately does not parse PR bodies or paths.
"""

from pathlib import Path
import re
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "deploy.yml"
TIER_VALUES = {"T0": 0, "T1": 1, "T2": 2, "T3": 3}
RISK_VALUES = {"R0": 0, "R1": 1, "R2": 2, "R3": 3}
FULL_SHA = re.compile(r"^[0-9a-f]{40}$")


def decide_activation(
    *,
    event_name,
    deployable,
    classifier_available,
    machine_validated,
    machine_tier=None,
    declared_risk=None,
    declared_tier=None,
    workflow_change=False,
):
    """Return the only safe state for a deployable commit.

    ``machine_tier`` is supplied by the authoritative #2180 classifier.  A
    declaration can raise the effective tier, but can never lower the
    classifier minimum.  Missing or contradictory evidence is held.
    """
    if not deployable:
        return {"decision": "no-op", "effective_tier": "none", "reason": "non-deployable change"}
    if event_name == "workflow_dispatch":
        return {
            "decision": "manual",
            "effective_tier": "protected",
            "reason": "manual activation requires the production environment gate",
        }
    if not classifier_available:
        return {
            "decision": "awaiting-activation",
            "effective_tier": "unknown",
            "reason": "authoritative classifier unavailable; fail closed (depends on #2180)",
        }
    if not machine_validated or machine_tier not in TIER_VALUES:
        return {
            "decision": "awaiting-activation",
            "effective_tier": "unknown",
            "reason": "machine-derived tier evidence is unavailable or invalid; fail closed",
        }
    if declared_risk not in RISK_VALUES or declared_tier not in TIER_VALUES:
        return {
            "decision": "awaiting-activation",
            "effective_tier": f"T{machine_tier}",
            "reason": "missing or invalid risk/tier declaration; fail closed",
        }
    if RISK_VALUES[declared_risk] != TIER_VALUES[declared_tier]:
        return {
            "decision": "awaiting-activation",
            "effective_tier": f"T{max(TIER_VALUES[declared_tier], RISK_VALUES[declared_risk])}",
            "reason": "Risk-Class and Autonomy-Tier do not match; fail closed",
        }
    if TIER_VALUES[declared_tier] < TIER_VALUES[machine_tier]:
        return {
            "decision": "awaiting-activation",
            "effective_tier": f"T{machine_tier}",
            "reason": "declared tier is below the machine-derived minimum; fail closed",
        }
    effective = max(TIER_VALUES[declared_tier], TIER_VALUES[machine_tier])
    if workflow_change:
        return {
            "decision": "awaiting-activation",
            "effective_tier": f"T{effective}",
            "reason": "workflow change requires production activation",
        }
    if effective in (0, 1):
        return {
            "decision": "auto",
            "effective_tier": f"T{effective}",
            "reason": f"machine-validated effective {declared_risk}/{declared_tier} is auto-deployable",
        }
    return {
        "decision": "awaiting-activation",
        "effective_tier": f"T{effective}",
        "reason": f"effective tier T{effective} requires Founder activation",
    }


def decide_manual_activation(*, workflow_ref, target_sha, current_main_sha, ci_success, founder_gate_reached):
    """Validate the manual gate without performing production work."""
    if workflow_ref != "refs/heads/main":
        return {"decision": "rejected", "reason": "manual application activation must run from refs/heads/main"}
    if not FULL_SHA.fullmatch(target_sha or ""):
        return {"decision": "rejected", "reason": "activation requires a full target SHA"}
    if target_sha != current_main_sha:
        return {"decision": "rejected", "reason": "target SHA is not the current main SHA"}
    if not ci_success:
        return {"decision": "rejected", "reason": "exact target SHA has no successful main CI"}
    if not founder_gate_reached:
        return {"decision": "awaiting-founder-approval", "reason": "production environment approval is required"}
    return {"decision": "activation-gate-reached", "reason": "Founder-approved exact-main activation may proceed"}


class DeployActivationPolicyTest(unittest.TestCase):
    def test_r0_t0_normal_change_is_auto_eligible(self):
        result = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier="T0", declared_risk="R0",
            declared_tier="T0",
        )
        self.assertEqual(result["decision"], "auto")

    def test_r1_t1_validated_normal_change_is_auto_eligible(self):
        result = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier="T1", declared_risk="R1",
            declared_tier="T1",
        )
        self.assertEqual(result["decision"], "auto")

    def test_t2_and_t3_are_held(self):
        for tier in ("T2", "T3"):
            with self.subTest(tier=tier):
                result = decide_activation(
                    event_name="workflow_run", deployable=True, classifier_available=True,
                    machine_validated=True, machine_tier=tier,
                    declared_risk=f"R{tier[-1]}", declared_tier=tier,
                )
                self.assertEqual(result["decision"], "awaiting-activation")

    def test_workflow_change_is_held_even_when_declared_t0_or_t1(self):
        for tier, risk in (("T0", "R0"), ("T1", "R1")):
            with self.subTest(tier=tier):
                result = decide_activation(
                    event_name="workflow_run", deployable=True, classifier_available=True,
                    machine_validated=True, machine_tier=tier,
                    declared_risk=risk, declared_tier=tier, workflow_change=True,
                )
                self.assertEqual(result["decision"], "awaiting-activation")

    def test_missing_metadata_or_classifier_evidence_fails_closed(self):
        missing = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier="T1",
        )
        unavailable = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=False,
            machine_validated=False,
        )
        self.assertEqual(missing["decision"], "awaiting-activation")
        self.assertEqual(unavailable["decision"], "awaiting-activation")

    def test_understated_and_mismatched_declarations_fail_closed(self):
        understated = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier="T2", declared_risk="R1",
            declared_tier="T1",
        )
        mismatched = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier="T1", declared_risk="R2",
            declared_tier="T1",
        )
        self.assertEqual(understated["decision"], "awaiting-activation")
        self.assertEqual(mismatched["decision"], "awaiting-activation")

    def test_manual_wrong_sha_and_non_current_main_sha_are_rejected(self):
        current = "a" * 40
        self.assertEqual(
            decide_manual_activation(
                workflow_ref="refs/heads/main", target_sha="b" * 40,
                current_main_sha=current, ci_success=True, founder_gate_reached=True,
            )["decision"],
            "rejected",
        )
        self.assertEqual(
            decide_manual_activation(
                workflow_ref="refs/heads/main", target_sha="not-a-sha",
                current_main_sha=current, ci_success=True, founder_gate_reached=True,
            )["decision"],
            "rejected",
        )

    def test_manual_non_main_workflow_ref_is_rejected(self):
        current = "a" * 40
        result = decide_manual_activation(
            workflow_ref="refs/tags/release", target_sha=current,
            current_main_sha=current, ci_success=True, founder_gate_reached=True,
        )
        self.assertEqual(result["decision"], "rejected")

    def test_valid_founder_path_reaches_gate_but_does_not_bypass_it(self):
        current = "a" * 40
        result = decide_manual_activation(
            workflow_ref="refs/heads/main", target_sha=current,
            current_main_sha=current, ci_success=True, founder_gate_reached=True,
        )
        self.assertEqual(result["decision"], "activation-gate-reached")
        self.assertNotEqual(result["decision"], "auto")


class DeployActivationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_existing_ci_completion_trigger_is_preserved(self):
        self.assertIn('workflows: ["CI — PHPUnit Tests"]', self.workflow)
        self.assertIn("types: [completed]", self.workflow)
        self.assertIn("branches: [main]", self.workflow)

    def test_manual_activation_is_existing_workflow_dispatch_and_environment_gate(self):
        self.assertIn("- application-deploy", self.workflow)
        self.assertIn("target_sha:", self.workflow)
        self.assertIn("ACTIVATE_PRODUCTION:<target_sha>", self.workflow)
        self.assertIn("environment:", self.workflow)
        self.assertIn("name: production-activation", self.workflow)
        self.assertIn("Checkout target revision for gate policy", self.workflow)
        self.assertIn("production environment protection is not configured", self.workflow)

    def test_manual_workflow_revision_is_canonical_main(self):
        self.assertIn('WORKFLOW_REF: ${{ github.ref }}', self.workflow)
        self.assertIn('"$WORKFLOW_REF" != "refs/heads/main"', self.workflow)
        self.assertIn("Manual application activation must be dispatched from refs/heads/main", self.workflow)

    def test_classifier_is_authoritative_and_fail_closed_when_missing(self):
        self.assertIn("scripts/governance/autonomy_gate.py", self.workflow)
        self.assertIn("authoritative classifier unavailable; fail closed (depends on #2180)", self.workflow)
        self.assertIn("classify_scope", self.workflow)
        self.assertIn("parse_declaration", self.workflow)
        self.assertIn("effective_tier", self.workflow)
        self.assertNotIn("re.search(r\"(?m)^\\*\\*Risk-Class", self.workflow)

    def test_state_machine_has_fail_closed_modes(self):
        for mode in ("no-op", "manual"):
            self.assertIn(f'"decision": "{mode}"', Path(__file__).read_text(encoding="utf-8"))
        for mode in ("auto", "awaiting-activation"):
            self.assertIn(f'"decision": "{mode}"', Path(__file__).read_text(encoding="utf-8"))
        self.assertIn("No production SSH, migration, frontend build, or data mutation was executed", self.workflow)

    def test_deploy_job_requires_auto_or_successful_manual_gate(self):
        self.assertIn("needs: [resolve-target, detect-deployable, classify-activation, production-activation]", self.workflow)
        self.assertIn("needs.classify-activation.outputs.mode == 'auto'", self.workflow)
        self.assertIn("needs.production-activation.result == 'success'", self.workflow)
        self.assertIn('TARGET_SHA="${{ needs.resolve-target.outputs.target_sha }}"', self.workflow)

    def test_manual_target_requires_current_main_and_successful_ci(self):
        self.assertIn("Manual activation must target the current main SHA", self.workflow)
        self.assertIn("actions/workflows/ci.yml/runs?branch=main&head_sha=", self.workflow)
        self.assertIn('run.get("name") == "CI — PHPUnit Tests"', self.workflow)
        self.assertIn('run.get("conclusion") == "success"', self.workflow)
        self.assertIn("Final exact-main gate before production executor", self.workflow)
        self.assertIn("REMOTE_MAIN_SHA=$(git rev-parse refs/remotes/origin/main)", self.workflow)

    def test_contract_test_runs_before_target_resolution(self):
        self.assertIn("contract-test:", self.workflow)
        self.assertIn("needs: contract-test", self.workflow)
        self.assertIn("python3 scripts/tests/test_deploy_activation_state.py", self.workflow)
        self.assertEqual(self.workflow.count("  detect-deployable:"), 1)

    def test_production_concurrency_is_scoped_to_side_effecting_executor(self):
        top_level = self.workflow.split("permissions:", 1)[0]
        self.assertNotIn("concurrency:", top_level)

        deploy_start = self.workflow.index("  deploy:\n")
        deploy_end = self.workflow.index("  staged_principal_rotation:", deploy_start)
        deploy_job = self.workflow[deploy_start:deploy_end]
        self.assertIn("concurrency:\n      group: production-deploy", deploy_job)
        self.assertIn("cancel-in-progress: false", deploy_job)

        rotation_start = self.workflow.index("  staged_principal_rotation:\n")
        rotation_job = self.workflow[rotation_start:]
        self.assertIn("concurrency:\n      group: production-deploy", rotation_job)
        self.assertIn("cancel-in-progress: false", rotation_job)


if __name__ == "__main__":
    unittest.main()
