"""Executable policy decisions and regression contracts for Deploy to Pi."""

from pathlib import Path
import sys
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "deploy.yml"
AUTOMERGE_WORKFLOW = WORKFLOW.parent / "auto-merge-safe.yml"
BUG_TRIAGE_WORKFLOW = WORKFLOW.parent / "bug-phase-a-triage.yml"
TIER_VALUES = {"T0": 0, "T1": 1, "T2": 2, "T3": 3}
RISK_VALUES = {"R0": 0, "R1": 1, "R2": 2, "R3": 3}
ROOT = Path(__file__).parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.governance.autonomy_gate import (  # noqa: E402
    classify_activation_scope,
    classify_production_runtime,
    classify_scope,
    decide_activation,
    decide_manual_activation,
    environment_protection_is_valid,
    effective_tier,
    is_deployable_path,
    is_production_activation_sensitive_path,
    parse_declaration,
)


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

    def test_production_side_effect_is_held_even_when_declared_t0_or_t1(self):
        for tier, risk in (("T0", "R0"), ("T1", "R1")):
            with self.subTest(tier=tier):
                result = decide_activation(
                    event_name="workflow_run", deployable=True, classifier_available=True,
                    machine_validated=True, machine_tier=tier,
                    declared_risk=risk, declared_tier=tier, protected_activation=True,
                )
                self.assertEqual(result["decision"], "awaiting-activation")

    def test_read_only_scheduler_does_not_block_reversible_runtime_activation(self):
        scope = classify_activation_scope(
            [
                ".github/workflows/autonomous-convergence.yml",
                "frontend/src/pages/AttendancePage.vue",
                "frontend/src/pages/SmartCalendar.vue",
                "frontend/src/pages/__tests__/AttendancePageAccessibility.test.js",
                ".exo/memory/reflection.yaml",
            ]
        )
        self.assertEqual(scope["tier_name"], "T1")
        self.assertFalse(scope["protected_activation"])
        result = decide_activation(
            event_name="workflow_run", deployable=True, classifier_available=True,
            machine_validated=True, machine_tier=scope["tier_name"],
            declared_risk="R1", declared_tier="T1",
            protected_activation=scope["protected_activation"],
        )
        self.assertEqual(result["decision"], "auto")

    def test_production_executor_and_sensitive_paths_remain_protected(self):
        self.assertTrue(is_production_activation_sensitive_path(".github/workflows/deploy.yml"))
        self.assertTrue(is_production_activation_sensitive_path("backend/database/migrations/2026_01_flag.php"))
        self.assertFalse(is_production_activation_sensitive_path(".github/workflows/autonomous-convergence.yml"))
        scope = classify_activation_scope(
            [".github/workflows/deploy.yml", "frontend/src/pages/StudentsList.vue"]
        )
        self.assertEqual(scope["tier_name"], "T3")
        self.assertTrue(scope["protected_activation"])

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

    def test_classifier_keeps_test_only_changes_out_of_production(self):
        self.assertFalse(is_deployable_path("backend/tests/Feature/AuthResponseContractTest.php"))
        self.assertFalse(is_deployable_path("frontend/src/pages/__tests__/BillingPage.test.js"))
        self.assertFalse(is_deployable_path("frontend/src/components/__tests__/Widget.test.js"))
        scope = classify_scope(
            ["backend/tests/Feature/AuthResponseContractTest.php"],
            "assert the auth response envelope",
        )
        self.assertEqual(scope["tier_name"], "T0")

    def test_runtime_marker_scan_ignores_test_and_governance_diffs(self):
        paths = [
            "frontend/src/pages/Badge.vue",
            "frontend/src/pages/__tests__/Badge.test.js",
            ".exo/memory/notes.md",
        ]
        patch = """diff --git a/frontend/src/pages/Badge.vue b/frontend/src/pages/Badge.vue
+++ b/frontend/src/pages/Badge.vue
<span>Display only</span>
diff --git a/frontend/src/pages/__tests__/Badge.test.js b/frontend/src/pages/__tests__/Badge.test.js
+++ b/frontend/src/pages/__tests__/Badge.test.js
+it('does not change auth, payment, or permission behavior', () => {})
"""
        scope = classify_activation_scope(paths, patch)
        self.assertEqual(scope["tier_name"], "T1")

    def test_deploy_queue_uses_runtime_manifest_and_full_undeployed_range(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("/deployment.json", workflow)
        self.assertIn("compare/{deployed}...{head}", workflow)
        self.assertIn("runtime_base_sha", workflow)
        self.assertIn("production deployment identity unavailable; classifier must fail closed", workflow)
        self.assertNotIn('base = parents[0]["sha"]', workflow)

    def test_manual_activation_holds_unknown_runtime_identity(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        classify = workflow[workflow.index("  classify-activation:"):]
        pop_branch = classify.index('if [[ "$EVENT_NAME" == "workflow_dispatch" && "$PHASE" == "pop-bootstrap" ]]')
        manual_branch = classify.index('if [[ "$EVENT_NAME" == "workflow_dispatch" ]]; then')
        identity_guard = classify.index('if [[ ! "$RUNTIME_BASE_SHA" =~ ^[0-9a-f]{40}$ ]]')
        runtime_state_guard = classify.index('if [[ "$RUNTIME_STATE" != "normal-version-lag"')
        manual_mode = classify.index('echo "mode=manual"', runtime_state_guard)
        self.assertLess(pop_branch, identity_guard)
        self.assertLess(identity_guard, manual_branch)
        self.assertLess(manual_branch, runtime_state_guard)
        self.assertLess(runtime_state_guard, manual_mode)
        self.assertIn("normal-version-lag", classify)
        self.assertIn("read-only manifest evidence must be valid before manual activation", classify)

    def test_classifier_holds_protected_paths_and_semantics(self):
        cases = [
            ([".github/workflows/deploy.yml"], "", "T3"),
            (["backend/database/migrations/2026_01_add_flag.php"], "", "T3"),
            (["backend/app/Http/Controllers/StudentsController.php"], "payment status", "T3"),
            (["frontend/src/pages/SmartCalendar.vue"], "schedule conflict", "T2"),
            (["frontend/src/components/Badge.vue"], "display only", "T1"),
        ]
        for paths, patch, expected in cases:
            with self.subTest(paths=paths):
                self.assertEqual(classify_scope(paths, patch)["tier_name"], expected)

    def test_declaration_is_validated_against_machine_minimum(self):
        risk, tier = parse_declaration("**Risk-Class:** R1\n**Autonomy-Tier:** T1")
        self.assertEqual((risk, tier), (1, 1))
        self.assertEqual(effective_tier(1, risk, tier), (1, None))
        self.assertEqual(effective_tier(3, risk, tier)[0], None)

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

    def test_verified_deployed_ancestor_is_normal_version_lag_and_retryable(self):
        production = "a" * 40
        target = "b" * 40
        result = classify_production_runtime(
            production_sha=production,
            target_sha=target,
            comparison_status="ahead",
            provenance_sha=production,
            manifest_source="github-actions:deploy.yml",
        )
        self.assertEqual(result["state"], "normal-version-lag")
        self.assertTrue(result["retry_allowed"])

    def test_unexpected_third_sha_is_blocked(self):
        production = "a" * 40
        target = "b" * 40
        result = classify_production_runtime(
            production_sha=production,
            target_sha=target,
            comparison_status="diverged",
            provenance_sha=production,
            manifest_source="github-actions:deploy.yml",
        )
        self.assertEqual(result["state"], "unexpected-production-sha")
        self.assertFalse(result["retry_allowed"])

    def test_unknown_provenance_and_invalid_evidence_are_blocked(self):
        production = "a" * 40
        target = "b" * 40
        unknown_provenance = classify_production_runtime(
            production_sha=production,
            target_sha=target,
            comparison_status="ahead",
            provenance_sha=None,
            manifest_source="github-actions:deploy.yml",
        )
        invalid_manifest = classify_production_runtime(
            production_sha=production,
            target_sha=target,
            comparison_status="ahead",
            provenance_sha=production,
            manifest_source="manual",
        )
        self.assertEqual(unknown_provenance["state"], "provenance-unknown")
        self.assertEqual(invalid_manifest["state"], "provenance-unknown")
        self.assertFalse(unknown_provenance["retry_allowed"])
        self.assertFalse(invalid_manifest["retry_allowed"])

    def test_solo_environment_rejects_required_reviewer_gate(self):
        self.assertTrue(environment_protection_is_valid(
            event_name="workflow_dispatch", phase="application-deploy",
            required_reviewers_configured=False, prevent_self_review=False,
        ))
        self.assertTrue(environment_protection_is_valid(
            event_name="workflow_dispatch", phase="pop-bootstrap",
            required_reviewers_configured=False, prevent_self_review=False,
        ))
        self.assertFalse(environment_protection_is_valid(
            event_name="workflow_dispatch", phase="application-deploy",
            required_reviewers_configured=True, prevent_self_review=True,
        ))
        for event_name, phase in (
            ("workflow_run", "application-deploy"),
            ("workflow_dispatch", "unknown-phase"),
        ):
            with self.subTest(event_name=event_name, phase=phase):
                self.assertFalse(environment_protection_is_valid(
                    event_name=event_name, phase=phase,
                    required_reviewers_configured=False, prevent_self_review=False,
                ))


class DeployActivationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_existing_ci_completion_trigger_is_preserved(self):
        self.assertIn('workflows: ["CI — PHPUnit Tests"]', self.workflow)
        self.assertIn("types: [completed]", self.workflow)
        self.assertIn("branches: [main]", self.workflow)

    def test_manual_activation_is_existing_workflow_dispatch_and_environment_gate(self):
        self.assertIn("repository_dispatch:", self.workflow)
        self.assertIn("autonomous-production-deploy", self.workflow)
        self.assertIn("github.event.client_payload.target_sha", self.workflow)
        self.assertIn("- application-deploy", self.workflow)
        self.assertIn("- pop-bootstrap", self.workflow)
        self.assertIn("campus_id:", self.workflow)
        self.assertIn("BOOTSTRAP_POP_MACHINE:<target_sha>:CAMPUS:<campus_id>", self.workflow)
        self.assertIn("target_sha:", self.workflow)
        self.assertIn("ACTIVATE_PRODUCTION:<target_sha>", self.workflow)
        self.assertIn("environment:", self.workflow)
        self.assertIn("name: production-activation", self.workflow)
        self.assertIn("Checkout target revision for gate policy", self.workflow)
        self.assertIn("production environment protection is not configured", self.workflow)
        self.assertIn("required_reviewers_configured", self.workflow)
        self.assertIn("solo mode requires no required-reviewer rule", self.workflow)
        self.assertIn("environment_protection_is_valid", self.workflow)
        self.assertIn('EVENT_NAME: ${{ github.event_name }}', self.workflow)
        self.assertIn('PHASE: ${{ inputs.phase }}', self.workflow)

    def test_manual_workflow_revision_is_canonical_main(self):
        self.assertIn('WORKFLOW_REF: ${{ github.ref }}', self.workflow)
        self.assertIn('"$WORKFLOW_REF" != "refs/heads/main"', self.workflow)
        self.assertIn("Manual application activation must be dispatched from refs/heads/main", self.workflow)

    def test_classifier_is_authoritative_and_fail_closed_when_missing(self):
        self.assertIn("scripts/governance/autonomy_gate.py", self.workflow)
        self.assertIn("authoritative classifier unavailable; fail closed", self.workflow)
        self.assertIn("classify_activation_scope", self.workflow)
        self.assertIn("comparison.get(\"commits\")", self.workflow)
        self.assertIn("is_deployable_path(path)", self.workflow)
        self.assertIn("is_production_activation_sensitive_path(path)", self.workflow)
        self.assertIn("read-only GitHub API metadata", self.workflow)
        self.assertIn("decide_activation", self.workflow)
        self.assertIn("parse_declaration", self.workflow)
        self.assertIn("effective_tier", self.workflow)
        self.assertNotIn("re.search(r\"(?m)^\\*\\*Risk-Class", self.workflow)

    def test_safe_merge_gate_keeps_large_github_payloads_out_of_environment(self):
        self.assertIn('PR_JSON_FILE="$(mktemp)"', AUTOMERGE_WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn('FILES_JSON_FILE="$(mktemp)"', AUTOMERGE_WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn('gh api "/repos/${REPO}/pulls/${PR_NUMBER}" >"$PR_JSON_FILE"', AUTOMERGE_WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn('Path(os.environ["PR_JSON_FILE"])', AUTOMERGE_WORKFLOW.read_text(encoding="utf-8"))
        self.assertNotIn('PR_JSON="$PR_JSON" FILES_JSON="$FILES_JSON"', AUTOMERGE_WORKFLOW.read_text(encoding="utf-8"))

    def test_state_machine_has_fail_closed_modes(self):
        policy = (ROOT / "scripts" / "governance" / "autonomy_gate.py").read_text(encoding="utf-8")
        for mode in ("no-op", "manual", "auto", "awaiting-activation"):
            self.assertIn(f'"decision": "{mode}"', policy)
        self.assertIn("No production SSH, migration, frontend build, or data mutation was executed", self.workflow)

    def test_deploy_job_requires_auto_or_successful_manual_gate(self):
        self.assertIn("needs: [resolve-target, detect-deployable, classify-activation, production-activation]", self.workflow)
        self.assertIn("needs.classify-activation.outputs.mode == 'auto'", self.workflow)
        self.assertIn("needs.production-activation.result == 'success'", self.workflow)
        self.assertIn('TARGET_SHA="${{ needs.resolve-target.outputs.target_sha }}"', self.workflow)

    def test_pop_bootstrap_is_a_protected_host_local_executor(self):
        self.assertIn("  pop-bootstrap:", self.workflow)
        self.assertIn("name: Bootstrap POP machine on Pi", self.workflow)
        self.assertIn("inputs.phase == 'pop-bootstrap'", self.workflow)
        self.assertIn("group: alltrue-production-side-effects-v2", self.workflow)
        self.assertIn("php artisan pop:bootstrap-machine", self.workflow)
        self.assertIn("--confirm=POP_BOOTSTRAP_MACHINE", self.workflow)
        self.assertIn("existing POP machine identity verified", self.workflow)
        self.assertIn("never regenerate or overwrite it", self.workflow)
        self.assertIn("-H 'Accept: application/json'", self.workflow)
        self.assertIn("Authenticated POP submit boundary expected HTTP 422", self.workflow)
        self.assertIn("neither its content nor the request body is emitted", self.workflow)
        self.assertIn("[[ \"$(stat -c '%a' \"$KEY_FILE\")\" == \"600\" ]]", self.workflow)
        self.assertIn("if: ${{ always() && inputs.phase != 'pop-bootstrap'", self.workflow)

    def test_production_concurrency_is_scoped_to_side_effecting_jobs(self):
        top_level = self.workflow.split("permissions:", 1)[0]
        self.assertNotIn("concurrency:", top_level)
        deploy_start = self.workflow.index("  deploy:\n")
        rotation_start = self.workflow.index("  staged_principal_rotation:")
        deploy_job = self.workflow[deploy_start:rotation_start]
        rotation_job = self.workflow[rotation_start:]
        for job in (deploy_job, rotation_job):
            self.assertIn("group: alltrue-production-side-effects-v2", job)
            self.assertIn("cancel-in-progress: false", job)

    def test_all_guarded_production_side_effects_share_rotated_queue_namespace(self):
        for filename in (
            "deploy.yml",
            "1387-db-password-rotation.yml",
            "1387-db-grant-repair.yml",
        ):
            workflow = (WORKFLOW.parent / filename).read_text(encoding="utf-8")
            self.assertIn("group: alltrue-production-side-effects-v2", workflow)
            self.assertNotIn("group: production-deploy", workflow)
            self.assertIn("cancel-in-progress: false", workflow)

    def test_test_only_changes_are_not_deployable(self):
        self.assertIn("is_deployable_path", self.workflow)
        classifier = (ROOT / "scripts" / "governance" / "autonomy_gate.py").read_text(encoding="utf-8")
        self.assertIn('"backend/tests/**"', classifier)
        self.assertIn('"scripts/ci/**"', classifier)

    def test_manual_target_requires_current_main_and_successful_ci(self):
        self.assertIn("Manual activation must target the current main SHA", self.workflow)
        self.assertIn("actions/workflows/ci.yml/runs?branch=main&head_sha=", self.workflow)
        self.assertIn('run.get("name") == "CI — PHPUnit Tests"', self.workflow)
        self.assertIn('run.get("conclusion") == "success"', self.workflow)
        self.assertIn("Final exact-main gate before production executor", self.workflow)
        self.assertIn("REMOTE_MAIN_SHA=$(git rev-parse refs/remotes/origin/main)", self.workflow)

    def test_runtime_gate_distinguishes_version_lag_from_unexpected_drift(self):
        self.assertIn("runtime_state", self.workflow)
        self.assertIn("classify_production_runtime", self.workflow)
        self.assertIn("normal-version-lag", self.workflow)
        self.assertIn("Deploy to Production", self.workflow)
        self.assertIn("actions/runs/", self.workflow)
        self.assertIn("/jobs?per_page=100", self.workflow)
        policy = (ROOT / "scripts" / "governance" / "autonomy_gate.py").read_text(encoding="utf-8")
        self.assertIn("unexpected-production-sha", policy)
        self.assertIn("provenance-unknown", policy)

    def test_admissions_flag_requires_explicit_manual_mode_and_preserves_auto_value(self):
        self.assertIn("admissions_funnel_v1:", self.workflow)
        self.assertIn("default: unchanged", self.workflow)
        self.assertIn('ADMISSIONS_FLAG_MODE="${{ inputs.admissions_funnel_v1 }}"', self.workflow)
        self.assertIn('ADMISSIONS_FLAG_MODE" = "on"', self.workflow)
        self.assertIn('ADMISSIONS_FLAG_MODE" = "off"', self.workflow)
        self.assertIn("ADMISSIONS_FLAG_VALUE=$(grep -E", self.workflow)
        self.assertIn("VITE_ADMISSIONS_FUNNEL_V1=%s", self.workflow)
        self.assertIn("ADMISSIONS_FLAG_CHANGED=1", self.workflow)
        self.assertIn("admissions flag restored during rollback", self.workflow)
        self.assertIn('[ "$ADMISSIONS_FLAG_CHANGED" -eq 1 ]', self.workflow)

    def test_deploy_failures_are_fail_closed_and_share_rollback(self):
        self.assertIn("rollback_deploy()", self.workflow)
        self.assertIn("abort_deploy()", self.workflow)
        self.assertIn('if ! (cd /home/admin/backend && composer install', self.workflow)
        self.assertIn('if ! (cd /home/admin/frontend && npm install', self.workflow)
        self.assertIn('if ! write_deployment_manifest "$TARGET_SHA"', self.workflow)
        self.assertIn('if ! php artisan optimize; then', self.workflow)
        self.assertIn('if ! rm -f /home/admin/frontend/.env.production.local; then', self.workflow)
        self.assertIn('if ! OPCACHE_RESPONSE=$(curl -skf -X POST', self.workflow)
        self.assertIn('abort_deploy "health check 或 smoke test 失敗"', self.workflow)
        self.assertIn('rollback_failed=1', self.workflow)
        self.assertIn('health=$health2 rollback_failed=$rollback_failed', self.workflow)
        self.assertNotIn('git reset --hard "$PREV_COMMIT" || echo', self.workflow)

    def test_contract_test_runs_before_target_resolution(self):
        self.assertIn("contract-test:", self.workflow)
        self.assertIn("needs: contract-test", self.workflow)
        self.assertIn("python3 scripts/tests/test_deploy_activation_state.py", self.workflow)
        self.assertEqual(self.workflow.count("  detect-deployable:"), 1)


class AutonomousMergeWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = AUTOMERGE_WORKFLOW.read_text(encoding="utf-8")

    def test_uses_pull_request_target_and_base_revision(self):
        self.assertIn("pull_request_target:", self.workflow)
        self.assertIn("pull_request.base.sha", self.workflow)
        self.assertIn("head.repo.full_name == github.repository", self.workflow)

    def test_only_machine_eligible_prs_get_server_side_auto_merge(self):
        self.assertIn("classify_scope", self.workflow)
        self.assertIn("gh pr merge \"$PR_NUMBER\" --repo \"$REPO\" --auto --squash --delete-branch", self.workflow)
        self.assertIn("decision=hold", self.workflow)
        self.assertIn("outputs.decision == 'auto'", self.workflow)
        self.assertIn("contents: write", self.workflow)
        self.assertIn("pull-requests: write", self.workflow)

    def test_auto_merge_workflow_does_not_reference_protected_environment(self):
        self.assertNotIn("production-activation", self.workflow)

    def test_auto_merge_rechecks_head_sha_before_side_effect(self):
        self.assertIn("CURRENT_HEAD_SHA", self.workflow)
        self.assertIn("PR head changed before auto-merge; fail closed", self.workflow)


class BugPhaseATriageWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = BUG_TRIAGE_WORKFLOW.read_text(encoding="utf-8")

    def test_accepts_persisted_comment_model_and_idempotent_result(self):
        self.assertIn("$commentSucceeded = ($comment[\"ok\"] ?? false) || isset($comment[\"id\"]) || ($comment[\"skipped\"] ?? false);", self.workflow)
        self.assertIn("if (!$commentSucceeded)", self.workflow)
        self.assertNotIn('if (!($comment["ok"] ?? false))', self.workflow)


if __name__ == "__main__":
    unittest.main()
