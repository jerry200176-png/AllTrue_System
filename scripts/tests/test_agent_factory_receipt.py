import json
import base64
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[2]))
import scripts.agent_factory_receipt as receipt_module  # type: ignore
from scripts.agent_factory_receipt import (  # type: ignore
    add_check, classify_failure, idempotency_key, new_receipt, retry_decision,
    append_event, collect, recovery_decision, set_terminal, shadow_effects,
    terminal_state, write_once,
)

implementation = receipt_module.implementation


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

    def test_recovery_claim_is_mutually_exclusive_and_bounded(self):
        self.assertEqual(recovery_decision(1, 1, {"deploy": [], "ci": []})["action"], "RETRY")
        self.assertEqual(
            recovery_decision(1, 2, {"deploy": [], "ci": []})["action"],
            "STOP",
        )
        self.assertEqual(
            recovery_decision(3, 3, {"deploy": [], "ci": []})["action"],
            "STOP",
        )
        self.assertEqual(
            recovery_decision(1, 1, {"deploy": [{"id": 9}], "ci": []})["action"],
            "STOP",
        )

    def collect_fixture(self, *, artifact="passed", frontend=True,
                        workflow_conclusion="success", artifact_status="PASSED",
                        artifact_relevant=None):
        target_sha = "b" * 40
        files = [{"filename": "frontend/e2e/smoke.spec.js", "patch": "+test"}] if frontend else [{"filename": "docs/guide.md", "patch": "+docs"}]
        body = "## Acceptance Criteria\n- [ ] verify the change\n\nRisk-Class: R1\nAutonomy-Tier: T1"
        event = {"workflow_run": {
            "id": 77,
            "name": "UI Smoke (Playwright)",
            "head_sha": target_sha,
            "head_branch": "chore/task-ui-receipt",
            "conclusion": workflow_conclusion,
            "run_attempt": 1,
            "pull_requests": [{"number": 42}],
        }}
        check_runs = [
            {"name": name, "conclusion": "success", "output": {"title": "ok"}}
            for name in ("Presubmit Checks", "PHPUnit Feature & Unit Tests", "Vite Frontend Build")
        ]
        if frontend:
            check_runs.append({"name": "UI Smoke (Playwright)", "conclusion": "success", "output": {"title": "ok"}})

        def fake_api(_repo, path):
            if path == "pulls/42":
                return {"number": 42, "state": "open", "head": {"sha": target_sha, "ref": "chore/task-ui-receipt"}, "body": body, "merged_at": None, "merge_commit_sha": None}
            if path.startswith("contents/.agent-session/manifest.json"):
                content = base64.b64encode(json.dumps({"session_id": "session-1", "agent_cli": "codex"}).encode()).decode()
                return {"content": content}
            if path == "pulls/42/files?per_page=100":
                return files
            if path.startswith(f"commits/{target_sha}/check-runs"):
                return {"check_runs": check_runs}
            if path.startswith("actions/workflows/deploy.yml/runs?"):
                return {"workflow_runs": []}
            raise AssertionError(f"unexpected API path: {path}")

        def fake_download(command, **_kwargs):
            directory = Path(command[command.index("--dir") + 1])
            if artifact == "missing":
                return subprocess.CompletedProcess(command, 1, stderr="artifact missing")
            directory.mkdir(parents=True, exist_ok=True)
            artifact_file = directory / "ui-verification.json"
            if artifact == "malformed":
                artifact_file.write_text("not-json", encoding="utf-8")
            else:
                relevant = frontend if artifact_relevant is None else artifact_relevant
                artifact_file.write_text(json.dumps({"checks": [{
                    "name": "UI Smoke (Playwright)",
                    "status": artifact_status,
                    "reason": "fixture",
                    "acceptance_relevant": relevant,
                }]}), encoding="utf-8")
            return subprocess.CompletedProcess(command, 0, stdout="")

        with tempfile.TemporaryDirectory() as root:
            event_path = Path(root) / "event.json"
            output_path = Path(root) / "receipt.json"
            event_path.write_text(json.dumps(event), encoding="utf-8")
            with patch.object(implementation, "api", side_effect=fake_api), patch.object(implementation.subprocess, "run", side_effect=fake_download):
                return collect(str(event_path), "AllTrue", "collector-run", str(output_path))

    def test_collect_ui_success_with_passed_artifact_is_passed(self):
        receipt = self.collect_fixture(artifact_status="PASSED")
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "PASSED")
        self.assertEqual(receipt["terminal_state"], "VERIFIED")

    def test_collect_ui_success_with_skipped_artifact_requires_founder(self):
        receipt = self.collect_fixture(artifact_status="SKIPPED")
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "SKIPPED")
        self.assertEqual(receipt["terminal_state"], "FOUNDER_REQUIRED")

    def test_collect_ui_success_with_missing_artifact_never_verifies(self):
        receipt = self.collect_fixture(artifact="missing")
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "SKIPPED")
        self.assertNotEqual(receipt["terminal_state"], "VERIFIED")
        self.assertEqual(receipt["terminal_state"], "FOUNDER_REQUIRED")

    def test_collect_ui_success_with_malformed_artifact_never_verifies(self):
        receipt = self.collect_fixture(artifact="malformed")
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "SKIPPED")
        self.assertNotEqual(receipt["terminal_state"], "VERIFIED")
        self.assertEqual(receipt["terminal_state"], "FOUNDER_REQUIRED")

    def test_collect_ui_job_failure_is_failed_final(self):
        receipt = self.collect_fixture(workflow_conclusion="failure", artifact="missing")
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "FAILED")
        self.assertEqual(receipt["terminal_state"], "FAILED_FINAL")

    def test_collect_nonfrontend_not_applicable_does_not_interrupt_founder(self):
        receipt = self.collect_fixture(frontend=False, artifact_status="NOT_APPLICABLE", artifact_relevant=False)
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "NOT_APPLICABLE")
        self.assertFalse(ui["acceptance_relevant"])
        self.assertEqual(receipt["terminal_state"], "VERIFIED")

    def test_collect_inconsistent_artifact_fails_closed(self):
        receipt = self.collect_fixture(artifact_status="NOT_APPLICABLE", artifact_relevant=False)
        ui = next(check for check in receipt["checks"] if check["name"] == "UI Smoke (Playwright)")
        self.assertEqual(ui["status"], "SKIPPED")
        self.assertEqual(receipt["terminal_state"], "FOUNDER_REQUIRED")

    def test_missing_declaration_does_not_control_authority(self):
        auto_merge = (Path(__file__).parents[2] / ".github/workflows/auto-merge-safe.yml").read_text()
        deploy = (Path(__file__).parents[2] / ".github/workflows/deploy.yml").read_text()
        self.assertIn("effective_tier(", auto_merge)
        self.assertIn("if error:", auto_merge)
        self.assertNotIn("Declaration absent; using machine-validated", auto_merge)
        self.assertNotIn("machine_scope[\"machine_minimum_tier\"]", deploy)

    def test_workflow_graph_is_bounded_and_one_way(self):
        root = Path(__file__).parents[2]
        receipt = (root / ".github/workflows/agent-factory-receipt.yml").read_text()
        recovery = (root / ".github/workflows/agent-factory-recovery.yml").read_text()
        convergence = (root / ".github/workflows/autonomous-convergence.yml").read_text()
        self.assertNotIn("gh run rerun", receipt)
        self.assertNotIn("repository_dispatch", receipt)
        self.assertIn('workflows: ["Autonomous main convergence"]', recovery)
        self.assertIn("group: agent-factory-recovery-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}", recovery)
        self.assertIn("OBSERVED_ATTEMPT", recovery)
        self.assertIn("LATEST_ATTEMPT", recovery)
        self.assertIn("gh run rerun \"$SOURCE_RUN_ID\" --failed", recovery)
        self.assertNotIn("/dispatches", recovery)
        self.assertIn("run_attempt < 3", recovery)
        self.assertIn("MAX_ATTEMPTS = 3", (root / "scripts/agent-factory/receipt.py").read_text())
        self.assertIn('client_payload[idempotency_key]', convergence)

    def test_deployment_idempotency_is_exact_sha_state_not_task_key(self):
        deploy = (Path(__file__).parents[2] / ".github/workflows/deploy.yml").read_text()
        self.assertIn("already_deployed", deploy)
        self.assertIn("head_sha=${TARGET_SHA}", deploy)
        self.assertIn("needs.resolve-target.outputs.already_deployed != 'true'", deploy)
        self.assertNotIn("client_payload[idempotency_key]", deploy)

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
