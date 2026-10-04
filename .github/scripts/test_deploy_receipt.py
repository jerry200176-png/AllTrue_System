"""Failure cases for the exact target deployment receipt."""

from pathlib import Path
import importlib.util
import re
import unittest
from datetime import datetime, timezone

from deploy_receipt import SOURCE, make_receipt


TARGET = "a" * 40
WORKFLOW_SHA = "b" * 40
EVENT_HEAD = "c" * 40
WORKFLOW_FILE = Path(__file__).parents[1] / "workflows" / "deploy.yml"
NOW = datetime(2026, 10, 3, 22, 12, tzinfo=timezone.utc)


def assert_workflow_receipt_contract(source):
    try:
        deploy = source.split("\n  deploy:\n", 1)[1].split("\n  parent-portal-smoke:\n", 1)[0]
    except IndexError as exc:
        raise ValueError("production deploy job boundary missing") from exc
    if "needs.production-activation.result == 'success'" not in deploy or "needs.resolve-target.result == 'success'" not in deploy:
        raise ValueError("production activation and target gates missing")
    steps = re.split(r"(?m)^      - name: ", deploy)[1:]
    names = [step.split("\n", 1)[0] for step in steps]
    required = ["Mark exact deployment attempt", "Deploy", "Build exact target deployment receipt",
                "Upload exact target deployment receipt", "Record deployed and production-verified state"]
    if any(names.count(name) != 1 for name in required):
        raise ValueError("required deploy/receipt steps must appear exactly once")
    if [names.index(name) for name in required] != sorted(names.index(name) for name in required):
        raise ValueError("receipt cannot precede a successful deploy or recording precede upload")
    for name in (required[0], *required[2:]):
        block = steps[names.index(name)]
        if re.search(r"(?m)^        if:", block):
            raise ValueError("receipt/success steps must use the default prior-step success condition")
    upload = steps[names.index("Upload exact target deployment receipt")]
    if "production-deploy-receipt-${{ github.run_id }}-${{ github.run_attempt }}" not in upload:
        raise ValueError("artifact identity must bind run and attempt")
    if "if-no-files-found: error" not in upload:
        raise ValueError("missing receipt must fail upload")


def manifest():
    return {
        "backend_sha": TARGET,
        "frontend_sha": "d" * 40,
        "frontend_build_sha": "d" * 40,
        "source": SOURCE,
        "deployed_at": "2026-10-03T22:11:33Z",
    }


def metadata():
    return {
        "GITHUB_REPOSITORY": "jerry200176-png/AllTrue_System",
        "GITHUB_EVENT_NAME": "workflow_dispatch",
        "GITHUB_WORKFLOW_SHA": WORKFLOW_SHA,
        "GITHUB_SHA": EVENT_HEAD,
        "TARGET_SHA": TARGET,
        "GITHUB_RUN_ID": "36955378940",
        "GITHUB_RUN_ATTEMPT": "2",
        "DEPLOY_ATTEMPT_STARTED_AT": "2026-10-03T22:11:00Z",
    }


class ReceiptTest(unittest.TestCase):
    def test_canonical_manifest_writer_source_is_accepted(self):
        writer = Path(__file__).parents[2] / "scripts" / "write-deployment-manifest.py"
        spec = importlib.util.spec_from_file_location("canonical_manifest_writer", writer)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        runtime = module.build_manifest(TARGET, {"build_sha": "d" * 40}, "2026-10-03T22:11:33Z")
        self.assertEqual(runtime["source"], SOURCE)
        self.assertEqual(make_receipt(runtime, metadata(), NOW)["source_sha"], TARGET)

    def test_workflow_only_records_after_deploy_and_receipt_upload(self):
        source = WORKFLOW_FILE.read_text(encoding="utf-8")
        assert_workflow_receipt_contract(source)
        record = source.replace("      - name: Record deployed and production-verified state", "      - name: Premature record")
        with self.assertRaises(ValueError):
            assert_workflow_receipt_contract(record)
        unmarked = source.replace("      - name: Mark exact deployment attempt", "      - name: No attempt marker")
        with self.assertRaises(ValueError):
            assert_workflow_receipt_contract(unmarked)
        bypass = source.replace("      - name: Upload exact target deployment receipt\n", "      - name: Upload exact target deployment receipt\n        if: always()\n")
        with self.assertRaises(ValueError):
            assert_workflow_receipt_contract(bypass)

    def test_manual_dispatch_binds_resolved_target_not_event_head(self):
        receipt = make_receipt(manifest(), metadata(), NOW)
        self.assertEqual(receipt["source_sha"], TARGET)
        self.assertEqual(receipt["event_head_sha"], EVENT_HEAD)
        self.assertEqual(receipt["workflow_revision_sha"], WORKFLOW_SHA)
        self.assertEqual((receipt["run_id"], receipt["run_attempt"]), (36955378940, 2))
        self.assertEqual(receipt["runtime"]["backend_sha"], TARGET)
        self.assertEqual(receipt["attempt_started_at"], "2026-10-03T22:11:00Z")
        self.assertEqual(receipt["verification_state"], "production-verified")
        self.assertEqual(receipt["application_artifact_digest"], "unknown")

    def test_automatic_event_uses_same_exact_target_contract(self):
        meta = metadata()
        meta["GITHUB_EVENT_NAME"] = "workflow_run"
        meta["GITHUB_SHA"] = TARGET
        self.assertEqual(make_receipt(manifest(), meta, NOW)["source_sha"], TARGET)

    def test_runtime_target_mismatch_refuses_success_receipt(self):
        runtime = manifest()
        runtime["backend_sha"] = "e" * 40
        with self.assertRaisesRegex(ValueError, "differs from resolved target"):
            make_receipt(runtime, metadata(), NOW)

    def test_legacy_frontend_identity_is_recorded_as_short_or_unknown(self):
        for value, status in (("e483c1dc", "short-sha"), (None, "unknown"), ("unknown", "unknown")):
            with self.subTest(value=value):
                runtime = manifest()
                runtime["frontend_sha"] = value
                runtime["frontend_build_sha"] = value
                self.assertEqual(make_receipt(runtime, metadata(), NOW)["runtime"]["frontend_identity_status"], status)

    def test_missing_or_ambiguous_run_identity_refuses_receipt(self):
        for key, value in (("GITHUB_RUN_ID", ""), ("GITHUB_RUN_ATTEMPT", "0"),
                           ("GITHUB_RUN_ATTEMPT", "abc"), ("TARGET_SHA", "a" * 7),
                           ("GITHUB_WORKFLOW_SHA", ""),
                           ("DEPLOY_ATTEMPT_STARTED_AT", "2026-10-03T21:00:00Z"),
                           ("DEPLOY_ATTEMPT_STARTED_AT", "2026-10-03T22:13:00Z")):
            with self.subTest(key=key, value=value):
                meta = metadata()
                meta[key] = value
                with self.assertRaises(ValueError):
                    make_receipt(manifest(), meta, NOW)

    def test_malformed_runtime_and_time_refuse_receipt(self):
        for field, value in (("deployed_at", "bad"), ("deployed_at", "2099-01-01T00:00:00Z"),
                             ("deployed_at", "2026-10-03T21:00:00Z"),
                             ("deployed_at", "2026-10-03T22:05:00Z"),
                             ("source", "other"), ("frontend_build_sha", "")):
            with self.subTest(field=field):
                runtime = manifest()
                runtime[field] = value
                with self.assertRaises(ValueError):
                    make_receipt(runtime, metadata(), NOW)


if __name__ == "__main__":
    unittest.main()
