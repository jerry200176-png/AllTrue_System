import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[2]))
from scripts.agent_factory_receipt import (  # type: ignore
    add_check, classify_failure, idempotency_key, new_receipt, retry_decision,
    append_event, set_terminal, shadow_effects, terminal_state, write_once,
)


class ReceiptTests(unittest.TestCase):
    def receipt(self, acceptance=("verify",)):
        return new_receipt("task-1", "session-1", "a" * 40, acceptance=list(acceptance), run_id="1")

    def test_terminal_requires_acceptance_relevant_passes(self):
        r = self.receipt(); add_check(r, "unit", "PASSED", acceptance_relevant=True)
        self.assertEqual(terminal_state(r), "VERIFIED")

    def test_skip_is_not_verified(self):
        r = self.receipt(); add_check(r, "ui", "SKIPPED", reason="credentials absent", acceptance_relevant=True)
        self.assertEqual(terminal_state(r), "FOUNDER_REQUIRED")

    def test_not_applicable_is_allowed(self):
        r = self.receipt(); add_check(r, "ui", "NOT_APPLICABLE", acceptance_relevant=True)
        self.assertEqual(terminal_state(r), "VERIFIED")

    def test_missing_required_check_cannot_verify_early(self):
        r = self.receipt(); r["required_checks"] = ["unit", "browser"]
        add_check(r, "unit", "PASSED", acceptance_relevant=True)
        self.assertIsNone(terminal_state(r))

    def test_failure_is_final(self):
        r = self.receipt(); add_check(r, "unit", "FAILED", acceptance_relevant=True)
        self.assertEqual(terminal_state(r), "FAILED_FINAL")

    def test_deployment_evidence_is_required_for_runtime_changes(self):
        r = self.receipt(); r["deployment_required"] = True
        add_check(r, "unit", "PASSED", acceptance_relevant=True)
        self.assertIsNone(terminal_state(r))
        r["deployment"] = {"status": "PASSED", "sha": "a" * 40}
        r["runtime_verification"] = {"status": "PASSED", "sha": "a" * 40}
        self.assertEqual(terminal_state(r), "VERIFIED")

    def test_empty_acceptance_requires_review(self):
        r = self.receipt(())
        add_check(r, "unit", "PASSED", acceptance_relevant=True)
        self.assertEqual(terminal_state(r), "FOUNDER_REQUIRED")

    def test_retry_is_bounded_and_idempotent(self):
        r = self.receipt(); r["attempt_count"] = 1
        first = retry_decision(r, "INFRASTRUCTURE_FAILURE", reason="CI timeout")
        self.assertEqual(first["action"], "RETRY")
        self.assertEqual(first["idempotency_key"], idempotency_key("task-1", "a" * 40))
        r["attempt_count"] = 3
        self.assertEqual(retry_decision(r, "INFRASTRUCTURE_FAILURE")["action"], "STOP")
        self.assertEqual(retry_decision(r, "FOUNDER_REQUIRED")["action"], "STOP")

    def test_failure_classification_keeps_high_risk_manual(self):
        self.assertEqual(classify_failure("migration approval required", 1), "FOUNDER_REQUIRED")
        self.assertEqual(classify_failure("provenance manifest missing", 1), "EVIDENCE_REPAIR")

    def test_receipts_are_write_once(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "receipt.json"; write_once(path, self.receipt())
            with self.assertRaises(FileExistsError): write_once(path, self.receipt())

    def test_events_are_idempotent(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "events.jsonl"; event = {"event_type": "retry", "task_id": "task-1"}
            append_event(path, event); append_event(path, event)
            self.assertEqual(len(path.read_text().splitlines()), 1)

    def test_terminal_state_cannot_be_stale_overwritten(self):
        r = self.receipt(); set_terminal(r, "FOUNDER_REQUIRED")
        with self.assertRaises(ValueError): set_terminal(r, "VERIFIED")

    def test_receipt_redacts_sensitive_text(self):
        r = new_receipt("task-1", "session-1", "a" * 40, acceptance=["contact a@b.test password=secret"])
        self.assertNotIn("a@b.test", r["acceptance_criteria"][0])
        self.assertNotIn("secret", r["acceptance_criteria"][0])

    def test_event_redacts_sensitive_reason(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "events.jsonl"
            append_event(path, {"event_type": "retry", "reason": "password=secret a@b.test"})
            event = json.loads(path.read_text())
            self.assertNotIn("secret", event["reason"])
            self.assertNotIn("a@b.test", event["reason"])

    def test_shadow_classifier_is_non_authoritative(self):
        result = shadow_effects(["frontend/src/Button.vue"], "copy change", existing="T1")
        self.assertEqual(result["shadow_classification"], "T1")
        self.assertFalse(result["authoritative"])
        self.assertEqual(result["actual_outcome"], "UNKNOWN")
        result = shadow_effects(["backend/database/migrations/one.php"], "alter table")
        self.assertIn("SCHEMA_CHANGE", result["detected_effects"])
        self.assertEqual(result["shadow_classification"], "T3")


if __name__ == "__main__":
    unittest.main()
