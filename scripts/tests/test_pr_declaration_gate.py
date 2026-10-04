import unittest
from pathlib import Path

from scripts.governance.autonomy_gate import (
    _LOCAL_ONLY_E2E_SPECS,
    classify_activation_scope,
    classify_scope,
    machine_declaration,
    validate_declaration,
)


class PrDeclarationGateTest(unittest.TestCase):
    def test_production_smoke_assertion_cannot_take_t0_auto_merge(self):
        path = "frontend/e2e/dashboard-workbench.spec.js"
        patch = (
            f"diff --git a/{path} b/{path}\n"
            "@@ -1 +1 @@\n"
            "-    expect(tuitionAlertsResponse.status()).toBe(200);\n"
            "+    test.skip(true, 'temporarily bypass tuition smoke');\n"
        )
        generated = machine_declaration([path], patch)
        self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
        rejected = validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [path], patch)
        self.assertFalse(rejected["valid"])
        self.assertIn("below", rejected["error"])

    def test_only_production_smoke_controls_raise_test_only_scope(self):
        for path in (
            "frontend/playwright.config.js",
            "frontend/e2e/classroom-management-production-smoke.spec.js",
            "frontend/e2e/smoke.spec.js",
            "frontend/e2e/parent-portal-production.spec.js",
            "frontend/e2e/fixtures/dismissOverlays.js",
            "frontend/e2e/fixtures/directorDashboardTelemetry.js",
            "frontend/e2e/fixtures/tutoringReceivableControls.js",
        ):
            with self.subTest(path=path):
                self.assertEqual(classify_scope([path], "")["tier_name"], "T3")
        for path in (
            "frontend/e2e/fixtures/ui-foundation/pilot-mount.js",
            "frontend/src/components/__tests__/Widget.test.js",
        ):
            with self.subTest(path=path):
                self.assertEqual(classify_scope([path], "")["tier_name"], "T0")

    def test_every_default_ui_smoke_spec_is_a_protected_control(self):
        paths = (
            "after-class-confirmed-save", "attendance-runtime", "billing-correction-guidance",
            "branch-health-clarity", "calendar-course-consistency", "calendar-print-production",
            "classroom-clarity", "classroom-management-production-smoke", "classroom-management-recovery",
            "dashboard-workbench", "duplicate-review-human-labels", "feedback-launcher-production",
            "navigation-history", "navigation-more-search", "parent-portal-production",
            "role-onboarding", "school-directory-production", "smoke", "tutoring-continuation",
            "tutoring-free-production",
        )
        for stem in paths:
            path = f"frontend/e2e/{stem}.spec.js"
            with self.subTest(path=path):
                declaration = machine_declaration([path], "+ weaken production smoke evidence")
                self.assertEqual((declaration["risk_class"], declaration["autonomy_tier"]), ("R3", "T3"))

    def test_local_only_playwright_specs_remain_test_only(self):
        config = (Path(__file__).resolve().parents[2] / "frontend/playwright.config.js").read_text(encoding="utf-8")
        for path in _LOCAL_ONLY_E2E_SPECS:
            with self.subTest(path=path):
                self.assertTrue((Path(__file__).resolve().parents[2] / path).is_file())
                stem = Path(path).name.removesuffix(".spec.js")
                self.assertIn(stem, config, f"{stem} must remain ignored by the default production smoke config")
                self.assertEqual(classify_scope([path], "")["tier_name"], "T0")

    def test_missing_declaration_fails_before_merge(self):
        result = validate_declaration(
            "",
            ["frontend/src/components/Badge.vue"],
            "+ display-only change",
        )
        self.assertFalse(result["valid"])
        self.assertIn("missing", result["error"])
        self.assertEqual(result["generated"]["autonomy_tier"], "T1")

    def test_malformed_declaration_fails_before_merge(self):
        result = validate_declaration(
            "Risk-Class: R9\nAutonomy-Tier: T1",
            ["frontend/src/components/Badge.vue"],
            "+ display-only change",
        )
        self.assertFalse(result["valid"])

    def test_understated_declaration_fails_against_machine_minimum(self):
        result = validate_declaration(
            "Risk-Class: R1\nAutonomy-Tier: T1",
            ["backend/app/Http/Controllers/StudentClassController.php"],
            "+ billing authorization check",
        )
        self.assertFalse(result["valid"])
        self.assertIn("below", result["error"])
        self.assertEqual(result["generated"]["autonomy_tier"], "T3")

    def test_valid_reversible_t1_and_t2_declarations_pass(self):
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R0\nAutonomy-Tier: T0",
                ["docs/README.md"],
                "+ clarify documentation",
            )["valid"]
        )
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R1\nAutonomy-Tier: T1",
                ["frontend/src/components/Badge.vue"],
                "+ display-only change",
            )["valid"]
        )
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R2\nAutonomy-Tier: T2",
                ["frontend/src/pages/SmartCalendar.vue"],
                "+ schedule conflict display",
            )["valid"]
        )

    def test_billing_path_requires_protected_t3_declaration(self):
        paths = [
            "backend/app/Http/Controllers/StudentClassController.php",
            "backend/app/Services/TransactionDiscountCalculator.php",
        ]
        self.assertEqual(classify_scope(paths, "+ billing discount")["tier_name"], "T3")
        result = validate_declaration(
            "Risk-Class: R3\nAutonomy-Tier: T3",
            paths,
            "+ billing discount authorization",
        )
        self.assertTrue(result["valid"])
        activation = classify_activation_scope(paths, "+ billing discount authorization")
        self.assertIn(activation["activation_class"], {"routine", "founder-required"})

    def test_deploy_classifier_stays_independent_from_merge_gate(self):
        routine = classify_activation_scope(
            ["frontend/src/components/Badge.vue"], "+ display-only change"
        )
        self.assertEqual(routine["activation_class"], "routine")
        protected = classify_activation_scope(
            ["backend/app/Http/Controllers/StudentClassController.php"],
            "+ billing authorization mutation",
        )
        self.assertEqual(protected["activation_class"], "founder-required")

    def test_machine_declaration_is_derived_from_scope(self):
        generated = machine_declaration(
            ["frontend/src/pages/SmartCalendar.vue"], "+ schedule behavior"
        )
        self.assertEqual(generated["risk_class"], "R2")
        self.assertEqual(generated["autonomy_tier"], "T2")


WF = ".github/workflows/production-case-dump.yml"


def _wf_patch(*lines, path=WF):
    return f"diff --git a/{path} b/{path}\n@@ -1 +1 @@\n" + "\n".join("+" + l for l in lines)


class ReadOnlyProbeTierTest(unittest.TestCase):
    READ = ("if ($case === 'x') {", "$out['n'] = $db::table('Student')->where('id', 1)->count();", "}")

    def test_read_only_probe_is_t2_not_founder(self):
        r = validate_declaration("Risk-Class: R2\nAutonomy-Tier: T2", [WF, ".agent-session/manifest.json"], _wf_patch(*self.READ))
        self.assertTrue(r["valid"], r)
        self.assertEqual(r["generated"]["autonomy_tier"], "T2")

    def test_probe_with_write_stays_t3(self):
        for line in ("$db::table('Student')->where('id', 1)->update(['a' => 1]);", "DB::statement('x');", "UPDATE Student SET a=1", "permissions:", "run: php artisan migrate"):
            self.assertEqual(classify_scope([WF], _wf_patch(line))["tier_name"], "T3", line)

    def test_probe_plus_other_workflow_or_no_patch_stays_t3(self):
        other = ".github/workflows/deploy.yml"
        patch = _wf_patch(*self.READ) + "\n" + _wf_patch("# x", path=other)
        self.assertEqual(classify_scope([WF, other], patch)["tier_name"], "T3")
        self.assertEqual(classify_scope([WF], "")["tier_name"], "T3")


if __name__ == "__main__":
    unittest.main()
