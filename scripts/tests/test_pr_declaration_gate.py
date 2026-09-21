import unittest

from scripts.governance.autonomy_gate import (
    classify_activation_scope,
    classify_scope,
    machine_declaration,
    validate_declaration,
)


class PrDeclarationGateTest(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main()
